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
    ) {
    }

    public function documentFrequency(string $term): int
    {
        return $this->documentFrequencies[$term] ?? 0;
    }

    /**
     * Adds one segment's contribution.
     *
     * @param array<string, int> $documentFrequencies
     */
    public function plus(int $documents, int $termLengthSum, array $documentFrequencies): self
    {
        $merged = $this->documentFrequencies;

        foreach ($documentFrequencies as $term => $frequency) {
            $merged[$term] = ($merged[$term] ?? 0) + $frequency;
        }

        $count = $this->documentCount + $documents;
        $total = (int) round($this->averageLength * $this->documentCount) + $termLengthSum;

        return new self(
            $count,
            $count > 0 ? $total / $count : 0.0,
            $merged,
        );
    }

    public static function empty(): self
    {
        return new self(0, 0.0, []);
    }
}
