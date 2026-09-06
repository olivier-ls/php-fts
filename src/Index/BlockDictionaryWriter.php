<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Storage\Varint;

/**
 * Builds the bytes of a block dictionary.
 *
 *     $writer = new BlockDictionaryWriter();
 *     $writer->add('#ch', $payloadForFirstTerm);
 *     $writer->add('#cha', $payloadForSecondTerm);
 *     $bytes = $writer->finish();
 *
 *     $segment->addSection('terms', $bytes);
 *
 * Keys must arrive in ascending byte order. That is not a convenience the
 * caller happens to owe: the whole structure — front coding, the block index,
 * the binary search — only works on sorted input, and unsorted input would not
 * fail loudly, it would silently make some keys unfindable. So the order is
 * checked on every insertion.
 *
 * The result is assembled in memory rather than streamed. A dictionary of
 * 100 000 keys comes to a few hundred kilobytes once front-coded, and the
 * caller has to hold the sorted keys anyway to feed them in order, so streaming
 * would save nothing real while costing a second pass over the block index.
 */
final class BlockDictionaryWriter
{
    private string $blocks = '';

    /** Index entries, and the offset of each within that buffer. */
    private string $indexEntries = '';

    /** @var int[] */
    private array $indexOffsets = [];

    private int $entryCount = 0;

    /** Entries buffered for the block currently being built. */
    private string $currentBlock = '';
    private int $currentBlockEntries = 0;
    private string $currentBlockFirstKey = '';
    private string $previousKey = '';

    private bool $finished = false;

    public function __construct(
        private readonly int $blockSize = BlockDictionaryFormat::DEFAULT_BLOCK_SIZE,
    ) {
        if ($this->blockSize < 1 || $this->blockSize > 65535) {
            throw new \InvalidArgumentException("Block size must be between 1 and 65535, got {$this->blockSize}");
        }
    }

    /**
     * @param string $key     must be non-empty and greater than the previous key
     * @param string $payload opaque bytes; the dictionary never interprets them
     *
     * @throws StorageException
     */
    public function add(string $key, string $payload): void
    {
        if ($this->finished) {
            throw new StorageException('Dictionary is already finished');
        }

        if ($key === '') {
            throw new StorageException('Dictionary keys cannot be empty');
        }

        if ($this->entryCount > 0 && strcmp($key, $this->previousKey) <= 0) {
            throw new StorageException(
                "Dictionary keys must be added in ascending order: '$key' came after '{$this->previousKey}'"
            );
        }

        // A block starts fresh: its first key is stored whole, which is what
        // lets the reader decode the block without touching its predecessors.
        if ($this->currentBlockEntries === 0) {
            $this->currentBlockFirstKey = $key;
            $shared = 0;
        } else {
            $shared = BlockDictionaryFormat::sharedPrefixLength($this->previousKey, $key);
        }

        $suffix = substr($key, $shared);

        $this->currentBlock .= Varint::encode($shared)
            . Varint::encode(strlen($suffix))
            . $suffix
            . Varint::encode(strlen($payload))
            . $payload;

        $this->currentBlockEntries++;
        $this->entryCount++;
        $this->previousKey = $key;

        if ($this->currentBlockEntries === $this->blockSize) {
            $this->flushBlock();
        }
    }

    /**
     * Closes the last block, appends the index, and returns the complete bytes.
     *
     * @throws StorageException
     */
    public function finish(): string
    {
        if ($this->finished) {
            throw new StorageException('Dictionary is already finished');
        }

        if ($this->currentBlockEntries > 0) {
            $this->flushBlock();
        }

        $this->finished = true;

        $blockCount = count($this->indexOffsets);

        // The offsets array is fixed-width so the index can be bisected in
        // place; the entries it points at are variable-length.
        $offsets = '';

        foreach ($this->indexOffsets as $offset) {
            $offsets .= pack('V', $offset);
        }

        $index       = $offsets . $this->indexEntries;
        $indexOffset = BlockDictionaryFormat::HEADER_SIZE + strlen($this->blocks);

        return BlockDictionaryFormat::packHeader(
            $this->blockSize,
            $this->entryCount,
            $blockCount,
            $indexOffset,
            strlen($index),
        ) . $this->blocks . $index;
    }

    public function count(): int
    {
        return $this->entryCount;
    }

    // -------------------------------------------------------------------------

    private function flushBlock(): void
    {
        $blockOffset = strlen($this->blocks);
        $blockLength = strlen($this->currentBlock);

        $this->blocks .= $this->currentBlock;

        $this->indexOffsets[] = strlen($this->indexEntries);

        // Block offsets are absolute, not deltas from the previous block.
        // Delta-encoding them would be a little smaller, but it would force a
        // reader to sum every gap from the first block to reach the Nth — which
        // is exactly what the binary search exists to avoid. A varint keeps an
        // absolute offset to three bytes for any dictionary under 2 MB anyway,
        // so the saving would have been a couple of kilobytes in exchange for
        // making random access impossible.
        $this->indexEntries .= Varint::encode(strlen($this->currentBlockFirstKey))
            . $this->currentBlockFirstKey
            . Varint::encode($blockOffset)
            . Varint::encode($blockLength);

        $this->currentBlock        = '';
        $this->currentBlockEntries = 0;
        $this->currentBlockFirstKey = '';
    }
}
