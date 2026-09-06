<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Storage\SegmentWriter;
use Ols\PhpFts\Storage\Varint;

/**
 * Builds a whole segment from a batch of documents.
 *
 * This is where the pieces meet:
 *
 *     $writer = new SegmentIndexWriter();
 *     $writer->put('sku-4471', ['title' => 'Brown leather shoe', 'price' => 129.90, …]);
 *     $writer->write('/path/seg_a1f.fts');
 *
 * Each document gets a **local ordinal** — 0, 1, 2 — in the order it arrives.
 * Everything inside the segment is keyed by that number: postings list them,
 * columns are indexed by them, the document store addresses them. The key you
 * passed in is stored separately, in its own dictionary, so it stays yours and
 * stays stable.
 *
 * The sections written:
 *
 *   terms         BlockDictionary  term → where its posting list is
 *   postings      the posting lists, concatenated
 *   dv.<field>    a column per filterable field
 *   dv.<field>.values   the value dictionary of a keyword column
 *   docs          the documents themselves
 *   keys          BlockDictionary  your id → local ordinal
 *   meta          field types and counts, as JSON
 *
 * ── Field types are inferred ────────────────────────────────────────────────
 *
 * There is no schema yet, so types are worked out from the values themselves,
 * across the whole batch:
 *
 *   int, float, bool   →  a numeric column, filterable and sortable
 *   short string       →  analysed into terms *and* given a keyword column,
 *                         so "brand" is both searchable and filterable
 *   long string        →  analysed into terms only; a description is not a
 *                         value anyone filters on, and a column of them would
 *                         be large and useless
 *   array of strings   →  analysed into terms
 *
 * The length threshold is a heuristic standing in for the explicit schema the
 * public API will offer.
 */
final class SegmentIndexWriter
{
    /** Strings at or below this many bytes also get a keyword column. */
    private const KEYWORD_MAX_LENGTH = 64;

    private Analyzer $analyzer;

    /** @var string[] ordinal => the caller's id */
    private array $keys = [];

    /** @var array<int, array<string, mixed>> ordinal => document */
    private array $documents = [];

    /** @var array<string, true> ids already used, to catch duplicates */
    private array $seen = [];

    public function __construct(?Analyzer $analyzer = null)
    {
        $this->analyzer = $analyzer ?? new Analyzer();
    }

    /**
     * @param array<string, mixed> $document
     * @throws StorageException
     */
    public function put(string|int $id, array $document): void
    {
        $id = (string) $id;

        if ($id === '') {
            throw new StorageException('Document ids cannot be empty');
        }

        if (isset($this->seen[$id])) {
            throw new StorageException("Duplicate document id in this batch: '$id'");
        }

        $this->seen[$id]   = true;
        $this->keys[]      = $id;
        $this->documents[] = $document;
    }

    public function count(): int
    {
        return count($this->documents);
    }

    /**
     * Writes the segment.
     *
     * @throws StorageException
     */
    public function write(string $path): void
    {
        $documentCount = count($this->documents);

        $segment = SegmentWriter::create($path);

        try {
            $fields = $this->inferFields();

            $this->writeTermsAndPostings($segment, $documentCount);
            $this->writeColumns($segment, $fields, $documentCount);

            $segment->addSection('docs', DocumentStore::encode($this->documents));
            $segment->addSection('keys', $this->encodeKeys());
            $segment->addSection('meta', (string) json_encode([
                'documentCount' => $documentCount,
                'fields'        => $fields,
            ]));

            $segment->commit();
        } catch (\Throwable $e) {
            $segment->discard();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Works out, for every field seen anywhere in the batch, what it is.
     *
     * @return array<string, string> field => 'number' | 'keyword' | 'text'
     */
    private function inferFields(): array
    {
        $fields = [];

        foreach ($this->documents as $document) {
            foreach ($document as $field => $value) {
                $field    = (string) $field;
                $observed = match (true) {
                    is_int($value), is_float($value), is_bool($value) => 'number',
                    is_string($value) && strlen($value) <= self::KEYWORD_MAX_LENGTH => 'keyword',
                    default => 'text',
                };

                // A field seen as several things settles on the loosest: one
                // long description anywhere means the field is not a keyword.
                $fields[$field] = match (true) {
                    !isset($fields[$field])          => $observed,
                    $fields[$field] === $observed    => $observed,
                    default                          => 'text',
                };
            }
        }

        return $fields;
    }

    /**
     * Analyses every document, then writes the term dictionary and the posting
     * lists it points at.
     *
     * @throws StorageException
     */
    private function writeTermsAndPostings(SegmentWriter $segment, int $documentCount): void
    {
        /** @var array<string, int[]> term => ordinals, ascending */
        $postings = [];

        foreach ($this->documents as $ordinal => $document) {
            foreach ($this->analyzer->analyze($this->textOf($document)) as $term) {
                $postings[$term][] = $ordinal;
            }
        }

        // Sorted bytewise, which the dictionary requires and which UTF-8 makes
        // the same as sorting by code point.
        $terms = array_keys($postings);
        sort($terms, SORT_STRING);

        $dictionary   = new BlockDictionaryWriter();
        $encodedLists = '';

        foreach ($terms as $term) {
            $term    = (string) $term;
            $ordinals = $postings[$term];
            $encoded  = PostingsWriter::encode($ordinals);

            // The payload is everything a search needs before touching the
            // postings: how many documents (for scoring), and where the list is.
            $dictionary->add($term, Varint::encode(count($ordinals))
                . Varint::encode(strlen($encodedLists))
                . Varint::encode(strlen($encoded)));

            $encodedLists .= $encoded;
        }

        $segment->addSection('terms', $dictionary->finish());
        $segment->addSection('postings', $encodedLists);
    }

    /**
     * @param array<string, string> $fields
     * @throws StorageException
     */
    private function writeColumns(SegmentWriter $segment, array $fields, int $documentCount): void
    {
        foreach ($fields as $field => $type) {
            if ($type === 'number') {
                $column = new NumericColumnWriter();

                foreach ($this->documents as $ordinal => $document) {
                    $value = $document[$field] ?? null;

                    $column->add($ordinal, match (true) {
                        is_bool($value)               => $value ? 1.0 : 0.0,
                        is_int($value), is_float($value) => (float) $value,
                        default                       => null,
                    });
                }

                $segment->addSection('dv.' . $field, $column->finish($documentCount));
                continue;
            }

            if ($type === 'keyword') {
                $column = new KeywordColumnWriter();

                foreach ($this->documents as $ordinal => $document) {
                    $value = $document[$field] ?? null;
                    $column->add($ordinal, is_string($value) ? $value : null);
                }

                $sections = $column->finish($documentCount);

                $segment->addSection('dv.' . $field, $sections['ordinals']);
                $segment->addSection('dv.' . $field . '.values', $sections['values']);
            }
        }
    }

    /**
     * @throws StorageException
     */
    private function encodeKeys(): string
    {
        $sorted = $this->keys;
        asort($sorted, SORT_STRING);

        $dictionary = new BlockDictionaryWriter();

        foreach ($sorted as $ordinal => $key) {
            $dictionary->add($key, Varint::encode($ordinal));
        }

        return $dictionary->finish();
    }

    /**
     * Everything in a document that should be searchable, as one string.
     *
     * @param array<string, mixed> $document
     */
    private function textOf(array $document): string
    {
        $parts = [];

        foreach ($document as $value) {
            if (is_string($value)) {
                $parts[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $parts[] = $item;
                    }
                }
            }
        }

        return implode(' ', $parts);
    }
}
