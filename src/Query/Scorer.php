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
 * The IDF of each query term is computed once, before any document is looked
 * at, so scoring a document is an addition per matched term and no logarithms.
 * That matters: the audit of 1.x found it calling `log()` and a dictionary
 * lookup *inside* the per-document loop.
 *
 * `k1` is load-bearing again. It was not, for as long as a document's terms
 * were its trigrams: the analyzer deduplicated them, term frequency was always
 * 1, and a saturation parameter applied to a constant scales every score
 * identically. Words are the terms now, a document can say one three times,
 * and saturating that is the difference between "mentions it" and "is about
 * it". See fieldedFrequency().
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

    /**
     * BM25F: one term's contribution when fields carry different weights.
     *
     *     tf~(t,d) = Σ  boost_f · tf(t,d,f) / (1 − b + b · len_f(d) / avglen_f)
     *               f
     *
     *     score(t,d) = IDF(t) · tf~ · (k1 + 1) / (k1 + tf~)
     *
     * ── Why the saturation comes last ──────────────────────────────────────
     *
     * That ordering is the whole difference between BM25F and scoring each
     * field separately then averaging, and it is exactly what 1.x got wrong.
     *
     * Its `boosts` branch scored every field on its own and divided by the sum
     * of all the boosts — including fields that matched nothing. So adding an
     * unrelated text field to your documents lowered the score of every result,
     * which is not a weighting, it is a dilution. It also skipped array fields
     * entirely as soon as any boost was passed, so `tags` silently stopped
     * contributing.
     *
     * Here the weighted frequencies are added up *first* and saturated once, so
     * a term in the title and in the description contributes more than either
     * alone, but not proportionally more — which is what saturation is for.
     *
     * ── Term frequency used to be missing, and said so ─────────────────────
     *
     * While a document's terms were its trigrams, the analyzer deduplicated
     * them and `tf` was always 1 — so this method took a *bitmask* and the bit
     * was the frequency. That was honest for what the index held and it cost
     * real ranking signal once words became the terms: a search for `opinel`
     * returned four products scoring 10.29 each, indistinguishable, while one
     * of them said `opinel` twice in its own name. Frequencies arrive per
     * field now, and `k1` finally does something.
     *
     * They arrive as floats rather than counts because a query slot blends its
     * variants: a document holding `couteau` twice and `couteaux` once carries
     * both towards the same typed word, each weighted by how close it is to
     * what was actually typed. See QuerySlot.
     *
     * @param float[] $frequencies bit => how often the term occurs in that
     *        field, weighted. A field absent from the map, or zero, does not
     *        hold the term and contributes nothing.
     * @param float[] $boosts   bit => weight
     * @param int[]   $lengths  bit => terms this document has in that field
     * @param float[] $averages bit => mean across the index
     * @param float[] $bByField bit => how much that field's length matters.
     *        A field whose values are all about the same length — a brand, a
     *        SKU — gains nothing from normalisation and can set 0, which stops
     *        it penalising the handful of brands that happen to be two words.
     */
    public function fieldedFrequency(
        array $frequencies,
        array $boosts,
        array $lengths,
        array $averages,
        array $bByField = [],
    ): float {
        $combined = 0.0;

        foreach ($frequencies as $bit => $frequency) {
            if ($frequency <= 0.0) {
                continue;
            }

            $average = $averages[$bit] ?? 0.0;
            $b       = $bByField[$bit] ?? $this->b;

            $normalisation = $average > 0.0
                ? 1.0 - $b + $b * (($lengths[$bit] ?? 0) / $average)
                : 1.0;

            $combined += ($boosts[$bit] ?? 1.0) * $frequency / $normalisation;
        }

        return $combined;
    }

    /**
     * Saturates a combined frequency and weights it by rarity.
     *
     * With a single field of boost 1 and average length, this gives exactly the
     * same number as score() — so turning boosts on does not silently rescale
     * every result.
     */
    public function fieldedScore(float $idf, float $combinedFrequency): float
    {
        if ($combinedFrequency <= 0.0) {
            return 0.0;
        }

        return $idf * $combinedFrequency * ($this->k1 + 1.0) / ($this->k1 + $combinedFrequency);
    }
}
