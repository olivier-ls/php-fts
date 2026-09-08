<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

/**
 * One word of the query, and every word in the index it might have meant.
 *
 * A slot is the unit the threshold counts, which is the whole point of having
 * one. The engine used to hold a query as a flat list of trigrams with a
 * single threshold over the lot, so `couteau de cuisine inox` was twenty
 * anonymous fragments and a document could clear the bar on one word's
 * trigrams plus a handful of strays — matching without containing `cuisine`
 * at all. A slot keeps the words apart, so "this word is present, allowing a
 * typo" is a thing the planner can require.
 *
 * ── Why every variant shares one document frequency ────────────────────────
 *
 * Because rarity is not relevance. `stel` expands to `steel`, and `cuisne` to
 * `cuisine` and to `cuisne` itself; if each variant carried its own IDF, the
 * rarest one would dominate — and the rarest is usually the wrong reading. On
 * the catalogue, `stella` (two documents) outscored `steel` (1 318) and the
 * results for `stel` filled up with pastels and place names.
 *
 * So the slot carries one frequency, that of its most common variant: the most
 * likely thing the user meant. Lucene calls this a blended frequency. What
 * separates the variants afterwards is {@see $candidates}' weight, which
 * measures distance from what was actually typed rather than rarity.
 */
final class QuerySlot
{
    /**
     * @param string $typed the word as the analyzer produced it from the query
     *
     * @param array<int, array{0: string, 1: float}> $candidates term, and how
     *        much it counts for — one for the word itself, less for a
     *        correction or a completion. A list rather than a term-keyed map on
     *        purpose: PHP turns an array key that looks like an integer into
     *        one, and a term is now a word — so `21` or a model number would
     *        come back from a map as an int and fail every string signature
     *        downstream.
     *
     * @param int   $documentFrequency documents holding the most common variant,
     *        across the whole index and not one segment
     * @param float $idf   what this slot contributes when a document holds it
     */
    public function __construct(
        public readonly string $typed,
        public readonly array $candidates,
        public readonly int $documentFrequency,
        public readonly float $idf,
    ) {
    }
}
