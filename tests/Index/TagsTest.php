<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Multi-valued fields through the engine: filtering, faceting, and the two
 * places a column can quietly disagree with itself — several segments, and a
 * merge.
 *
 * The semantics under test, stated once:
 *
 *   - `eq` asks whether the list *holds* the value. A list of three tags does
 *     not equal one tag, so there is nothing else it could mean.
 *   - "this *and* that" is `Filter::all()` of two `eq` clauses. The tree
 *     already says it, so the column has no `containsAll`.
 *   - A facet's counts add up to more than the number of documents, because a
 *     document with three tags is counted in three buckets.
 */
class TagsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_tags_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    private function catalogue(?Schema $schema = null, bool $split = false): SearchEngine
    {
        return $this->fill(SearchEngine::open($this->dir, $schema ?? $this->declared()), $split);
    }

    private function declared(): Schema
    {
        return Schema::make()
            ->text('title')
            ->tags('tags')
            ->keyword('brand');
    }

    private function fill(SearchEngine $engine, bool $split = false): SearchEngine
    {
        $first = [
            'a' => ['title' => 'Brown leather shoe', 'tags' => ['summer', 'luxury'], 'brand' => 'Nike'],
            'b' => ['title' => 'Black leather boot', 'tags' => ['winter', 'luxury'], 'brand' => 'Nike'],
        ];

        $second = [
            'c' => ['title' => 'Canvas sneaker', 'tags' => ['summer'], 'brand' => 'Adidas'],
            'd' => ['title' => 'Plain clog',                          'brand' => 'Adidas'],
            'e' => ['title' => 'Single tag',     'tags' => 'summer',   'brand' => 'Puma'],
        ];

        if ($split) {
            // Two commits, so every answer below is assembled from two
            // segments and two separate tag columns.
            $engine->putMany($first);
            $engine->putMany($second);
        } else {
            $engine->putMany($first + $second);
        }

        return $engine;
    }

    /**
     * @return string[]
     */
    private function ids(SearchEngine $engine, Filter $filter): array
    {
        $ids = array_map(
            static fn($hit): string => $hit->id,
            $engine->search('', filters: $filter)->hits
        );

        sort($ids);

        return $ids;
    }

    // =========================================================================
    // Filtering
    // =========================================================================

    #[Test]
    public function eq_asks_whether_the_list_holds_the_value(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(['a', 'c', 'e'], $this->ids($engine, Filter::eq('tags', 'summer')));
        $this->assertSame(['a', 'b'], $this->ids($engine, Filter::eq('tags', 'luxury')));
        $this->assertSame(['b'], $this->ids($engine, Filter::eq('tags', 'winter')));
    }

    #[Test]
    public function a_tag_given_unwrapped_is_still_a_tag(): void
    {
        // Document 'e' arrived with `'tags' => 'summer'` rather than a list. A
        // product with one tag is not a different shape of product.
        $this->assertContains('e', $this->ids($this->catalogue(), Filter::eq('tags', 'summer')));
    }

    #[Test]
    public function an_unknown_tag_matches_nothing_rather_than_failing(): void
    {
        $this->assertSame([], $this->ids($this->catalogue(), Filter::eq('tags', 'autumn')));
    }

    #[Test]
    public function two_tags_are_and_ed_by_the_filter_tree(): void
    {
        $this->assertSame(
            ['a'],
            $this->ids($this->catalogue(), Filter::all(
                Filter::eq('tags', 'summer'),
                Filter::eq('tags', 'luxury'),
            ))
        );
    }

    #[Test]
    public function in_is_the_union(): void
    {
        $this->assertSame(
            ['a', 'b', 'c', 'e'],
            $this->ids($this->catalogue(), Filter::in('tags', ['summer', 'winter']))
        );
    }

    #[Test]
    public function neq_excludes_the_untagged_and_not_does_not(): void
    {
        $engine = $this->catalogue();

        // The same SQL reading as everywhere else: a comparison against
        // nothing is not true, so 'd' — which has no tags at all — is not
        // "tagged something other than summer". `not()` is plain set
        // complement, and includes it.
        $this->assertSame(['b'], $this->ids($engine, Filter::neq('tags', 'summer')));
        $this->assertSame(['b', 'd'], $this->ids($engine, Filter::not(Filter::eq('tags', 'summer'))));
    }

    #[Test]
    public function not_in_follows_the_same_rule(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(['b'], $this->ids($engine, Filter::notIn('tags', ['summer'])));
        $this->assertSame([], $this->ids($engine, Filter::notIn('tags', ['summer', 'winter', 'luxury'])));
    }

    #[Test]
    public function exists_and_missing_read_the_empty_range(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(['a', 'b', 'c', 'e'], $this->ids($engine, Filter::exists('tags')));
        $this->assertSame(['d'], $this->ids($engine, Filter::missing('tags')));
    }

    #[Test]
    public function a_tag_filter_combines_with_the_rest(): void
    {
        $this->assertSame(
            ['a'],
            $this->ids($this->catalogue(), Filter::all(
                Filter::eq('tags', 'summer'),
                Filter::eq('brand', 'Nike'),
            ))
        );
    }

    #[Test]
    public function a_tag_filter_narrows_a_query(): void
    {
        $engine = $this->catalogue();

        $result = $engine->search('leather', filters: Filter::eq('tags', 'winter'));

        $this->assertSame(1, $result->total);
        $this->assertSame('b', $result->hits[0]->id);
    }

    #[Test]
    public function a_range_operator_on_tags_is_refused(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('tags');

        $this->catalogue()->search('', filters: Filter::gt('tags', 'summer'));
    }

    #[Test]
    public function a_list_as_a_single_comparand_is_refused_with_the_fix(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('use in() for several');

        $this->catalogue()->search('', filters: Filter::eq('tags', ['summer', 'winter']));
    }

    // =========================================================================
    // Faceting
    // =========================================================================

    #[Test]
    public function counts_add_up_to_more_than_the_documents(): void
    {
        $result = $this->catalogue()->search('', facets: ['tags']);

        $this->assertSame(5, $result->total);
        $this->assertSame(['summer' => 3, 'luxury' => 2, 'winter' => 1], $result->facets['tags']);
    }

    #[Test]
    public function a_facet_is_counted_over_the_matches(): void
    {
        $result = $this->catalogue()->search('', filters: Filter::eq('brand', 'Nike'), facets: ['tags']);

        $this->assertSame(2, $result->total);
        $this->assertSame(['luxury' => 2, 'summer' => 1, 'winter' => 1], $result->facets['tags']);
    }

    #[Test]
    public function a_tag_facet_can_exclude_its_own_clause(): void
    {
        // The e-commerce case: a shopper has picked `summer`, and the facet
        // still has to show that `winter` exists to switch to.
        $result = $this->catalogue()->search(
            '',
            filters: Filter::in('tags', ['summer'])->tag('tags'),
            facets:  ['tags' => Facet::terms(exclude: 'tags')],
        );

        $this->assertSame(3, $result->total);
        $this->assertSame(['summer' => 3, 'luxury' => 2, 'winter' => 1], $result->facets['tags']);
    }

    #[Test]
    public function every_other_clause_still_narrows_a_disjunctive_tag_facet(): void
    {
        $result = $this->catalogue()->search(
            '',
            filters: Filter::all(
                Filter::eq('brand', 'Nike'),
                Filter::in('tags', ['summer'])->tag('tags'),
            ),
            facets: ['tags' => Facet::terms(exclude: 'tags')],
        );

        // Nike products of every tag — not every product of every tag.
        $this->assertSame(1, $result->total);
        $this->assertSame(['luxury' => 2, 'summer' => 1, 'winter' => 1], $result->facets['tags']);
    }

    #[Test]
    public function a_tag_facet_can_be_limited(): void
    {
        $result = $this->catalogue()->search('', facets: ['tags' => Facet::terms(size: 2)]);

        $this->assertSame(['summer' => 3, 'luxury' => 2], $result->facets['tags']);
    }

    #[Test]
    public function statistics_are_refused_on_a_tag_field(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('not a numeric column');

        $this->catalogue()->search('', facets: ['tags' => Facet::stats('tags')]);
    }

    // =========================================================================
    // Several segments, and a merge
    // =========================================================================

    #[Test]
    public function tag_columns_of_several_segments_are_added_together(): void
    {
        $engine = $this->catalogue(split: true);

        $this->assertSame(2, $engine->stats()['segments']);
        $this->assertSame(['a', 'c', 'e'], $this->ids($engine, Filter::eq('tags', 'summer')));
        $this->assertSame(
            ['summer' => 3, 'luxury' => 2, 'winter' => 1],
            $engine->search('', facets: ['tags'])->facets['tags']
        );
    }

    #[Test]
    public function a_merge_changes_nothing_a_caller_can_see(): void
    {
        $engine = $this->catalogue(split: true);

        $before = $engine->search('', filters: Filter::eq('tags', 'summer'), facets: ['tags']);
        $engine->optimize();
        $after = $engine->search('', filters: Filter::eq('tags', 'summer'), facets: ['tags']);

        $this->assertSame(1, $engine->stats()['segments']);
        $this->assertSame($before->total, $after->total);
        $this->assertSame($before->facets['tags'], $after->facets['tags']);
        $this->assertSame(['a', 'c', 'e'], $this->ids($engine, Filter::eq('tags', 'summer')));
    }

    #[Test]
    public function deleting_a_document_removes_it_from_the_counts(): void
    {
        $engine = $this->catalogue(split: true);
        $engine->delete('a');

        // Ties break by value, so `luxury` precedes `winter`.
        $this->assertSame(
            ['summer' => 2, 'luxury' => 1, 'winter' => 1],
            $engine->search('', facets: ['tags'])->facets['tags']
        );
        $this->assertSame(['c', 'e'], $this->ids($engine, Filter::eq('tags', 'summer')));
    }

    #[Test]
    public function replacing_a_document_replaces_its_tags(): void
    {
        $engine = $this->catalogue();
        $engine->put('a', ['title' => 'Brown leather shoe', 'tags' => ['winter'], 'brand' => 'Nike']);

        $this->assertSame(['c', 'e'], $this->ids($engine, Filter::eq('tags', 'summer')));
        $this->assertSame(['a', 'b'], $this->ids($engine, Filter::eq('tags', 'winter')));
    }

    // =========================================================================
    // The schema, declared and inferred
    // =========================================================================

    #[Test]
    public function a_list_is_inferred_as_tags_and_is_filterable_without_a_schema(): void
    {
        // The turnkey path: nothing declared at all — not an empty
        // declaration, which is a different thing and suppresses inference.
        $engine = $this->fill(SearchEngine::open($this->dir));

        $this->assertSame('tags', $engine->schema()->typeOf('tags'));
        $this->assertSame(['a', 'c', 'e'], $this->ids($engine, Filter::eq('tags', 'summer')));
        $this->assertSame(
            ['summer' => 3, 'luxury' => 2, 'winter' => 1],
            $engine->search('', facets: ['tags'])->facets['tags']
        );
    }

    #[Test]
    public function a_list_of_prose_stays_text(): void
    {
        // Repetition is what earns a column, exactly as for a bare string. A
        // list of paragraphs is prose in an array.
        $engine = SearchEngine::open($this->dir);
        $engine->put('a', ['paragraphs' => [str_repeat('long prose ', 12), str_repeat('more of it ', 12)]]);

        $this->assertSame('text', $engine->schema()->typeOf('paragraphs'));

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('No filterable column');

        $engine->search('', filters: Filter::eq('paragraphs', 'x'));
    }

    #[Test]
    public function tags_are_searchable_as_well_as_filterable(): void
    {
        $engine = $this->catalogue();

        // A tag contributes terms like any other indexed field, so a shopper
        // typing it in the search box finds it.
        $this->assertSame(3, $engine->search('summer')->total);
    }

    #[Test]
    public function a_tag_field_can_be_declared_unfilterable(): void
    {
        $engine = $this->catalogue(schema: Schema::make()
            ->text('title')
            ->tags('tags', filterable: false)
            ->keyword('brand'));

        // Still searchable, and no column: a catalogue with thousands of tags
        // nobody filters on should not pay for the column.
        $this->assertSame(3, $engine->search('summer')->total);

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('No filterable column');

        $engine->search('', filters: Filter::eq('tags', 'summer'));
    }

    #[Test]
    public function tags_come_back_in_the_document_as_they_went_in(): void
    {
        $engine = $this->catalogue();

        $this->assertSame(['summer', 'luxury'], $engine->get('a')['tags']);
        $this->assertArrayNotHasKey('tags', $engine->get('d'));
    }
}
