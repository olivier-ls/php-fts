<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\Bitset;
use Ols\PhpFts\Index\NumericColumn;
use Ols\PhpFts\Index\NumericColumnWriter;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\SegmentWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A column of numbers: one fixed-width slot per document, addressed by document
 * number, answering filters as document sets.
 */
class NumericColumnTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_col_' . uniqid();
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
     * @param array<int, float|null> $values document number => value
     */
    private function column(array $values, ?int $documentCount = null): NumericColumn
    {
        $writer = new NumericColumnWriter();

        foreach ($values as $document => $value) {
            $writer->add($document, $value);
        }

        $documentCount ??= count($values);

        $path    = $this->dir . '/seg_' . uniqid() . '.fts';
        $segment = SegmentWriter::create($path);
        $segment->addSection('dv.price', $writer->finish($documentCount));
        $segment->commit();

        return NumericColumn::open(SegmentReader::open($path), 'dv.price');
    }

    // =========================================================================
    // Values in, values out
    // =========================================================================

    #[Test]
    public function values_round_trip(): void
    {
        $column = $this->column([129.90, 89.0, 149.50, 0.0]);

        $this->assertSame(4, $column->count());
        $this->assertSame(129.90, $column->get(0));
        $this->assertSame(89.0, $column->get(1));
        $this->assertSame(149.50, $column->get(2));
        $this->assertSame(0.0, $column->get(3));
    }

    #[Test]
    public function negative_and_very_large_values_survive(): void
    {
        $column = $this->column([-42.5, 1.0E+15, -0.0001]);

        $this->assertSame(-42.5, $column->get(0));
        $this->assertSame(1.0E+15, $column->get(1));
        $this->assertSame(-0.0001, $column->get(2));
    }

    // =========================================================================
    // Zero is a value; absent is not
    // =========================================================================

    #[Test]
    public function a_missing_value_is_not_zero(): void
    {
        // The distinction shops depend on. Document 1 has a stock of zero;
        // document 2 has no stock field at all. "stock >= 0" must keep the
        // first and drop the second.
        $column = $this->column([5.0, 0.0, null, 3.0]);

        $this->assertSame(0.0, $column->get(1));
        $this->assertNull($column->get(2));

        $this->assertSame([0, 1, 3], $column->exists()->toArray());
        $this->assertSame([2], $column->missing()->toArray());

        $this->assertSame([0, 1, 3], $column->range(0.0, null)->toArray());
    }

    #[Test]
    public function documents_after_the_last_value_still_have_a_slot(): void
    {
        // A field added late in a catalogue: the first documents have it, the
        // rest do not, and the column still has to be as long as the segment.
        $column = $this->column([10.0, 20.0], documentCount: 5);

        $this->assertSame(5, $column->count());
        $this->assertSame([0, 1], $column->exists()->toArray());
        $this->assertNull($column->get(4));
    }

    // =========================================================================
    // Filtering
    // =========================================================================

    #[Test]
    public function equality_selects_exact_values(): void
    {
        $column = $this->column([10.0, 20.0, 10.0, 30.0]);

        $this->assertSame([0, 2], $column->equals(10.0)->toArray());
        $this->assertSame([], $column->equals(15.0)->toArray());
    }

    #[Test]
    public function a_range_covers_every_comparison(): void
    {
        $column = $this->column([10.0, 20.0, 30.0, 40.0, 50.0]);

        $this->assertSame([0, 1, 2], $column->range(null, 30.0)->toArray(), 'price <= 30');
        $this->assertSame([0, 1], $column->range(null, 30.0, maxInclusive: false)->toArray(), 'price < 30');
        $this->assertSame([2, 3, 4], $column->range(30.0, null)->toArray(), 'price >= 30');
        $this->assertSame([3, 4], $column->range(30.0, null, minInclusive: false)->toArray(), 'price > 30');
        $this->assertSame([1, 2, 3], $column->range(20.0, 40.0)->toArray(), 'between');
        $this->assertSame([2], $column->range(20.0, 40.0, false, false)->toArray(), 'strictly between');
    }

    #[Test]
    public function a_filter_returns_a_set_that_combines_with_others(): void
    {
        // The whole reason filtering returns a Bitset: clauses combine without
        // touching the column again.
        $price = $this->column([100.0, 200.0, 300.0, 400.0]);

        $affordable = $price->range(null, 300.0);
        $notCheap   = $price->range(150.0, null);

        $this->assertSame([1, 2], $affordable->and($notCheap)->toArray());
    }

    #[Test]
    public function filtering_ignores_documents_without_a_value(): void
    {
        $column = $this->column([10.0, null, 30.0]);

        $this->assertSame([0, 2], $column->range(null, 100.0)->toArray());
        $this->assertSame([], $column->equals(0.0)->toArray(), 'an empty slot is not a zero');
    }

    // =========================================================================
    // Statistics, for a price-range facet
    // =========================================================================

    #[Test]
    public function stats_describe_the_whole_column(): void
    {
        $stats = $this->column([10.0, 20.0, 30.0, null])->stats();

        $this->assertSame(3, $stats['count']);
        $this->assertSame(10.0, $stats['min']);
        $this->assertSame(30.0, $stats['max']);
        $this->assertSame(60.0, $stats['sum']);
        $this->assertSame(20.0, $stats['avg']);
    }

    #[Test]
    public function stats_can_be_restricted_to_the_documents_that_matched(): void
    {
        // What a facet actually needs: the price range of the search results,
        // not of the catalogue.
        $column  = $this->column([10.0, 20.0, 30.0, 40.0, 50.0]);
        $matches = Bitset::of([1, 3], 5);

        $stats = $column->stats($matches);

        $this->assertSame(2, $stats['count']);
        $this->assertSame(20.0, $stats['min']);
        $this->assertSame(40.0, $stats['max']);
        $this->assertSame(30.0, $stats['avg']);
    }

    #[Test]
    public function stats_of_nothing_are_null_rather_than_zero(): void
    {
        $stats = $this->column([10.0, 20.0])->stats(Bitset::empty(2));

        $this->assertSame(0, $stats['count']);
        $this->assertNull($stats['min']);
        $this->assertNull($stats['max']);
        $this->assertNull($stats['avg']);
    }

    // =========================================================================
    // At scale, and across the chunk boundary
    // =========================================================================

    #[Test]
    public function a_column_larger_than_one_chunk_is_read_correctly(): void
    {
        // The scan works in chunks of 4 096 to bound memory, so the boundary is
        // where an off-by-one would hide.
        $values = [];

        for ($i = 0; $i < 10000; $i++) {
            $values[$i] = $i % 2 === 0 ? (float) $i : null;
        }

        $column = $this->column($values);

        $this->assertSame(10000, $column->count());
        $this->assertSame(5000, $column->exists()->count());

        foreach ([0, 4094, 4096, 4098, 8190, 8192, 9998] as $document) {
            $this->assertSame((float) $document, $column->get($document), "document $document");
        }

        $this->assertNull($column->get(4095));
        $this->assertNull($column->get(8191));

        $selected = $column->range(4090.0, 4100.0);
        $this->assertSame([4090, 4092, 4094, 4096, 4098, 4100], $selected->toArray());
    }

    #[Test]
    public function a_column_of_twenty_thousand_documents_stays_small(): void
    {
        $values = [];

        for ($i = 0; $i < 20000; $i++) {
            $values[$i] = (float) ($i % 500);
        }

        $writer = new NumericColumnWriter();
        foreach ($values as $document => $value) {
            $writer->add($document, $value);
        }

        $bytes = $writer->finish(20000);

        // Eight bytes of value per document, plus one bit of presence, plus a
        // twelve-byte header.
        $this->assertSame(12 + 2500 + 160000, strlen($bytes));
    }

    // =========================================================================
    // What it refuses
    // =========================================================================

    #[Test]
    public function values_must_be_added_in_document_order(): void
    {
        $writer = new NumericColumnWriter();
        $writer->add(5, 1.0);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/document order/i');

        $writer->add(3, 2.0);
    }

    #[Test]
    public function a_document_count_smaller_than_the_data_is_refused(): void
    {
        $writer = new NumericColumnWriter();
        $writer->add(10, 1.0);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/declares/i');

        $writer->finish(5);
    }

    #[Test]
    public function a_section_that_is_not_a_column_is_refused(): void
    {
        $path    = $this->dir . '/seg_wrong.fts';
        $segment = SegmentWriter::create($path);
        $segment->addSection('dv.price', str_repeat('not a column', 20));
        $segment->commit();

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/numeric column/i');

        NumericColumn::open(SegmentReader::open($path), 'dv.price');
    }
}
