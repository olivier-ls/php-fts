<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Index\IndexDirectory;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Facets that stay usable after the shopper has used them.
 *
 * The problem, concretely: a shopper picks Nike, so the result set is Nike
 * products, so the brand facet is counted over Nike products and shows
 * `Nike (n)` alone. The shopper can no longer see that Adidas exists, and the
 * facet that made the choice possible has destroyed itself.
 *
 * What is asserted throughout is the pair of properties that makes a
 * disjunctive facet correct rather than merely wider:
 *
 *   1. The facet excluding its own tag shows the alternatives.
 *   2. The shopper's *other* choices still narrow it.
 *
 * Run across several segments and again after `optimize()`, because a facet is
 * counted per segment and merged, and that is where the counts go wrong.
 */
class DisjunctiveFacetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_df_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    private function catalogue(): IndexDirectory
    {
        $schema = Schema::make()
            ->text('title')
            ->keyword('brand')
            ->keyword('category')
            ->number('price')
            ->boolean('active');

        $index = IndexDirectory::open($this->dir, $schema);

        // Three commits, so every count below is merged from several segments.
        $index->putMany([
            'a' => ['title' => 'Nike running shoe',   'brand' => 'Nike',   'category' => 'Shoes',
                    'price' => 120.0, 'active' => true],
            'b' => ['title' => 'Nike leather boot',   'brand' => 'Nike',   'category' => 'Boots',
                    'price' => 180.0, 'active' => true],
        ]);
        $index->putMany([
            'c' => ['title' => 'Adidas running shoe', 'brand' => 'Adidas', 'category' => 'Shoes',
                    'price' => 100.0, 'active' => true],
            'd' => ['title' => 'Adidas canvas shoe',  'brand' => 'Adidas', 'category' => 'Shoes',
                    'price' => 60.0,  'active' => true],
            'e' => ['title' => 'Adidas wool sock',    'brand' => 'Adidas', 'category' => 'Socks',
                    'price' => 15.0,  'active' => true],
        ]);
        $index->putMany([
            'f' => ['title' => 'Puma leather shoe',   'brand' => 'Puma',   'category' => 'Shoes',
                    'price' => 90.0,  'active' => true],
            'g' => ['title' => 'Puma retired shoe',   'brand' => 'Puma',   'category' => 'Shoes',
                    'price' => 40.0,  'active' => false],
        ]);

        return $index;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<mixed>         $facets
     */
    private function assertFacets(
        array $expected,
        Filter $filter,
        array $facets,
        string $because = '',
    ): void {
        $index = $this->catalogue();

        $this->assertGreaterThan(1, $index->stats()['segments'], 'the fixture must span segments');
        $this->assertSame($expected, $index->search('', filters: $filter, facets: $facets)->facets, $because);

        $index->optimize();

        $this->assertSame(1, $index->stats()['segments']);
        $this->assertSame(
            $expected,
            $index->search('', filters: $filter, facets: $facets)->facets,
            'a merge must not change a facet'
        );
    }

    // =========================================================================
    // The problem, and the fix
    // =========================================================================

    #[Test]
    public function without_exclusion_a_facet_shows_only_what_was_chosen(): void
    {
        // The behaviour being fixed, pinned so the fix is visibly a change.
        $this->assertFacets(
            ['brand' => ['Nike' => 2]],
            Filter::eq('brand', 'Nike')->tag('brand'),
            ['brand' => Facet::terms()],
        );
    }

    #[Test]
    public function excluding_its_own_tag_shows_the_alternatives(): void
    {
        $this->assertFacets(
            ['brand' => ['Adidas' => 3, 'Nike' => 2, 'Puma' => 2]],
            Filter::eq('brand', 'Nike')->tag('brand'),
            ['brand' => Facet::terms(exclude: 'brand')],
            'the shopper has to be able to see Adidas to switch to it'
        );
    }

    #[Test]
    public function the_other_choices_still_narrow_the_facet(): void
    {
        // The half that makes this correct rather than merely wider: the brand
        // facet counts Shoes of every brand, not every product of every brand.
        // Puma has two shoes but one is inactive, and Adidas has three
        // products but only two are shoes.
        $this->assertFacets(
            ['brand' => ['Adidas' => 2, 'Nike' => 1, 'Puma' => 1]],
            Filter::all(
                Filter::eq('active', true),
                Filter::eq('brand', 'Nike')->tag('brand'),
                Filter::eq('category', 'Shoes')->tag('category'),
            ),
            ['brand' => Facet::terms(exclude: 'brand')],
        );
    }

    #[Test]
    public function two_facets_each_exclude_their_own_clause_in_one_query(): void
    {
        // The whole point: one query, and both facets stay usable. Brand
        // counts active Shoes of every brand; category counts active Nike of
        // every category.
        $this->assertFacets(
            [
                'brand'    => ['Adidas' => 2, 'Nike' => 1, 'Puma' => 1],
                'category' => ['Boots' => 1, 'Shoes' => 1],
            ],
            Filter::all(
                Filter::eq('active', true),
                Filter::eq('brand', 'Nike')->tag('brand'),
                Filter::eq('category', 'Shoes')->tag('category'),
            ),
            [
                'brand'    => Facet::terms(exclude: 'brand'),
                'category' => Facet::terms(exclude: 'category'),
            ],
        );
    }

    #[Test]
    public function the_hits_are_never_widened_by_an_exclusion(): void
    {
        // Only the counting is widened. A shopper who picked Nike must still
        // be shown Nike products.
        $index = $this->catalogue();

        $result = $index->search('', limit: 50, filters: Filter::all(
            Filter::eq('active', true),
            Filter::eq('brand', 'Nike')->tag('brand'),
        ), facets: ['brand' => Facet::terms(exclude: 'brand')]);

        $ids = array_map(static fn($hit): string => $hit->id, $result->hits);
        sort($ids);

        $this->assertSame(['a', 'b'], $ids);
        $this->assertSame(2, $result->total, 'the total follows the hits, not the facet');

        // Puma has two shoes but one is retired, and `active` was not tagged,
        // so it still narrows the facet. Only the brand clause was lifted.
        $this->assertSame(['Adidas' => 3, 'Nike' => 2, 'Puma' => 1], $result->facets['brand']);
    }

    #[Test]
    public function a_disjunctive_facet_still_respects_the_query(): void
    {
        // The exclusion lifts a filter clause, not the search itself.
        $index = $this->catalogue();

        $facets = $index->search('running', filters: Filter::eq('brand', 'Nike')->tag('brand'), facets: [
            'brand' => Facet::terms(exclude: 'brand'),
        ])->facets;

        $this->assertSame(['Adidas' => 1, 'Nike' => 1], $facets['brand'], 'only the running shoes');
    }

    #[Test]
    public function an_or_of_choices_is_lifted_as_a_whole(): void
    {
        // What a multi-select facet builds. Both clauses carry the tag, so the
        // emptied `any` dissolves and the facet sees every brand.
        $this->assertFacets(
            ['brand' => ['Adidas' => 3, 'Nike' => 2, 'Puma' => 2]],
            Filter::all(
                Filter::any(
                    Filter::eq('brand', 'Nike')->tag('brand'),
                    Filter::eq('brand', 'Adidas')->tag('brand'),
                ),
            ),
            ['brand' => Facet::terms(exclude: 'brand')],
        );
    }

    #[Test]
    public function a_tagged_in_clause_is_lifted_the_same_way(): void
    {
        $this->assertFacets(
            ['brand' => ['Adidas' => 3, 'Nike' => 2, 'Puma' => 2]],
            Filter::in('brand', ['Nike', 'Adidas'])->tag('brand'),
            ['brand' => Facet::terms(exclude: 'brand')],
        );
    }

    // =========================================================================
    // Statistics facets disjoin too
    // =========================================================================

    #[Test]
    public function a_statistics_facet_can_exclude_its_own_range(): void
    {
        // So a price slider can show the true bounds of the catalogue rather
        // than the bounds of the range already chosen — otherwise it collapses
        // onto itself and can never be widened again.
        $index = $this->catalogue();

        $facets = $index->search('', filters: Filter::all(
            Filter::eq('active', true),
            Filter::between('price', 100, 200)->tag('price'),
        ), facets: [
            'price' => Facet::stats(exclude: 'price'),
        ])->facets;

        $this->assertSame(6, $facets['price']['count'], 'every active product');
        $this->assertSame(15.0, $facets['price']['min']);
        $this->assertSame(180.0, $facets['price']['max']);
    }

    #[Test]
    public function a_statistics_facet_without_exclusion_sees_only_the_chosen_range(): void
    {
        $index = $this->catalogue();

        $facets = $index->search('', filters: Filter::between('price', 100, 200), facets: ['price'])->facets;

        $this->assertSame(3, $facets['price']['count']);
        $this->assertSame(100.0, $facets['price']['min']);
        $this->assertSame(180.0, $facets['price']['max']);
    }

    // =========================================================================
    // Naming, sizing, and the shorthand
    // =========================================================================

    #[Test]
    public function a_facet_can_be_named_apart_from_its_column(): void
    {
        $index = $this->catalogue();

        $facets = $index->search('', facets: [
            'makers'  => Facet::terms(field: 'brand'),
            'pricing' => Facet::stats('price'),
        ])->facets;

        $this->assertSame(['Adidas' => 3, 'Nike' => 2, 'Puma' => 2], $facets['makers']);
        $this->assertSame(7, $facets['pricing']['count']);
    }

    #[Test]
    public function the_same_column_can_be_counted_twice_under_two_names(): void
    {
        $index = $this->catalogue();

        $facets = $index->search('', facets: [
            'top'  => Facet::terms(size: 1, field: 'brand'),
            'full' => Facet::terms(size: 0, field: 'brand'),
        ])->facets;

        $this->assertSame(['Adidas' => 3], $facets['top']);
        $this->assertSame(['Adidas' => 3, 'Nike' => 2, 'Puma' => 2], $facets['full']);
    }

    #[Test]
    public function a_size_is_applied_after_every_segment_has_contributed(): void
    {
        // Cutting each segment to one and adding those up would give three
        // values of one each. The top brand of the index is Adidas with three,
        // and it is only top once the segments are combined.
        $index = $this->catalogue();

        $this->assertGreaterThan(1, $index->stats()['segments']);
        $this->assertSame(['Adidas' => 3], $index->search('', facets: ['brand' => Facet::terms(size: 1)])->facets['brand']);
    }

    #[Test]
    public function values_with_equal_counts_are_ordered_the_same_before_and_after_a_merge(): void
    {
        // Counts are gathered per segment and added up, so without a tie-break
        // two equal values come out in whatever order the segments happened to
        // be traversed in — and that order changes when segments merge. A
        // facet that reshuffles between two identical requests is one a UI
        // cannot render stably or cache.
        $index = $this->catalogue();

        // Every category once, so every count is a tie except Shoes.
        $index->putMany([
            'h' => ['title' => 'Zeta wool sock', 'brand' => 'Zeta', 'category' => 'Socks',
                    'price' => 9.0, 'active' => true],
            'i' => ['title' => 'Alpha boot',     'brand' => 'Alpha', 'category' => 'Boots',
                    'price' => 9.0, 'active' => true],
        ]);

        $before = $index->search('', facets: ['category'])->facets['category'];

        $index->optimize();

        $after = $index->search('', facets: ['category'])->facets['category'];

        $this->assertSame($before, $after);
        $this->assertSame(['Shoes' => 5, 'Boots' => 2, 'Socks' => 2], $after, 'ties break by value');
    }

    #[Test]
    public function the_bare_field_name_shorthand_still_works(): void
    {
        $index = $this->catalogue();

        $facets = $index->search('', facets: ['brand', 'price'])->facets;

        $this->assertSame(['Adidas' => 3, 'Nike' => 2, 'Puma' => 2], $facets['brand']);
        $this->assertSame(7, $facets['price']['count'], 'a number still gets statistics');
    }

    #[Test]
    public function a_single_segment_index_behaves_the_same(): void
    {
        // The one-segment path is a different code path in SegmentIndex, and it
        // has to agree with the merged one.
        $index = $this->catalogue();
        $index->optimize();

        $this->assertSame(1, $index->stats()['segments']);

        $facets = $index->search('', filters: Filter::eq('brand', 'Nike')->tag('brand'), facets: [
            'brand' => Facet::terms(exclude: 'brand'),
        ])->facets;

        $this->assertSame(['Adidas' => 3, 'Nike' => 2, 'Puma' => 2], $facets['brand']);
    }

    // =========================================================================
    // Refusals
    // =========================================================================

    #[Test]
    public function excluding_a_tag_nobody_used_changes_nothing(): void
    {
        // Not an error: a template that always excludes 'brand' should work
        // whether or not the shopper has picked a brand yet.
        $this->assertFacets(
            ['brand' => ['Nike' => 2]],
            Filter::eq('brand', 'Nike'),
            ['brand' => Facet::terms(exclude: 'brand')],
        );
    }

    #[Test]
    public function term_counts_are_refused_on_a_numeric_column(): void
    {
        $index = $this->catalogue();

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("'price' is a numeric column");

        $index->search('', facets: ['price' => Facet::terms()]);
    }

    #[Test]
    public function statistics_are_refused_on_a_keyword_column(): void
    {
        $index = $this->catalogue();

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("Use Facet::terms() for an exact value");

        $index->search('', facets: ['brand' => Facet::stats()]);
    }

    #[Test]
    public function a_facet_on_a_field_with_no_column_is_refused(): void
    {
        $index = $this->catalogue();

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("No facetable column for field 'title'");

        $index->search('', facets: ['title']);
    }
}
