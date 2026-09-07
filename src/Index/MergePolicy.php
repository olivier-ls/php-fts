<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

/**
 * Decides which segments to merge, and when.
 *
 * Deliberately boring. The policy is tiered: segments are grouped by size, and
 * a tier holding too many of them gets collapsed into one segment at the tier
 * above. That gives each document O(log n) merges over its lifetime rather
 * than one rewrite of the whole index per batch.
 *
 * ── Why merging happens on writes, and only on writes ───────────────────────
 *
 * A merge produces a segment and publishes a commit, so it needs the write
 * lock. If a search could trigger one, a search could wait behind an import —
 * losing the property that makes the whole design work, which is that reads
 * take no lock at all. Reads are also the latency-sensitive path: a query that
 * occasionally costs 300 ms because it drew the short straw is worse than a
 * query that is reliably fast.
 *
 * So every mutation checks. That is what keeps the pathological case from
 * arising: someone looping `put()` ten thousand times does not accumulate ten
 * thousand segments, because the tiers collapse as the loop runs.
 *
 * ── And why it is bounded ───────────────────────────────────────────────────
 *
 * An automatic merge runs inside somebody's HTTP request, so it works to a
 * document cap and a time budget. Whatever it does not finish, the next write
 * picks up. `optimize()` lifts both, because whoever calls it has decided to
 * wait.
 */
final class MergePolicy
{
    public function __construct(
        /** Segments in one tier before it is collapsed. */
        public readonly int $segmentsPerTier = 8,

        /** Each tier holds segments this many times larger than the last. */
        public readonly int $tierFactor = 8,

        /** An automatic merge will not take on more documents than this. */
        public readonly int $maxAutoMergeDocuments = 50_000,

        /** How long an automatic merge may spend, in milliseconds. */
        public readonly float $timeBudgetMs = 250.0,

        /**
         * A segment more than this deleted is worth rewriting on its own, to
         * give the space back and to stop searches reading documents they will
         * then discard.
         */
        public readonly float $deletedRatioThreshold = 0.5,
    ) {
    }

    /**
     * Which segments to merge next, as positions into the list given.
     *
     * Returns fewer than two positions when there is nothing worth doing.
     *
     * @param array<int, array{documents: int, deleted: int}> $segments
     * @return int[]
     */
    public function select(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        // Group by tier, smallest first: collapsing the cheapest tier keeps the
        // amortised cost per document low.
        $tiers = [];

        foreach ($segments as $position => $segment) {
            $live = max(0, $segment['documents'] - $segment['deleted']);
            $tiers[$this->tierOf($live)][] = $position;
        }

        ksort($tiers);

        foreach ($tiers as $positions) {
            if (count($positions) < $this->segmentsPerTier) {
                continue;
            }

            $chosen = [];
            $total  = 0;

            foreach ($positions as $position) {
                $live = max(0, $segments[$position]['documents'] - $segments[$position]['deleted']);

                if ($chosen !== [] && $total + $live > $this->maxAutoMergeDocuments) {
                    break;
                }

                $chosen[] = $position;
                $total   += $live;
            }

            if (count($chosen) >= 2) {
                return $chosen;
            }
        }

        // Nothing to collapse, but a segment may be mostly tombstones. Rewriting
        // it alone drops the deleted documents and shrinks the manifest.
        foreach ($segments as $position => $segment) {
            if ($segment['documents'] === 0 || $segment['deleted'] === 0) {
                continue;
            }

            $ratio = $segment['deleted'] / $segment['documents'];

            if ($ratio > $this->deletedRatioThreshold
                && $segment['documents'] <= $this->maxAutoMergeDocuments
            ) {
                return [$position];
            }
        }

        return [];
    }

    /**
     * Everything, for optimize(): one segment, no caps.
     *
     * Returns nothing when there is nothing to gain — a single segment with no
     * deletions is already optimal, and rewriting it would be pure cost.
     *
     * @param array<int, array{documents: int, deleted: int}> $segments
     * @return int[]
     */
    public function selectAll(array $segments): array
    {
        if (count($segments) > 1) {
            return array_keys($segments);
        }

        foreach ($segments as $position => $segment) {
            if ($segment['deleted'] > 0) {
                return [$position];
            }
        }

        return [];
    }

    /**
     * Which tier a segment of this many live documents belongs to.
     */
    public function tierOf(int $documents): int
    {
        if ($documents < $this->tierFactor) {
            return 0;
        }

        return (int) floor(log($documents) / log($this->tierFactor));
    }
}
