<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\SearchEngine;
use Ols\PhpFts\Tests\Fixtures\MultilingualCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Can a search find text in this writing system, and only the right text.
 *
 * ── The gap this closes ────────────────────────────────────────────────────
 *
 * `AnalyzerTest` proves that Russian, Greek, Arabic, Hebrew, Thai, Korean and
 * Chinese produce terms — fourteen scripts, forty-three tests, all of them
 * about `analyze()`. **Nothing proved a search could find them.** The only
 * end-to-end search in a non-Latin script was one Japanese document asserting
 * one total, and one four-document Chinese fixture.
 *
 * So the analyzer was validated and the query path was not, outside Latin.
 * That is the asymmetry both audits kept pointing at, and it is where a defect
 * lives longest: a term correctly produced and never reachable looks exactly
 * like an empty catalogue.
 *
 * ── What is asserted, and what is deliberately not ─────────────────────────
 *
 * Recall and precision. Not relevance.
 *
 * `find` says a query must reach given documents; `reject` says it must not
 * reach others. Neither says anything about *order*, because order is a
 * judgement — whether the first result is the one a Japanese shopper wanted
 * needs a native reader and a real catalogue, and this suite cannot have
 * either. Ranking is asserted in `RelevanceTest`, in French, where the
 * judgement was actually made.
 *
 * The properties here rest on lexical facts instead: that `ножи` is a form of
 * `нож`, that `折りたたみ` is kanji followed by okurigana, that Thai writes no
 * space between words. Those a dictionary settles. See
 * {@see MultilingualCatalogue} for why the catalogue is the same twenty
 * products in every script rather than four sourced corpora.
 */
class MultilingualRecallTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_lang_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    /** @return array<string, array{0: string}> */
    public static function scripts(): array
    {
        $cases = [];

        foreach (MultilingualCatalogue::SCRIPTS as $script) {
            $cases[$script] = [$script];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('scripts')]
    public function every_document_is_reachable_by_its_own_name(string $script): void
    {
        // The weakest possible property, and the one that had never been
        // asserted for anything but Latin: a document must be found by the
        // words of its own name. If this fails the script is not searchable at
        // all, whatever the analyzer produced.
        $engine   = $this->catalogue($script);
        $products = MultilingualCatalogue::products($script);

        foreach ($products as $id => $product) {
            $result = $engine->search((string) $product['name'], limit: 100);
            $found  = array_map(static fn($hit): string => $hit->id, $result->hits);

            $this->assertContains(
                $id,
                $found,
                sprintf('[%s] %s is not found by its own name: %s', $script, $id, $product['name'])
            );
        }
    }

    #[Test]
    #[DataProvider('scripts')]
    public function nothing_matches_a_query_the_catalogue_has_no_word_for(string $script): void
    {
        // The mirror, and the one a continuous script can fail: bigrams over a
        // whole phrase make a short query match in places no word boundary
        // ever was.
        $engine = $this->catalogue($script);

        $this->assertSame(0, $engine->search('zzzzqqqq')->total, "[$script] Latin nonsense");
        $this->assertSame(0, $engine->search('щщщщыыыы')->total, "[$script] Cyrillic nonsense");
    }

    #[Test]
    #[DataProvider('scripts')]
    public function a_query_reaches_what_it_names_and_leaves_the_rest(string $script): void
    {
        $engine = $this->catalogue($script);

        foreach (MultilingualCatalogue::probes($script) as $probe) {
            $result = $engine->search($probe['query'], limit: 100);
            $found  = array_map(static fn($hit): string => $hit->id, $result->hits);

            foreach ($probe['find'] as $id) {
                $this->assertContains($id, $found, sprintf(
                    "[%s] '%s' must reach %s — %s",
                    $script,
                    $probe['query'],
                    $id,
                    $probe['why'],
                ));
            }

            foreach ($probe['reject'] as $id) {
                $this->assertNotContains($id, $found, sprintf(
                    "[%s] '%s' must NOT reach %s — %s",
                    $script,
                    $probe['query'],
                    $id,
                    $probe['why'],
                ));
            }
        }
    }

    #[Test]
    #[DataProvider('scripts')]
    public function filters_and_facets_work_whatever_the_script(string $script): void
    {
        // A keyword column holds the brand as bytes, so it has no business
        // caring — but nothing had checked that a non-Latin value survives
        // dictionary encoding, faceting and an exact filter, and "no business
        // caring" is how the field-mask bug got in.
        $engine   = $this->catalogue($script);
        $products = MultilingualCatalogue::products($script);

        $brands = [];

        foreach ($products as $product) {
            $brand          = (string) $product['brand'];
            $brands[$brand] = ($brands[$brand] ?? 0) + 1;
        }

        $facets = $engine->search('', facets: ['brand'])->facets['brand'];

        foreach ($brands as $brand => $count) {
            $this->assertSame($count, $facets[$brand] ?? null, "[$script] facet count for '$brand'");

            $filtered = $engine->search('', filters: [['field' => 'brand', 'op' => '=', 'value' => $brand]]);

            $this->assertSame($count, $filtered->total, "[$script] filtering on '$brand'");
        }
    }

    #[Test]
    #[DataProvider('scripts')]
    public function highlighting_marks_the_text_it_matched(string $script): void
    {
        // Spans are byte offsets into the plain text, and a multi-byte script
        // is where an off-by-one shows up as a cut character rather than as a
        // wrong answer. Asserting the marked text is still valid UTF-8 is the
        // cheap version of that check, and it covers every script at once.
        $engine = $this->catalogue($script);
        $probe  = MultilingualCatalogue::probes($script)[0];

        $result = $engine->search($probe['query'], limit: 5, highlight: ['name', 'description']);

        $this->assertNotSame([], $result->hits, "[$script] the first probe must match something");

        $marked = 0;

        foreach ($result->hits as $hit) {
            foreach ($hit->highlights as $field => $html) {
                $this->assertTrue(
                    mb_check_encoding((string) $html, 'UTF-8'),
                    "[$script] highlight for '$field' cut a character in half"
                );

                if (str_contains((string) $html, '<mark>')) {
                    $marked++;
                }
            }
        }

        $this->assertGreaterThan(0, $marked, "[$script] nothing was marked at all");
    }

    // -------------------------------------------------------------------------

    private function catalogue(string $script): SearchEngine
    {
        $engine = SearchEngine::open($this->dir);

        $engine->putMany(MultilingualCatalogue::products($script));

        return $engine;
    }
}
