<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\SortException;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use Ols\PhpFts\Sort;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ordering results by a column instead of by relevance.
 *
 * The property that makes this the engine's job rather than the caller's is
 * asserted here as pagination: the pages of a sorted search must partition the
 * matches, with nothing shown twice and nothing skipped. A caller sorting the
 * twenty hits it was handed cannot produce that, because it never sees the
 * other matches.
 */
class SortTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_sort_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    private function catalogue(bool $split = true): SearchEngine
    {
        $engine = SearchEngine::open($this->dir, Schema::make()
            ->text('title')
            ->keyword('brand')
            ->number('price')
            ->number('stock')
            ->boolean('active'));

        $first = [
            'a' => ['title' => 'Brown leather shoe',     'brand' => 'Nike',   'price' => 129.9, 'stock' => 4, 'active' => true],
            'b' => ['title' => 'Black leather boot',     'brand' => 'Nike',   'price' => 89.0,  'stock' => 0, 'active' => true],
        ];

        $second = [
            'c' => ['title' => 'Canvas leather sneaker', 'brand' => 'Adidas', 'price' => 259.0, 'stock' => 7, 'active' => false],
            'd' => ['title' => 'Suede leather loafer',   'brand' => 'Adidas',                   'stock' => 2, 'active' => true],
            'e' => ['title' => 'Plain leather clog',     'brand' => 'Puma',   'price' => 89.0,  'stock' => 9, 'active' => true],
        ];

        if ($split) {
            // Two commits: the sort has to hold across segments, each with its
            // own column and its own document numbering.
            $engine->putMany($first);
            $engine->putMany($second);
        } else {
            $engine->putMany($first + $second);
        }

        return $engine;
    }

    /**
     * @param Sort|array<mixed> $sort
     * @return string[]
     */
    private function order(
        SearchEngine $engine,
        Sort|array $sort,
        string $query = '',
        int $limit = 20,
        int $offset = 0,
        Filter|array $filters = [],
    ): array {
        return array_map(
            static fn($hit): string => $hit->id,
            $engine->search($query, limit: $limit, offset: $offset, filters: $filters, sort: $sort)->hits
        );
    }

    // =========================================================================
    // The order itself
    // =========================================================================

    #[Test]
    public function nothing_asked_for_is_relevance(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(
            $this->order($engine, [], 'leather'),
            $this->order($engine, [Sort::score()], 'leather'),
        );
    }

    #[Test]
    public function ascending_puts_the_smallest_first(): void
    {
        // 89, 89, 129.9, 259, then the one with no price.
        $this->assertSame(
            ['b', 'e', 'a', 'c', 'd'],
            $this->order($this->catalogue(), [Sort::asc('price')])
        );
    }

    #[Test]
    public function descending_puts_the_largest_first(): void
    {
        $this->assertSame(
            ['c', 'a', 'b', 'e', 'd'],
            $this->order($this->catalogue(), [Sort::desc('price')])
        );
    }

    #[Test]
    public function a_document_with_no_value_goes_last_in_both_directions(): void
    {
        // Not first in one direction and last in the other: "cheapest first"
        // must not open on a page of products with no price.
        $engine     = $this->catalogue();
        $ascending  = $this->order($engine, [Sort::asc('price')]);
        $descending = $this->order($engine, [Sort::desc('price')]);

        $this->assertSame('d', end($ascending));
        $this->assertSame('d', end($descending));
    }

    #[Test]
    public function a_second_criterion_settles_ties(): void
    {
        // 'b' and 'e' both cost 89. Stock descending puts 'e' (9) first.
        $this->assertSame(
            ['e', 'b', 'a', 'c', 'd'],
            $this->order($this->catalogue(), [Sort::asc('price'), Sort::desc('stock')])
        );
    }

    #[Test]
    public function relevance_can_be_a_criterion_among_others(): void
    {
        $engine = $this->catalogue();

        // Every title holds `leather` once and three of them are the same
        // length, so 'a', 'b' and 'e' score identically — and price then
        // decides between them.
        $order = $this->order($engine, [Sort::score(), Sort::asc('price')], 'leather');

        $this->assertSame(['b', 'e', 'a'], array_slice($order, 0, 3));
    }

    #[Test]
    public function a_boolean_column_can_be_sorted_on(): void
    {
        // Stored as 1 and 0, so descending is "active first".
        $order = $this->order($this->catalogue(), [Sort::desc('active')]);

        $this->assertSame('c', end($order), 'the only inactive product goes last');
    }

    #[Test]
    public function sorting_and_filtering_compose(): void
    {
        $this->assertSame(
            ['b', 'a'],
            $this->order($this->catalogue(), [Sort::asc('price')], filters: Filter::eq('brand', 'Nike'))
        );
    }

    #[Test]
    public function sorting_a_query_orders_only_its_matches(): void
    {
        // `brand` is a keyword, so it is searchable as well as filterable:
        // querying Nike matches two of the five, and only those two are
        // ordered.
        $this->assertSame(
            ['b', 'a'],
            $this->order($this->catalogue(), [Sort::asc('price')], 'Nike')
        );
    }

    // =========================================================================
    // Pagination — the reason this cannot be the caller's job
    // =========================================================================

    #[Test]
    public function the_pages_of_a_sorted_search_partition_its_matches(): void
    {
        $engine = $this->catalogue();
        $whole  = $this->order($engine, [Sort::asc('price')]);

        $paged = [];

        for ($offset = 0; $offset < 6; $offset += 2) {
            $page = $this->order($engine, [Sort::asc('price')], limit: 2, offset: $offset);

            $this->assertSame(array_slice($whole, $offset, 2), $page, "page at offset $offset");

            $paged = [...$paged, ...$page];
        }

        // Nothing twice, nothing missed.
        $this->assertSame($whole, $paged);
        $this->assertSame(count($paged), count(array_unique($paged)));
    }

    #[Test]
    public function a_page_of_a_sorted_search_is_not_the_page_of_a_scored_one(): void
    {
        // The bug a caller cannot fix for itself: the two cheapest matches are
        // not among the two most relevant, so sorting the page it was handed
        // would give it the wrong documents.
        $engine = $this->catalogue();

        $byScore = $this->order($engine, [], 'leather', limit: 2);
        $byPrice = $this->order($engine, [Sort::asc('price')], 'leather', limit: 2);

        $this->assertNotSame($byScore, $byPrice);
        $this->assertSame(['b', 'e'], $byPrice);
    }

    #[Test]
    public function the_total_is_unaffected_by_the_order(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(
            $engine->search('leather')->total,
            $engine->search('leather', sort: [Sort::asc('price')])->total
        );
    }

    #[Test]
    public function facets_are_unaffected_by_the_order(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(
            $engine->search('', facets: ['brand'])->facets,
            $engine->search('', facets: ['brand'], sort: [Sort::desc('price')])->facets
        );
    }

    #[Test]
    public function a_sorted_hit_still_reports_its_relevance(): void
    {
        $result = $this->catalogue()->search('leather', sort: [Sort::asc('price')]);

        foreach ($result as $hit) {
            $this->assertGreaterThan(0.0, $hit->score, "hit {$hit->id} lost its score");
        }
    }

    // =========================================================================
    // Across segments, and across a merge
    // =========================================================================

    #[Test]
    public function the_order_does_not_depend_on_how_documents_were_committed(): void
    {
        $split = $this->catalogue(split: true);
        $order = $this->order($split, [Sort::asc('price')]);

        $this->tearDown();
        $this->setUp();

        $single = $this->catalogue(split: false);

        $this->assertSame($order, $this->order($single, [Sort::asc('price')]));
    }

    #[Test]
    public function a_merge_does_not_change_a_sorted_page(): void
    {
        $engine = $this->catalogue();
        $before = $this->order($engine, [Sort::asc('price')]);

        $engine->optimize();

        $this->assertSame(1, $engine->stats()['segments']);
        $this->assertSame($before, $this->order($engine, [Sort::asc('price')]));
    }

    #[Test]
    public function a_deleted_document_leaves_the_order(): void
    {
        $engine = $this->catalogue();
        $engine->delete('b');

        $this->assertSame(['e', 'a', 'c', 'd'], $this->order($engine, [Sort::asc('price')]));
    }

    // =========================================================================
    // What is refused, and what it says
    // =========================================================================

    #[Test]
    public function sorting_on_text_is_refused_with_a_reason(): void
    {
        $this->expectException(SortException::class);
        $this->expectExceptionMessage('only numeric fields can be sorted');

        $this->catalogue()->search('', sort: [Sort::asc('brand')]);
    }

    #[Test]
    public function sorting_on_an_unknown_field_is_refused(): void
    {
        $this->expectException(SortException::class);
        $this->expectExceptionMessage("No sortable column for field 'nonesuch'");

        $this->catalogue()->search('', sort: [Sort::asc('nonesuch')]);
    }

    #[Test]
    public function sorting_on_a_field_with_no_column_is_refused(): void
    {
        $engine = SearchEngine::open($this->dir, Schema::make()
            ->text('title')
            ->number('price', filterable: false));

        $engine->put('a', ['title' => 'Shoe', 'price' => 10.0]);

        $this->expectException(SortException::class);
        $this->expectExceptionMessage('No sortable column');

        $engine->search('', sort: [Sort::asc('price')]);
    }

    #[Test]
    public function a_field_asked_for_twice_is_refused(): void
    {
        $this->expectException(SortException::class);
        $this->expectExceptionMessage("Field 'price' is already part of this sort");

        $this->catalogue()->search('', sort: [Sort::asc('price'), Sort::desc('price')]);
    }

    #[Test]
    public function relevance_asked_for_twice_is_refused(): void
    {
        $this->expectException(SortException::class);
        $this->expectExceptionMessage('Relevance is already part of this sort');

        $this->catalogue()->search('', sort: [Sort::score(), Sort::score()]);
    }

    #[Test]
    public function something_that_is_not_a_criterion_is_refused(): void
    {
        $this->expectException(SortException::class);
        $this->expectExceptionMessage('not string');

        $this->catalogue()->search('', sort: ['price']);
    }

    #[Test]
    public function a_single_criterion_needs_no_array(): void
    {
        $this->assertSame(
            ['b', 'e', 'a', 'c', 'd'],
            $this->order($this->catalogue(), Sort::asc('price'))
        );
    }

    #[Test]
    public function an_empty_sort_is_relevance(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(
            $this->order($engine, [], 'leather'),
            $this->order($engine, [], 'leather')
        );
        $this->assertSame([], Sort::normalise([]));
    }
}
