<?php

declare(strict_types=1);

namespace Ols\PhpFts\Storage;

use Ols\PhpFts\Exception\CorruptSegmentException;

/**
 * Variable-length integer encoding (LEB128, unsigned).
 *
 * A fixed-width integer always costs its width. A `uint32` spends four bytes
 * on the value 3 exactly as it does on the value 4 000 000 000. Across an index
 * that is mostly small numbers, three of those four bytes are zeroes.
 *
 * A varint spends one byte per seven bits of value, using the eighth bit of
 * each byte to say "another byte follows":
 *
 *      0        →  0000 0000                                    1 byte
 *      127      →  0111 1111                                    1 byte
 *      128      →  1000 0000  0000 0001                         2 bytes
 *      300      →  1010 1100  0000 0010                         2 bytes
 *      1 000 000 → 1100 0000  1000 0100  0011 1101              3 bytes
 *
 * So values under 128 cost one byte, under 16 384 cost two, and so on.
 *
 * Why this matters here: posting lists are sorted document numbers, stored as
 * the gaps between them rather than the numbers themselves. In a list of
 * documents containing a common trigram the gaps are tiny — often 1 or 2 — so
 * nearly every one fits in a single byte. On the 20 000-document benchmark that
 * turns roughly 27 MB of postings into roughly 7.5 MB.
 *
 * The catch, and the reason this is not used everywhere: a varint has no fixed
 * width, so you cannot jump to the Nth value without decoding the N-1 before
 * it. That is fine for posting lists, which are read forward from a known
 * starting point, and unacceptable for doc-values columns, which are addressed
 * by document number and therefore stay fixed-width.
 */
final class Varint
{
    /**
     * Ten bytes hold 70 bits, more than any PHP integer needs. A varint longer
     * than that cannot have been produced by encode() and means the bytes being
     * read are not what the caller thinks they are.
     */
    private const MAX_BYTES = 10;

    private function __construct()
    {
    }

    /**
     * @param int $value must be zero or positive
     * @throws \InvalidArgumentException
     */
    public static function encode(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("Varint values must be non-negative, got $value");
        }

        $bytes = '';

        // Emit seven bits at a time, low bits first, setting the continuation
        // flag on every byte except the last.
        while ($value > 0x7F) {
            $bytes .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }

        return $bytes . chr($value);
    }

    /**
     * Decodes one varint, advancing $position past it.
     *
     * $position is taken by reference because decoding is nearly always a walk
     * through a buffer of many values, and returning a tuple for every one of
     * them would allocate an array per integer read.
     *
     * @param int $position in/out: where to read, then where the next value starts
     * @throws CorruptSegmentException
     */
    public static function decode(string $bytes, int &$position): int
    {
        $value = 0;
        $shift = 0;
        $read  = 0;

        while (true) {
            if ($position >= strlen($bytes)) {
                throw new CorruptSegmentException('Varint runs past the end of the buffer');
            }

            $byte = ord($bytes[$position]);
            $position++;
            $read++;

            $value |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $value;
            }

            if ($read >= self::MAX_BYTES) {
                throw new CorruptSegmentException('Varint is longer than any valid value');
            }

            $shift += 7;
        }
    }

    /**
     * @param int[] $values
     * @throws \InvalidArgumentException
     */
    public static function encodeAll(array $values): string
    {
        $bytes = '';

        foreach ($values as $value) {
            $bytes .= self::encode($value);
        }

        return $bytes;
    }

    /**
     * Decodes every varint in the buffer, from $position to the end.
     *
     * @return int[]
     * @throws CorruptSegmentException
     */
    public static function decodeAll(string $bytes, int $position = 0): array
    {
        $values = [];
        $length = strlen($bytes);

        while ($position < $length) {
            $values[] = self::decode($bytes, $position);
        }

        return $values;
    }

    /**
     * Number of bytes encode() would produce, without producing them.
     *
     * Used when laying out a structure that has to record where something will
     * land before it is written.
     */
    public static function size(int $value): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("Varint values must be non-negative, got $value");
        }

        $size = 1;

        while ($value > 0x7F) {
            $value >>= 7;
            $size++;
        }

        return $size;
    }
}
