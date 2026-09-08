<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Query;

use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Is the answer *right*, as opposed to merely consistent?
 *
 * Every other test of the query path asks an equivalence question — does the
 * driven walk agree with the undriven one (DrivenWalkTest), does a merged
 * segment agree with a fresh one (MergeEquivalenceTest). Both are worth having
 * and neither can see a ranking that is wrong in the same way twice.
 *
 * That gap is not theoretical. The suite was green, on 797 tests, while a
 * search for `pliant` on a 45 000-product catalogue returned
 * `Ranger Point Precision` first — a product containing no form of the word.
 * Nothing was inconsistent; the ranking was simply wrong, and only an external
 * property says so.
 *
 * ── Why a single text field ─────────────────────────────────────────────────
 *
 * BM25F sums a term's weighted frequency across fields before saturating it, so
 * a term in a short boosted title and a term in a long description contribute
 * differently on purpose. That is correct and it is not what these tests are
 * about. One field removes it from the measurement, so a failure here can only
 * mean the slot arithmetic is wrong.
 *
 * ── Why the fixture is padded ───────────────────────────────────────────────
 *
 * IDF has to mean something. With five documents every term is rare, the
 * saturation curve is flat where it matters, and a ranking bug hides. The
 * padding gives the vocabulary a shape: a few hundred documents that hold none
 * of the words under test, and a few dozen that hold each of them.
 */
class RelevanceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_relevance_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    /**
     * A catalogue where `pliant` and three words two edits away from it all
     * occur often enough to carry a realistic document frequency.
     *
     * `plan`, `plat` and `point` are each within the edit budget of `pliant`
     * (six characters, so two edits under `fuzziness: AUTO`) and all three are
     * genuine expansion candidates of it — measured on the reference catalogue,
     * where `point` alone sits in 1 324 documents.
     *
     * @param array<string, string> $extra id => text, added to the padding
     */
    private function catalogue(array $extra): SearchEngine
    {
        $engine = SearchEngine::open($this->dir, Schema::make()->text('text'));

        $documents = [];

        // Padding: holds none of the words under test.
        for ($n = 0; $n < 200; $n++) {
            $documents["pad-$n"] = ['text' => "article numero $n en stock"];
        }

        // Enough of each word for its document frequency to be meaningful.
        for ($n = 0; $n < 30; $n++) {
            $documents["has-pliant-$n"] = ['text' => "chaise pliant modele $n"];
            $documents["has-point-$n"]  = ['text' => "outil point modele $n"];
        }

        foreach ($extra as $id => $text) {
            $documents[$id] = ['text' => $text];
        }

        $engine->putMany($documents);

        return $engine;
    }

    /**
     * Every match's score, not a page of them.
     *
     * A page would be the wrong instrument here: the fixture deliberately holds
     * sixty documents carrying one form or another of the word under test, so
     * the document a test is asking about is regularly outside the top twenty
     * while still being ranked. Comparing two scores means having both.
     *
     * @return array<string, float> id => score, best first
     */
    private function scores(SearchEngine $engine, string $query): array
    {
        $scores = [];

        foreach ($engine->search($query, limit: 1_000) as $hit) {
            $scores[$hit->id] = $hit->score;
        }

        return $scores;
    }

    // =========================================================================
    // The property the engine was missing
    // =========================================================================

    /**
     * The one that was failing.
     *
     * `plan`, `plat` and `point` are each two edits from `pliant`, so each
     * counts for 1 − 2/6 = 0.667. Summed into one slot they make 2.0 against
     * the exact word's 1.0, and BM25's saturation turns that into a higher
     * score: three wrong words beat one right one.
     *
     * Measured before the fix, on this fixture's larger sibling: "Plan plat
     * point" scored 4.4597 and "Truc pliant" 4.2952.
     */
    #[Test]
    public function a_document_holding_the_typed_word_beats_one_holding_only_near_misses(): void
    {
        $engine = $this->catalogue([
            'exact'     => 'truc pliant',
            'near-miss' => 'plan plat point',
        ]);

        $scores = $this->scores($engine, 'pliant');

        $this->assertArrayHasKey('exact', $scores, 'the exact match did not come back at all');
        $this->assertArrayHasKey('near-miss', $scores);

        $this->assertGreaterThan(
            $scores['near-miss'],
            $scores['exact'],
            'a document holding none of the typed word outscored one that holds it'
        );
    }

    /**
     * The same thing said without relying on how many near misses it takes.
     *
     * However many variants of a query word a document holds, they cannot
     * together be worth more than the word itself — otherwise "close to it
     * three times" outranks "it", which is not what tolerance is for.
     */
    #[Test]
    public function stacking_near_misses_does_not_overtake_the_word_itself(): void
    {
        $engine = $this->catalogue([
            'exact' => 'pliant',
            'two'   => 'plan plat',
            'three' => 'plan plat point',
            'four'  => 'plan plat point plant',
        ]);

        $scores = $this->scores($engine, 'pliant');

        foreach (['two', 'three', 'four'] as $id) {
            $this->assertArrayHasKey($id, $scores);

            $this->assertLessThan(
                $scores['exact'],
                $scores[$id],
                "'$id' holds only near misses and outscored the exact word"
            );
        }
    }

    /**
     * A regression lock rather than a failing test: one near miss already loses
     * to one exact match, and must keep losing.
     *
     * This is the half of the weighting that works. `accepts()` returns 1.0 for
     * the word itself and less for anything the reader had to guess at, and
     * that difference is what stopped a product genuinely named "Cuisne" from
     * falling out of its own results.
     */
    #[Test]
    public function one_near_miss_loses_to_the_word_itself(): void
    {
        $engine = $this->catalogue([
            'exact'     => 'objet pliant special',
            'near-miss' => 'objet point special',
        ]);

        $scores = $this->scores($engine, 'pliant');

        $this->assertGreaterThan($scores['near-miss'], $scores['exact']);
    }

    /**
     * Repetition of the *typed* word must still count.
     *
     * The fix for the property above must not be "ignore frequency": saying a
     * word twice is a real signal, and it is the one `§ fieldfreq` was added to
     * carry. A search for `opinel` used to return four products scoring 10.29
     * each, indistinguishable, one of which named Opinel twice.
     */
    #[Test]
    public function saying_the_typed_word_twice_still_counts_for_more(): void
    {
        $engine = $this->catalogue([
            'once'  => 'couvercle pliant metal',
            'twice' => 'couvercle pliant pliant',
        ]);

        $scores = $this->scores($engine, 'pliant');

        $this->assertGreaterThan(
            $scores['once'],
            $scores['twice'],
            'term frequency stopped contributing'
        );
    }

    /**
     * A completion ranks below the finished word.
     *
     * Only the weaker half of the intended rule is asserted, and deliberately.
     * `TermExpansion` means a completion to be *stronger* evidence than a
     * substitution — appending is weaker evidence of error than mistyping — but
     * it cannot be today: `accepts()` tries the edit budget first and returns
     * as soon as it succeeds, so a completion that also happens to be within
     * the budget (`pliant` → `pliantes`, two characters appended, distance 2)
     * is charged the substitution weight of 0.667 instead of the completion
     * weight of 0.875. Whichever way that is settled, a completion losing to
     * the exact word holds, so that is what is locked here.
     */
    #[Test]
    public function a_completion_ranks_below_the_word_it_completes(): void
    {
        $engine = $this->catalogue([
            'exact'      => 'lampe pliant metal',
            'completion' => 'lampe pliantes metal',
        ]);

        $scores = $this->scores($engine, 'pliant');

        $this->assertGreaterThan($scores['completion'], $scores['exact']);
    }
}
