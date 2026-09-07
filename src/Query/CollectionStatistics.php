<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

/**
 * The numbers BM25 needs about the index as a whole, rather than about one
 * segment.
 *
 * ── Why this has to be gathered before anything is scored ───────────────────
 *
 * IDF asks how rare a term is *in the index*. If each segment answered from its
 * own dictionary, the same term would be rare in one segment and common in
 * another, and the same document would score differently depending on which
 * segment it happened to land in. Merging segments would then change the
 * ranking — exactly the class of bug that the "same results before and after a
 * merge" test caught in the query planner.
 *
 * So a search asks every segment for its document frequencies first, sums them,
 * and only then asks any of them to score. That costs nothing extra: the
 * dictionary lookups it needs are the ones the search was going to do anyway,
 * and the segment caches them.
 */
final class CollectionStatistics
{
    /**
     * @param int                 $documentCount       live documents across the index
     * @param float               $averageLength       mean terms per document
     * @param array<string, int>  $documentFrequencies term => documents holding it
     */
    public function __construct(
        public readonly int $documentCount,
        public readonly float $averageLength,
        public readonly array $documentFrequencies = [],

        /**
         * Mean terms per field, by mask bit — what BM25F normalises each field
         * against. A title averages a handful of terms and a description
         * hundreds, so normalising both against one figure would make every
         * title look short and every description look long.
         *
         * @var array<int, float>
         */
        public readonly array $fieldAverages = [],
    ) {
    }

    public function documentFrequency(string $term): int
    {
        return $this->documentFrequencies[$term] ?? 0;
    }

    public function fieldAverage(int $bit): float
    {
        return $this->fieldAverages[$bit] ?? 0.0;
    }

    /**
     * Adds one segment's contribution.
     *
     * Averages are recombined from totals rather than averaged, which is the
     * same care the numeric facet needs: the mean of two means is not the mean
     * unless both sides hold the same number of documents.
     *
     * @param array<string, int> $documentFrequencies
     * @param array<int, int>    $fieldLengthSums bit => summed lengths
     */
    public function plus(
        int $documents,
        int $termLengthSum,
        array $documentFrequencies,
        array $fieldLengthSums = [],
    ): self {
        $merged = $this->documentFrequencies;

        foreach ($documentFrequencies as $term => $frequency) {
            $merged[$term] = ($merged[$term] ?? 0) + $frequency;
        }

        $count = $this->documentCount + $documents;
        $total = (int) round($this->averageLength * $this->documentCount) + $termLengthSum;

        $fieldTotals = [];

        foreach ($this->fieldAverages as $bit => $average) {
            $fieldTotals[$bit] = (int) round($average * $this->documentCount);
        }

        foreach ($fieldLengthSums as $bit => $sum) {
            $fieldTotals[$bit] = ($fieldTotals[$bit] ?? 0) + $sum;
        }

        $fieldAverages = [];

        foreach ($fieldTotals as $bit => $fieldTotal) {
            $fieldAverages[$bit] = $count > 0 ? $fieldTotal / $count : 0.0;
        }

        return new self(
            $count,
            $count > 0 ? $total / $count : 0.0,
            $merged,
            $fieldAverages,
        );
    }

    public static function empty(): self
    {
        return new self(0, 0.0, [], []);
    }
}
