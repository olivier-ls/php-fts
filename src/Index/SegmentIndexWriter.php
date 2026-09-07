<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\FieldTypeException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Schema;
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
 *   meta          the schema and the counts BM25 needs, as JSON
 *
 * ── A schema, declared or inferred ──────────────────────────────────────────
 *
 * A Schema may be passed in, in which case it decides everything: which fields
 * are searchable, which get a column, what each is boosted by, what is stored.
 * A field it does not declare is still stored and returned, but is neither
 * searched nor filtered — the caller said what mattered, and the writer takes
 * them at their word.
 *
 * With no schema, types are worked out from the values themselves, across the
 * whole batch:
 *
 *   int, float, bool   →  a numeric column, filterable and sortable
 *   short string       →  analysed into terms *and* given a keyword column,
 *                         so "brand" is both searchable and filterable
 *   long string        →  analysed into terms only; a description is not a
 *                         value anyone filters on, and a column of them would
 *                         be large and useless
 *   array of strings   →  analysed into terms
 *
 * The length threshold is a heuristic, and it is the reason declaring a schema
 * is worth it: inference reads the batch it is given, so the only way to be
 * certain a field is filterable is to say so. Whichever path was taken, the
 * result is a Schema, written into the segment's meta and frozen by the index
 * at its first commit.
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

    /** Whether the documents were carried across rather than analysed. */
    private bool $carried = false;

    /** @var array<string, array<int, int>> term => ordinal => field mask */
    private array $carriedPostings = [];

    /** @var array<int, array<int, int>> ordinal => bit => length */
    private array $carriedLengths = [];

    /** @var array<int, array<string, mixed>> ordinal => field => column value */
    private array $carriedColumns = [];

    /** The schema actually written, declared or inferred. */
    private ?Schema $effectiveSchema = null;

    /** Summed document lengths, for the average BM25 normalises against. */
    private int $termLengthSum = 0;

    /** @var string[] bit => field name, the mask vocabulary */
    private array $searchableFields = [];

    /** @var array<int, int> bit => summed lengths */
    private array $fieldLengthSums = [];

    private int $maskWidth = 1;

    /**
     * @param Schema|null $schema declared field definitions, used instead of
     *        inferring. The index freezes its schema at its first commit and
     *        passes it here afterwards, because inference depends on the batch:
     *        ten documents with ten distinct titles look like a keyword field,
     *        two hundred do not. Left to re-infer, a merge could change a
     *        type, and a filter that worked on the unmerged segments would then
     *        fail on the merged one.
     */
    public function __construct(?Analyzer $analyzer = null, private readonly ?Schema $schema = null)
    {
        $this->analyzer = $analyzer ?? new Analyzer();
    }

    /**
     * The schema this writer used, declared or inferred, known once write() has
     * run.
     */
    public function schema(): Schema
    {
        return $this->effectiveSchema ?? Schema::make();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws StorageException
     * @throws FieldTypeException when a declared field's value is not of its type
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

        // Checked here rather than at write(): this is where the id, the field
        // and the caller's own loop are all still in view. By write() the batch
        // is half-built and the message could only say that something,
        // somewhere, was of the wrong type.
        //
        // Normalising here also means the term index, the columns and the
        // document store all read one already-agreed value, instead of each
        // making up its own mind about what `['Puma']` was supposed to be.
        $this->seen[$id]   = true;
        $this->keys[]      = $id;
        $this->documents[] = $this->schema?->coerce($document, $id) ?? $document;
    }

    public function count(): int
    {
        return count($this->documents);
    }

    // -------------------------------------------------------------------------
    // The carried path: a document that has already been indexed once.
    //
    // A merge has no text to analyse — `source()` decides what is stored, and a
    // field excluded from it still has terms and a column. So instead of being
    // re-analysed, a document is *carried*: its postings, its field lengths and
    // its column values are read out of the segment it came from and handed
    // over as they are. Everything below the collection step is shared with
    // put(), so both paths produce the same layout by construction rather than
    // by two implementations agreeing.
    // -------------------------------------------------------------------------

    /**
     * One document, already indexed, coming across from another segment.
     *
     * @param array<string, mixed> $stored  the document as it should stay stored
     * @param array<int, int>      $lengths bit => how many terms in that field
     * @param array<string, mixed> $columns field => the value its column holds
     *
     * @internal for SegmentMerger
     * @throws StorageException
     */
    public function carry(string $id, array $stored, array $lengths, array $columns): void
    {
        if ($id === '') {
            throw new StorageException('Document ids cannot be empty');
        }

        if (isset($this->seen[$id])) {
            throw new StorageException("Duplicate document id in this batch: '$id'");
        }

        // No coercion: these values were coerced when they were first indexed,
        // against this same frozen schema. Running them through again would at
        // best be work and at worst would refuse, on a merge, a document the
        // index already holds.
        $this->seen[$id]        = true;
        $this->keys[]           = $id;
        $this->documents[]      = $stored;
        $this->carriedLengths[] = $lengths;
        $this->carriedColumns[] = $columns;
        $this->carried          = true;
    }

    /**
     * The postings of a whole segment being carried, added to what is already
     * accumulated.
     *
     * Per segment rather than per document, because that is the order they are
     * read in — see SegmentIndex::postingsByTerm(). The ordinals are the
     * *new* ones, which only the caller knows.
     *
     * @param array<string, array<int, int>> $postings term => ordinal => field mask
     *
     * @internal for SegmentMerger
     */
    public function carryPostings(array $postings): void
    {
        foreach ($postings as $term => $byOrdinal) {
            foreach ($byOrdinal as $ordinal => $mask) {
                // A term found in several fields keeps one posting with several
                // bits set, exactly as the analysing path builds it.
                $this->carriedPostings[$term][$ordinal] =
                    ($this->carriedPostings[$term][$ordinal] ?? 0) | $mask;
            }
        }
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
            // A declared schema wins outright. Fields it does not mention are
            // kept and returned but neither searched nor filtered — a catalogue
            // export gains columns all the time, and inferring the stragglers
            // would bring back exactly the drift the schema exists to remove.
            //
            // With no schema, everything is inferred and the result is turned
            // into one, so declared and inferred indexes take the same path
            // from here on.
            if ($this->carried && $this->schema === null) {
                // Impossible by construction — a merge only happens after a
                // commit, and a commit freezes a schema — but inferring from
                // carried documents would read a `source()`-narrowed copy and
                // quietly give a field a different type. Better to say so.
                throw new StorageException('Carried documents need the frozen schema; none was given');
            }

            $schema = $this->schema ?? Schema::inferred($this->inferFields());

            $this->effectiveSchema = $schema;

            $this->writeTermsAndPostings($segment, $documentCount, $schema);
            $this->writeColumns($segment, $schema, $documentCount);

            $segment->addSection('docs', DocumentStore::encode($this->storedDocuments($schema), $this->keys));
            $segment->addSection('keys', $this->encodeKeys());
            $segment->addSection('meta', (string) json_encode([
                'documentCount'    => $documentCount,
                'termLengthSum'    => $this->termLengthSum,
                'schema'           => $schema->toArray(),
                'searchableFields' => $this->searchableFields,
                'fieldLengthSums'  => $this->fieldLengthSums,
                'maskWidth'        => $this->maskWidth,
            ]));

            $segment->commit();
        } catch (\Throwable $e) {
            $segment->discard();
            throw $e;
        }
    }

    /**
     * The documents as they should be stored, with whatever the schema excludes
     * left out.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storedDocuments(Schema $schema): array
    {
        $stored = [];

        foreach ($this->documents as $ordinal => $document) {
            $stored[$ordinal] = $schema->project($document);
        }

        return $stored;
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
     * A **list** is judged by the same measure, applied to its items: short and
     * repeated across documents is a set of tags, and earns a multi-valued
     * column that can be filtered and faceted. A list of paragraphs is prose in
     * an array and stays text. The PHP type decides the *shape*, and repetition
     * decides whether that shape is worth a column — the same rule as for a
     * bare string, which is the point.
     *
     * @return array<string, string> field => 'number' | 'keyword' | 'tags' | 'text'
     */
    private function inferFields(): array
    {
        $documentCount = count($this->documents);

        /** @var array<string, bool> */
        $numeric = [];
        /** @var array<string, bool> */
        $tooLong = [];
        /** @var array<string, bool> */
        $listed = [];
        /** @var array<string, array<string, true>> */
        $distinct = [];

        foreach ($this->documents as $document) {
            foreach ($document as $field => $value) {
                $field = (string) $field;

                if (is_int($value) || is_float($value) || is_bool($value)) {
                    $numeric[$field] = true;
                    continue;
                }

                if (is_array($value)) {
                    // A list keeps its shape: whatever its items turn out to
                    // be, this field is multi-valued and cannot be a plain
                    // keyword column.
                    $listed[$field] = true;

                    foreach ($value as $item) {
                        if (is_int($item) || (is_float($item) && is_finite($item))) {
                            $item = (string) $item;
                        }

                        if (!is_string($item)) {
                            continue;
                        }

                        if (strlen($item) > self::KEYWORD_MAX_LENGTH) {
                            $tooLong[$field] = true;
                            continue;
                        }

                        $distinct[$field][$item] = true;
                    }

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

            if (isset($tooLong[$field]) || count($values) > $ceiling) {
                $fields[$field] = 'text';
                continue;
            }

            $fields[$field] = isset($listed[$field]) ? 'tags' : 'keyword';
        }

        foreach ($tooLong as $field => $_) {
            $fields[$field] ??= 'text';
        }

        // A list that contributed no usable item — every value was an object,
        // or every list was empty — is still a field, and still text.
        foreach ($listed as $field => $_) {
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
    /**
     * Analyses every document field by field, then writes the dictionary, the
     * posting lists, the field masks and the field lengths.
     *
     * ── Why fields are analysed separately ─────────────────────────────────
     *
     * Scoring has to know *where* a term was found: matching in a title means
     * more than matching in a description, and that is what `boosts` is for.
     * So each searchable field is analysed on its own, and for every
     * (term, document) pair the fields holding it are recorded as a bitmap.
     *
     * The mask lives in its own section rather than interleaved with the
     * postings. Selecting candidates reads only the compact delta stream; the
     * masks are touched for the handful of documents that reach scoring.
     * Interleaving would have hurt both the compression and the skipping.
     *
     * @throws StorageException
     */
    private function writeTermsAndPostings(SegmentWriter $segment, int $documentCount, Schema $schema): void
    {
        $searchable = $schema->searchableFields();
        $maskWidth  = max(1, (int) ceil(count($searchable) / 8));

        // The two ways a segment can come by its postings. Everything after
        // this line is shared, so a carried segment and an analysed one cannot
        // disagree about the layout — there is only one encoder.
        [$postings, $lengths, $sums] = $this->carried
            ? $this->carriedTerms($searchable)
            : $this->analysedTerms($searchable);

        // Sorted bytewise, which the dictionary requires and which UTF-8 makes
        // the same as sorting by code point.
        $terms = array_keys($postings);
        sort($terms, SORT_STRING);

        $dictionary   = new BlockDictionaryWriter();
        $encodedLists = '';
        $masks        = '';

        foreach ($terms as $term) {
            $term = (string) $term;

            $byOrdinal = $postings[$term];
            ksort($byOrdinal);

            $ordinals = array_keys($byOrdinal);
            $encoded  = PostingsWriter::encode($ordinals);

            // Everything a search needs before touching the postings: how many
            // documents hold the term, where its list is, and where its masks
            // are.
            $dictionary->add($term, Varint::encode(count($ordinals))
                . Varint::encode(strlen($encodedLists))
                . Varint::encode(strlen($encoded))
                . Varint::encode(strlen($masks)));

            $encodedLists .= $encoded;

            foreach ($byOrdinal as $mask) {
                for ($byte = 0; $byte < $maskWidth; $byte++) {
                    $masks .= chr(($mask >> ($byte * 8)) & 0xFF);
                }
            }
        }

        $segment->addSection('terms', $dictionary->finish());
        $segment->addSection('postings', $encodedLists);

        // With one searchable field every mask byte holds the same single bit,
        // so it says nothing — and BM25F over one neutral field gives exactly
        // the number plain BM25 gives. Measured on a 2 000-product catalogue
        // with three fields the masks came to 23% of the segment, so a schema
        // that does not need them should not carry them.
        if (count($searchable) > 1) {
            $segment->addSection('fieldmask', $masks);
        }

        // Fixed width, addressed by document number then field — the same rule
        // as the doc-values columns. Clamped at 65 535 terms in one field, which
        // a value would have to be about ten thousand words long to reach.
        $packed = '';

        for ($ordinal = 0; $ordinal < $documentCount; $ordinal++) {
            foreach (array_keys($sums) as $bit) {
                $packed .= pack('v', min(0xFFFF, $lengths[$ordinal][$bit] ?? 0));
            }
        }

        $segment->addSection('lengths', $packed);

        $this->searchableFields = $searchable;
        $this->fieldLengthSums  = $sums;
        $this->maskWidth        = $maskWidth;
        $this->termLengthSum    = array_sum($sums);
    }

    /**
     * Analyses every document, field by field.
     *
     * @param string[] $searchable bit => field name
     * @return array{0: array<string, array<int, int>>, 1: array<int, array<int, int>>, 2: array<int, int>}
     */
    private function analysedTerms(array $searchable): array
    {
        /** @var array<string, array<int, int>> term => ordinal => field bitmap */
        $postings = [];

        /** @var array<int, array<int, int>> ordinal => bit => terms in that field */
        $lengths = [];

        /** @var array<int, int> bit => summed lengths across the segment */
        $sums = array_fill(0, max(1, count($searchable)), 0);

        foreach ($this->documents as $ordinal => $document) {
            foreach ($searchable as $bit => $field) {
                $terms = $this->analyzer->analyze($this->textOf($document[$field] ?? null));

                // Recorded now because it cannot be recovered from the postings
                // without walking every one of them, and BM25F needs a length
                // per field, not per document.
                $lengths[$ordinal][$bit] = count($terms);
                $sums[$bit]             += count($terms);

                foreach ($terms as $term) {
                    // A term found in several fields keeps one posting with
                    // several bits set, rather than one posting per field.
                    $postings[$term][$ordinal] = ($postings[$term][$ordinal] ?? 0) | (1 << $bit);
                }
            }
        }

        return [$postings, $lengths, $sums];
    }

    /**
     * The postings and lengths that came across from other segments.
     *
     * Nothing is analysed and nothing is recomputed: a term the source segment
     * held is a term this one holds, and a field length measured once is
     * measured. The sums are re-added because they are a property of *this*
     * segment, whose documents are a subset of the sources' — anything deleted
     * along the way must not still be in the average BM25 normalises against.
     *
     * @param string[] $searchable bit => field name
     * @return array{0: array<string, array<int, int>>, 1: array<int, array<int, int>>, 2: array<int, int>}
     */
    private function carriedTerms(array $searchable): array
    {
        $sums    = array_fill(0, max(1, count($searchable)), 0);
        $lengths = [];

        foreach ($this->carriedLengths as $ordinal => $byBit) {
            foreach (array_keys($sums) as $bit) {
                $length = $byBit[$bit] ?? 0;

                $lengths[$ordinal][$bit] = $length;
                $sums[$bit]             += $length;
            }
        }

        return [$this->carriedPostings, $lengths, $sums];
    }


    /**
     * The value a column should record for one document.
     *
     * The document is the source of truth when it was analysed here, and the
     * *old column* is when it was carried across. That distinction is the fix
     * for `source()`: a field the schema does not store is not in the document
     * a merge reads back, but its value never left the columnar side of the
     * segment, so it is still there to be copied.
     */
    private function valueForColumn(int $ordinal, string $field): mixed
    {
        return $this->carried
            ? ($this->carriedColumns[$ordinal][$field] ?? null)
            : ($this->documents[$ordinal][$field] ?? null);
    }

    /**
     * @param Schema $schema decides which fields earn a column
     * @throws StorageException
     */
    private function writeColumns(SegmentWriter $segment, Schema $schema, int $documentCount): void
    {
        foreach ($schema->fields() as $field => $definition) {
            if (!$definition['filterable']) {
                continue;
            }

            $type = $definition['type'];

            if ($type === 'number' || $type === 'boolean') {
                $column = new NumericColumnWriter();

                foreach (array_keys($this->documents) as $ordinal) {
                    $value = $this->valueForColumn($ordinal, $field);

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

                foreach (array_keys($this->documents) as $ordinal) {
                    $value = $this->valueForColumn($ordinal, $field);
                    $column->add($ordinal, is_string($value) ? $value : null);
                }

                $sections = $column->finish($documentCount);

                $segment->addSection('dv.' . $field, $sections['ordinals']);
                $segment->addSection('dv.' . $field . '.values', $sections['values']);
                continue;
            }

            if ($type === 'tags') {
                $column = new TagColumnWriter();

                foreach (array_keys($this->documents) as $ordinal) {
                    $value = $this->valueForColumn($ordinal, $field);

                    // A single string is a one-element list. The writer accepts
                    // it, so a document whose one tag arrived unwrapped is not a
                    // document with no tags.
                    $column->add($ordinal, is_string($value) || is_array($value) ? $value : null);
                }

                $sections = $column->finish($documentCount);

                // The same two section names as a keyword column, and the same
                // value dictionary inside them. What differs is the shape of
                // the first one, which its own magic identifies.
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
     * One field's value as searchable text.
     *
     * A list of tags becomes its items joined by a space, so each is analysed
     * as its own word rather than running into its neighbour.
     */
    private function textOf(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $parts[] = $item;
            }
        }

        return implode(' ', $parts);
    }
}
