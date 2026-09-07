<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Index\IndexDirectory;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A filter tree turned into the documents it selects.
 *
 * Deliberately run across **several segments**, because that is where set
 * arithmetic goes wrong: a tree is compiled once per segment against that
 * segment's own columns and ordinals, and `any` and `not` in particular have
 * to mean the same thing whether the catalogue sits in one segment or four.
 * Every count below is also checked after `optimize()`, which collapses them
 * into one.
 */
class FilterCompilationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_fc_' . uniqid();
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
            ->number('stock')
            ->boolean('active');

        $index = IndexDirectory::open($this->dir, $schema);

        // Four commits, so every filter below really crosses segments.
        $index->putMany([
            'a' => ['title' => 'Brown leather shoe', 'brand' => 'Adidas', 'category' => 'Shoes',
                    'price' => 129.90, 'stock' => 4,  'active' => true],
            'b' => ['title' => 'Blue suede boot', 'brand' => 'Puma', 'category' => 'Boots',
                    'price' => 89.00,  'stock' => 0,  'active' => true],
        ]);
        $index->putMany([
            'c' => ['title' => 'Brown suede shoe', 'brand' => 'Adidas', 'category' => 'Shoes',
                    'price' => 149.00, 'stock' => 12, 'active' => false],
            'd' => ['title' => 'Red canvas sneaker', 'brand' => 'Nike', 'category' => 'Sneakers',
                    'price' => 59.00,  'stock' => 7,  'active' => true],
        ]);
        $index->putMany([
            'e' => ['title' => 'Green wool sock', 'brand' => 'Nike', 'category' => 'Socks',
                    'price' => 12.50,  'stock' => 40, 'active' => true],
        ]);
        // No brand and no price at all: the document that makes `missing`,
        // `neq` and `not` differ from one another.
        $index->putMany([
            'f' => ['title' => 'Unbranded slipper', 'category' => 'Slippers', 'stock' => 3, 'active' => false],
        ]);

        return $index;
    }

    /**
     * @return string[]
     */
    private function ids(IndexDirectory $index, Filter $filter): array
    {
        $ids = array_map(
            static fn($hit): string => $hit->id,
            $index->search('', limit: 50, filters: $filter)->hits
        );

        sort($ids);

        return $ids;
    }

    /**
     * Asserts the same selection before and after a merge.
     *
     * @param string[] $expected
     */
    private function assertSelects(array $expected, Filter $filter, string $because = ''): void
    {
        $index = $this->catalogue();

        $this->assertGreaterThan(1, $index->stats()['segments'], 'the fixture must span segments');
        $this->assertSame($expected, $this->ids($index, $filter), $because);
        $this->assertSame(
            count($expected),
            $index->search('', filters: $filter)->total,
            'the total must agree with the hits'
        );

        $index->optimize();

        $this->assertSame(1, $index->stats()['segments']);
        $this->assertSame($expected, $this->ids($index, $filter), 'a merge must not change a filter');
    }

    // =========================================================================
    // Leaves
    // =========================================================================

    #[Test]
    public function eq_on_a_keyword(): void
    {
        $this->assertSelects(['a', 'c'], Filter::eq('brand', 'Adidas'));
    }

    #[Test]
    public function eq_on_a_number(): void
    {
        $this->assertSelects(['d'], Filter::eq('price', 59.00));
    }

    #[Test]
    public function eq_on_a_boolean(): void
    {
        $this->assertSelects(['a', 'b', 'd', 'e'], Filter::eq('active', true));
    }

    #[Test]
    public function comparisons_bracket_a_range(): void
    {
        $this->assertSelects(['a', 'c'], Filter::gt('price', 100));
        $this->assertSelects(['a', 'c'], Filter::gte('price', 129.90));
        $this->assertSelects(['e'], Filter::lt('price', 59));
        $this->assertSelects(['d', 'e'], Filter::lte('price', 59));
    }

    #[Test]
    public function between_is_inclusive_at_both_ends(): void
    {
        $this->assertSelects(['b', 'd'], Filter::between('price', 59.00, 89.00));
    }

    #[Test]
    public function between_accepts_an_open_end(): void
    {
        // "100 and up" without needing a second clause.
        $this->assertSelects(['a', 'c'], Filter::between('price', 100, null));
        $this->assertSelects(['d', 'e'], Filter::between('price', null, 59));
    }

    #[Test]
    public function in_and_not_in_on_a_keyword(): void
    {
        $this->assertSelects(['a', 'c', 'd', 'e'], Filter::in('brand', ['Adidas', 'Nike']));

        // 'f' has no brand, so it is not "a brand other than these" — the same
        // rule as neq.
        $this->assertSelects(['b'], Filter::notIn('brand', ['Adidas', 'Nike']));
    }

    #[Test]
    public function in_and_not_in_on_a_number(): void
    {
        $this->assertSelects(['b', 'd'], Filter::in('price', [59.00, 89.00]));
        $this->assertSelects(['a', 'c', 'e'], Filter::notIn('price', [59.00, 89.00]));
    }

    #[Test]
    public function exists_and_missing_are_complementary_over_the_documents_that_have_the_field(): void
    {
        $this->assertSelects(['a', 'b', 'c', 'd', 'e'], Filter::exists('brand'));
        $this->assertSelects(['f'], Filter::missing('brand'));
    }

    // =========================================================================
    // neq and not are not the same thing
    // =========================================================================

    #[Test]
    public function neq_requires_the_field_to_be_present(): void
    {
        // SQL semantics: a comparison against nothing is not true, so the
        // unbranded slipper is not "a brand other than Adidas".
        $this->assertSelects(['b', 'd', 'e'], Filter::neq('brand', 'Adidas'));
    }

    #[Test]
    public function not_is_plain_complement_and_includes_the_missing(): void
    {
        // Which is what the word means, and why both exist rather than one
        // being quietly picked for you.
        $this->assertSelects(['b', 'd', 'e', 'f'], Filter::not(Filter::eq('brand', 'Adidas')));
    }

    #[Test]
    public function not_over_a_group_negates_the_whole_group(): void
    {
        // Not (Adidas and dear) — so cheap Adidas, everything else, and the
        // document with no brand at all.
        $this->assertSelects(
            ['b', 'd', 'e', 'f'],
            Filter::not(Filter::all(Filter::eq('brand', 'Adidas'), Filter::gt('price', 100)))
        );
    }

    #[Test]
    public function not_not_is_the_original(): void
    {
        $this->assertSelects(['a', 'c'], Filter::not(Filter::not(Filter::eq('brand', 'Adidas'))));
    }

    // =========================================================================
    // Groups
    // =========================================================================

    #[Test]
    public function all_intersects(): void
    {
        $this->assertSelects(
            ['a'],
            Filter::all(
                Filter::eq('brand', 'Adidas'),
                Filter::eq('active', true),
                Filter::gt('stock', 0),
            )
        );
    }

    #[Test]
    public function any_unions(): void
    {
        $this->assertSelects(
            ['a', 'c', 'e'],
            Filter::any(
                Filter::eq('brand', 'Adidas'),
                Filter::eq('category', 'Socks'),
            )
        );
    }

    #[Test]
    public function an_empty_all_narrows_nothing(): void
    {
        $this->assertSelects(['a', 'b', 'c', 'd', 'e', 'f'], Filter::all());
    }

    #[Test]
    public function an_empty_any_matches_nothing(): void
    {
        $this->assertSelects([], Filter::any());
    }

    #[Test]
    public function groups_nest_to_arbitrary_depth(): void
    {
        // Active, and either a cheap Nike or any Adidas, and not out of stock.
        $this->assertSelects(
            ['a', 'd', 'e'],
            Filter::all(
                Filter::eq('active', true),
                Filter::any(
                    Filter::all(Filter::eq('brand', 'Nike'), Filter::lt('price', 100)),
                    Filter::eq('brand', 'Adidas'),
                ),
                Filter::not(Filter::eq('stock', 0)),
            )
        );
    }

    #[Test]
    public function a_query_and_a_filter_narrow_together(): void
    {
        $index = $this->catalogue();

        $result = $index->search('shoe', limit: 50, filters: Filter::eq('brand', 'Adidas'));

        $ids = array_map(static fn($hit): string => $hit->id, $result->hits);
        sort($ids);

        $this->assertSame(['a', 'c'], $ids);
        $this->assertSame(2, $result->total);
    }

    #[Test]
    public function facets_are_counted_over_the_filtered_set(): void
    {
        $index = $this->catalogue();

        $facets = $index->search('', filters: Filter::eq('active', true), facets: ['brand', 'price'])->facets;

        $this->assertSame(['Nike' => 2, 'Adidas' => 1, 'Puma' => 1], $facets['brand']);
        $this->assertSame(4, $facets['price']['count']);
        $this->assertSame(12.50, $facets['price']['min']);
    }

    // =========================================================================
    // Both input shapes still work
    // =========================================================================

    #[Test]
    public function a_flat_list_of_clauses_still_works(): void
    {
        $index = $this->catalogue();

        $result = $index->search('', limit: 50, filters: [
            ['field' => 'brand', 'op' => '=', 'value' => 'Adidas'],
            ['field' => 'price', 'op' => '>', 'value' => 130],
        ]);

        $this->assertSame(1, $result->total);
        $this->assertSame('c', $result->hits[0]->id);
    }

    #[Test]
    public function a_structure_decoded_from_json_works(): void
    {
        $index = $this->catalogue();

        $body = json_decode('{"op":"all","filters":[
            {"op":"eq","field":"active","value":true},
            {"op":"any","filters":[
                {"op":"eq","field":"brand","value":"Nike"},
                {"op":"<=","field":"price","value":"100"}
            ]}
        ]}', true);

        $ids = $this->ids($index, Filter::fromArray($body));

        $this->assertSame(['b', 'd', 'e'], $ids);
    }

    // =========================================================================
    // Refusals, against a real index
    // =========================================================================

    #[Test]
    public function a_range_over_a_keyword_is_refused(): void
    {
        $index = $this->catalogue();

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("does not apply to the keyword field 'brand'");

        $index->search('', filters: Filter::gt('brand', 'Adidas'));
    }

    #[Test]
    public function a_filter_on_an_unfilterable_field_is_refused(): void
    {
        $index = $this->catalogue();

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("No filterable column for field 'title'");

        $index->search('', filters: Filter::eq('title', 'Brown leather shoe'));
    }

    #[Test]
    public function a_type_mismatch_is_refused_rather_than_silently_matching(): void
    {
        // The README's promise: eq('brand', true) must never match the string
        // 'Adidas'. It used to be cast to '1' and quietly match nothing, which
        // looks the same until the day a value really is '1'.
        $index = $this->catalogue();

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("Field 'brand' is declared keyword but received a boolean");

        $index->search('', filters: Filter::eq('brand', true));
    }

    #[Test]
    public function a_malformed_filter_is_reported_before_any_segment_is_read(): void
    {
        $index = $this->catalogue();

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("Unknown filter operator 'lke'");

        $index->search('', filters: [['field' => 'brand', 'op' => 'lke', 'value' => 'Adidas']]);
    }

    // =========================================================================
    // Tags, ready for disjunctive facets
    // =========================================================================

    #[Test]
    public function a_tag_does_not_change_what_a_filter_selects(): void
    {
        // Tags are metadata for the facet layer, not part of the condition.
        $this->assertSelects(['a', 'c'], Filter::eq('brand', 'Adidas')->tag('brand'));
    }

    #[Test]
    public function stripping_a_tag_widens_the_selection_to_the_other_choices(): void
    {
        // What a disjunctive brand facet will count over: the shopper's
        // category choice still narrows, their brand choice does not.
        $filter = Filter::all(
            Filter::eq('active', true),
            Filter::eq('brand', 'Nike')->tag('brand'),
        );

        $this->assertSelects(['d', 'e'], $filter);
        $this->assertSelects(['a', 'b', 'd', 'e'], $filter->withoutTag('brand'));
    }
}
