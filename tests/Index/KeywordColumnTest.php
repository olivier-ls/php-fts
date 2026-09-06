<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\Bitset;
use Ols\PhpFts\Index\KeywordColumn;
use Ols\PhpFts\Index\KeywordColumnWriter;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\SegmentWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A column of exact string values, dictionary-encoded — brands, categories,
 * colours. The structure facets are counted from.
 */
class KeywordColumnTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_kw_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    /**
     * @param array<int, string|null> $values document number => value
     */
    private function column(array $values, ?int $documentCount = null): KeywordColumn
    {
        $writer = new KeywordColumnWriter();

        foreach ($values as $document => $value) {
            $writer->add($document, $value);
        }

        $sections = $writer->finish($documentCount ?? count($values));

        $path    = $this->dir . '/seg_' . uniqid() . '.fts';
        $segment = SegmentWriter::create($path);
        $segment->addSection('dv.brand', $sections['ordinals']);
        $segment->addSection('dv.brand.values', $sections['values']);
        $segment->commit();

        return KeywordColumn::open(SegmentReader::open($path), 'dv.brand');
    }

    // =========================================================================
    // Values in, values out
    // =========================================================================

    #[Test]
    public function values_round_trip(): void
    {
        $column = $this->column(['Adidas', 'Puma', 'Adidas', 'Nike']);

        $this->assertSame('Adidas', $column->get(0));
        $this->assertSame('Puma', $column->get(1));
        $this->assertSame('Adidas', $column->get(2));
        $this->assertSame('Nike', $column->get(3));
    }

    #[Test]
    public function repeated_values_are_stored_once(): void
    {
        $column = $this->column(array_fill(0, 100, 'Adidas'));

        $this->assertSame(100, $column->count());
        $this->assertSame(1, $column->cardinality(), 'one distinct value for a hundred documents');
    }

    #[Test]
    public function a_missing_value_is_distinguished_from_a_present_one(): void
    {
        $column = $this->column(['Adidas', null, 'Puma']);

        $this->assertSame([0, 2], $column->exists()->toArray());
        $this->assertSame([1], $column->missing()->toArray());
        $this->assertNull($column->get(1));
    }

    #[Test]
    public function an_empty_string_counts_as_absent(): void
    {
        // A document whose brand is "" has no brand, which is what a keyword
        // column is asked about.
        $column = $this->column(['Adidas', '', 'Puma']);

        $this->assertSame([0, 2], $column->exists()->toArray());
        $this->assertSame(2, $column->cardinality());
    }

    #[Test]
    public function values_of_any_script_round_trip(): void
    {
        $column = $this->column(['アディダス', 'Найк', 'Ελλάδα', 'Adidas']);

        $this->assertSame('アディダス', $column->get(0));
        $this->assertSame('Найк', $column->get(1));
        $this->assertSame('Ελλάδα', $column->get(2));
        $this->assertSame([0], $column->equals('アディダス')->toArray());
    }

    // =========================================================================
    // Filtering
    // =========================================================================

    #[Test]
    public function equality_selects_the_documents_carrying_a_value(): void
    {
        $column = $this->column(['Adidas', 'Puma', 'Adidas', 'Nike', null]);

        $this->assertSame([0, 2], $column->equals('Adidas')->toArray());
        $this->assertSame([3], $column->equals('Nike')->toArray());
    }

    #[Test]
    public function an_unknown_value_matches_nothing(): void
    {
        $column = $this->column(['Adidas', 'Puma']);

        $this->assertSame([], $column->equals('Reebok')->toArray());
        $this->assertSame([], $column->equals('adidas')->toArray(), 'keyword values are exact, case included');
    }

    #[Test]
    public function in_selects_any_of_several_values(): void
    {
        $column = $this->column(['Adidas', 'Puma', 'Nike', 'Adidas', 'Asics']);

        $this->assertSame([0, 1, 3], $column->in(['Adidas', 'Puma'])->toArray());
        $this->assertSame([0, 3], $column->in(['Adidas', 'Reebok'])->toArray(), 'unknown values are ignored');
        $this->assertSame([], $column->in([])->toArray());
    }

    #[Test]
    public function a_filter_returns_a_set_that_combines_with_others(): void
    {
        $column = $this->column(['Adidas', 'Puma', 'Adidas', 'Nike']);

        $adidas   = $column->equals('Adidas');
        $notFirst = Bitset::of([1, 2, 3], 4);

        $this->assertSame([2], $adidas->and($notFirst)->toArray());
    }

    // =========================================================================
    // Facets
    // =========================================================================

    #[Test]
    public function a_facet_counts_documents_per_value(): void
    {
        $column = $this->column(['Adidas', 'Puma', 'Adidas', 'Nike', 'Adidas', 'Puma']);

        $this->assertSame(['Adidas' => 3, 'Puma' => 2, 'Nike' => 1], $column->facet());
    }

    #[Test]
    public function a_facet_counts_only_the_matching_documents(): void
    {
        // What a facet is actually for: the brands present in the search
        // results, not in the catalogue.
        $column  = $this->column(['Adidas', 'Puma', 'Adidas', 'Nike', 'Adidas']);
        $matches = Bitset::of([0, 1, 3], 5);

        $this->assertSame(['Adidas' => 1, 'Puma' => 1, 'Nike' => 1], $column->facet($matches));
    }

    #[Test]
    public function a_facet_reports_the_largest_counts_first_and_can_be_capped(): void
    {
        $values = array_merge(
            array_fill(0, 10, 'Adidas'),
            array_fill(0, 5, 'Puma'),
            array_fill(0, 3, 'Nike'),
            array_fill(0, 1, 'Asics'),
        );

        $column = $this->column($values);

        $this->assertSame(['Adidas' => 10, 'Puma' => 5], $column->facet(size: 2));
    }

    #[Test]
    public function documents_without_a_value_are_not_counted(): void
    {
        $column = $this->column(['Adidas', null, null, 'Puma']);

        $this->assertSame(['Adidas' => 1, 'Puma' => 1], $column->facet());
    }

    #[Test]
    public function a_facet_over_nothing_is_empty(): void
    {
        $column = $this->column(['Adidas', 'Puma']);

        $this->assertSame([], $column->facet(Bitset::empty(2)));
    }

    // =========================================================================
    // Sizing
    // =========================================================================

    #[Test]
    public function the_ordinal_width_follows_the_number_of_distinct_values(): void
    {
        $this->assertSame(1, KeywordColumn::ordinalWidth(50));
        $this->assertSame(1, KeywordColumn::ordinalWidth(255));
        $this->assertSame(2, KeywordColumn::ordinalWidth(256));
        $this->assertSame(2, KeywordColumn::ordinalWidth(65535));
        $this->assertSame(4, KeywordColumn::ordinalWidth(65536));
    }

    #[Test]
    public function a_catalogue_of_twenty_thousand_products_with_fifty_brands_stays_small(): void
    {
        $brands = [];

        for ($i = 0; $i < 50; $i++) {
            $brands[] = 'Brand ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        }

        $writer = new KeywordColumnWriter();

        for ($i = 0; $i < 20000; $i++) {
            $writer->add($i, $brands[$i % 50]);
        }

        $sections = $writer->finish(20000);

        // One byte of ordinal per document, plus a bit of presence, plus a
        // header — against roughly 180 KB if the string were stored each time.
        $this->assertLessThan(25000, strlen($sections['ordinals']));
        $this->assertLessThan(2000, strlen($sections['values']));
    }

    #[Test]
    public function a_column_spanning_several_chunks_is_read_correctly(): void
    {
        $values = [];

        for ($i = 0; $i < 10000; $i++) {
            $values[$i] = $i % 3 === 0 ? null : 'Brand ' . ($i % 7);
        }

        $column = $this->column($values);

        $this->assertSame(10000, $column->count());
        $this->assertSame(7, $column->cardinality());

        foreach ([4094, 4095, 4096, 4097, 8191, 8192, 9999] as $document) {
            $expected = $document % 3 === 0 ? null : 'Brand ' . ($document % 7);
            $this->assertSame($expected, $column->get($document), "document $document");
        }

        $facet = $column->facet();
        $this->assertSame(array_sum($facet), $column->exists()->count());
    }

    #[Test]
    public function more_than_two_hundred_and_fifty_six_values_widen_the_ordinal(): void
    {
        $values = [];

        for ($i = 0; $i < 1000; $i++) {
            $values[$i] = 'Value ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
        }

        $column = $this->column($values);

        $this->assertSame(1000, $column->cardinality());
        $this->assertSame('Value 0999', $column->get(999));
        $this->assertSame([500], $column->equals('Value 0500')->toArray());
    }

    // =========================================================================
    // What it refuses
    // =========================================================================

    #[Test]
    public function values_must_be_added_in_document_order(): void
    {
        $writer = new KeywordColumnWriter();
        $writer->add(5, 'Adidas');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/document order/i');

        $writer->add(3, 'Puma');
    }

    #[Test]
    public function a_section_that_is_not_a_column_is_refused(): void
    {
        $path    = $this->dir . '/seg_wrong.fts';
        $segment = SegmentWriter::create($path);
        $segment->addSection('dv.brand', str_repeat('not a column', 20));
        $segment->commit();

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/keyword column/i');

        KeywordColumn::open(SegmentReader::open($path), 'dv.brand');
    }
}
