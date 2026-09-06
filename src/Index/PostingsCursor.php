<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Storage\Varint;

/**
 * Walks one term's posting list, forwards only, with the ability to jump.
 *
 *     $cursor = PostingsCursor::open($bytes);
 *
 *     while ($cursor->current() !== PostingsFormat::END) {
 *         // …
 *         $cursor->next();
 *     }
 *
 * Two ways to move, and the difference is the whole point of the structure:
 *
 *   next()             the following document
 *   advance($target)   the first document at or after $target
 *
 * `advance()` is what makes intersection cheap. To find the documents holding
 * every term of a query, you take the highest document any cursor is sitting
 * on and push all the others up to it; each push skips whole blocks instead of
 * decoding every gap along the way. The cost ends up proportional to the size
 * of the answer rather than the size of the lists — which is exactly what v1
 * could not do, and why it capped its lists instead.
 *
 * The bytes are held in memory rather than read block by block. For the index
 * sizes this engine targets a posting list runs to tens of kilobytes, so
 * reading it costs one seek, whereas fetching blocks on demand would cost one
 * seek each — a bad trade on the network storage that shared hosting uses. The
 * skip table still earns its place: in PHP the expensive part is decoding
 * varints, not fetching bytes, and skipping avoids the decoding.
 */
final class PostingsCursor
{
    private int $count;
    private int $blockCount;

    /** Fixed-width skip entries, or '' when the list is a single block. */
    private string $skips = '';

    /** Offset of the blocks region within $bytes. */
    private int $blocksOffset;

    /** Documents of the block currently decoded. @var int[] */
    private array $block = [];
    private int $blockNumber = -1;
    private int $positionInBlock = 0;

    private int $current = PostingsFormat::END;

    /**
     * @throws CorruptSegmentException
     */
    private function __construct(private readonly string $bytes)
    {
        $position     = 0;
        $this->count  = Varint::decode($this->bytes, $position);
        $this->blockCount = PostingsFormat::blockCount($this->count);

        if (PostingsFormat::hasSkipTable($this->count)) {
            $skipLength = $this->blockCount * PostingsFormat::SKIP_ENTRY_SIZE;
            $this->skips = substr($this->bytes, $position, $skipLength);

            if (strlen($this->skips) !== $skipLength) {
                throw new CorruptSegmentException(
                    "Posting list declares {$this->count} documents but its skip table is truncated"
                );
            }

            $position += $skipLength;
        }

        $this->blocksOffset = $position;

        if ($this->count > 0) {
            $this->loadBlock(0);
        }
    }

    /**
     * @throws CorruptSegmentException
     */
    public static function open(string $bytes): self
    {
        return new self($bytes);
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * The document the cursor sits on, or END once it has run out.
     */
    public function current(): int
    {
        return $this->current;
    }

    /**
     * Moves to the next document and returns it, or END.
     *
     * @throws CorruptSegmentException
     */
    public function next(): int
    {
        if ($this->current === PostingsFormat::END) {
            return PostingsFormat::END;
        }

        $this->positionInBlock++;

        if ($this->positionInBlock < count($this->block)) {
            return $this->current = $this->block[$this->positionInBlock];
        }

        if ($this->blockNumber + 1 >= $this->blockCount) {
            return $this->current = PostingsFormat::END;
        }

        $this->loadBlock($this->blockNumber + 1);

        return $this->current;
    }

    /**
     * Moves to the first document at or after $target and returns it, or END.
     *
     * Never moves backwards: a target already behind the cursor leaves it where
     * it is. That property is what lets several cursors be pushed towards each
     * other in a loop without any of them oscillating.
     *
     * @throws CorruptSegmentException
     */
    public function advance(int $target): int
    {
        if ($this->current === PostingsFormat::END || $target <= $this->current) {
            return $this->current;
        }

        // Jump straight to the block that could hold the target, when the list
        // is long enough to have a skip table.
        $block = $this->blockContaining($target);

        if ($block > $this->blockNumber) {
            $this->loadBlock($block);
        }

        // Then scan forward. At most one block's worth of documents, because the
        // block chosen above is the last one whose first document is not past
        // the target.
        while ($this->current < $target) {
            if ($this->next() === PostingsFormat::END) {
                return PostingsFormat::END;
            }
        }

        return $this->current;
    }

    /**
     * @return int[]
     * @throws CorruptSegmentException
     */
    public function toArray(): array
    {
        $documents = [];
        $cursor    = self::open($this->bytes);

        while ($cursor->current() !== PostingsFormat::END) {
            $documents[] = $cursor->current();
            $cursor->next();
        }

        return $documents;
    }

    // -------------------------------------------------------------------------

    /**
     * The last block whose first document is not past $target.
     *
     * Binary search over the fixed-width skip table, straight out of the string
     * with unpack — no decoding, no allocation.
     */
    private function blockContaining(int $target): int
    {
        if ($this->skips === '') {
            return 0;
        }

        $low   = 0;
        $high  = $this->blockCount - 1;
        $found = 0;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->firstDocumentOfBlock($middle) <= $target) {
                $found = $middle;
                $low   = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }

    private function firstDocumentOfBlock(int $block): int
    {
        return unpack('V', substr($this->skips, $block * PostingsFormat::SKIP_ENTRY_SIZE, 4))[1];
    }

    private function byteOffsetOfBlock(int $block): int
    {
        return unpack('V', substr($this->skips, $block * PostingsFormat::SKIP_ENTRY_SIZE + 4, 4))[1];
    }

    /**
     * Decodes one block into an array of absolute document numbers.
     *
     * @throws CorruptSegmentException
     */
    private function loadBlock(int $number): void
    {
        $position = $this->blocksOffset + ($this->skips === '' ? 0 : $this->byteOffsetOfBlock($number));

        $remaining = min(
            PostingsFormat::BLOCK_SIZE,
            $this->count - $number * PostingsFormat::BLOCK_SIZE
        );

        if ($remaining <= 0) {
            throw new CorruptSegmentException("Posting list has no block $number");
        }

        // The block opens with an absolute document number; the rest are gaps.
        $documents = [Varint::decode($this->bytes, $position)];

        for ($i = 1; $i < $remaining; $i++) {
            $documents[] = $documents[$i - 1] + Varint::decode($this->bytes, $position);
        }

        $this->block           = $documents;
        $this->blockNumber     = $number;
        $this->positionInBlock = 0;
        $this->current         = $documents[0];
    }
}
