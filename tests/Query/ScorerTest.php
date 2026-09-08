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

    // =========================================================================
    // BM25F
    // =========================================================================

    #[Test]
    public function a_boosted_field_contributes_more(): void
    {
        $scorer = new Scorer();

        $inTitle       = $scorer->fieldedFrequency([0 => 1], [0 => 3.0, 1 => 1.0], [0 => 10, 1 => 100], [0 => 10.0, 1 => 100.0]);
        $inDescription = $scorer->fieldedFrequency([1 => 1], [0 => 3.0, 1 => 1.0], [0 => 10, 1 => 100], [0 => 10.0, 1 => 100.0]);

        $this->assertGreaterThan($inDescription, $inTitle);
    }

    #[Test]
    public function a_term_in_two_fields_beats_the_same_term_in_one(): void
    {
        $scorer = new Scorer();
        $boosts = [0 => 1.0, 1 => 1.0];

        $both = $scorer->fieldedFrequency([0 => 1, 1 => 1], $boosts, [0 => 10, 1 => 10], [0 => 10.0, 1 => 10.0]);
        $one  = $scorer->fieldedFrequency([0 => 1], $boosts, [0 => 10, 1 => 10], [0 => 10.0, 1 => 10.0]);

        $this->assertGreaterThan($one, $both);
    }

    #[Test]
    public function combining_fields_saturates_rather_than_adding_up(): void
    {
        // The whole point of BM25F, and precisely what 1.x got wrong by scoring
        // each field separately and averaging. Two fields matching is worth more
        // than one, but not twice as much.
        $scorer = new Scorer();
        $boosts = [0 => 1.0, 1 => 1.0];
        $idf    = 2.0;

        $one  = $scorer->fieldedScore($idf, $scorer->fieldedFrequency([0 => 1], $boosts, [0 => 10, 1 => 10], [0 => 10.0, 1 => 10.0]));
        $both = $scorer->fieldedScore($idf, $scorer->fieldedFrequency([0 => 1, 1 => 1], $boosts, [0 => 10, 1 => 10], [0 => 10.0, 1 => 10.0]));

        $this->assertGreaterThan($one, $both);
        $this->assertLessThan($one * 2.0, $both, 'twice the fields must not be twice the score');
    }

    #[Test]
    public function each_field_is_normalised_against_its_own_average(): void
    {
        // A title of five terms is short; a description of five terms is very
        // short. Normalising both against one figure would make every title look
        // brief and every description look enormous.
        $scorer = new Scorer();
        $boosts = [0 => 1.0, 1 => 1.0];

        // Same field length, wildly different averages.
        $shortField = $scorer->fieldedFrequency([0 => 1], $boosts, [0 => 5, 1 => 5], [0 => 5.0, 1 => 500.0]);
        $longField  = $scorer->fieldedFrequency([1 => 1], $boosts, [0 => 5, 1 => 5], [0 => 5.0, 1 => 500.0]);

        $this->assertGreaterThan(
            $shortField,
            $longField,
            'five terms in a field that usually holds five hundred is unusually precise'
        );
    }

    #[Test]
    public function a_field_the_document_does_not_hold_contributes_nothing(): void
    {
        $scorer = new Scorer();

        $this->assertSame(
            0.0,
            $scorer->fieldedFrequency([], [0 => 3.0, 1 => 1.0], [0 => 10, 1 => 10], [0 => 10.0, 1 => 10.0])
        );

        $this->assertSame(0.0, $scorer->fieldedScore(2.0, 0.0));
    }

    #[Test]
    public function one_neutral_field_scores_the_same_either_way(): void
    {
        // Turning boosts on must not silently rescale every result.
        $scorer = new Scorer();
        $idf    = 1.7;

        $fielded = $scorer->fieldedScore(
            $idf,
            $scorer->fieldedFrequency([0 => 1], [0 => 1.0], [0 => 100], [0 => 100.0])
        );

        $this->assertEqualsWithDelta($scorer->score($idf, 100, 100.0), $fielded, 1.0E-9);
    }

    #[Test]
    public function a_fielded_score_is_never_negative_or_nan(): void
    {
        $scorer = new Scorer();

        $shapes = [[], [0 => 1], [0 => 1, 1 => 1], [0 => 255, 1 => 0], [0 => 0.5]];

        foreach ([0.0, 0.5, 20.0] as $idf) {
            foreach ($shapes as $frequencies) {
                foreach ([[0 => 0.0, 1 => 0.0], [0 => 3.0, 1 => 1.0]] as $boosts) {
                    $combined = $scorer->fieldedFrequency($frequencies, $boosts, [0 => 0, 1 => 0], [0 => 0.0, 1 => 0.0]);
                    $score    = $scorer->fieldedScore($idf, $combined);

                    $this->assertGreaterThanOrEqual(0.0, $score);
                    $this->assertFalse(is_nan($score));
                }
            }
        }
    }

    #[Test]
    public function saying_a_word_more_often_scores_higher_but_saturates(): void
    {
        // What the index could not express while a document's terms were its
        // deduplicated trigrams: every frequency was 1, so `k1` scaled every
        // score identically and four products could come back scoring exactly
        // 10.29 with nothing to separate them.
        $scorer = new Scorer();
        $boosts = [0 => 1.0];
        $idf    = 2.0;

        $once   = $scorer->fieldedScore($idf, $scorer->fieldedFrequency([0 => 1], $boosts, [0 => 10], [0 => 10.0]));
        $thrice = $scorer->fieldedScore($idf, $scorer->fieldedFrequency([0 => 3], $boosts, [0 => 10], [0 => 10.0]));
        $often  = $scorer->fieldedScore($idf, $scorer->fieldedFrequency([0 => 40], $boosts, [0 => 10], [0 => 10.0]));

        $this->assertGreaterThan($once, $thrice, 'three mentions beat one');
        $this->assertGreaterThan($thrice, $often);

        // But saturating, not proportional: tripling the count must not triple
        // the score, or one keyword-stuffed description would own the results.
        $this->assertLessThan(3 * $once, $thrice);

        // And the byte the index stores is enough, because the formula stops
        // caring long before 255 does.
        $capped = $scorer->fieldedScore($idf, $scorer->fieldedFrequency([0 => 255], $boosts, [0 => 10], [0 => 10.0]));
        $beyond = $scorer->fieldedScore($idf, $scorer->fieldedFrequency([0 => 4000], $boosts, [0 => 10], [0 => 10.0]));

        $this->assertLessThan(0.005, ($beyond - $capped) / $capped, 'clamping at 255 costs under half a percent');
    }

    #[Test]
    public function a_closer_variant_counts_for_more_per_occurrence(): void
    {
        // Frequencies arrive weighted by how close the matching word is to what
        // was typed, so `cuisne` occurring once in a product actually named
        // that outweighs `cuisine` occurring once as a correction.
        $scorer = new Scorer();
        $boosts = [0 => 1.0];

        $exact       = $scorer->fieldedFrequency([0 => 1.0], $boosts, [0 => 10], [0 => 10.0]);
        $oneEditAway = $scorer->fieldedFrequency([0 => 1.0 - 1 / 6], $boosts, [0 => 10], [0 => 10.0]);

        $this->assertGreaterThan($oneEditAway, $exact);
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
