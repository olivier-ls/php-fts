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
    /** A string longer than this is prose, never a value to filter on. */
    private const KEYWORD_MAX_LENGTH = 64;

    /**
     * Distinct values a string field may have and still earn a column, however
     * few documents there are. Below this the column costs almost nothing, and
     * the "few values relative to documents" test is meaningless anyway.
     */
    private const KEYWORD_MIN_DISTINCT = 64;

    private Analyzer $analyzer;

    /** @var string[] ordinal => the caller's id */
    private array $keys = [];

    /** @var array<int, array<string, mixed>> ordinal => document */
    private array $documents = [];

    /** @var array<string, true> ids already used, to catch duplicates */
    private array $seen = [];

    /** @var array<string, string>|null the types actually written */
    private ?array $effectiveFields = null;

    /** Summed document lengths, for the average BM25 normalises against. */
    private int $termLengthSum = 0;

    /**
     * @param array<string, string>|null $fields field => type, to use instead of
     *        inferring. The index freezes its field types at its first commit and
     *        passes them here afterwards, because inference depends on the batch:
     *        ten documents with ten distinct titles look like a keyword field,
     *        two hundred do not. Left to re-infer, a merge could change a field's
     *        type, and a filter that worked on the unmerged segments would then
     *        fail on the merged one.
     */
    public function __construct(?Analyzer $analyzer = null, private readonly ?array $fields = null)
    {
        $this->analyzer = $analyzer ?? new Analyzer();
    }

    /**
     * The field types this writer used, known once write() has run.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->effectiveFields ?? [];
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
            // A frozen schema wins, but a field it has never seen still needs a
            // type — adding a field to later documents must not make it
            // unfilterable.
            $fields = $this->fields === null
                ? $this->inferFields()
                : $this->fields + $this->inferFields();

            $this->effectiveFields = $fields;

            $this->writeTermsAndPostings($segment, $documentCount);
            $this->writeColumns($segment, $fields, $documentCount);

            $segment->addSection('docs', DocumentStore::encode($this->documents));
            $segment->addSection('keys', $this->encodeKeys());
            $segment->addSection('meta', (string) json_encode([
                'documentCount' => $documentCount,
                'termLengthSum' => $this->termLengthSum,
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
     * Numbers are easy. Strings are the interesting case, and length alone is
     * not enough to decide: a product title is short, and giving it a keyword
     * column is pure waste — nobody filters on an exact title and nobody facets
     * one, because every value is unique.
     *
     * What actually distinguishes a keyword from prose is **repetition**. A
     * brand appears across hundreds of documents; a title appears once. So a
     * string field earns a column when its distinct values are few relative to
     * the number of documents — which is the same thing as saying it is worth
     * faceting on.
     *
     * The small-index allowance exists because the ratio means nothing when
     * there are ten documents: everything looks unique, and the column would be
     * tiny anyway.
     *
     * @return array<string, string> field => 'number' | 'keyword' | 'text'
     */
    private function inferFields(): array
    {
        $documentCount = count($this->documents);

        /** @var array<string, bool> */
        $numeric = [];
        /** @var array<string, bool> */
        $tooLong = [];
        /** @var array<string, array<string, true>> */
        $distinct = [];

        foreach ($this->documents as $document) {
            foreach ($document as $field => $value) {
                $field = (string) $field;

                if (is_int($value) || is_float($value) || is_bool($value)) {
                    $numeric[$field] = true;
                    continue;
                }

                if (!is_string($value)) {
                    continue;
                }

                if (strlen($value) > self::KEYWORD_MAX_LENGTH) {
                    $tooLong[$field] = true;
                    continue;
                }

                $distinct[$field][$value] = true;
            }
        }

        $ceiling = max(self::KEYWORD_MIN_DISTINCT, intdiv($documentCount, 2));
        $fields  = [];

        foreach ($numeric as $field => $_) {
            $fields[$field] = 'number';
        }

        foreach ($distinct as $field => $values) {
            if (isset($fields[$field])) {
                continue;   // already numeric somewhere; numbers win
            }

            $fields[$field] = !isset($tooLong[$field]) && count($values) <= $ceiling
                ? 'keyword'
                : 'text';
        }

        foreach ($tooLong as $field => $_) {
            $fields[$field] ??= 'text';
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

        /** @var int[] ordinal => how many terms the document produced */
        $lengths = [];

        foreach ($this->documents as $ordinal => $document) {
            $terms = $this->analyzer->analyze($this->textOf($document));

            // BM25 penalises a document for being longer than average, which is
            // what stops a long description from outranking a precise title
            // simply by colliding with more of the query by accident. The count
            // has to be recorded now: it cannot be recovered from the postings
            // without walking every one of them.
            $lengths[$ordinal] = count($terms);

            foreach ($terms as $term) {
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

        // Fixed width, addressed by document number — the same rule as the
        // doc-values columns. Clamped at 65 535, which a document would have to
        // be about ten thousand words long to reach.
        $packed  = '';
        $total   = 0;

        for ($ordinal = 0; $ordinal < $documentCount; $ordinal++) {
            $length  = min(0xFFFF, $lengths[$ordinal] ?? 0);
            $packed .= pack('v', $length);
            $total  += $length;
        }

        $segment->addSection('lengths', $packed);

        $this->termLengthSum = $total;
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
