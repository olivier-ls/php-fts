<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

/**
 * A query, resolved against the index once, before any segment is asked to
 * match it.
 *
 * ── Why this is built once for the whole index ─────────────────────────────
 *
 * Because segments hold different vocabularies. `steel` may be in the segment
 * written last week and not in the one written today, so a segment expanding
 * `stel` on its own would answer with a different set of candidate words than
 * its neighbour — and the same document would then match, or score, according
 * to which segment it happened to land in. A merge would change the result.
 *
 * That is the same trap CollectionStatistics exists to avoid for IDF, and the
 * one the previous planner had already fallen into once: its threshold was
 * computed from what a segment happened to contain, and a test comparing a
 * search before and after a merge caught it. So expansion is gathered from
 * every segment, unioned, and frozen into this plan, which all of them then
 * see identically.
 *
 * ── Why the threshold is an amount of information ──────────────────────────
 *
 * It used to be a count: 60% of the query's terms had to be present. With
 * words as terms that reads badly, because words are not worth the same. On
 * `couteu de cuisine`, `de` sits in 38 555 of 45 000 documents and says
 * nothing; requiring "two of these three words" therefore accepted anything
 * holding `de` and one other, and the search reported 17 728 matches. The same
 * query thresholded on 60% of the *IDF* the words carry reports 599, because
 * `de` contributes 2.8% of the query's information and can no longer satisfy
 * anything on its own.
 *
 * A rare word is close to mandatory under this rule and a filler word is close
 * to free, which is what a person typing them meant.
 */
final class QueryPlan
{
    /**
     * The share of a query's information a document has to carry.
     *
     * Kept at the 0.6 the trigram planner used, so the change of terms is not
     * also a change of strictness. It means something defensible now that it
     * weighs words rather than counting fragments: a document must account for
     * most of what the query was about.
     */
    public const MINIMUM_SHOULD_MATCH = 0.6;

    /**
     * @param QuerySlot[] $slots    the query's words, in the order they were typed
     * @param float       $required IDF a document has to gather across slots
     * @param bool        $matchesEverything true when the query had no terms at
     *        all, which is what makes filters and facets usable on their own
     */
    private function __construct(
        public readonly array $slots,
        public readonly float $required,
        public readonly bool $matchesEverything,

        /** Every slot's IDF added up: what a document holding all of them gathers. */
        public readonly float $total = 0.0,

        /**
         * Whether the segment may drive the walk from a mandatory slot.
         *
         * Always true in use. It exists so that the differential test can ask
         * the same question both ways and insist on the same answer, hit for
         * hit and score for score — because "these two walks agree" is the
         * only thing that makes the fast one safe, and nothing else in the
         * suite would notice if it stopped being true.
         */
        public readonly bool $driven = true,
    ) {
    }

    /** The same plan, walked the slow and obvious way. @internal for tests */
    public function withoutDriver(): self
    {
        return new self($this->slots, $this->required, $this->matchesEverything, $this->total, false);
    }

    /** Every document, because nothing was asked for. */
    public static function matchAll(): self
    {
        return new self([], 0.0, true);
    }

    /**
     * IDF a document is allowed to miss and still match.
     *
     * A slot worth more than this cannot be skipped by any document that
     * matches — which makes it **mandatory**, and makes it a place to start
     * from rather than one more list to walk. See SegmentIndex::matchQuery().
     */
    public function slack(): float
    {
        return $this->total - $this->required;
    }

    /**
     * Builds the plan from what the index turned out to hold.
     *
     * @param string[] $typed      the query's terms, from the analyzer
     * @param array<int, array<string, float>> $candidates for each typed term,
     *        by position, the index terms it could have meant and how much each
     *        one counts for
     * @param float $minimumShouldMatch the share of the query's information a
     *        document has to carry
     */
    public static function build(
        array $typed,
        array $candidates,
        CollectionStatistics $statistics,
        Scorer $scorer,
        float $minimumShouldMatch = self::MINIMUM_SHOULD_MATCH,
    ): self {
        if ($typed === []) {
            return self::matchAll();
        }

        $slots    = [];
        $totalIdf = 0.0;

        foreach (array_values($typed) as $position => $word) {
            $found = $candidates[$position] ?? [];

            // A word nothing in the index resembles is dropped rather than
            // made impossible. Keeping it would put its IDF into the budget
            // with no way to ever gather it, so one unknown word — a stray
            // keystroke, a brand nobody stocks — would empty the whole result
            // instead of narrowing it. Dropped, it simply asks for nothing.
            if ($found === []) {
                continue;
            }

            $frequency = 0;
            $variants  = [];

            foreach ($found as $term => $weight) {
                // Cast because PHP will have turned a numeric term into an int
                // key on the way in — see QuerySlot::$candidates.
                $term      = (string) $term;
                $frequency = max($frequency, $statistics->documentFrequency($term));

                $variants[] = [$term, $weight];
            }

            $idf       = $scorer->idf(max(1, $frequency), $statistics->documentCount);
            $totalIdf += $idf;

            $slots[] = new QuerySlot($word, $variants, max(1, $frequency), $idf);
        }

        if ($slots === []) {
            return new self([], 0.0, false);
        }

        return new self($slots, $minimumShouldMatch * $totalIdf, false, $totalIdf);
    }

    /** True when the query asked for something the index cannot answer at all. */
    public function matchesNothing(): bool
    {
        return !$this->matchesEverything && $this->slots === [];
    }

    /**
     * Every term the plan will look up, for the highlighter.
     *
     * Highlighting has to mark what the *documents* say, not what was typed: a
     * search for `stel` finds documents containing `steel`, and marking `stel`
     * would mark nothing at all.
     *
     * @return string[]
     */
    public function terms(): array
    {
        $terms = [];

        foreach ($this->slots as $slot) {
            foreach ($slot->candidates as [$term]) {
                $terms[] = $term;
            }
        }

        return $terms;
    }
}
