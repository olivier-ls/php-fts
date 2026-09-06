<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\Varint;

/**
 * Looks keys up in a block dictionary held in a segment section.
 *
 *     $dictionary = new BlockDictionaryReader($segment, 'terms');
 *     $payload    = $dictionary->get('#cha');     // null if absent
 *
 * Opening reads two things and nothing else: the 24-byte header, and the block
 * index. For a dictionary of 100 000 keys the index runs to a few tens of
 * kilobytes and is kept as two plain strings — never expanded into PHP arrays,
 * which is precisely the cost that made v1's 810 KB table so expensive to open.
 *
 * A lookup is then a binary search over that in-memory index, followed by a
 * single read of one block. Everything else in the section stays on disk.
 */
final class BlockDictionaryReader
{
    /**
     * Recently decoded blocks, keyed by block number.
     *
     * A query looks up every n-gram of the search text, and n-grams of the same
     * word are neighbours in sorted order, so they land in the same few blocks.
     * Holding the last handful avoids re-reading a block several times for one
     * query, for a few tens of kilobytes.
     *
     * @var array<int, string>
     */
    private array $blockCache = [];

    private const BLOCK_CACHE_SIZE = 8;

    private int $blockSize;
    private int $entryCount;
    private int $blockCount;

    /** Fixed-width u32 array: where each block's index entry begins. */
    private string $indexOffsets = '';

    /** Variable-length index entries: first key, block offset, block length. */
    private string $indexEntries = '';

    /**
     * @throws CorruptSegmentException
     */
    public function __construct(
        private readonly SegmentReader $segment,
        private readonly string $section,
    ) {
        $header = BlockDictionaryFormat::unpackHeader(
            $this->segment->read($this->section, 0, BlockDictionaryFormat::HEADER_SIZE)
        );

        $this->blockSize  = $header['blockSize'];
        $this->entryCount = $header['entryCount'];
        $this->blockCount = $header['blockCount'];

        if ($this->blockCount === 0) {
            return;
        }

        $index          = $this->segment->read($this->section, $header['indexOffset'], $header['indexLength']);
        $offsetsLength  = $this->blockCount * 4;

        if (strlen($index) < $offsetsLength) {
            throw new CorruptSegmentException(
                "Dictionary in section '{$this->section}' declares {$this->blockCount} blocks but its index is too short"
            );
        }

        $this->indexOffsets = substr($index, 0, $offsetsLength);
        $this->indexEntries = substr($index, $offsetsLength);
    }

    public function count(): int
    {
        return $this->entryCount;
    }

    public function blockCount(): int
    {
        return $this->blockCount;
    }

    public function blockSize(): int
    {
        return $this->blockSize;
    }

    /**
     * Returns the payload stored for a key, or null if the key is absent.
     *
     * @throws CorruptSegmentException
     */
    public function get(string $key): ?string
    {
        if ($this->blockCount === 0 || $key === '') {
            return null;
        }

        $block = $this->locateBlock($key);

        if ($block === null) {
            return null;
        }

        return $this->searchBlock($this->block($block), $key);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Walks every entry in ascending key order.
     *
     * Used by merges, which read several dictionaries at once and interleave
     * them, and by anything that has to rebuild rather than look up.
     *
     * @return \Generator<string, string> key => payload
     * @throws CorruptSegmentException
     */
    public function iterate(): \Generator
    {
        for ($block = 0; $block < $this->blockCount; $block++) {
            $bytes    = $this->block($block);
            $position = 0;
            $previous = '';

            while ($position < strlen($bytes)) {
                [$key, $payload] = $this->decodeEntry($bytes, $position, $previous);
                $previous        = $key;

                yield $key => $payload;
            }
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Finds the only block that could contain the key: the last one whose first
     * key does not sort after it.
     *
     * Returns null when the key sorts before every block, which means it is not
     * in the dictionary at all.
     *
     * @throws CorruptSegmentException
     */
    private function locateBlock(string $key): ?int
    {
        $low    = 0;
        $high   = $this->blockCount - 1;
        $found  = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if (strcmp($this->firstKeyOf($middle), $key) <= 0) {
                $found = $middle;
                $low   = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }

    /**
     * Reads one block's first key straight out of the in-memory index.
     *
     * Note what this does not do: build an array, allocate an object, or decode
     * anything it does not need. A binary search over 100 000 keys touches
     * around 11 blocks' first keys, and each one costs a substr.
     *
     * @throws CorruptSegmentException
     */
    private function firstKeyOf(int $block): string
    {
        $position  = $this->indexEntryOffset($block);
        $keyLength = Varint::decode($this->indexEntries, $position);

        return substr($this->indexEntries, $position, $keyLength);
    }

    /**
     * @return array{0: int, 1: int} offset within the blocks region, and length
     * @throws CorruptSegmentException
     */
    private function blockLocation(int $block): array
    {
        $position  = $this->indexEntryOffset($block);
        $keyLength = Varint::decode($this->indexEntries, $position);
        $position += $keyLength;

        // Read in two statements rather than inside one array literal: both
        // calls advance $position, and relying on argument evaluation order to
        // get them in sequence is the kind of cleverness that breaks silently.
        $offset = Varint::decode($this->indexEntries, $position);
        $length = Varint::decode($this->indexEntries, $position);

        return [$offset, $length];
    }

    /**
     * @throws CorruptSegmentException
     */
    private function indexEntryOffset(int $block): int
    {
        $packed = substr($this->indexOffsets, $block * 4, 4);

        if (strlen($packed) !== 4) {
            throw new CorruptSegmentException("Dictionary index has no entry for block $block");
        }

        return unpack('V', $packed)[1];
    }

    /**
     * @throws CorruptSegmentException
     */
    private function block(int $number): string
    {
        if (isset($this->blockCache[$number])) {
            return $this->blockCache[$number];
        }

        [$offset, $length] = $this->blockLocation($number);

        $bytes = $this->segment->read(
            $this->section,
            BlockDictionaryFormat::HEADER_SIZE + $offset,
            $length
        );

        // Evict with unset(), never array_shift(): the cache is keyed by block
        // number, and array_shift() renumbers integer keys — every cached block
        // would silently start answering for the wrong one.
        if (count($this->blockCache) >= self::BLOCK_CACHE_SIZE) {
            unset($this->blockCache[array_key_first($this->blockCache)]);
        }

        $this->blockCache[$number] = $bytes;

        return $bytes;
    }

    /**
     * Scans a block for a key, reconstructing each entry from its predecessor.
     *
     * Entries are sorted, so the scan stops as soon as it passes the key rather
     * than reading the rest of the block.
     *
     * @throws CorruptSegmentException
     */
    private function searchBlock(string $bytes, string $key): ?string
    {
        $position = 0;
        $previous = '';

        while ($position < strlen($bytes)) {
            [$current, $payload] = $this->decodeEntry($bytes, $position, $previous);

            $comparison = strcmp($current, $key);

            if ($comparison === 0) {
                return $payload;
            }

            if ($comparison > 0) {
                return null;
            }

            $previous = $current;
        }

        return null;
    }

    /**
     * Decodes one front-coded entry, advancing $position past it.
     *
     * @return array{0: string, 1: string} the full key, and its payload
     * @throws CorruptSegmentException
     */
    private function decodeEntry(string $bytes, int &$position, string $previous): array
    {
        $shared       = Varint::decode($bytes, $position);
        $suffixLength = Varint::decode($bytes, $position);

        if ($shared > strlen($previous)) {
            throw new CorruptSegmentException(
                "Dictionary entry borrows $shared bytes from a key of " . strlen($previous)
            );
        }

        $suffix    = substr($bytes, $position, $suffixLength);
        $position += $suffixLength;

        if (strlen($suffix) !== $suffixLength) {
            throw new CorruptSegmentException('Dictionary entry has a truncated key');
        }

        $payloadLength = Varint::decode($bytes, $position);
        $payload       = substr($bytes, $position, $payloadLength);
        $position     += $payloadLength;

        if (strlen($payload) !== $payloadLength) {
            throw new CorruptSegmentException('Dictionary entry has a truncated payload');
        }

        return [substr($previous, 0, $shared) . $suffix, $payload];
    }
}
