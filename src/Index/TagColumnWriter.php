<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Storage\Varint;

/**
 * Builds a column of *lists* of exact values — the tags of a product.
 *
 *     $writer = new TagColumnWriter();
 *     $writer->add(0, ['summer', 'luxury']);
 *     $writer->add(1, ['winter']);
 *     $writer->add(2, null);
 *     ['ordinals' => $a, 'values' => $b] = $writer->finish(3);
 *
 * ── Why this is not a KeywordColumn ─────────────────────────────────────────
 *
 * A keyword column holds one ordinal per document, at a fixed width, so
 * document *d* is at byte `d × width` and nothing has to be looked up to find
 * it. That is what makes it fast, and it is also exactly what a list cannot
 * have: a document with three tags and one with none do not occupy the same
 * number of bytes.
 *
 * So the values are laid end to end, and every document gets an *offset* into
 * that run instead of a value:
 *
 *     offsets    [ 0, 2, 3, 3 ]              one more entry than documents
 *     ordinals   [ luxury, summer, winter ]  document 0 owns [0,2), 1 owns [2,3)
 *
 * Two things fall out of it, and both are why the layout is this one:
 *
 *   - **Random access survives.** Offsets are fixed width, so document *d*'s
 *     range is still two reads at computable positions — no scan from the
 *     start of the column to find the third document's tags.
 *   - **Presence needs no bitmap.** A document with no tags is a range of
 *     length zero, so `exists()` is `offsets[d + 1] > offsets[d]` and the
 *     keyword column's presence bitset is one structure this one does without.
 *
 * ── Sorted and deduplicated, per document ───────────────────────────────────
 *
 * The ordinals of one document are stored ascending and without repeats. The
 * sorting is free — it is what lets a reader stop early — and the dedup is not
 * cosmetic: `['summer', 'summer']` counted twice would make a facet report more
 * documents for `summer` than there are documents, which is the kind of number
 * a shopper notices.
 */
final class TagColumnWriter
{
    /** @var array<int, array<string, string>> document => value => value */
    private array $values = [];

    /**
     * Canonical instance of each distinct value, so that a tag shared by
     * twenty thousand documents is one string rather than twenty thousand.
     *
     * @var array<string, string>
     */
    private array $distinct = [];

    private int $highestDocument = -1;
    private bool $finished = false;

    /**
     * @param string|array<mixed>|null $value a list, a single value, or nothing.
     *        A bare string is accepted as a one-element list, the same tolerance
     *        the schema has: a product with one tag is not a different shape of
     *        product.
     *
     * @throws StorageException
     */
    public function add(int $document, string|array|null $value): void
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

        if ($value === null) {
            return;
        }

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_int($item) || (is_float($item) && is_finite($item))) {
                $item = (string) $item;
            }

            // An empty string is treated as absent, as in a keyword column: a
            // column exists to answer "which documents carry tag X", and the
            // empty tag is not a tag.
            if (!is_string($item) || $item === '') {
                continue;
            }

            $this->distinct[$item]     ??= $item;
            $this->values[$document][$item] = $this->distinct[$item];
        }
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

        // Sorted bytewise, as the dictionary requires, which also makes ordinal
        // order and alphabetical order the same thing.
        $sorted = array_keys($this->distinct);
        sort($sorted, SORT_STRING);

        $ordinalOf = array_flip($sorted);

        $dictionary = new BlockDictionaryWriter();

        foreach ($sorted as $ordinal => $value) {
            $dictionary->add((string) $value, Varint::encode($ordinal));
        }

        $total = 0;

        foreach ($this->values as $values) {
            $total += count($values);
        }

        $ordinalWidth = TagColumn::width(count($sorted));
        $offsetWidth  = TagColumn::width($total);

        $ordinalFormat = TagColumn::formatFor($ordinalWidth);
        $offsetFormat  = TagColumn::formatFor($offsetWidth);

        $offsets  = '';
        $ordinals = '';
        $cursor   = 0;

        for ($document = 0; $document < $documentCount; $document++) {
            $offsets .= pack($offsetFormat, $cursor);

            if (!isset($this->values[$document])) {
                continue;
            }

            $mine = [];

            foreach ($this->values[$document] as $value) {
                $mine[] = $ordinalOf[$value];
            }

            // Ascending, so a reader comparing against a sorted set of wanted
            // ordinals can stop as soon as it is past them.
            sort($mine, SORT_NUMERIC);

            foreach ($mine as $ordinal) {
                $ordinals .= pack($ordinalFormat, $ordinal);
            }

            $cursor += count($mine);
        }

        // The sentinel: the last document's range ends where the values end,
        // so `offsets[d + 1]` is always readable and no length has to be
        // special-cased for the final document.
        $offsets .= pack($offsetFormat, $cursor);

        $header = TagColumn::MAGIC
            . pack('C', TagColumn::VERSION)
            . pack('C', $ordinalWidth)
            . pack('C', $offsetWidth)
            . "\x00"
            . pack('V', $documentCount)
            . pack('V', count($sorted))
            . pack('V', $total);

        return [
            'ordinals' => $header . $offsets . $ordinals,
            'values'   => $dictionary->finish(),
        ];
    }
}
