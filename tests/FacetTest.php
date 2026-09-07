<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Facet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The facet declaration, with no index anywhere near it.
 *
 * Small on purpose: a Facet only carries what to count, how much of it, and
 * which filter clause to ignore while counting. The counting itself belongs to
 * the columns, and the disjunction to the search loop.
 */
class FacetTest extends TestCase
{
    #[Test]
    public function terms_defaults_to_a_bounded_list(): void
    {
        $facet = Facet::terms();

        $this->assertSame('terms', $facet->kind);
        $this->assertSame(20, $facet->size);
        $this->assertNull($facet->field);
        $this->assertNull($facet->exclude);
    }

    #[Test]
    public function stats_has_no_size(): void
    {
        $this->assertSame('stats', Facet::stats()->kind);
        $this->assertSame(0, Facet::stats()->size);
    }

    #[Test]
    public function a_facet_reads_the_name_it_is_registered_under_unless_told_otherwise(): void
    {
        // So the same column can be counted twice under two names, and a name
        // in a template need not be a column name.
        $this->assertSame('brand', Facet::terms()->fieldFor('brand'));
        $this->assertSame('price', Facet::stats('price')->fieldFor('cheapest'));
    }

    #[Test]
    public function a_negative_size_is_refused(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('cannot be negative');

        Facet::terms(size: -1);
    }

    #[Test]
    public function an_empty_excluded_tag_is_refused(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('empty tag');

        Facet::terms(exclude: '');
    }

    // =========================================================================
    // limit()
    // =========================================================================

    #[Test]
    public function limit_cuts_a_term_list_and_keeps_its_order(): void
    {
        $counted = ['Nike' => 42, 'Adidas' => 31, 'Puma' => 12, 'Asics' => 4];

        $this->assertSame(
            ['Nike' => 42, 'Adidas' => 31],
            Facet::terms(size: 2)->limit($counted)
        );
    }

    #[Test]
    public function a_size_of_zero_keeps_everything(): void
    {
        $counted = ['Nike' => 42, 'Adidas' => 31];

        $this->assertSame($counted, Facet::terms(size: 0)->limit($counted));
    }

    #[Test]
    public function statistics_pass_through_limit_untouched(): void
    {
        $stats = ['count' => 4, 'min' => 12.5, 'max' => 149.0, 'sum' => 400.0, 'avg' => 100.0];

        $this->assertSame($stats, Facet::stats()->limit($stats));
    }

    // =========================================================================
    // normalise()
    // =========================================================================

    #[Test]
    public function a_list_of_field_names_becomes_automatic_facets(): void
    {
        $normalised = Facet::normalise(['brand', 'price']);

        $this->assertSame(['brand', 'price'], array_keys($normalised));
        $this->assertSame('auto', $normalised['brand']->kind);
        $this->assertSame('brand', $normalised['brand']->fieldFor('brand'));
    }

    #[Test]
    public function names_and_facets_may_be_mixed_in_one_array(): void
    {
        // Because `['brand', 'cheapest' => Facet::stats('price')]` is a
        // reasonable thing to write.
        $normalised = Facet::normalise([
            'brand',
            'cheapest' => Facet::stats('price'),
            'kind'     => 'category',
        ]);

        $this->assertSame(['brand', 'cheapest', 'kind'], array_keys($normalised));
        $this->assertSame('auto', $normalised['brand']->kind);
        $this->assertSame('stats', $normalised['cheapest']->kind);
        $this->assertSame('price', $normalised['cheapest']->fieldFor('cheapest'));
        $this->assertSame('category', $normalised['kind']->fieldFor('kind'));
    }

    #[Test]
    public function nothing_normalises_to_nothing(): void
    {
        $this->assertSame([], Facet::normalise([]));
    }

    #[Test]
    public function a_facet_that_is_neither_a_facet_nor_a_field_name_is_refused(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage("Facet 'brand' must be a Facet or a field name, got int");

        Facet::normalise(['brand' => 20]);
    }

    #[Test]
    public function an_unnamed_facet_must_be_a_field_name(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('must be a field name');

        Facet::normalise([Facet::terms()]);
    }
}
