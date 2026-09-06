<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;

/**
 * A sorted map from string key to opaque payload, stored in one byte range.
 *
 * This is the structure that replaces v1's directly-addressed table, and it is
 * worth being explicit about what was wrong with that table, because it shapes
 * every decision here.
 *
 * v1 indexed trigrams by computing an array position from their three
 * characters: `c1 * 37² + c2 * 37 + c3`. Access was genuinely O(1), but it cost
 * two things. First, the alphabet had to be fixed and tiny — 26 letters, 10
 * digits, one separator — so anything outside it was destroyed at tokenising
 * time, which is why the engine cannot index Japanese, Russian or Arabic.
 * Second, every possible position had to exist whether or not it held anything:
 * 50 653 entries, 810 KB, loaded in full on every single search, as 50 653 PHP
 * arrays. An index holding twelve documents paid exactly the same price as one
 * holding twenty thousand.
 *
 * Here, keys are stored — not computed — so there is no alphabet at all, and
 * only keys that exist take up room. Finding one costs a binary search over a
 * small in-memory index plus a single disk read.
 *
 * ── Layout ──────────────────────────────────────────────────────────────────
 *
 *   HEADER          24 B
 *     "BDIC" | version u8 | reserved u8 | blockSize u16
 *     entryCount u32 | blockCount u32 | indexOffset u32 | indexLength u32
 *
 *   BLOCKS          groups of `blockSize` entries, front-coded
 *     per entry: sharedLen varint | suffixLen varint | suffix
 *                payloadLen varint | payload
 *
 *   BLOCK INDEX     one entry per block
 *     offsets:  u32 × blockCount     ← fixed width, so it can be binary-searched
 *     entries:  firstKeyLen varint | firstKey
 *               blockOffset varint | blockLength varint
 *
 * ── Front coding ────────────────────────────────────────────────────────────
 *
 * Sorted keys share long prefixes, so each one records only how many leading
 * bytes it borrows from its predecessor:
 *
 *     #ch   →  shared 0, suffix "#ch"
 *     #cha  →  shared 3, suffix "a"
 *     #che  →  shared 3, suffix "e"
 *     #chi  →  shared 3, suffix "i"
 *
 * This works unusually well on n-grams, which are short and heavily clustered.
 * The first key of every block stores `shared = 0`, which is what makes a block
 * decodable on its own without reading the ones before it.
 *
 * ── Why the block index is split in two ─────────────────────────────────────
 *
 * Index entries are variable-length, and you cannot binary-search a run of
 * variable-length records — there is no way to jump to the middle one. So the
 * offsets of those records are stored separately, as a fixed-width u32 array.
 * A search reads the whole index once (tens of KB), then bisects it *in place*
 * with substr and unpack, without ever building a PHP array of structures.
 * That last point is the whole difference with v1: the cost of opening is a
 * couple of reads, not fifty thousand allocations.
 */
final class BlockDictionaryFormat
{
    public const MAGIC   = 'BDIC';
    public const VERSION = 1;

    public const HEADER_SIZE = 24;

    /**
     * Entries per block.
     *
     * The trade-off is direct: larger blocks compress better, because more keys
     * share a prefix run, but every lookup then decodes more entries it does not
     * want. 64 is a starting point, not a measured optimum — settling it against
     * a real corpus and real storage is deliberately left until there is a
     * corpus to measure.
     */
    public const DEFAULT_BLOCK_SIZE = 64;

    private function __construct()
    {
    }

    public static function packHeader(
        int $blockSize,
        int $entryCount,
        int $blockCount,
        int $indexOffset,
        int $indexLength,
    ): string {
        return self::MAGIC
            . pack('C', self::VERSION)
            . "\x00"
            . pack('v', $blockSize)
            . pack('V', $entryCount)
            . pack('V', $blockCount)
            . pack('V', $indexOffset)
            . pack('V', $indexLength);
    }

    /**
     * @return array{blockSize: int, entryCount: int, blockCount: int, indexOffset: int, indexLength: int}
     * @throws CorruptSegmentException
     */
    public static function unpackHeader(string $bytes): array
    {
        if (strlen($bytes) < self::HEADER_SIZE) {
            throw new CorruptSegmentException('Dictionary header is truncated');
        }

        if (substr($bytes, 0, 4) !== self::MAGIC) {
            throw new CorruptSegmentException('Section does not hold a block dictionary');
        }

        $version = ord($bytes[4]);

        if ($version !== self::VERSION) {
            throw new CorruptSegmentException(
                "Dictionary is format version $version, this build reads version " . self::VERSION
            );
        }

        return [
            'blockSize'   => unpack('v', substr($bytes, 6, 2))[1],
            'entryCount'  => unpack('V', substr($bytes, 8, 4))[1],
            'blockCount'  => unpack('V', substr($bytes, 12, 4))[1],
            'indexOffset' => unpack('V', substr($bytes, 16, 4))[1],
            'indexLength' => unpack('V', substr($bytes, 20, 4))[1],
        ];
    }

    /**
     * Length of the prefix two keys have in common, in bytes.
     *
     * Bytes rather than characters on purpose: UTF-8 orders bytewise exactly as
     * it orders code points, so a byte-level comparison sorts every script
     * correctly without decoding anything. A shared prefix that stops in the
     * middle of a multi-byte character is harmless — the suffix simply carries
     * the rest of it, and the key is reassembled byte for byte.
     */
    public static function sharedPrefixLength(string $a, string $b): int
    {
        $limit  = min(strlen($a), strlen($b));
        $shared = 0;

        while ($shared < $limit && $a[$shared] === $b[$shared]) {
            $shared++;
        }

        return $shared;
    }
}
