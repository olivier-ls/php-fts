<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\Bitset;
use Ols\PhpFts\Index\TagColumn;
use Ols\PhpFts\Index\TagColumnWriter;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\SegmentWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A column of *lists* of exact values — the tags of a product.
 *
 * What is worth asserting here is not that values come back out, but the
 * things the layout has to get right and that a single-valued column never
 * has to think about: a document owning a range rather than a slot, an empty
 * range meaning absence, and a facet whose counts legitimately add up to more
 * than the number of documents.
 */
class TagColumnTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_tc_' . uniqid();
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
     * @param array<int, string|array<mixed>|null> $values document number => values
     */
    private function column(array $values, ?int $documentCount = null): TagColumn
    {
        $writer = new TagColumnWriter();

        foreach ($values as $document => $value) {
            $writer->add($document, $value);
        }

        $sections = $writer->finish($documentCount ?? count($values));

        $path    = $this->dir . '/seg_' . uniqid() . '.fts';
        $segment = SegmentWriter::create($path);
        $segment->addSection('dv.tags', $sections['ordinals']);
        $segment->addSection('dv.tags.values', $sections['values']);
        $segment->commit();

        return TagColumn::open(SegmentReader::open($path), 'dv.tags');
    }

    /**
     * @return int[]
     */
    private function documents(Bitset $set): array
    {
        return iterator_to_array($set->iterate(), false);
    }

    // =========================================================================
    // Values in, values out
    // =========================================================================

    #[Test]
    public function lists_round_trip(): void
    {
        $column = $this->column([
            ['summer', 'luxury'],
            ['winter'],
            [],
            ['luxury', 'summer', 'winter'],
        ]);

        // Ascending, because ordinals are assigned in sorted value order and
        // stored sorted — which is what lets a scan stop early.
        $this->assertSame(['luxury', 'summer'], $column->get(0));
        $this->assertSame(['winter'], $column->get(1));
        $this->assertSame([], $column->get(2));
        $this->assertSame(['luxury', 'summer', 'winter'], $column->get(3));
    }

    #[Test]
    public function a_document_can_hold_more_values_than_its_neighbours(): void
    {
        // The whole reason this column exists: a variable number of values per
        // document, addressed by a range rather than a slot.
        $column = $this->column([
            ['a'],
            ['a', 'b', 'c', 'd', 'e'],
            ['e'],
        ]);

        $this->assertSame(['a'], $column->get(0));
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $column->get(1));
        $this->assertSame(['e'], $column->get(2));
        $this->assertSame(7, $column->totalValues());
        $this->assertSame(5, $column->cardinality());
    }

    #[Test]
    public function a_bare_string_is_a_list_of_one(): void
    {
        $column = $this->column(['summer', ['summer', 'winter']]);

        $this->assertSame(['summer'], $column->get(0));
        $this->assertSame([0, 1], $this->documents($column->contains('summer')));
    }

    #[Test]
    public function numbers_in_a_list_become_their_string(): void
    {
        $column = $this->column([[2024, 2025], [2025]]);

        $this->assertSame(['2024', '2025'], $column->get(0));
        $this->assertSame([0, 1], $this->documents($column->contains('2025')));
    }

    #[Test]
    public function values_are_deduplicated_within_a_document(): void
    {
        // Not cosmetic: counted twice, a facet would report more documents for
        // a value than there are documents.
        $column = $this->column([['summer', 'summer', 'summer']]);

        $this->assertSame(['summer'], $column->get(0));
        $this->assertSame(1, $column->totalValues());
        $this->assertSame(['summer' => 1], $column->facet());
    }

    #[Test]
    public function an_empty_value_is_not_a_value(): void
    {
        $column = $this->column([['summer', '', 'winter'], ['']]);

        $this->assertSame(['summer', 'winter'], $column->get(0));
        $this->assertSame([], $column->get(1));
        $this->assertSame([0], $this->documents($column->exists()));
    }

    #[Test]
    public function a_column_can_be_entirely_empty(): void
    {
        $column = $this->column([null, null, null]);

        $this->assertSame(0, $column->cardinality());
        $this->assertSame(0, $column->totalValues());
        $this->assertSame([], $this->documents($column->exists()));
        $this->assertSame([0, 1, 2], $this->documents($column->missing()));
        $this->assertSame([], $this->documents($column->contains('summer')));
        $this->assertSame([], $column->facet());
    }

    #[Test]
    public function documents_beyond_the_column_hold_nothing(): void
    {
        $column = $this->column([['summer']], documentCount: 4);

        $this->assertSame(['summer'], $column->get(0));
        $this->assertSame([], $column->get(3));
        $this->assertSame([], $column->get(9));
        $this->assertSame([], $column->get(-1));
        $this->assertSame([0], $this->documents($column->exists()));
    }

    // =========================================================================
    // Presence, which is a range of length zero
    // =========================================================================

    #[Test]
    public function absence_needs_no_bitmap(): void
    {
        $column = $this->column([['a'], null, ['b'], [], ['c']]);

        $this->assertSame([0, 2, 4], $this->documents($column->exists()));
        $this->assertSame([1, 3], $this->documents($column->missing()));
    }

    // =========================================================================
    // Filtering
    // =========================================================================

    #[Test]
    public function contains_finds_the_documents_holding_a_value(): void
    {
        $column = $this->column([
            ['summer', 'luxury'],
            ['winter', 'luxury'],
            ['summer'],
            null,
        ]);

        $this->assertSame([0, 2], $this->documents($column->contains('summer')));
        $this->assertSame([0, 1], $this->documents($column->contains('luxury')));
        $this->assertSame([1], $this->documents($column->contains('winter')));
    }

    #[Test]
    public function a_value_the_dictionary_does_not_know_matches_nothing(): void
    {
        $column = $this->column([['summer'], ['winter']]);

        $this->assertSame([], $this->documents($column->contains('autumn')));
        $this->assertSame([], $this->documents($column->contains('')));
    }

    #[Test]
    public function contains_any_is_the_union(): void
    {
        $column = $this->column([
            ['summer'],
            ['winter'],
            ['autumn'],
            null,
        ]);

        $this->assertSame([0, 1], $this->documents($column->containsAny(['summer', 'winter'])));
        $this->assertSame([0], $this->documents($column->containsAny(['summer', 'nonesuch'])));
        $this->assertSame([], $this->documents($column->containsAny([])));
        $this->assertSame([], $this->documents($column->containsAny(['nonesuch'])));
    }

    #[Test]
    public function a_conjunction_is_the_intersection_of_two_scans(): void
    {
        // Why there is no containsAll(): the filter tree already says it, and
        // two bitsets and an AND is what it compiles to.
        $column = $this->column([
            ['summer', 'luxury'],
            ['summer'],
            ['luxury'],
        ]);

        $this->assertSame(
            [0],
            $this->documents($column->contains('summer')->and($column->contains('luxury')))
        );
    }

    // =========================================================================
    // Faceting
    // =========================================================================

    #[Test]
    public function counts_add_up_to_more_than_the_documents(): void
    {
        // Correct, and the point of a tag facet: three documents carrying five
        // tags between them.
        $column = $this->column([
            ['summer', 'luxury'],
            ['summer', 'luxury'],
            ['summer'],
        ]);

        $this->assertSame(['summer' => 3, 'luxury' => 2], $column->facet());
    }

    #[Test]
    public function a_facet_counts_only_the_documents_given(): void
    {
        $column = $this->column([
            ['summer', 'luxury'],
            ['winter'],
            ['summer'],
        ]);

        $selected = Bitset::empty(3);
        $selected->set(0);
        $selected->set(1);

        $this->assertSame(['luxury' => 1, 'summer' => 1, 'winter' => 1], $column->facet($selected));
    }

    #[Test]
    public function a_facet_reports_the_largest_counts_first_and_breaks_ties_by_value(): void
    {
        $column = $this->column([
            ['common', 'beta'],
            ['common', 'alpha'],
            ['common'],
        ]);

        // `beta` and `alpha` both count 1; alphabetical order settles them, so
        // that two identical requests answer identically and a merge cannot
        // reshuffle the result.
        $this->assertSame(['common' => 3, 'alpha' => 1, 'beta' => 1], $column->facet());
    }

    #[Test]
    public function a_facet_can_be_limited(): void
    {
        $column = $this->column([
            ['a', 'b', 'c'],
            ['a', 'b'],
            ['a'],
        ]);

        $this->assertSame(['a' => 3, 'b' => 2], $column->facet(size: 2));
        $this->assertSame(['a' => 3, 'b' => 2, 'c' => 1], $column->facet(size: 0));
    }

    // =========================================================================
    // Beyond one chunk, and beyond one byte of ordinal
    // =========================================================================

    #[Test]
    public function the_column_survives_more_documents_than_one_chunk(): void
    {
        // 4096 documents to a chunk, so this crosses two boundaries and lands
        // mid-chunk — where an off-by-one in the offsets would show up.
        $values = [];

        for ($document = 0; $document < 9000; $document++) {
            $values[$document] = $document % 3 === 0 ? ['summer', 'luxury'] : ['winter'];
        }

        $column = $this->column($values);

        $this->assertSame(9000, $column->count());
        $this->assertSame(3000, $column->contains('summer')->count());
        $this->assertSame(3000, $column->contains('luxury')->count());
        $this->assertSame(6000, $column->contains('winter')->count());
        $this->assertSame(['winter' => 6000, 'luxury' => 3000, 'summer' => 3000], $column->facet());

        // The last document of all, which is the one a chunk loop drops.
        $this->assertSame(['winter'], $column->get(8999));
    }

    #[Test]
    public function more_than_255_values_widen_the_ordinals(): void
    {
        $values = [];

        for ($document = 0; $document < 300; $document++) {
            $values[$document] = ['tag-' . $document, 'shared'];
        }

        $column = $this->column($values);

        $this->assertSame(301, $column->cardinality());
        $this->assertSame(600, $column->totalValues());
        $this->assertSame([7], $this->documents($column->contains('tag-7')));
        $this->assertSame(300, $column->contains('shared')->count());
        $this->assertSame(['shared', 'tag-299'], $column->get(299));
    }

    #[Test]
    public function more_than_65535_values_widen_the_offsets(): void
    {
        // 40 000 documents with two values each is 80 000 values, past what a
        // two-byte offset can address.
        $values = [];

        for ($document = 0; $document < 40000; $document++) {
            $values[$document] = ['a', 'b'];
        }

        $column = $this->column($values);

        $this->assertSame(80000, $column->totalValues());
        $this->assertSame(['a', 'b'], $column->get(39999));
        $this->assertSame(40000, $column->contains('b')->count());
    }

    // =========================================================================
    // The writer's contract
    // =========================================================================

    #[Test]
    public function values_must_arrive_in_document_order(): void
    {
        $writer = new TagColumnWriter();
        $writer->add(5, ['a']);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('document order');

        $writer->add(2, ['b']);
    }

    #[Test]
    public function a_negative_document_is_refused(): void
    {
        $writer = new TagColumnWriter();

        $this->expectException(StorageException::class);

        $writer->add(-1, ['a']);
    }

    #[Test]
    public function a_column_cannot_be_finished_twice(): void
    {
        $writer = new TagColumnWriter();
        $writer->add(0, ['a']);
        $writer->finish(1);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('already finished');

        $writer->finish(1);
    }

    #[Test]
    public function a_finished_column_takes_no_more_values(): void
    {
        $writer = new TagColumnWriter();
        $writer->add(0, ['a']);
        $writer->finish(1);

        $this->expectException(StorageException::class);

        $writer->add(1, ['b']);
    }

    #[Test]
    public function a_column_cannot_declare_fewer_documents_than_it_holds(): void
    {
        $writer = new TagColumnWriter();
        $writer->add(9, ['a']);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('declares 4');

        $writer->finish(4);
    }
}
