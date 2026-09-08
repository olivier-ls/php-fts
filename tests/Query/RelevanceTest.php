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
     * A catalogue where `pliant` and words one edit away from it all occur
     * often enough to carry a realistic document frequency.
     *
     * ── Why the near misses are invented words ─────────────────────────────
     *
     * They were real ones — `plan`, `plat`, `point`, each two edits from
     * `pliant` and each a measured expansion candidate of it on the reference
     * catalogue. Anchoring the edit budget on a shared prefix then stopped
     * admitting them, and a test whose subject has been removed from the
     * candidate set quietly stops testing anything: every assertion passed
     * against documents that no longer matched at all.
     *
     * So the vocabulary here is deliberately synthetic. `pliand`, `plianr` and
     * `plianx` are one substitution from `pliant` and share five characters
     * with it, so no plausible tightening of the admission rule removes them,
     * and none of them is the typed word. What is under test is the arithmetic
     * that combines variants, not which variants French happens to supply.
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
            $documents["has-near-$n"]   = ['text' => "outil pliand modele $n"];
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
     * Each near miss is one substitution from `pliant`, so each counts for
     * 1 − 1/6 = 0.833. Added into one slot they make 2.5 against the exact
     * word's 1.0, and BM25's saturation turns that into a higher score: three
     * wrong words beat one right one. Taking the best instead of the sum
     * leaves 0.833, which loses as it should.
     *
     * Measured before the fix, with the real French words this fixture used to
     * carry: "Plan plat point" scored 4.4597 and "Truc pliant" 4.2952.
     */
    #[Test]
    public function a_document_holding_the_typed_word_beats_one_holding_only_near_misses(): void
    {
        $engine = $this->catalogue([
            'exact'     => 'truc pliant',
            'near-miss' => 'pliand plianr plianx',
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
            'two'   => 'pliand plianr',
            'three' => 'pliand plianr plianx',
            'four'  => 'pliand plianr plianx pliane',
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
     * The same property, defeated by repetition instead of by stacking.
     *
     * Taking the best variant per field instead of the sum stops three
     * *different* near misses adding up. It does not stop **one** near miss
     * said twice, and on the reference catalogue that is the case that
     * actually bites: `Ranger Point Precision Ranger Point Precision` names
     * `point` twice, so the slot carried 0.667 × 2 = 1.334 against the exact
     * word's 1.0, and saturation turned the larger frequency into the higher
     * score. `LYMAN X-Block Gunsmith Bench Block` still does the same to
     * `black`, which a prefix rule cannot reach: `bl` is intact and one vowel
     * substituted in a five-letter word is genuinely ambiguous.
     *
     * The mechanism is worth naming because it is not the arithmetic of
     * combining variants at all: the variant weight modulates *term
     * frequency*, and term frequency is unbounded, so a lexical doubt ("this
     * is probably a different word") is traded against repetition ("this
     * document says it a lot"). Those are different axes and BM25 has one slot
     * for them. Two ways out — apply the weight to the slot's IDF instead of
     * to its frequency, or stop admitting `point` as a reading of `pliant` at
     * all — and the second is what a prefix constraint on the edit budget
     * does.
     */
    #[Test]
    public function a_repeated_near_miss_does_not_overtake_the_word_itself(): void
    {
        $engine = $this->catalogue([
            'exact'    => 'chaise pliant',
            'repeated' => 'chaise pliand pliand',
        ]);

        $scores = $this->scores($engine, 'pliant');

        $this->assertGreaterThan(
            // A near miss that does not come back at all is stronger than one
            // that comes back and loses, so absence satisfies this outright.
            $scores['repeated'] ?? -INF,
            $scores['exact'],
            'a near miss said twice outscored the word itself'
        );
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
            'near-miss' => 'objet pliand special',
        ]);

        $scores = $this->scores($engine, 'pliant');

        // Absent is stronger than losing: a candidate the admission rule
        // rejects does not match at all, and a tightening that removes this
        // near miss entirely should not read as a broken test. Either outcome
        // satisfies the property.
        $this->assertGreaterThan($scores['near-miss'] ?? -INF, $scores['exact']);
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
