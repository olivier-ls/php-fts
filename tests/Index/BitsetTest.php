<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Index\Bitset;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sets of document numbers held as bits in a PHP string.
 *
 * The traps this covers are all the same one: PHP's string bitwise operators
 * do not agree about length. `&` truncates to the shorter operand, `|` pads to
 * the longer, and `~` knows nothing about capacity at all — so a set combined
 * with one of a different size could quietly change meaning, and complementing
 * could invent documents out of the unused bits in the last byte.
 */
class BitsetTest extends TestCase
{
    // =========================================================================
    // Building and reading
    // =========================================================================

    #[Test]
    public function an_empty_set_holds_nothing(): void
    {
        $set = Bitset::empty(100);

        $this->assertSame(0, $set->count());
        $this->assertTrue($set->isEmpty());
        $this->assertSame([], $set->toArray());
        $this->assertFalse($set->has(0));
    }

    #[Test]
    public function a_full_set_holds_every_document_and_no_more(): void
    {
        $set = Bitset::full(20);

        $this->assertSame(20, $set->count());
        $this->assertSame(range(0, 19), $set->toArray());
        $this->assertTrue($set->has(19));
        $this->assertFalse($set->has(20), 'capacity 20 means documents 0 to 19');
    }

    #[Test]
    public function documents_can_be_added_and_read_back(): void
    {
        $set = Bitset::of([0, 7, 8, 63, 64, 99], 100);

        $this->assertSame([0, 7, 8, 63, 64, 99], $set->toArray());
        $this->assertSame(6, $set->count());

        foreach ([0, 7, 8, 63, 64, 99] as $document) {
            $this->assertTrue($set->has($document), "document $document");
        }

        foreach ([1, 6, 9, 62, 65, 98] as $document) {
            $this->assertFalse($set->has($document), "document $document");
        }
    }

    #[Test]
    public function out_of_range_documents_are_ignored(): void
    {
        $set = Bitset::of([-1, 5, 1000], 100);

        $this->assertSame([5], $set->toArray());
    }

    #[Test]
    public function a_capacity_of_zero_is_valid(): void
    {
        $set = Bitset::empty(0);

        $this->assertSame(0, $set->count());
        $this->assertSame([], $set->toArray());
        $this->assertSame([], $set->not()->toArray());
    }

    // =========================================================================
    // The algebra
    // =========================================================================

    #[Test]
    public function and_keeps_documents_present_in_both(): void
    {
        $a = Bitset::of([1, 3, 5, 7, 9], 20);
        $b = Bitset::of([3, 4, 5, 9, 10], 20);

        $this->assertSame([3, 5, 9], $a->and($b)->toArray());
    }

    #[Test]
    public function or_keeps_documents_present_in_either(): void
    {
        $a = Bitset::of([1, 3, 5], 20);
        $b = Bitset::of([3, 4], 20);

        $this->assertSame([1, 3, 4, 5], $a->or($b)->toArray());
    }

    #[Test]
    public function and_not_removes_the_other_set(): void
    {
        // How deletions are applied: live documents are matches minus tombstones.
        $matches  = Bitset::of([1, 3, 5, 7], 20);
        $deleted  = Bitset::of([3, 7], 20);

        $this->assertSame([1, 5], $matches->andNot($deleted)->toArray());
    }

    #[Test]
    public function not_returns_everything_else(): void
    {
        $set = Bitset::of([1, 3], 10);

        $this->assertSame([0, 2, 4, 5, 6, 7, 8, 9], $set->not()->toArray());
    }

    #[Test]
    public function combining_does_not_modify_either_operand(): void
    {
        $a = Bitset::of([1, 2, 3], 20);
        $b = Bitset::of([3, 4, 5], 20);

        $a->and($b);
        $a->or($b);
        $a->andNot($b);

        $this->assertSame([1, 2, 3], $a->toArray());
        $this->assertSame([3, 4, 5], $b->toArray());
    }

    // =========================================================================
    // The length traps
    // =========================================================================

    #[Test]
    public function complementing_does_not_invent_documents_past_the_capacity(): void
    {
        // Capacity 20 occupies three bytes, so bits 20 to 23 exist physically
        // and mean nothing. `~` sets them; they must not be counted.
        $set = Bitset::of([0], 20);

        $complement = $set->not();

        $this->assertSame(19, $complement->count());
        $this->assertSame(range(1, 19), $complement->toArray());
        $this->assertFalse($complement->has(20));
        $this->assertFalse($complement->has(23));
    }

    #[Test]
    public function complementing_a_full_byte_capacity_is_exact(): void
    {
        // The boundary where there is no tail to mask at all.
        $this->assertSame([], Bitset::full(16)->not()->toArray());
        $this->assertSame(range(0, 15), Bitset::empty(16)->not()->toArray());
    }

    #[Test]
    public function and_with_a_shorter_set_does_not_truncate_the_result(): void
    {
        // PHP's `&` would return a three-byte string here, silently losing every
        // document past 23.
        $wide   = Bitset::of([5, 100, 200], 300);
        $narrow = Bitset::full(24);

        $result = $wide->and($narrow);

        $this->assertSame(300, $result->capacity());
        $this->assertSame([5], $result->toArray(), 'only documents inside the narrow set survive');
    }

    #[Test]
    public function or_with_a_shorter_set_keeps_this_sets_capacity(): void
    {
        $wide   = Bitset::of([100], 300);
        $narrow = Bitset::of([5], 24);

        $result = $wide->or($narrow);

        $this->assertSame(300, $result->capacity());
        $this->assertSame([5, 100], $result->toArray());
    }

    #[Test]
    public function or_with_a_longer_set_does_not_grow_past_the_capacity(): void
    {
        // PHP's `|` pads to the longer operand, which would let documents from
        // a larger segment leak into a smaller one's set.
        $narrow = Bitset::of([5], 24);
        $wide   = Bitset::of([5, 100, 200], 300);

        $result = $narrow->or($wide);

        $this->assertSame(24, $result->capacity());
        $this->assertSame([5], $result->toArray());
    }

    // =========================================================================
    // Round-tripping through bytes, as deletion bitmaps do
    // =========================================================================

    #[Test]
    public function a_set_round_trips_through_its_bytes(): void
    {
        $set = Bitset::of([0, 17, 42, 199], 200);

        $restored = Bitset::fromBytes($set->bytes(), 200);

        $this->assertSame($set->toArray(), $restored->toArray());
    }

    #[Test]
    public function restoring_from_a_short_buffer_pads_rather_than_fails(): void
    {
        // A deletion bitmap saved when the segment was smaller.
        $restored = Bitset::fromBytes("\x05", 100);

        $this->assertSame([0, 2], $restored->toArray());
        $this->assertSame(100, $restored->capacity());
    }

    #[Test]
    public function restoring_from_an_over_long_buffer_drops_the_excess(): void
    {
        $restored = Bitset::fromBytes(str_repeat("\xff", 50), 10);

        $this->assertSame(range(0, 9), $restored->toArray());
    }

    // =========================================================================
    // At the scale it is meant for
    // =========================================================================

    #[Test]
    public function it_holds_twenty_thousand_documents_in_a_few_kilobytes(): void
    {
        $set = Bitset::full(20000);

        $this->assertSame(2500, strlen($set->bytes()));
        $this->assertSame(20000, $set->count());
    }

    #[Test]
    public function counting_and_iterating_agree_on_a_large_sparse_set(): void
    {
        $documents = [];

        for ($i = 0; $i < 20000; $i += 37) {
            $documents[] = $i;
        }

        $set = Bitset::of($documents, 20000);

        $this->assertSame(count($documents), $set->count());
        $this->assertSame($documents, $set->toArray());
    }

    #[Test]
    public function a_chain_of_filters_produces_the_expected_documents(): void
    {
        // The shape a real query takes: text matches, narrowed by two filters,
        // with deleted documents removed.
        $capacity = 1000;

        $text    = Bitset::of(range(0, 999, 2), $capacity);   // even
        $active  = Bitset::of(range(0, 999, 3), $capacity);   // multiples of 3
        $inStock = Bitset::of(range(0, 999, 5), $capacity);   // multiples of 5
        $deleted = Bitset::of([30, 90], $capacity);

        $result = $text->and($active)->and($inStock)->andNot($deleted);

        // Multiples of 30, less the two deleted ones.
        $expected = array_values(array_diff(range(0, 999, 30), [30, 90]));

        $this->assertSame($expected, $result->toArray());
    }
}
