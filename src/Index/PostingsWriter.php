<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Storage\Varint;

/**
 * Encodes one term's posting list.
 *
 *     $writer = new PostingsWriter();
 *     $writer->add(4);
 *     $writer->add(9);
 *     $writer->add(11);
 *     $bytes = $writer->finish();
 *
 * Document numbers must arrive in ascending order and without repetition. Like
 * the dictionary's sort requirement this is checked rather than trusted,
 * because the failure it prevents is silent: an out-of-order gap would decode
 * to a document number that exists, is wrong, and belongs to another document
 * entirely.
 */
final class PostingsWriter
{
    private string $blocks = '';

    /** Fixed-width skip entries: firstOrdinal u32, blockOffset u32. */
    private string $skips = '';

    private int $count = 0;

    /** The block being filled. */
    private string $currentBlock = '';
    private int $currentBlockCount = 0;
    private int $currentBlockFirst = 0;

    private int $previous = -1;
    private bool $finished = false;

    /**
     * @throws StorageException
     */
    public function add(int $document): void
    {
        if ($this->finished) {
            throw new StorageException('Posting list is already finished');
        }

        if ($document < 0) {
            throw new StorageException("Document numbers cannot be negative, got $document");
        }

        if ($document <= $this->previous) {
            throw new StorageException(
                "Posting lists must ascend without repetition: $document came after {$this->previous}"
            );
        }

        // Every block restarts from an absolute number, which is what makes it
        // decodable without the blocks before it — and therefore what makes the
        // skip table useful.
        if ($this->currentBlockCount === 0) {
            $this->currentBlockFirst = $document;
            $this->currentBlock      = Varint::encode($document);
        } else {
            $this->currentBlock .= Varint::encode($document - $this->previous);
        }

        $this->currentBlockCount++;
        $this->count++;
        $this->previous = $document;

        if ($this->currentBlockCount === PostingsFormat::BLOCK_SIZE) {
            $this->flushBlock();
        }
    }

    /**
     * @param int[] $documents ascending, no repeats
     * @throws StorageException
     */
    public static function encode(array $documents): string
    {
        $writer = new self();

        foreach ($documents as $document) {
            $writer->add($document);
        }

        return $writer->finish();
    }

    /**
     * @throws StorageException
     */
    public function finish(): string
    {
        if ($this->finished) {
            throw new StorageException('Posting list is already finished');
        }

        if ($this->currentBlockCount > 0) {
            $this->flushBlock();
        }

        $this->finished = true;

        $header = Varint::encode($this->count);

        // A single block never gets a skip table: there is nothing to skip to,
        // and the table would cost more than the scan it replaces.
        if (!PostingsFormat::hasSkipTable($this->count)) {
            return $header . $this->blocks;
        }

        return $header . $this->skips . $this->blocks;
    }

    public function count(): int
    {
        return $this->count;
    }

    // -------------------------------------------------------------------------

    private function flushBlock(): void
    {
        // Fixed width, so a reader can bisect the table with unpack and without
        // decoding anything.
        $this->skips .= pack('V', $this->currentBlockFirst) . pack('V', strlen($this->blocks));

        $this->blocks .= $this->currentBlock;

        $this->currentBlock      = '';
        $this->currentBlockCount = 0;
        $this->currentBlockFirst = 0;
    }
}
