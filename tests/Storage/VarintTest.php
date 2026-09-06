<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Storage;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Storage\Varint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VarintTest extends TestCase
{
    // =========================================================================
    // The encoding itself
    // =========================================================================

    /**
     * @return array<string, array{int, string}>
     */
    public static function knownEncodings(): array
    {
        return [
            'zero'                 => [0, "\x00"],
            'one'                  => [1, "\x01"],
            'largest single byte'  => [127, "\x7f"],
            'first two-byte value' => [128, "\x80\x01"],
            'three hundred'        => [300, "\xac\x02"],
            'largest two bytes'    => [16383, "\xff\x7f"],
            'first three bytes'    => [16384, "\x80\x80\x01"],
        ];
    }

    #[Test]
    #[DataProvider('knownEncodings')]
    public function it_matches_the_reference_encoding(int $value, string $expected): void
    {
        $this->assertSame($expected, Varint::encode($value));
    }

    #[Test]
    #[DataProvider('knownEncodings')]
    public function it_decodes_the_reference_encoding(int $value, string $bytes): void
    {
        $position = 0;

        $this->assertSame($value, Varint::decode($bytes, $position));
        $this->assertSame(strlen($bytes), $position, 'decoding must consume exactly the value');
    }

    #[Test]
    public function it_round_trips_across_the_whole_range(): void
    {
        $values = [0, 1, 63, 64, 127, 128, 255, 256, 16383, 16384, 65535, 1 << 20, 1 << 31, 1 << 40, PHP_INT_MAX];

        foreach ($values as $value) {
            $position = 0;
            $this->assertSame($value, Varint::decode(Varint::encode($value), $position), "round trip of $value");
        }
    }

    #[Test]
    public function size_predicts_the_encoded_length(): void
    {
        foreach ([0, 1, 127, 128, 16383, 16384, 1 << 31, PHP_INT_MAX] as $value) {
            $this->assertSame(
                strlen(Varint::encode($value)),
                Varint::size($value),
                "size() must agree with encode() for $value"
            );
        }
    }

    #[Test]
    public function negative_values_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Varint::encode(-1);
    }

    // =========================================================================
    // Reading a buffer of many values
    // =========================================================================

    #[Test]
    public function values_can_be_read_one_after_another(): void
    {
        $bytes    = Varint::encode(5) . Varint::encode(300) . Varint::encode(1);
        $position = 0;

        $this->assertSame(5, Varint::decode($bytes, $position));
        $this->assertSame(300, Varint::decode($bytes, $position));
        $this->assertSame(1, Varint::decode($bytes, $position));
        $this->assertSame(strlen($bytes), $position);
    }

    #[Test]
    public function a_whole_buffer_round_trips(): void
    {
        $values = [0, 3, 3, 1, 250, 17, 1, 1, 99999, 2];

        $this->assertSame($values, Varint::decodeAll(Varint::encodeAll($values)));
    }

    #[Test]
    public function decoding_can_start_partway_into_a_buffer(): void
    {
        $prefix = 'SECTIONHEADER';
        $bytes  = $prefix . Varint::encodeAll([7, 8, 9]);

        $this->assertSame([7, 8, 9], Varint::decodeAll($bytes, strlen($prefix)));
    }

    #[Test]
    public function an_empty_buffer_decodes_to_nothing(): void
    {
        $this->assertSame([], Varint::decodeAll(''));
    }

    // =========================================================================
    // Refusing malformed input
    // =========================================================================

    #[Test]
    public function a_value_cut_short_is_refused(): void
    {
        // 0x80 says "another byte follows", and there is none.
        $position = 0;

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/past the end/i');

        Varint::decode("\x80", $position);
    }

    #[Test]
    public function an_endless_value_is_refused(): void
    {
        // Every byte claims a successor. Without a bound this would loop until
        // the buffer ran out, which on a large section means a long stall.
        $position = 0;

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/longer than any valid value/i');

        Varint::decode(str_repeat("\x80", 32), $position);
    }

    // =========================================================================
    // Why the format uses this at all
    // =========================================================================

    #[Test]
    public function delta_encoded_document_numbers_are_far_smaller_than_fixed_width(): void
    {
        // A posting list as it really looks: sorted document numbers, dense
        // because the term is common. Stored as gaps, almost every value is
        // small enough for a single byte.
        $documents = [];
        $current   = 0;

        for ($i = 0; $i < 5000; $i++) {
            $current    += random_int(1, 6);
            $documents[] = $current;
        }

        $fixedWidth = 4 * count($documents);

        $deltas   = [];
        $previous = 0;

        foreach ($documents as $document) {
            $deltas[] = $document - $previous;
            $previous = $document;
        }

        $encoded = Varint::encodeAll($deltas);

        $this->assertLessThan(
            $fixedWidth / 3,
            strlen($encoded),
            'delta + varint should cut a dense posting list to well under a third of uint32'
        );

        // And it must still be exactly reversible.
        $decoded  = [];
        $previous = 0;

        foreach (Varint::decodeAll($encoded) as $delta) {
            $previous  += $delta;
            $decoded[] = $previous;
        }

        $this->assertSame($documents, $decoded);
    }
}
