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
 *
 * ── Where the document cap comes from ───────────────────────────────────────
 *
 * A merge's memory was the thing that decided this, so the cap was measured
 * rather than picked. Once the merge stopped accumulating its input — see
 * SegmentMerger — what it costs turned out to be **almost exactly linear in
 * documents**, and barely anything else:
 *
 *     documents          5 k → 80 k       942 → 1 065 B/doc
 *     searchable fields  1 → 6            871 → 895 B/doc      (nothing)
 *     key length         4 B → 40 B       923 → 972 B/doc      (nothing)
 *     filterable fields  0 → 16           896 → 2 731 B/doc    (~115 B each)
 *
 * The last line is the one that matters and the reason the cap cannot simply
 * be a number: a column costs one array slot per document, so an index with
 * sixteen filterable fields costs three times what one with none costs, for
 * the same documents. Both figures are free to read — the count is in the
 * manifest, the fields are in the frozen schema.
 *
 * What is emphatically *not* a predictor is the size of the segments. A 19 MB
 * index of 5 000 documents with a large stored payload merged in 4 MB; a 1.3 MB
 * index of 20 000 short ones took 18 MB. The correlation runs backwards,
 * because the docstore — which is most of a segment file — is streamed and
 * costs nothing to carry. Bytes on disk were the obvious unit and would have
 * been wrong by a factor of twenty.
 *
 * Checked against the catalogue in `benchmark/`, which the coefficients were
 * not fitted on: 45 000 real products with seven filterable fields, predicted
 * 73.2 MB and measured 73.0.
 *
 * ── How much memory a shared host actually gives ────────────────────────────
 *
 * This is the one number the whole design leans on, and it was an assumption
 * until it was checked. **It is not a constant, and it is not 128 MB.** The
 * OVH cluster this library is developed against gives a request **512 MB**, on
 * the CLI and over HTTP alike — measured, not read off a documentation page.
 * Cheaper plans and older offers are where 128 MB comes from, and it is still
 * the figure to design against, but assuming it everywhere would be designing
 * for a host nobody in this project actually has.
 *
 * Which is the argument for not hard-coding either: the budget is read from
 * `memory_limit` at the moment it matters. A host with 128 MB and an
 * application living in 40 MB of it gets a smaller merge than one with 512 MB,
 * without anybody configuring anything, and a merge that will not fit does not
 * happen — the segments stay, search gets a little slower, and `optimize()`
 * from a cron fixes it. That is the right way round: piling up segments is a
 * slow index, and running out of memory is a failed request.
 *
 * On 512 MB the derived cap lands near 130 000 documents, so the ceiling is
 * what binds and this class does nothing. That is the correct outcome and
 * worth saying plainly: the mechanism earns its place on the hosts that need
 * it, and stays out of the way on the ones that do not.
 */
final class MergePolicy
{
    /**
     * What one document costs a merge, before its columns.
     *
     * Its key, its field lengths, its share of the block dictionary and of the
     * field masks. Rounded up from the 871–1 065 B/doc measured across every
     * shape above that has no columns.
     */
    private const BYTES_PER_DOCUMENT = 1_000;

    /**
     * What one filterable field adds, per document.
     *
     * A column value is carried across a merge as a slot in a nested array, so
     * this is one PHP array entry: measured at ~115 B, taken at 128 to round
     * the estimate the safe way.
     */
    private const BYTES_PER_COLUMN = 128;

    public function __construct(
        /** Segments in one tier before it is collapsed. */
        public readonly int $segmentsPerTier = 8,

        /** Each tier holds segments this many times larger than the last. */
        public readonly int $tierFactor = 8,

        /**
         * The ceiling on an automatic merge, whatever the memory says.
         *
         * A time guard rather than a memory one: the time budget is checked
         * between merges and not during one, so without this a single merge of
         * a very large index could hold a request for minutes on a machine
         * with memory to spare.
         */
        public readonly int $maxAutoMergeDocuments = 50_000,

        /** How long an automatic merge may spend, in milliseconds. */
        public readonly float $timeBudgetMs = 250.0,

        /**
         * A segment more than this deleted is worth rewriting on its own, to
         * give the space back and to stop searches reading documents they will
         * then discard.
         */
        public readonly float $deletedRatioThreshold = 0.5,

        /**
         * Bytes an automatic merge may plan to use, if you would rather say.
         *
         * Left null it is worked out from what the process has left, which is
         * what a library should do — nobody deploying to a shared host knows
         * this number, and the host is where it matters. Set it to make the
         * decision reproducible, which is what the tests do: reading
         * `memory_get_usage()` is the one thing in this class that depends on
         * anything but its arguments.
         */
        public readonly ?int $memoryBudgetBytes = null,

        /**
         * How much of the free memory an automatic merge may plan to use.
         *
         * Half, because the merge is not the only thing that will allocate
         * before the request ends — and because the estimate above is an
         * estimate. Set to 0.0 to drop the memory cap and go back to counting
         * documents alone.
         */
        public readonly float $memoryFraction = 0.5,
    ) {
    }

    /**
     * How many documents an automatic merge may take on, here and now.
     *
     * @param int $filterableFields how many fields of the index's schema have a
     *        column, which is what makes one document dearer than another
     */
    public function documentCap(int $filterableFields = 0): int
    {
        $budget = $this->memoryBudgetBytes ?? $this->freeShare();

        if ($budget === null) {
            // No memory_limit to reason from — the CLI's usual state. The
            // ceiling is the only bound left, and it is the one that was there
            // before any of this.
            return $this->maxAutoMergeDocuments;
        }

        $perDocument = self::BYTES_PER_DOCUMENT + self::BYTES_PER_COLUMN * max(0, $filterableFields);

        return min($this->maxAutoMergeDocuments, intdiv(max(0, $budget), $perDocument));
    }

    /**
     * Which segments to merge next, as positions into the list given.
     *
     * Returns fewer than two positions when there is nothing worth doing.
     *
     * @param array<int, array{documents: int, deleted: int}> $segments
     * @param int $filterableFields the index's column count, which decides how
     *        many documents fit in the memory budget
     * @return int[]
     */
    public function select(array $segments, int $filterableFields = 0): array
    {
        if ($segments === []) {
            return [];
        }

        $cap = $this->documentCap($filterableFields);

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

                if ($chosen !== [] && $total + $live > $cap) {
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

            if ($ratio > $this->deletedRatioThreshold && $segment['documents'] <= $cap) {
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
     * The share of the process's remaining memory a merge may plan to use,
     * or null when there is no limit to take a share of.
     */
    /**
     * Bytes an index-building operation may plan to use, or null when there is
     * no `memory_limit` to take a share of.
     *
     * A merge turns this into a document count, because a carried document
     * costs a predictable amount. An import cannot: what a document costs to
     * *analyse* depends on how much text it holds, and a catalogue of one-line
     * titles and one of thousand-word descriptions differ by two orders of
     * magnitude. So the import side spends this budget by watching, not by
     * predicting — see `IndexDirectory::putMany()`.
     *
     * Public for that caller. It is the same question either way: how much of
     * what is left may this operation plan on.
     */
    public function budgetBytes(): ?int
    {
        return $this->memoryBudgetBytes ?? $this->freeShare();
    }

    private function freeShare(): ?int
    {
        if ($this->memoryFraction <= 0.0) {
            return null;
        }

        $limit = self::memoryLimit();

        if ($limit === null) {
            return null;
        }

        // The real allocation rather than the accounted one, because that is
        // what memory_limit is enforced against — and because a merge's cost
        // is measured the same way.
        $free = $limit - memory_get_usage(true);

        return (int) (max(0, $free) * $this->memoryFraction);
    }

    /**
     * `memory_limit` in bytes, or null when it is unlimited or unreadable.
     *
     * The shorthand suffixes are PHP's own, and case-insensitive: `512M` is
     * what the php.ini this was measured against writes.
     */
    private static function memoryLimit(): ?int
    {
        // Cast rather than compared to false: ini_get() can say false for a
        // directive that does not exist, which this one always does, and the
        // cast turns that impossible case into the empty string the regex
        // refuses anyway.
        $setting = trim((string) ini_get('memory_limit'));

        if (!preg_match('/^(-?\d+)\s*([kmg]?)$/i', $setting, $matched)) {
            return null;
        }

        $value = (int) $matched[1];

        if ($value < 0) {
            return null;   // -1: no limit, so no share of it either
        }

        return match (strtolower($matched[2])) {
            'k'     => $value * 1024,
            'm'     => $value * 1024 * 1024,
            'g'     => $value * 1024 * 1024 * 1024,
            default => $value,
        };
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
