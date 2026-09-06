<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\StorageException;

/**
 * Builds a column of numbers, one slot per document.
 *
 *     $writer = new NumericColumnWriter();
 *     $writer->add(0, 129.90);
 *     $writer->add(1, null);        // the field is absent from this document
 *     $writer->add(2, 89.00);
 *     $bytes = $writer->finish();
 *
 * Every value occupies exactly eight bytes whether or not the document has one,
 * which is the opposite of the choice made for postings — and deliberate. A
 * column is addressed *by document number*: answering "what is document 4 712's
 * price" has to be a seek to `4712 × 8`, not a walk through 4 711 varints. That
 * is the rule the format follows throughout: variable-length where you scan,
 * fixed-width where you jump.
 *
 * A missing value is not zero. Zero is a price, and a stock of zero is the
 * thing shops filter on most. Presence is therefore recorded separately, one
 * bit per document, so that `stock >= 0` can include a document whose stock is
 * genuinely 0 while excluding one that has no stock field at all.
 */
final class NumericColumnWriter
{
    /** @var array<int, float> document number => value */
    private array $values = [];

    private int $highestDocument = -1;
    private bool $finished = false;

    /**
     * @param float|null $value null when the document has no such field
     * @throws StorageException
     */
    public function add(int $document, ?float $value): void
    {
        if ($this->finished) {
            throw new StorageException('Column is already finished');
        }

        if ($document < 0) {
            throw new StorageException("Document numbers cannot be negative, got $document");
        }

        if ($document <= $this->highestDocument) {
            throw new StorageException(
                "Column values must be added in document order: $document came after {$this->highestDocument}"
            );
        }

        $this->highestDocument = $document;

        if ($value !== null) {
            $this->values[$document] = $value;
        }
    }

    /**
     * @param int $documentCount total documents in the segment, so that trailing
     *                           documents without a value still get a slot
     * @throws StorageException
     */
    public function finish(int $documentCount): string
    {
        if ($this->finished) {
            throw new StorageException('Column is already finished');
        }

        if ($documentCount <= $this->highestDocument) {
            throw new StorageException(
                "Column holds document {$this->highestDocument} but the segment declares $documentCount"
            );
        }

        $this->finished = true;

        $presence = Bitset::empty($documentCount);
        $slots    = '';

        for ($document = 0; $document < $documentCount; $document++) {
            if (isset($this->values[$document])) {
                $presence->set($document);
                $slots .= pack('e', $this->values[$document]);
            } else {
                // The slot still exists so that the Nth value stays at offset
                // N × 8; its content is never read, because the presence bit
                // says there is nothing there.
                $slots .= "\x00\x00\x00\x00\x00\x00\x00\x00";
            }
        }

        return NumericColumn::MAGIC
            . pack('C', NumericColumn::VERSION)
            . "\x00\x00\x00"
            . pack('V', $documentCount)
            . $presence->bytes()
            . $slots;
    }
}
