<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The filter tree on its own, with no index anywhere near it.
 *
 * That it can be tested this way is the point of the design: a Filter is a
 * value object, so its shape, its validation and its serialisation are settled
 * here, and only the translation into bitmaps needs a segment.
 *
 * Most of what is asserted is a refusal. `fromArray()` is meant to be pointed
 * at an HTTP request body, and a misspelled operator that silently dropped its
 * clause would *widen* a search instead of narrowing it — the failure nobody
 * notices until someone sees a product they should not have.
 */
class FilterTest extends TestCase
{
    // =========================================================================
    // Shape
    // =========================================================================

    #[Test]
    public function a_leaf_holds_its_field_operator_and_value(): void
    {
        $filter = Filter::eq('brand', 'Nike');

        $this->assertFalse($filter->isGroup());
        $this->assertSame('eq', $filter->operator);
        $this->assertSame('brand', $filter->field);
        $this->assertSame('Nike', $filter->value);
        $this->assertSame([], $filter->children);
    }

    #[Test]
    public function a_group_holds_children_and_no_field(): void
    {
        $filter = Filter::all(Filter::eq('brand', 'Nike'), Filter::gt('stock', 0));

        $this->assertTrue($filter->isGroup());
        $this->assertNull($filter->field);
        $this->assertCount(2, $filter->children);
    }

    #[Test]
    public function between_carries_its_bounds_as_a_pair(): void
    {
        $this->assertSame([50, 300], Filter::between('price', 50, 300)->value);
    }

    #[Test]
    public function groups_nest_arbitrarily(): void
    {
        $filter = Filter::all(
            Filter::eq('active', true),
            Filter::any(
                Filter::all(Filter::eq('brand', 'Nike'), Filter::lt('price', 100)),
                Filter::eq('brand', 'Adidas'),
            ),
            Filter::not(Filter::exists('discontinued_at')),
        );

        $this->assertCount(3, $filter->children);
        $this->assertSame('any', $filter->children[1]->operator);
        $this->assertSame('all', $filter->children[1]->children[0]->operator);
    }

    #[Test]
    public function a_filter_is_immutable(): void
    {
        $filter = Filter::eq('brand', 'Nike');
        $tagged = $filter->tag('brand');

        $this->assertNull($filter->tag, 'tagging must not mutate in place');
        $this->assertSame('brand', $tagged->tag);
        $this->assertSame('Nike', $tagged->value, 'and must keep everything else');
    }

    // =========================================================================
    // The empty group is deliberate
    // =========================================================================

    #[Test]
    public function an_empty_all_is_the_identity(): void
    {
        // So that a filter assembled by a loop which adds no clauses needs no
        // special case at the call site.
        $this->assertSame([], Filter::all()->children);
        $this->assertSame('all', Filter::all()->operator);
    }

    #[Test]
    public function an_empty_any_is_kept_as_written(): void
    {
        // It means "one of these zero things", which is nothing — and the
        // compiler is where that becomes an empty set.
        $this->assertSame([], Filter::any()->children);
    }

    #[Test]
    public function nothing_normalises_to_nothing(): void
    {
        $this->assertNull(Filter::normalise([]));
        $this->assertSame('eq', Filter::normalise(Filter::eq('a', 1))->operator);
    }

    // =========================================================================
    // What the builder refuses
    // =========================================================================

    #[Test]
    public function a_filter_must_name_a_field(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('must name a field');

        Filter::eq('', 'Nike');
    }

    #[Test]
    public function in_with_no_values_is_refused_rather_than_defined(): void
    {
        // Matching nothing and matching everything are both defensible, and
        // code building in($field, $selected) from a form would silently get
        // whichever one it did not want.
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('Leave the filter out entirely');

        Filter::in('brand', []);
    }

    #[Test]
    public function a_reversed_range_is_refused(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('can never match');

        Filter::between('price', 300, 50);
    }

    #[Test]
    public function an_empty_tag_is_refused(): void
    {
        $this->expectException(FilterException::class);

        Filter::eq('brand', 'Nike')->tag('');
    }

    // =========================================================================
    // Tags
    // =========================================================================

    #[Test]
    public function without_tag_lifts_the_tagged_clause_and_keeps_the_others(): void
    {
        // The disjunctive-facet mechanism: once a shopper picks Nike, the brand
        // facet has to be counted as though the brand clause were absent, or
        // they can never see Adidas to switch to it. Their *other* choices must
        // still narrow the counts.
        $filter = Filter::all(
            Filter::eq('active', true),
            Filter::in('brand', ['Nike'])->tag('brand'),
            Filter::in('category', ['Sneakers'])->tag('category'),
        );

        $withoutBrand = $filter->withoutTag('brand');

        $this->assertNotNull($withoutBrand);
        $this->assertCount(2, $withoutBrand->children);
        $this->assertSame('active', $withoutBrand->children[0]->field);
        $this->assertSame('category', $withoutBrand->children[1]->field);
    }

    #[Test]
    public function without_tag_reaches_into_nested_groups(): void
    {
        $filter = Filter::all(
            Filter::eq('active', true),
            Filter::any(
                Filter::eq('brand', 'Nike')->tag('brand'),
                Filter::eq('brand', 'Adidas')->tag('brand'),
            ),
        );

        $stripped = $filter->withoutTag('brand');

        $this->assertNotNull($stripped);
        $this->assertCount(1, $stripped->children, 'the emptied any dissolves');
        $this->assertSame('active', $stripped->children[0]->field);
    }

    #[Test]
    public function stripping_the_only_clause_leaves_an_empty_all(): void
    {
        $filter = Filter::all(Filter::in('brand', ['Nike'])->tag('brand'));

        $stripped = $filter->withoutTag('brand');

        $this->assertNotNull($stripped, 'an all with nothing left still matches everything');
        $this->assertSame([], $stripped->children);
    }

    #[Test]
    public function stripping_a_whole_tagged_tree_leaves_nothing(): void
    {
        $filter = Filter::all(Filter::eq('brand', 'Nike'))->tag('brand');

        $this->assertNull($filter->withoutTag('brand'));
    }

    #[Test]
    public function an_unknown_tag_changes_nothing(): void
    {
        $filter = Filter::all(Filter::eq('active', true), Filter::eq('brand', 'Nike')->tag('brand'));

        $this->assertSame($filter->toArray(), $filter->withoutTag('colour')?->toArray());
    }

    #[Test]
    public function tags_lists_every_tag_in_the_tree_once(): void
    {
        $filter = Filter::all(
            Filter::eq('active', true),
            Filter::eq('brand', 'Nike')->tag('brand'),
            Filter::any(
                Filter::eq('category', 'Shoes')->tag('category'),
                Filter::eq('category', 'Sport')->tag('category'),
            ),
        );

        $this->assertSame(['brand', 'category'], $filter->tags());
        $this->assertSame([], Filter::eq('active', true)->tags());
    }

    // =========================================================================
    // fromArray, pointed at untrusted input
    // =========================================================================

    #[Test]
    public function a_nested_structure_round_trips(): void
    {
        $filter = Filter::all(
            Filter::eq('active', true),
            Filter::between('price', 50, 300),
            Filter::in('category', ['Shoes', 'Sport']),
            Filter::any(
                Filter::eq('brand', 'Nike')->tag('brand'),
                Filter::missing('brand'),
            ),
            Filter::not(Filter::exists('discontinued_at')),
        );

        // Through JSON, because that is where this structure comes from.
        $decoded = json_decode((string) json_encode($filter->toArray()), true);

        $this->assertIsArray($decoded);
        $this->assertSame($filter->toArray(), Filter::fromArray($decoded)->toArray());
    }

    #[Test]
    public function a_flat_list_of_clauses_is_combined_with_all(): void
    {
        $filter = Filter::fromArray([
            ['op' => 'eq', 'field' => 'active', 'value' => true],
            ['op' => 'gt', 'field' => 'stock', 'value' => 0],
        ]);

        $this->assertSame('all', $filter->operator);
        $this->assertCount(2, $filter->children);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function aliases(): iterable
    {
        // A front end writes '<=' rather than 'lte', and there is no reason to
        // make it translate.
        yield 'equals'         => ['=', 'eq'];
        yield 'double equals'  => ['==', 'eq'];
        yield 'not equals'     => ['!=', 'neq'];
        yield 'diamond'        => ['<>', 'neq'];
        yield 'greater'        => ['>', 'gt'];
        yield 'greater equal'  => ['>=', 'gte'];
        yield 'less'           => ['<', 'lt'];
        yield 'less equal'     => ['<=', 'lte'];
        yield 'not in spaced'  => ['not in', 'notIn'];
        yield 'not in snake'   => ['not_in', 'notIn'];
    }

    #[Test]
    #[DataProvider('aliases')]
    public function an_operator_may_be_spelled_the_way_a_front_end_writes_it(
        string $written,
        string $canonical,
    ): void {
        $value  = $canonical === 'notIn' ? ['Nike'] : 1;
        $filter = Filter::fromArray(['op' => $written, 'field' => 'x', 'value' => $value]);

        $this->assertSame($canonical, $filter->operator);
    }

    #[Test]
    public function and_and_or_are_accepted_as_group_names(): void
    {
        $this->assertSame('all', Filter::fromArray(['op' => 'and', 'filters' => []])->operator);
        $this->assertSame('any', Filter::fromArray(['op' => 'OR', 'filters' => []])->operator);
    }

    #[Test]
    public function a_tag_survives_fromArray(): void
    {
        $filter = Filter::fromArray([
            'op'    => 'in',
            'field' => 'brand',
            'value' => ['Nike'],
            'tag'   => 'brand',
        ]);

        $this->assertSame('brand', $filter->tag);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformed(): iterable
    {
        yield 'unknown operator' => [
            ['op' => 'lke', 'field' => 'title', 'value' => 'x'],
            "Unknown filter operator 'lke'",
        ];
        yield 'no operator' => [
            ['field' => 'title', 'value' => 'x'],
            "missing its 'op'",
        ];
        yield 'operator not a string' => [
            ['op' => 12, 'field' => 'title', 'value' => 'x'],
            "must be a string",
        ];
        yield 'no field' => [
            ['op' => 'eq', 'value' => 'x'],
            "missing its 'field'",
        ];
        yield 'empty field' => [
            ['op' => 'eq', 'field' => '', 'value' => 'x'],
            "missing its 'field'",
        ];
        yield 'no value' => [
            ['op' => 'eq', 'field' => 'title'],
            "missing its 'value'",
        ];
        yield 'in without a list' => [
            ['op' => 'in', 'field' => 'brand', 'value' => 'Nike'],
            'expects a list of values',
        ];
        yield 'in with an empty list' => [
            ['op' => 'in', 'field' => 'brand', 'value' => []],
            'Leave the filter out entirely',
        ];
        yield 'between with one bound' => [
            ['op' => 'between', 'field' => 'price', 'value' => [50]],
            'exactly two values',
        ];
        yield 'between with a map' => [
            ['op' => 'between', 'field' => 'price', 'value' => ['min' => 50, 'max' => 300]],
            'exactly two values',
        ];
        yield 'group without filters' => [
            ['op' => 'all'],
            "expects a list under 'filters'",
        ];
        yield 'group with a map of filters' => [
            ['op' => 'all', 'filters' => ['a' => ['op' => 'exists', 'field' => 'x']]],
            "expects a list under 'filters'",
        ];
        yield 'group with a scalar child' => [
            ['op' => 'all', 'filters' => ['nope']],
            'to be an array',
        ];
        yield 'not with two children' => [
            ['op' => 'not', 'filters' => [
                ['op' => 'exists', 'field' => 'a'],
                ['op' => 'exists', 'field' => 'b'],
            ]],
            'negates exactly one filter, got 2',
        ];
        yield 'not with no children' => [
            ['op' => 'not', 'filters' => []],
            'negates exactly one filter, got 0',
        ];
        yield 'a map that is not a clause' => [
            ['brand' => 'Nike'],
            "missing its 'op'",
        ];
        yield 'a list of scalars' => [
            ['Nike', 'Adidas'],
            'must be an array',
        ];
    }

    #[Test]
    #[DataProvider('malformed')]
    public function malformed_input_is_refused_and_says_why(array $data, string $expected): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage($expected);

        Filter::fromArray($data);
    }

    #[Test]
    public function a_deeply_nested_payload_is_refused_rather_than_exhausting_the_stack(): void
    {
        // fromArray is meant to be pointed at a request body, so its depth is
        // an attack surface, not a style question.
        $data = ['op' => 'exists', 'field' => 'x'];

        for ($i = 0; $i <= Filter::MAX_DEPTH; $i++) {
            $data = ['op' => 'all', 'filters' => [$data]];
        }

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('nests deeper than');

        Filter::fromArray($data);
    }

    #[Test]
    public function exists_and_missing_carry_no_value(): void
    {
        $this->assertSame(
            ['op' => 'exists', 'field' => 'discontinued_at'],
            Filter::exists('discontinued_at')->toArray()
        );
        $this->assertArrayNotHasKey('value', Filter::missing('brand')->toArray());
    }
}
