<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

/**
 * How relevant a document is to a query: BM25.
 *
 *     score(d, q) = Σ  IDF(t) · (k1 + 1) / (1 + k1 · norm(d))
 *                  t∈q∩d
 *
 *     IDF(t)  = ln(1 + (N − df + 0.5) / (df + 0.5))
 *     norm(d) = 1 − b + b · len(d) / avglen
 *
 * ── The two things it adds over counting matched terms ──────────────────────
 *
 * **IDF.** A document matching a rare trigram has said something; one matching
 * `es#` has not. Counting terms treats those alike, which is why the previous
 * ranking put whichever document happened to be longest at the top: a long
 * description produces more distinct trigrams, so it collides with more of the
 * query by accident.
 *
 * **Length normalisation.** That accident is what `b` corrects. A document is
 * penalised in proportion to how much longer than average it is, so matching
 * three query terms out of six words counts for more than matching four out of
 * six hundred.
 *
 * ── A shortcut this engine is entitled to ──────────────────────────────────
 *
 * The analyzer deduplicates, so a term appears at most once per document and
 * the term frequency is always 1. BM25 then collapses to
 *
 *     score(d) = normFactor(d) · Σ IDF(t)
 *
 * with `normFactor` depending only on the document's length. So the IDF of each
 * query term is computed once, before any document is looked at, and scoring a
 * document is an addition per matched term plus one multiplication at the end.
 * That matters: the audit of 1.x found it calling `log()` and a dictionary
 * lookup *inside* the per-document loop.
 *
 * The saturation parameter `k1` therefore has no effect on ranking here — with
 * a constant term frequency it scales every score identically. It is kept
 * because it is part of the formula and will matter as soon as BM25F combines
 * several fields, and because 1.x claimed a saturation it never had.
 *
 * ── Why the score is not scaled to 0-100 ───────────────────────────────────
 *
 * 1.x divided by the theoretical maximum for a *single* term and clamped at
 * 100, so any query of more than a few trigrams saturated and a large part of
 * the result set came back as exactly 100.00 — destroying the ordering at the
 * top, which is the only place it matters. Raw BM25 has no upper bound and is
 * not meant to; it is a ranking signal, not a percentage.
 */
final class Scorer
{
    public function __construct(
        /** Term-frequency saturation. Standard Lucene default. */
        public readonly float $k1 = 1.2,

        /** How much document length matters. 0 disables it, 1 applies it fully. */
        public readonly float $b = 0.75,
    ) {
    }

    /**
     * How much one term contributes when a document holds it.
     *
     * Computed once per query term, before any document is scored.
     *
     * @param int $documentFrequency documents in the whole index holding the term
     * @param int $documentCount     documents in the whole index
     */
    public function idf(int $documentFrequency, int $documentCount): float
    {
        $documentCount     = max(1, $documentCount);
        $documentFrequency = max(0, min($documentFrequency, $documentCount));

        // The Lucene form: the +1 keeps it positive even for a term present in
        // every document, where the textbook formula goes negative and starts
        // rewarding documents for *not* matching.
        return log(1.0 + ($documentCount - $documentFrequency + 0.5) / ($documentFrequency + 0.5));
    }

    /**
     * The length penalty for one document, as a factor applied to its IDF sum.
     *
     * @param int   $length        terms the document produced
     * @param float $averageLength mean across the index
     */
    public function lengthFactor(int $length, float $averageLength): float
    {
        if ($averageLength <= 0.0) {
            return $this->k1 + 1.0;
        }

        $norm = 1.0 - $this->b + $this->b * ($length / $averageLength);

        return ($this->k1 + 1.0) / (1.0 + $this->k1 * $norm);
    }

    /**
     * @param float $idfSum        summed IDF of the query terms this document holds
     * @param int   $length        terms the document produced
     * @param float $averageLength mean across the index
     */
    public function score(float $idfSum, int $length, float $averageLength): float
    {
        return $idfSum * $this->lengthFactor($length, $averageLength);
    }
}
