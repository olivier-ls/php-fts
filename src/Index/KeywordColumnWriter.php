<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Storage\Varint;

/**
 * Builds a column of exact string values — a brand, a category, a colour.
 *
 *     $writer = new KeywordColumnWriter();
 *     $writer->add(0, 'Adidas');
 *     $writer->add(1, 'Puma');
 *     $writer->add(2, 'Adidas');
 *     ['ordinals' => $a, 'values' => $b] = $writer->finish(3);
 *
 * ── Dictionary encoding ─────────────────────────────────────────────────────
 *
 * A catalogue of twenty thousand products has maybe fifty brands. Storing the
 * string against every document would cost around 120 KB and make counting a
 * facet a matter of comparing strings twenty thousand times. Instead the
 * distinct values are stored once, in sorted order, and each document holds a
 * small integer pointing into that list.
 *
 * Two consequences follow, and both matter more than the space saved:
 *
 *   - **Counting a facet becomes counting integers.** Tallying brands over the
 *     matching documents is `$counts[$ordinal]++` — no string comparison, no
 *     hashing, no document read. Only the handful of counts actually reported
 *     are turned back into strings at the end.
 *
 *   - **Filtering becomes one lookup then a scan for one integer.** `brand =
 *     "Adidas"` resolves the string to its ordinal once, against the value
 *     dictionary, and the column scan then compares small integers.
 *
 * Ordinals are assigned in sorted value order, so ordinal order and alphabetical
 * order are the same thing. That is what will let a range query over keywords
 * work later without any further structure.
 *
 * ── Two sections rather than one ────────────────────────────────────────────
 *
 * The value dictionary is a BlockDictionary — the same structure the term index
 * uses — so it is written to its own segment section and looked up the same
 * way. Reusing it costs nothing and means value lookup is already front-coded,
 * block-indexed and binary-searched.
 */
final class KeywordColumnWriter
{
    /** @var array<int, string> document number => value */
    private array $values = [];

    /**
     * Canonical instance of each distinct value, so that repeating a brand
     * across twenty thousand documents holds one string rather than twenty
     * thousand copies of it.
     *
     * @var array<string, string>
     */
    private array $distinct = [];

    private int $highestDocument = -1;
    private bool $finished = false;

    /**
     * @param string|null $value null when the document has no such field
     * @throws StorageException
     */
    public function add(int $document, ?string $value): void
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

        if ($value === null || $value === '') {
            // An empty string is treated as absent. A keyword column exists to
            // answer "which documents have brand X", and a document whose brand
            // is the empty string has no brand.
            return;
        }

        $this->distinct[$value]  ??= $value;
        $this->values[$document]   = $this->distinct[$value];
    }

    /**
     * @return array{ordinals: string, values: string} the two section payloads
     * @throws StorageException
     */
    public function finish(int $documentCount): array
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

        // Sorted bytewise, which is also the order the dictionary requires and
        // which makes ordinal order and alphabetical order the same.
        $sorted = array_keys($this->distinct);
        sort($sorted, SORT_STRING);

        $ordinalOf = array_flip($sorted);
        $width     = KeywordColumn::ordinalWidth(count($sorted));

        $dictionary = new BlockDictionaryWriter();

        foreach ($sorted as $ordinal => $value) {
            $dictionary->add((string) $value, Varint::encode($ordinal));
        }

        $presence = Bitset::empty($documentCount);
        $ordinals = '';
        $format   = match ($width) {
            1 => 'C',
            2 => 'v',
            default => 'V',
        };

        for ($document = 0; $document < $documentCount; $document++) {
            if (isset($this->values[$document])) {
                $presence->set($document);
                $ordinals .= pack($format, $ordinalOf[$this->values[$document]]);
            } else {
                $ordinals .= str_repeat("\x00", $width);
            }
        }

        $header = KeywordColumn::MAGIC
            . pack('C', KeywordColumn::VERSION)
            . pack('C', $width)
            . "\x00\x00"
            . pack('V', $documentCount)
            . pack('V', count($sorted));

        return [
            'ordinals' => $header . $presence->bytes() . $ordinals,
            'values'   => $dictionary->finish(),
        ];
    }
}
