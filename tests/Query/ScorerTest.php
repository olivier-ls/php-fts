<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Query;

use Ols\PhpFts\Query\Scorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BM25, in isolation.
 *
 * Tested as properties rather than against precomputed constants: what matters
 * is that a rarer term counts for more, that a longer document counts for less,
 * and that neither ever goes negative — not that a particular input gives a
 * particular decimal.
 */
class ScorerTest extends TestCase
{
    // =========================================================================
    // IDF
    // =========================================================================

    #[Test]
    public function a_rarer_term_is_worth_more(): void
    {
        $scorer = new Scorer();

        $rare   = $scorer->idf(1, 10_000);
        $common = $scorer->idf(5_000, 10_000);

        $this->assertGreaterThan($common, $rare);
    }

    #[Test]
    public function idf_falls_monotonically_as_a_term_spreads(): void
    {
        $scorer   = new Scorer();
        $previous = INF;

        foreach ([1, 10, 100, 1_000, 5_000, 9_000, 10_000] as $frequency) {
            $idf = $scorer->idf($frequency, 10_000);

            $this->assertLessThan($previous, $idf, "df = $frequency");
            $previous = $idf;
        }
    }

    #[Test]
    public function a_term_in_every_document_is_worth_little_but_never_negative(): void
    {
        // The textbook formula goes negative here, which would reward a document
        // for *not* matching. The Lucene form used instead cannot.
        $scorer = new Scorer();

        $idf = $scorer->idf(10_000, 10_000);

        $this->assertGreaterThan(0.0, $idf);
        $this->assertLessThan(0.2, $idf);
    }

    #[Test]
    public function idf_survives_nonsense_inputs(): void
    {
        // A document frequency larger than the collection, or an empty index,
        // can only come from corrupt metadata. It must not produce NAN and take
        // a whole result set with it.
        $scorer = new Scorer();

        $this->assertGreaterThan(0.0, $scorer->idf(50, 10));
        $this->assertGreaterThan(0.0, $scorer->idf(0, 0));
        $this->assertGreaterThan(0.0, $scorer->idf(-5, 100));
    }

    // =========================================================================
    // Length normalisation
    // =========================================================================

    #[Test]
    public function a_longer_document_is_penalised(): void
    {
        $scorer = new Scorer();

        $short = $scorer->lengthFactor(20, 100.0);
        $exact = $scorer->lengthFactor(100, 100.0);
        $long  = $scorer->lengthFactor(500, 100.0);

        $this->assertGreaterThan($exact, $short);
        $this->assertGreaterThan($long, $exact);
    }

    #[Test]
    public function b_zero_switches_length_off(): void
    {
        $scorer = new Scorer(b: 0.0);

        $this->assertSame(
            $scorer->lengthFactor(10, 100.0),
            $scorer->lengthFactor(10_000, 100.0),
            'with b = 0 the length of a document is irrelevant'
        );
    }

    #[Test]
    public function an_index_with_no_average_length_still_scores(): void
    {
        // Before the lengths section existed, older segments have no average.
        // Scoring must degrade to "no normalisation" rather than divide by zero.
        $scorer = new Scorer();

        $factor = $scorer->lengthFactor(50, 0.0);

        $this->assertGreaterThan(0.0, $factor);
        $this->assertFalse(is_nan($factor));
    }

    // =========================================================================
    // Putting them together
    // =========================================================================

    #[Test]
    public function a_short_document_outscores_a_long_one_holding_the_same_terms(): void
    {
        // The failure this fixes: counting matched terms made these equal, so
        // whichever happened to be first won — and a long description collides
        // with more of a query by accident.
        $scorer = new Scorer();
        $idfSum = 3.5;

        $this->assertGreaterThan(
            $scorer->score($idfSum, 400, 100.0),
            $scorer->score($idfSum, 25, 100.0)
        );
    }

    #[Test]
    public function matching_more_of_the_query_scores_higher(): void
    {
        $scorer = new Scorer();

        $this->assertGreaterThan(
            $scorer->score(1.2, 100, 100.0),
            $scorer->score(3.6, 100, 100.0)
        );
    }

    #[Test]
    public function a_score_is_never_negative(): void
    {
        $scorer = new Scorer();

        foreach ([0.0, 0.01, 5.0, 100.0] as $idfSum) {
            foreach ([0, 1, 1_000, 100_000] as $length) {
                $score = $scorer->score($idfSum, $length, 120.0);

                $this->assertGreaterThanOrEqual(0.0, $score);
                $this->assertFalse(is_nan($score));
            }
        }
    }
}
