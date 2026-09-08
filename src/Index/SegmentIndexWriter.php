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

    /**
     * @var array<int, array<string, mixed>> ordinal => document
     *
     * Only the analysing path fills this: it has to, because a field's type is
     * inferred from the whole batch and a term's postings are only complete
     * once every document has been read. A carried document goes straight into
     * the store below and is not kept.
     */
    private array $documents = [];

    /** How many documents have arrived, by either path. */
    private int $count = 0;

    /** The documents as they will be stored, encoded as they arrive. */
    private ?DocumentStoreWriter $store = null;

    /** @var array<string, true> ids already used, to catch duplicates */
    private array $seen = [];

    /** Whether the documents were carried across rather than analysed. */
    private bool $carried = false;

    /**
     * Opens the carried postings, in ascending term order.
     *
     * A closure rather than a Generator, because it is handed over before the
     * documents are, and must not start running until write() asks for it.
     *
     * @var (\Closure(): \Generator<string, array<int, string>>)|null
     */
    private ?\Closure $postingsStream = null;

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

    /** Bytes per posting in the `fieldfreq` section: one per searchable field. */
    private int $frequencyWidth = 1;

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
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
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

        if ($this->schema === null) {
            // Impossible by construction — a merge only happens after a commit,
            // and a commit freezes a schema — but the store below has to
            // project the document *now*, and with no schema to project
            // against it would store a `source()`-narrowed copy as if it were
            // the whole thing. Better to say so, here, where the id is still
            // in view.
            throw new StorageException('Carried documents need the frozen schema; none was given');
        }

        // No coercion: these values were coerced when they were first indexed,
        // against this same frozen schema. Running them through again would at
        // best be work and at worst would refuse, on a merge, a document the
        // index already holds.
        $this->seen[$id] = true;
        $this->keys[]    = $id;

        // Encoded and let go, rather than kept until write(). This is the
        // difference between a merge that holds its whole input and one that
        // holds a document at a time — see DocumentStoreWriter.
        $this->store ??= new DocumentStoreWriter();
        $this->store->add($this->schema->project($stored), $id);

        $this->carriedLengths[] = $lengths;
        $this->carriedColumns[] = $columns;
        $this->carried          = true;
        $this->count++;
    }

    /**
     * Where the carried postings will come from, when write() asks.
     *
     * A **stream**, not a map, and that is the whole point of it. The obvious
     * shape — hand over one segment's postings at a time and let the writer
     * accumulate them — costs about 75 bytes per posting, because a nested PHP
     * array is what it accumulates into. Measured on a real catalogue that was
     * 106 MB for ten thousand documents and 700 MB for forty-five thousand, on
     * an operation nobody asked for and whose size nobody chose.
     *
     * A stream costs one term. The caller merges its sources' dictionaries —
     * all sorted, all readable in order — and yields each term once, already
     * remapped; the encoder below writes it and forgets it. What is left in
     * memory is the largest posting list in the index, which is bounded by the
     * document count rather than by the vocabulary.
     *
     * The closure is called once, at write() time. Handing over a Generator
     * directly would start the merge before the ordinals it translates to are
     * known.
     *
     * @param \Closure(): \Generator<string, array<int, string>> $stream yields
     *        term => ordinal => field mask, terms in ascending byte order and
     *        ordinals already translated to this segment's
     *
     * @internal for SegmentMerger
     */
    public function carryPostings(\Closure $stream): void
    {
        $this->postingsStream = $stream;
    }

    /**
     * Writes the segment.
     *
     * @throws StorageException
     */
    public function write(string $path): void
    {
        $documentCount = $this->count;

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
            $schema = $this->schema ?? Schema::inferred($this->inferFields());

            $this->effectiveSchema = $schema;

            $this->writeTermsAndPostings($segment, $documentCount, $schema);
            $this->writeColumns($segment, $schema, $documentCount);

            // A carried document was projected and encoded as it arrived; an
            // analysed one could not be, because the schema it is projected
            // against is only settled a few lines above. It is still streamed
            // rather than concatenated, which is what saves the second copy of
            // the batch the projection used to make.
            $store = $this->store ?? $this->encodeDocuments($schema);

            $store->writeTo($segment, 'docs');

            $segment->addSection('keys', $this->encodeKeys());
            $segment->addSection('keyfwd', $this->encodeForwardKeys());
            $segment->addSection('meta', (string) json_encode([
                'documentCount'    => $documentCount,
                'termLengthSum'    => $this->termLengthSum,
                'schema'           => $schema->toArray(),
                'searchableFields' => $this->searchableFields,
                'fieldLengthSums'  => $this->fieldLengthSums,
                'frequencyWidth'   => $this->frequencyWidth,
            ]));

            $segment->commit();
        } catch (\Throwable $e) {
            $segment->discard();
            throw $e;
        }
    }

    /**
     * The analysed batch, projected and encoded into a store.
     *
     * @throws StorageException
     */
    private function encodeDocuments(Schema $schema): DocumentStoreWriter
    {
        $store = new DocumentStoreWriter();

        foreach ($this->documents as $ordinal => $document) {
            $store->add($schema->project($document), $this->keys[$ordinal] ?? '');
        }

        return $store;
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

        // One byte per field, where the mask was one *bit* per field, so this
        // scales with how many fields a schema searches. The reference
        // catalogue searches five, over 2 171 183 postings: 2.2 MB of masks
        // becomes 10.9 MB of frequencies out of a 69.8 MB index, and buys the
        // term frequency BM25 had been missing entirely.
        $width = max(1, count($searchable));

        // The two ways a segment can come by its postings. Both hand over the
        // same thing — an ordered stream of (term, ordinal => mask) — so a
        // carried segment and an analysed one cannot disagree about the
        // layout: there is one encoder below, and it consumes a stream.
        [$stream, $lengths, $sums] = $this->carried
            ? $this->carriedTerms($searchable)
            : $this->analysedTerms($searchable);

        $dictionary   = new BlockDictionaryWriter();
        $frequencies  = '';
        $written      = 0;

        // The second tier, built as the terms stream past. Two buffers per gram
        // rather than a list of terms per gram: the terms arrive sorted, so
        // each list can be encoded the moment it grows and never held as an
        // array. That bounds this by the vocabulary — around 25 000 grams and
        // a couple of megabytes — instead of by the postings, which on this
        // catalogue would have been 590 000 array slots. See TermGramIndex.
        /** @var array<string, string> gram => front-coded payload so far */
        $gramPayloads = [];

        /** @var array<string, string> gram => the last term appended to it */
        $gramPrevious = [];

        // Streamed, not concatenated. A posting list is written the moment its
        // term is complete and is not held afterwards, which is what lets a
        // merge run in the memory of its largest term rather than of its whole
        // vocabulary. The masks are still buffered, because they are a section
        // of their own and only one can be open at a time — one byte per
        // posting, against the ~75 a nested array costs.
        $segment->beginSection('postings');

        foreach ($stream as $term => $byOrdinal) {
            $term = (string) $term;

            ksort($byOrdinal);

            $ordinals = array_keys($byOrdinal);
            $encoded  = PostingsWriter::encode($ordinals);

            // Everything a search needs before touching the postings: how many
            // documents hold the term, where its list is, and where its masks
            // are.
            $dictionary->add($term, Varint::encode(count($ordinals))
                . Varint::encode($written)
                . Varint::encode(strlen($encoded))
                . Varint::encode(strlen($frequencies)));

            $segment->write($encoded);
            $written += strlen($encoded);

            foreach ($byOrdinal as $record) {
                $frequencies .= $record;
            }

            foreach (TermGramIndex::of($term) as $gram) {
                $gramPayloads[$gram] = ($gramPayloads[$gram] ?? '')
                    . TermGramIndex::append($term, $gramPrevious[$gram] ?? '');

                $gramPrevious[$gram] = $term;
            }
        }

        $segment->endSection();

        // After the postings rather than before, because the dictionary points
        // into them and is only complete once the last list is written. Where
        // a section sits in the file is nothing to a reader — the directory at
        // the end says where everything is — so the order costs nothing and
        // both paths take it, which is what keeps a merged segment byte for
        // byte the segment a fresh commit would have written.
        $segment->addSection('terms', $dictionary->finish());

        // The gram lists have to be sorted before they can be a dictionary, and
        // they arrive in the order the vocabulary happened to reach them. This
        // is a sort of 25 000 short strings, not of the postings — the reason
        // the payloads were front-coded on the way in rather than assembled
        // here from arrays of terms.
        //
        // Absent entirely when nothing in the vocabulary is expandable: a
        // wholly Japanese index writes no second tier, because its terms are
        // n-grams and the query side never expands those.
        if ($gramPayloads !== []) {
            $grams = array_map('strval', array_keys($gramPayloads));
            sort($grams, SORT_STRING);

            $gramDictionary = new BlockDictionaryWriter();

            foreach ($grams as $gram) {
                $gramDictionary->add($gram, $gramPayloads[$gram]);
            }

            $segment->addSection('termgrams', $gramDictionary->finish());
        }

        // Always written, unlike the field mask it replaces. That mask was
        // skipped for a single-field schema because every byte held the same
        // one bit and therefore said nothing. A *frequency* on a single field
        // says how often the document uses the word, which is ranking signal
        // whatever the schema looks like — so there is no case where this
        // section is dead weight, and no branch deciding it.
        $segment->addSection('fieldfreq', $frequencies);

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
        $this->frequencyWidth   = $width;
        $this->termLengthSum    = array_sum($sums);
    }

    /**
     * Analyses every document, field by field.
     *
     * Unlike a merge, this path has to hold the whole map: a term's postings
     * are only complete once every document has been analysed, so there is no
     * order in which they could be written as they are found. What bounds it
     * is the batch — the caller chose how many documents to commit at once,
     * and can choose fewer. A merge's size is not chosen by anybody, which is
     * why that path streams and this one does not.
     *
     * @param string[] $searchable bit => field name
     * @return array{0: \Generator<string, array<int, string>>, 1: array<int, array<int, int>>, 2: array<int, int>}
     */
    private function analysedTerms(array $searchable): array
    {
        /** @var array<string, array<int, string>> term => ordinal => one byte per field */
        $postings = [];

        /** @var array<int, array<int, int>> ordinal => bit => terms in that field */
        $lengths = [];

        /** @var array<int, int> bit => summed lengths across the segment */
        $sums = array_fill(0, max(1, count($searchable)), 0);

        $width = max(1, count($searchable));
        $empty = str_repeat("\x00", $width);

        foreach ($this->documents as $ordinal => $document) {
            foreach ($searchable as $bit => $field) {
                $counts = $this->analyzer->frequencies($this->textOf($document[$field] ?? null));

                // Tokens, not distinct terms. This is the |d| of BM25, and
                // while terms were deduplicated trigrams the two were the same
                // number — they are not once a document can say a word twice.
                $length = (int) array_sum($counts);

                // Recorded now because it cannot be recovered from the postings
                // without walking every one of them, and BM25F needs a length
                // per field, not per document.
                $lengths[$ordinal][$bit] = $length;
                $sums[$bit]             += $length;

                foreach ($counts as $term => $count) {
                    // Cast: PHP will have made an all-digit term an int key.
                    $term = (string) $term;

                    // A term found in several fields keeps one posting carrying
                    // a frequency for each, rather than one posting per field.
                    // The record is a byte per field and is written to disk
                    // exactly as it stands here — the in-memory value *is* the
                    // on-disk row, so nothing is re-encoded on the way out.
                    //
                    // Clamped at 255, which BM25 makes nearly free: with
                    // k1 = 1.2 the saturated contribution of tf 255 and of
                    // tf 4 000 differ by 0.4%. A byte is enough because the
                    // formula stops caring long before the byte does — and a
                    // field holding one word 255 times is already pathological.
                    $record = $postings[$term][$ordinal] ?? $empty;

                    $record[$bit] = chr(min(255, $count));

                    $postings[$term][$ordinal] = $record;
                }
            }
        }

        return [self::inTermOrder($postings), $lengths, $sums];
    }

    /**
     * A map of postings, yielded in the order the dictionary requires.
     *
     * Bytewise, which UTF-8 makes the same as sorting by code point. The map is
     * passed by value and the generator holds the only reference to it once the
     * caller lets go, so nothing is duplicated.
     *
     * @param array<string, array<int, string>> $postings
     * @return \Generator<string, array<int, string>>
     */
    private static function inTermOrder(array $postings): \Generator
    {
        $terms = array_keys($postings);
        sort($terms, SORT_STRING);

        foreach ($terms as $term) {
            yield (string) $term => $postings[$term];
        }
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
     * @return array{0: \Generator<string, array<int, string>>, 1: array<int, array<int, int>>, 2: array<int, int>}
     * @throws StorageException
     */
    private function carriedTerms(array $searchable): array
    {
        if ($this->postingsStream === null) {
            throw new StorageException('Carried documents need their postings; carryPostings() was never called');
        }

        $sums    = array_fill(0, max(1, count($searchable)), 0);
        $lengths = [];

        foreach ($this->carriedLengths as $ordinal => $byBit) {
            foreach (array_keys($sums) as $bit) {
                $length = $byBit[$bit] ?? 0;

                $lengths[$ordinal][$bit] = $length;
                $sums[$bit]             += $length;
            }
        }

        return [($this->postingsStream)(), $lengths, $sums];
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

                for ($ordinal = 0; $ordinal < $documentCount; $ordinal++) {
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

                for ($ordinal = 0; $ordinal < $documentCount; $ordinal++) {
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

                for ($ordinal = 0; $ordinal < $documentCount; $ordinal++) {
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
    /**
     * The other direction: local ordinal back to the caller's id.
     *
     * `§ keys` is sorted by key, which is the direction a lookup needs and the
     * wrong one for reporting a result. The reverse used to be derived at read
     * time by walking the whole dictionary and inverting it — **60.7 ms and
     * 5.4 MB on a 45 000-document segment, per request, to serve the twenty
     * ids of one page**, and linear in the size of the index. The format
     * document said this direction was free because a docstore record carried
     * its own key; it does not, and has not for as long as
     * `DocumentStoreWriter` has existed, where the id is only used to name a
     * document that fails to encode.
     *
     * So it is written down instead. Fixed-width offsets then the keys back to
     * back — the same shape as `§ docstore`, and the same rule as everywhere
     * else in this format: variable length where you scan, fixed width where
     * you jump. Measured cost on the reference catalogue: `§ keys` is 317 732
     * bytes, so this adds about 500 KB to a 73 MB segment, or 0.7%. The
     * docstore is 70% of it.
     *
     * One offset more than there are documents, so the last key's length is
     * read the same way as every other's.
     */
    private function encodeForwardKeys(): string
    {
        $offsets = '';
        $keys    = '';
        $at      = 0;

        // `$this->keys` is appended in ordinal order by both put() and carry(),
        // which is what makes this a plain traversal rather than a sort.
        foreach ($this->keys as $key) {
            $offsets .= pack('V', $at);
            $keys    .= $key;
            $at      += strlen($key);
        }

        return $offsets . pack('V', $at) . $keys;
    }

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
