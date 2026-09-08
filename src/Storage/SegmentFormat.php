<?php

declare(strict_types=1);

namespace Ols\PhpFts\Storage;

use Ols\PhpFts\Exception\CorruptSegmentException;

/**
 * The on-disk layout of a segment file, and the primitives shared by the
 * reader and the writer.
 *
 * A segment is one self-contained file, written once in a single forward pass
 * and never modified afterwards. Everything else in the engine depends on that
 * immutability: it is what allows postings to be delta-compressed, the term
 * dictionary to be front-coded, and readers to work without taking a lock.
 *
 *   ┌────────────────────────────────────────────┐
 *   │ HEADER          16 B                       │
 *   │   "FTSG" | formatVersion u16 | reserved    │
 *   ├────────────────────────────────────────────┤
 *   │ SECTION payloads, back to back             │
 *   │   terms, postings, docvalues, docstore, …  │
 *   ├────────────────────────────────────────────┤
 *   │ DIRECTORY                                  │
 *   │   count u32                                │
 *   │   per entry: nameLen u8 | name             │
 *   │              offset u64 | length u64       │
 *   │              crc32 u32                     │
 *   ├────────────────────────────────────────────┤
 *   │ TRAILER         32 B                       │
 *   │   "GSTF" | dirOffset u64 | dirCrc32 u32    │
 *   │   fileLength u64 | formatVersion u16 | pad │
 *   └────────────────────────────────────────────┘
 *
 * Why the directory sits at the end rather than the start: section offsets are
 * only known once their bytes have been written, and a segment is produced in
 * one forward pass with no seeking backwards. ZIP puts its central directory
 * last for exactly the same reason.
 *
 * Why the trailer records `fileLength`: comparing it to the real file size is
 * an O(1) completeness check that catches the only failure that actually
 * happens in practice — a write cut short. It costs one `stat`, no matter how
 * large the segment is, and it is what a reader uses to reject a segment and
 * fall back to the previous commit.
 *
 * Integrity is layered rather than global. The trailer's checksum covers the
 * directory only, because that is the part a reader must trust to navigate at
 * all, and it is small. Each section carries its own checksum in its directory
 * entry, so a section can be verified on demand without ever reading the whole
 * file. A single whole-file checksum would have made every open O(file size),
 * which defeats the point of the format.
 */
final class SegmentFormat
{
    public const MAGIC         = 'FTSG';
    public const TRAILER_MAGIC = 'GSTF';

    /**
     * Bumped to 2 in the rewrite that made words the terms.
     *
     * A version-1 segment is not readable by a version-2 build and cannot be
     * converted: its terms are the trigrams of documents, and this build looks
     * up words. There is no migration, only reindexing from the application's
     * own source. Opening one raises UnsupportedFormatException, which is
     * deliberately outside the corrupt-segment hierarchy so that the rollback
     * machinery does not mistake an old index for a torn commit and quietly
     * present it as empty.
     */
    public const VERSION = 2;

    public const HEADER_SIZE  = 16;
    public const TRAILER_SIZE = 32;

    /** Section names are length-prefixed with a single byte. */
    public const MAX_NAME_LENGTH = 255;

    /**
     * Smallest byte count that could possibly be a valid segment:
     * header, an empty directory (a u32 zero), and a trailer.
     */
    public const MIN_FILE_SIZE = self::HEADER_SIZE + 4 + self::TRAILER_SIZE;

    private function __construct()
    {
    }

    /**
     * Packs a 64-bit unsigned integer, little-endian.
     *
     * Offsets and lengths are 64-bit so the format never has to be revised for
     * large indexes. PHP integers are signed, so the usable range stops at
     * 2^63-1 — about 9 exabytes, which is not a constraint anyone will meet.
     */
    public static function packU64(int $value): string
    {
        return pack('P', $value);
    }

    /**
     * @throws CorruptSegmentException
     */
    public static function unpackU64(string $bytes, int $offset = 0): int
    {
        $slice = substr($bytes, $offset, 8);

        if (strlen($slice) !== 8) {
            throw new CorruptSegmentException('Truncated 64-bit value in segment metadata');
        }

        $value = unpack('P', $slice)[1];

        // A negative result means the stored value exceeded 2^63-1, which can
        // only come from a corrupt or hostile file — no writer produces it.
        if ($value < 0) {
            throw new CorruptSegmentException('Segment metadata declares an out-of-range offset');
        }

        return $value;
    }

    public static function packU32(int $value): string
    {
        return pack('V', $value);
    }

    /**
     * @throws CorruptSegmentException
     */
    public static function unpackU32(string $bytes, int $offset = 0): int
    {
        $slice = substr($bytes, $offset, 4);

        if (strlen($slice) !== 4) {
            throw new CorruptSegmentException('Truncated 32-bit value in segment metadata');
        }

        return unpack('V', $slice)[1];
    }

    /**
     * CRC-32 of a string, as an unsigned integer.
     *
     * `crc32()` returns a signed int on 32-bit builds; going through the hash
     * extension keeps the value unsigned and identical on every platform, which
     * matters because the checksum is written to a file that must be portable.
     * ext-hash is compiled into PHP and cannot be disabled since 7.4, so this
     * costs nothing in terms of requirements.
     */
    public static function checksum(string $bytes): int
    {
        return (int) hexdec(hash('crc32b', $bytes));
    }

    /**
     * Incremental CRC-32 context, for sections written in several chunks.
     *
     * @return \HashContext
     */
    public static function checksumStart(): \HashContext
    {
        return hash_init('crc32b');
    }

    public static function checksumFinish(\HashContext $context): int
    {
        return (int) hexdec(hash_final($context));
    }
}
