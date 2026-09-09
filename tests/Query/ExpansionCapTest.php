<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Query;

use Ols\PhpFts\Analysis\Utf8;
use Ols\PhpFts\Query\QueryPlan;
use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What survives {@see QueryPlan::MAX_EXPANSIONS}, when a query reaches more
 * readings than the plan will carry.
 *
 * ── Why this file exists, and why the suite needed it ───────────────────────
 *
 * Every other test of the expansion machinery runs on a handful of documents,
 * where the cap cannot bite: there are fewer candidates than there are places
 * for them, so whatever the plan does with the surplus is unobservable. The
 * one test of a single-character CJK query stood on four documents.
 *
 * That is exactly the shape of validation that cannot see a capping bug. A
 * single character of a continuous script reaches *every bigram of the
 * vocabulary holding it* — hundreds, on a real Chinese catalogue — and each
 * one is weighed the same, because a bigram is half about the character asked
 * for whichever bigram it is. With every weight equal, the sort's final
 * settle decided the answer alone, and that settle is `strcmp`: the fifty
 * bigrams kept were the fifty lowest in UTF-8 byte order, which is to say the
 * fifty whose *first* character had the lowest code point. Recall became a
 * function of the code chart.
 *
 * So the corpus below is built to be *just* large enough to observe the cap,
 * and shaped so that byte order and usefulness disagree: the bigram that
 * matters most sorts last.
 */
class ExpansionCapTest extends TestCase
{
    /** The character every document shares, and the one that gets searched. */
    private const SHARED = '茶';

    /**
     * Filler bigrams, enough to overrun the cap on their own.
     *
     * One more than MAX_EXPANSIONS, so that even before the target is counted
     * there is no room left for it.
     */
    private const FILLERS = QueryPlan::MAX_EXPANSIONS + 1;

    /**
     * The first character of each filler bigram, ascending from U+4E00.
     *
     * The opening run of the CJK block — 一, 丁, 丂 … — every code point of
     * which is an assigned ideograph, so each really is indexed as Han rather
     * than swallowed as a separator.
     */
    private const FILLER_BASE = 0x4E00;

    /**
     * The first character of the bigram that must be found: U+9F8D 龍.
     *
     * Near the top of the block, so `龍茶` sorts after every filler — UTF-8
     * preserves code point order, and the first character is what a byte
     * comparison of two bigrams decides on. Under the old rule it was
     * therefore the *first* candidate thrown away.
     */
    private const TARGET = "\u{9F8D}";

    /** How many documents carry the target bigram. */
    private const TARGET_DOCUMENTS = 5;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_cap_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    #[Test]
    public function the_corpus_really_does_overrun_the_cap(): void
    {
        // Asserted rather than assumed: if a later change made the vocabulary
        // smaller than the cap, every other test in this file would pass for
        // the wrong reason and say nothing at all.
        $this->assertGreaterThan(
            QueryPlan::MAX_EXPANSIONS,
            self::FILLERS + 1,
            'the fixture must offer more readings than the plan will carry'
        );
    }

    #[Test]
    public function a_single_character_query_keeps_the_readings_that_reach_documents(): void
    {
        $engine = $this->catalogue();

        $result = $engine->search(self::SHARED, limit: 100);
        $found  = array_map(static fn($hit): string => $hit->id, $result->hits);

        // The whole point: `龍茶` sorts after all fifty-one fillers, so byte
        // order excludes it, and it is the reading that carries five documents
        // where each filler carries one.
        for ($i = 0; $i < self::TARGET_DOCUMENTS; $i++) {
            $this->assertContains(
                "target-$i",
                $found,
                'a bigram in five documents must outrank fifty-one bigrams in one, '
                . 'whatever their first code point'
            );
        }
    }

    #[Test]
    public function the_query_still_reaches_the_cap_and_no_further(): void
    {
        $engine = $this->catalogue();

        $result = $engine->search(self::SHARED, limit: 200);

        // Fifty readings, and the corpus offers fifty-two. So the cap is doing
        // its job — the point of the fix is *which* fifty, not how many.
        $this->assertLessThan(
            self::FILLERS + self::TARGET_DOCUMENTS,
            $result->total,
            'the cap must still bound the expansion'
        );

        $this->assertGreaterThanOrEqual(
            self::TARGET_DOCUMENTS,
            $result->total,
            'and must still admit the readings worth keeping'
        );
    }

    #[Test]
    public function an_exact_bigram_is_unaffected_by_the_cap(): void
    {
        $engine = $this->catalogue();

        // Two characters are not expanded at all: they *are* the bigram the
        // documents produced, so the exact lookup answers and nothing is
        // capped. Kept here because it is the property the fix must not have
        // disturbed while changing the order of everything around it.
        $result = $engine->search(self::TARGET . self::SHARED, limit: 100);

        $this->assertSame(self::TARGET_DOCUMENTS, $result->total);
    }

    // -------------------------------------------------------------------------

    /**
     * Fifty-one bigrams in one document each, and one in five.
     */
    private function catalogue(): SearchEngine
    {
        $engine    = SearchEngine::open($this->dir);
        $documents = [];

        for ($i = 0; $i < self::FILLERS; $i++) {
            $documents["filler-$i"] = [
                'title' => Utf8::chr(self::FILLER_BASE + $i) . self::SHARED,
            ];
        }

        for ($i = 0; $i < self::TARGET_DOCUMENTS; $i++) {
            $documents["target-$i"] = ['title' => self::TARGET . self::SHARED];
        }

        $engine->putMany($documents);

        return $engine;
    }
}
