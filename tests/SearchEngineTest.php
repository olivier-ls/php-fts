<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Exception\FieldTypeException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The public surface, exercised the way an application uses it.
 *
 * Deliberately no internals here: no segment, no manifest, no bitset. If
 * something in this file needs a class from `Index\` to be asserted, the
 * façade is not finished. What is asserted instead is what the README
 * promises — that a request can open, write, search and end, and that the
 * engine holds nothing and needs nothing released.
 */
class SearchEngineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_engine_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    private function engine(?Schema $schema = null): SearchEngine
    {
        return SearchEngine::open($this->dir, $schema);
    }

    private function catalogue(): SearchEngine
    {
        $engine = $this->engine();

        $engine->putMany([
            'sku-1' => ['title' => 'Brown leather shoe', 'brand' => 'Adidas', 'price' => 129.90, 'stock' => 42],
            'sku-2' => ['title' => 'Black leather boot', 'brand' => 'Adidas', 'price' => 189.00, 'stock' => 0],
            'sku-3' => ['title' => 'Canvas sneaker',     'brand' => 'Nike',   'price' => 79.00,  'stock' => 7],
            'jp-1'  => ['title' => '革靴 ブラウン',        'brand' => 'Nike',   'price' => 19800.0, 'stock' => 3],
        ]);

        return $engine;
    }

    // =========================================================================
    // Opening
    // =========================================================================

    #[Test]
    public function opening_creates_the_directory(): void
    {
        $engine = $this->engine();

        $this->assertDirectoryExists($this->dir);
        $this->assertSame(0, $engine->count());
        $this->assertSame($this->dir, $engine->directory());
    }

    #[Test]
    public function an_index_is_searchable_the_moment_it_is_opened(): void
    {
        // Empty, not broken: a first request that searches before anything was
        // ever written should get no results, not an exception.
        $result = $this->engine()->search('leather');

        $this->assertSame(0, $result->total);
        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function a_second_engine_sees_what_the_first_committed(): void
    {
        $this->engine()->put('sku-1', ['title' => 'Brown leather shoe']);

        // No shared state between the two objects, and nothing to flush: a
        // commit is on disk when put() returns, which is what makes the
        // "open, work, end" request model work at all.
        $this->assertSame(1, $this->engine()->count());
        $this->assertSame(1, $this->engine()->search('leather')->total);
    }

    #[Test]
    public function reopening_with_a_contradicting_schema_is_refused(): void
    {
        $this->engine(Schema::make()->text('title'))->put('sku-1', ['title' => 'Shoe']);

        $this->expectException(FtsException::class);
        $this->expectExceptionMessage('frozen at the first commit');

        $this->engine(Schema::make()->text('title')->number('price'));
    }

    // =========================================================================
    // Writing
    // =========================================================================

    #[Test]
    public function a_document_goes_in_under_an_id_of_your_own(): void
    {
        $engine = $this->engine();
        $engine->put('sku-4471', ['title' => 'Brown leather shoe', 'price' => 129.90]);

        $this->assertTrue($engine->has('sku-4471'));
        $this->assertSame('Brown leather shoe', $engine->get('sku-4471')['title']);
        $this->assertNull($engine->get('sku-nope'));
    }

    #[Test]
    public function an_integer_id_is_a_string_id(): void
    {
        $engine = $this->engine();
        $engine->put(42, ['title' => 'Shoe']);

        $this->assertTrue($engine->has(42));
        $this->assertTrue($engine->has('42'));
        $this->assertSame('42', $engine->search('shoe')->hits[0]->id);
    }

    #[Test]
    public function putting_the_same_id_twice_leaves_one_document(): void
    {
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'Brown leather shoe']);
        $engine->put('sku-1', ['title' => 'Black canvas sneaker']);

        $this->assertSame(1, $engine->count());
        $this->assertSame('Black canvas sneaker', $engine->get('sku-1')['title']);

        // The old copy is gone from the terms too, not just from the docstore.
        $this->assertSame(0, $engine->search('leather')->total);
        $this->assertSame(1, $engine->search('sneaker')->total);
    }

    #[Test]
    public function insert_generates_an_id_and_hands_it_back(): void
    {
        $engine = $this->engine();

        $first  = $engine->insert(['title' => 'Brown leather shoe']);
        $second = $engine->insert(['title' => 'Black leather boot']);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $engine->count());
        $this->assertSame('Brown leather shoe', $engine->get($first)['title']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
    }

    #[Test]
    public function a_batch_is_one_commit(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(4, $engine->count());
        $this->assertSame(1, $engine->stats()['segments']);
    }

    #[Test]
    public function a_generator_can_be_imported_without_being_materialised(): void
    {
        $engine = $this->engine();

        $engine->putMany((static function (): \Generator {
            foreach (range(1, 50) as $n) {
                yield "sku-$n" => ['title' => "Product $n", 'price' => $n * 1.5];
            }
        })());

        $this->assertSame(50, $engine->count());
        $this->assertSame(50, $engine->search('product')->total);
    }

    #[Test]
    public function a_refused_document_takes_its_whole_batch_with_it(): void
    {
        $engine = $this->engine(Schema::make()->text('title')->number('price'));

        try {
            $engine->putMany([
                'sku-1' => ['title' => 'Shoe', 'price' => 129.90],
                'sku-2' => ['title' => 'Boot', 'price' => 'sur devis'],
            ]);

            $this->fail('Expected the batch to be refused.');
        } catch (FieldTypeException $e) {
            $this->assertStringContainsString("'price'", $e->getMessage());
        }

        // Not half an import: nothing is published rather than one of the two,
        // because nothing would tell the caller which half landed.
        $this->assertSame(0, $engine->count());
        $this->assertFalse($engine->has('sku-1'));
    }

    #[Test]
    public function deleting_removes_a_document_from_results_and_from_counts(): void
    {
        $engine = $this->catalogue();

        $this->assertTrue($engine->delete('sku-1'));
        $this->assertFalse($engine->delete('sku-1'));

        $this->assertSame(3, $engine->count());
        $this->assertFalse($engine->has('sku-1'));
        $this->assertSame(1, $engine->search('leather')->total);
    }

    #[Test]
    public function clear_empties_the_index_and_leaves_it_usable(): void
    {
        $engine = $this->catalogue();
        $engine->clear();

        $this->assertSame(0, $engine->count());
        $this->assertSame(0, $engine->search('leather')->total);
        $this->assertFalse($engine->has('sku-1'));
        $this->assertSame(0, $engine->stats()['segments']);

        $engine->put('sku-9', ['title' => 'Brown leather shoe']);

        $this->assertSame(1, $engine->search('leather')->total);
    }

    #[Test]
    public function a_cleared_index_can_be_reopened_with_a_different_schema(): void
    {
        $engine = $this->engine(Schema::make()->text('title'));
        $engine->put('sku-1', ['title' => 'Shoe']);
        $engine->clear();

        // The freeze exists because segments were written to that schema, and
        // after a clear there are none. So this is the one way to change a
        // schema without a second directory.
        $reopened = $this->engine(Schema::make()->text('title')->number('price'));
        $reopened->put('sku-1', ['title' => 'Shoe', 'price' => 129.90]);

        $this->assertSame(1, $reopened->search('shoe', filters: Filter::lt('price', 200))->total);
    }

    // =========================================================================
    // Searching
    // =========================================================================

    #[Test]
    public function a_search_returns_hits_with_their_id_score_and_document(): void
    {
        $result = $this->catalogue()->search('leather');

        $this->assertSame(2, $result->total);
        $this->assertCount(2, $result);

        foreach ($result as $hit) {
            $this->assertContains($hit->id, ['sku-1', 'sku-2']);
            $this->assertGreaterThan(0.0, $hit->score);
            $this->assertArrayHasKey('title', $hit->document);
        }
    }

    #[Test]
    public function a_typo_still_finds_the_document(): void
    {
        // Words are the terms, so this is no longer free: the planner compares
        // what was typed against the words the index holds and accepts one
        // within an edit budget. `lether` is one insertion from `leather`.
        $this->assertSame(2, $this->catalogue()->search('lether')->total);
    }

    #[Test]
    public function a_word_still_being_typed_finds_what_it_completes(): void
    {
        // The other half of tolerance, and the half an edit budget cannot
        // reach: `leath` is three insertions from `leather`, far outside the
        // budget for a five-letter word, but it is a prefix of it. This is what
        // makes search-as-you-type work and what covers the suffixing
        // languages — Turkish, Finnish, French plurals — that no corpus in this
        // repository can test.
        $engine = $this->catalogue();

        $this->assertSame(2, $engine->search('leath')->total);
        $this->assertSame(2, $engine->search('leathe')->total);
    }

    #[Test]
    public function a_completion_is_marked_as_the_whole_word_it_completes(): void
    {
        // Highlighting follows the expansion rather than the query: the
        // document says `leather`, so `leather` is what gets marked, whole.
        // Marking only the typed prefix would tell the reader the engine
        // matched a fragment, which is not what happened.
        $result = $this->catalogue()->search('leath', highlight: ['title']);

        foreach ($result as $hit) {
            $this->assertStringContainsString('<mark>leather</mark>', strtolower($hit->highlights['title']));
        }

        $this->assertGreaterThan(0, $result->total);
    }

    #[Test]
    public function a_prefix_shorter_than_three_letters_completes_nothing(): void
    {
        // Two letters reach a fifth of a French vocabulary and say nothing
        // about intent. They still match a word that *is* those two letters.
        $engine = $this->catalogue();

        $this->assertSame(0, $engine->search('le')->total, 'not every leather and lether');
    }

    #[Test]
    public function japanese_needs_no_configuration(): void
    {
        $this->assertSame(1, $this->catalogue()->search('革靴')->total);
    }

    #[Test]
    public function the_total_is_the_whole_index_and_not_the_page(): void
    {
        $engine = $this->engine();

        $engine->putMany((static function (): \Generator {
            foreach (range(1, 30) as $n) {
                yield "sku-$n" => ['title' => "Leather shoe number $n"];
            }
        })());

        $result = $engine->search('leather', limit: 5);

        $this->assertSame(30, $result->total);
        $this->assertCount(5, $result->hits);
    }

    #[Test]
    public function offset_pages_through_the_ranking(): void
    {
        $engine = $this->engine();

        $engine->putMany((static function (): \Generator {
            foreach (range(1, 10) as $n) {
                yield "sku-$n" => ['title' => "Leather shoe number $n"];
            }
        })());

        $ids = static fn(int $offset): array => array_map(
            static fn($hit): string => $hit->id,
            $engine->search('leather', limit: 4, offset: $offset)->hits
        );

        $this->assertCount(4, $ids(0));
        $this->assertCount(4, $ids(4));
        $this->assertCount(2, $ids(8));
        $this->assertSame([], array_intersect($ids(0), $ids(4)));
    }

    #[Test]
    public function an_empty_query_matches_everything_so_filters_work_alone(): void
    {
        $result = $this->catalogue()->search('', filters: Filter::eq('brand', 'Nike'));

        $this->assertSame(2, $result->total);
    }

    #[Test]
    public function filters_facets_boosts_and_highlights_all_arrive(): void
    {
        // One call using every argument at once: the façade's job is to pass
        // them through, and this is what would catch a swapped parameter.
        $result = $this->catalogue()->search(
            query:     'leather',
            limit:     1,
            filters:   Filter::all(Filter::eq('brand', 'Adidas'), Filter::gt('stock', 0)),
            facets:    ['brand' => Facet::terms()],
            boosts:    ['title' => 5.0],
            highlight: Highlight::fields(['title'])->tags('<b>', '</b>'),
        );

        $this->assertSame(1, $result->total);
        $this->assertCount(1, $result->hits);
        $this->assertSame('sku-1', $result->hits[0]->id);
        $this->assertSame('Brown <b>leather</b> shoe', $result->hits[0]->highlights['title']);
        $this->assertSame(['Adidas' => 1], $result->facets['brand']);
    }

    #[Test]
    public function a_result_reports_how_long_it_took(): void
    {
        $this->assertGreaterThan(0.0, $this->catalogue()->search('leather')->took);
    }

    // =========================================================================
    // Introspection and maintenance
    // =========================================================================

    #[Test]
    public function the_engine_is_countable(): void
    {
        $this->assertCount(4, $this->catalogue());
    }

    #[Test]
    public function stats_describe_the_index_on_disk(): void
    {
        $stats = $this->catalogue()->stats();

        $this->assertSame(4, $stats['documents']);
        $this->assertSame(0, $stats['deleted']);
        $this->assertSame(1, $stats['segments']);
        $this->assertGreaterThan(0, $stats['bytes']);
        $this->assertFalse($stats['needsOptimize']);
    }

    #[Test]
    public function stats_count_a_deleted_document_as_deleted(): void
    {
        $engine = $this->catalogue();
        $engine->delete('sku-1');

        $stats = $engine->stats();

        $this->assertSame(3, $stats['documents']);
        $this->assertSame(1, $stats['deleted']);
    }

    #[Test]
    public function optimize_merges_and_changes_nothing_a_caller_can_see(): void
    {
        $engine = $this->engine();

        $engine->put('sku-1', ['title' => 'Brown leather shoe']);
        $engine->put('sku-2', ['title' => 'Black leather boot']);
        $engine->put('sku-3', ['title' => 'Canvas sneaker']);

        $this->assertSame(3, $engine->stats()['segments']);

        $before = $engine->search('leather');
        $engine->optimize();
        $after = $engine->search('leather');

        $this->assertSame(1, $engine->stats()['segments']);
        $this->assertSame($before->total, $after->total);
        $this->assertSame(
            array_map(static fn($hit): string => $hit->id, $before->hits),
            array_map(static fn($hit): string => $hit->id, $after->hits),
        );
    }

    #[Test]
    public function optimize_on_a_healthy_index_does_nothing(): void
    {
        $engine = $this->catalogue();
        $engine->optimize();

        $this->assertSame(4, $engine->count());
        $this->assertSame(1, $engine->stats()['segments']);
    }

    #[Test]
    public function the_schema_is_readable_whether_declared_or_inferred(): void
    {
        $declared = $this->engine(Schema::make()->text('title')->number('price'));
        $declared->put('sku-1', ['title' => 'Shoe', 'price' => 129.90]);

        $this->assertSame('number', $declared->schema()->typeOf('price'));
        $this->assertFalse($declared->schema()->isInferred());
    }

    #[Test]
    public function an_inferred_schema_reads_the_types_off_the_documents(): void
    {
        $engine = $this->catalogue();

        $this->assertTrue($engine->schema()->isInferred());
        $this->assertSame('number', $engine->schema()->typeOf('price'));

        // A string infers to keyword — an exact value to filter and facet on —
        // and is still indexed, which is why searching it works above. What
        // declaring text() buys is the boost and the length normalisation, not
        // the searching.
        $this->assertSame('keyword', $engine->schema()->typeOf('title'));
        $this->assertContains('title', $engine->schema()->searchableFields());
    }
}
