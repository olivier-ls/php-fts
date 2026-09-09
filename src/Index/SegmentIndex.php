<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Analysis\Utf8;
use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\FieldTypeException;
use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\HighlightException;
use Ols\PhpFts\Exception\SortException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Hit;
use Ols\PhpFts\Query\CollectionStatistics;
use Ols\PhpFts\Query\Highlighter;
use Ols\PhpFts\Query\QueryPlan;
use Ols\PhpFts\Query\QuerySlot;
use Ols\PhpFts\Query\Scorer;
use Ols\PhpFts\Query\TermExpansion;
use Ols\PhpFts\Query\TopK;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchResult;
use Ols\PhpFts\Sort;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\Varint;

/**
 * Searches one segment.
 *
 *     $index  = SegmentIndex::open('/path/seg_a1f.fts');
 *     $result = $index->search('lether sho',
 *         filters: Filter::all(
 *             Filter::lte('price', 300),
 *             Filter::any(Filter::eq('brand', 'Nike'), Filter::eq('brand', 'Adidas')),
 *         ),
 *         facets:  ['brand'],
 *     );
 *
 *     $result->total;            // exact, across the whole index
 *     $result->facets['brand'];  // counted over every match, not the page
 *     foreach ($result as $hit) { … }
 *
 * ── How a search moves through the pieces ───────────────────────────────────
 *
 *   1. The query goes through the same Analyzer the documents did, so the two
 *      cannot disagree about what a term is.
 *   2. Each term is looked up in the term dictionary: one binary search over an
 *      in-memory index, then one read of one block. The payload says how many
 *      documents hold the term and where its posting list lives.
 *   3. Posting lists are walked with cursors that jump rather than decode
 *      everything, and combined into a Bitset of candidates.
 *   4. Filters become Bitsets too, from the columns, and are ANDed in. From
 *      here on, "which documents match" is one string of bits.
 *   5. The total is a population count of that string. The facets are counted
 *      from it. Neither reads a document.
 *   6. Only the page being returned is read from the document store.
 *
 * ── Scoring, and why the statistics come from outside ──────────────────────
 *
 * Ranking is BM25 (see Scorer). IDF asks how rare a term is *in the index*, so
 * a segment cannot answer it from its own dictionary: the same term would be
 * rare in one segment and common in another, and the same document would score
 * differently depending on where it happened to land. So `select()` takes a
 * CollectionStatistics gathered across every segment, and only falls back to
 * its own numbers when it really is the whole index.
 *
 * Scoring is field-aware: every posting carries a bitmap of which fields hold
 * the term, so `boosts: ['title' => 3.0]` weights a title match above a
 * description one — and each field is normalised against its own average
 * length, because a title of five terms is not short the way a description of
 * five terms would be. How hard that normalisation bites can be set per field
 * in the schema, which is what BM25F allows and what a title needs: its length
 * says much less about relevance than a description's does.
 *
 * ── Filters are compiled here, not built here ───────────────────────────────
 *
 * A Filter is a value object that knows nothing about segments. It has to be
 * compiled per segment, because each one numbers its own documents and holds
 * its own columns, so the same tree becomes a different bitmap in each. See
 * `compile()`.
 */
final class SegmentIndex
{
    private SegmentReader $segment;
    private Analyzer $analyzer;

    private int $documentCount;

    private Schema $schema;

    private ?BlockDictionaryReader $terms = null;
    private ?BlockDictionaryReader $keys = null;

    /** The grams of this segment's vocabulary; absent on segments written before it existed. */
    private ?BlockDictionaryReader $termGrams = null;

    /** Reverse of the key dictionary, built on first use. @var string[]|null */
    private ?array $keysByOrdinal = null;

    /**
     * `§ keyfwd`'s offset table, read once. False once a segment is known not
     * to have the section, so the question is asked of the directory once
     * rather than on every hit.
     */
    private string|false|null $keyOffsets = null;

    /** @var array<string, array{documents: int, offset: int, length: int, masks: int}|null> */
    private array $termCache = [];

    private ?string $lengths = null;

    /** @var array<int, array<int, int>> ordinal => bit => field length */
    private array $fieldLengthCache = [];

    /** @var string[] bit => field name */
    private array $searchableFields = [];

    /** @var array<int, int> bit => summed field length */
    private array $fieldLengthSums = [];

    /** Bytes per posting in `fieldfreq`: one per searchable field. */
    private int $frequencyWidth = 1;

    /**
     * How many times smaller than the whole walk a driver has to be.
     *
     * Driving swaps a sequential decode of the other posting lists for one
     * `advance()` per candidate per variant, and an advance is a binary search
     * over the skip table plus a block decode. That is cheap against a long
     * list and dear against a short one, so the saving is real only when the
     * driver is a small fraction of the postings rather than merely smaller
     * than all of them.
     *
     * A quarter, measured: at that ratio `couteau de cuisine inox` still drives
     * from `cuisine` — 1 071 postings against about 25 000 — and
     * `couteau pliant lame acier`, whose four slots are all common, stops
     * trying. Ungated it tried, and took its posting walk from 119 ms to 216.
     */
    private const DRIVER_SHARE = 4;

    private int $termLengthSum = 0;

    private Scorer $scorer;
    private DocumentStore $documents;

    /** @var array<string, NumericColumn|KeywordColumn|TagColumn|null> */
    private array $columns = [];

    /**
     * Compiled filter leaves, by clause.
     *
     * ── Why one search compiles the same clause several times ─────────────
     *
     * Disjunctive facets. A facet that excludes its own clause has to be
     * counted over the candidates narrowed by *every other* clause, so the
     * multi-segment layer asks this segment to narrow the same candidates once
     * per excluded tag — and `narrow()` calls `compile()`, which rebuilds every
     * leaf of the tree from its column.
     *
     * That is not cheap the way set arithmetic over bits is cheap.
     * `KeywordColumn::equals()`, `TagColumn::contains()` and
     * `NumericColumn::range()` each **scan the whole column**, in chunks of
     * 4 096 documents. A search with four clauses and three excluding facets
     * therefore scanned every column four times over — and the variants differ
     * only in the one clause they lifted out, so three of those four passes
     * rebuilt bit-for-bit identical sets. On the reference catalogue that is
     * around 720 000 PHP loop iterations of which three quarters were
     * redundant.
     *
     * ── Why holding them for the object's life is safe ────────────────────
     *
     * A segment file never changes, and neither does a compiled leaf. Every
     * combining operation — `and`, `or`, `not`, `andNot` — returns a *new*
     * Bitset rather than mutating; the only mutator is `set()`, and nothing
     * calls it on a set that came back from here. So a cached leaf cannot be
     * altered by whoever borrowed it, and a SegmentIndex lives for one request.
     *
     * Keyed on the clause rather than on the tree, because that is the part
     * two variants share. `exists` and `missing` carry no value and encode as
     * themselves.
     *
     * @var array<string, Bitset>
     */
    private array $leaves = [];

    private function __construct(SegmentReader $segment, ?Analyzer $analyzer)
    {
        $this->segment  = $segment;
        $this->analyzer = $analyzer ?? new Analyzer();

        $meta = json_decode($segment->read('meta'), true);

        if (!is_array($meta) || !isset($meta['documentCount'], $meta['schema'])) {
            throw new CorruptSegmentException('Segment metadata is missing or unreadable');
        }

        $this->documentCount    = (int) $meta['documentCount'];
        $this->termLengthSum    = (int) ($meta['termLengthSum'] ?? 0);
        $this->schema           = Schema::fromArray($meta['schema']);
        $this->searchableFields = array_values($meta['searchableFields'] ?? []);
        $this->fieldLengthSums  = $meta['fieldLengthSums'] ?? [];
        $this->frequencyWidth   = max(1, (int) ($meta['frequencyWidth'] ?? 1));
        $this->documents        = DocumentStore::open($segment, 'docs');
        $this->scorer           = new Scorer();
    }

    /**
     * @throws CorruptSegmentException
     */
    public static function open(string $path, ?Analyzer $analyzer = null): self
    {
        return new self(SegmentReader::open($path), $analyzer);
    }

    public function count(): int
    {
        return $this->documentCount;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    /**
     * Field names to their types, for reporting.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        $types = [];

        foreach ($this->schema->fields() as $field => $definition) {
            $types[$field] = $definition['type'];
        }

        return $types;
    }

    /**
     * Fetches a document by the id it was indexed under.
     *
     * @return array<string, mixed>|null
     * @throws CorruptSegmentException
     */
    public function get(string|int $id): ?array
    {
        $ordinal = $this->ordinalOf((string) $id);

        return $ordinal === null ? null : $this->documents->get($ordinal);
    }

    public function has(string|int $id): bool
    {
        return $this->ordinalOf((string) $id) !== null;
    }

    /**
     * @param Filter|array<mixed> $filters a Filter tree, a nested array, or a
     *        flat list of clauses, which are ANDed
     * @param array<mixed>        $facets  field names, or name => Facet
     * @param Highlight|string[]  $highlight fields to highlight, or a Highlight
     * @param Sort|array<mixed>   $sort      criteria, in order of precedence
     *
     * @throws CorruptSegmentException
     * @throws FilterException
     * @throws HighlightException
     * @throws SortException
     */
    public function search(
        string $query = '',
        int $limit = 20,
        int $offset = 0,
        Filter|array $filters = [],
        array $facets = [],
        array $boosts = [],
        Highlight|array $highlight = [],
        Sort|array $sort = [],
    ): SearchResult {
        $started  = hrtime(true);
        $criteria = Sort::normalise($sort);

        // Verified before a single posting list is read: a field that cannot be
        // highlighted is the caller's mistake, and it should be reported as
        // such rather than by every hit quietly missing a highlight.
        $highlight = Highlight::normalise($highlight);
        $marker    = $highlight === null ? null : $this->highlighter($highlight);

        // Normalised before the plan is resolved, so that a malformed filter is
        // still reported before a single posting list is read.
        $filter = Filter::normalise($filters) ?? Filter::all();

        $plan = $this->planFor($query);

        // What the *documents* say, not what was typed: a search for `stel`
        // finds documents containing `steel`, and marking `stel` would mark
        // nothing at all.
        $marked = $highlight === null ? [] : array_fill_keys($plan->terms(), true);

        [$candidates, $scores] = $this->candidates($plan, boosts: $boosts);

        $matches = $this->narrow($candidates, $filter);
        $total   = $matches->count();

        $counted = [];

        foreach (Facet::normalise($facets) as $name => $facet) {
            // Counted over the candidates narrowed by every clause *except*
            // the one this facet excludes, so a facet the shopper has already
            // used still shows them what else they could pick.
            $narrowed = $facet->exclude === null
                ? $matches
                : $this->narrow($candidates, $filter->withoutTag($facet->exclude) ?? Filter::all());

            $counted[$name] = $facet->limit($this->facet($facet, $name, $narrowed));
        }

        $hits = [];

        foreach ($this->page($matches, $scores, $limit, $offset, $criteria) as $ordinal => $score) {
            $document = $this->documents->get($ordinal);

            if ($document === null) {
                continue;
            }

            $hits[] = new Hit(
                $this->keyOf($ordinal),
                $score,
                $document,
                $marker === null ? [] : $marker->document($document, $marked, $highlight),
            );
        }

        return new SearchResult($hits, $total, $counted, (hrtime(true) - $started) / 1e6, $plan->unknown);
    }

    /**
     * A highlighter for this segment, or none when nothing was asked for.
     *
     * It borrows the segment's own analyzer, which is the whole reason a
     * re-analysed field is guaranteed to agree with what was indexed.
     *
     * @throws HighlightException
     */
    public function highlighter(Highlight $highlight): Highlighter
    {
        Highlighter::verify($highlight, $this->schema);

        return new Highlighter($this->analyzer);
    }

    // -------------------------------------------------------------------------
    // Used by the multi-segment layer, which has to combine several segments
    // before it can rank or count anything. Not part of the public surface.
    // -------------------------------------------------------------------------

    /**
     * The documents in this segment matching a query and its filters.
     *
     * @param array<int, array{field: string, op: string, value: mixed}> $filters
     * @param Bitset|null $deleted documents the manifest marks as deleted
     *
     * @param CollectionStatistics|null $statistics index-wide numbers for BM25.
     *        Null makes the segment score against itself, which is right when it
     *        is the whole index and wrong as soon as it is not — see the class.
     *
     * @return array{0: Bitset, 1: array<int, float>} the matches, and their scores
     * @internal
     * @throws CorruptSegmentException
     * @throws FilterException
     */
    public function select(
        QueryPlan|string $query,
        Filter|array $filters = [],
        ?Bitset $deleted = null,
        ?CollectionStatistics $statistics = null,
        array $boosts = [],
    ): array {
        [$candidates, $scores] = $this->candidates($query, $deleted, $statistics, $boosts);
        $filter                = Filter::normalise($filters);

        return [$filter === null ? $candidates : $this->narrow($candidates, $filter), $scores];
    }

    /**
     * What the query alone matches, less what has been deleted.
     *
     * Split out from `select()` because a search with disjunctive facets needs
     * several *differently filtered* views of the same query — one per facet
     * that excludes its own clause. Matching the query is the expensive half:
     * it walks posting lists. Filtering is set arithmetic over bits. So the
     * query is matched once and the filters applied to the result as many times
     * as the facets require.
     *
     * @param CollectionStatistics|null $statistics index-wide numbers for BM25.
     *        Null makes the segment score against itself, which is right when it
     *        is the whole index and wrong as soon as it is not — see the class.
     *
     * @return array{0: Bitset, 1: array<int, float>} the candidates, and their scores
     * @internal
     * @throws CorruptSegmentException
     */
    public function candidates(
        QueryPlan|string $query,
        ?Bitset $deleted = null,
        ?CollectionStatistics $statistics = null,
        array $boosts = [],
    ): array {
        // A string is the convenience for the single-segment case: the plan is
        // resolved against this segment, which is only right when it is the
        // whole index. The multi-segment layer always passes a plan it built
        // from every vocabulary — see QueryPlan.
        $plan = $query instanceof QueryPlan ? $query : $this->planFor($query, $statistics);

        [$matches, $scores] = $this->matchQuery(
            $plan,
            $statistics ?? new CollectionStatistics($this->documentCount, $this->averageLength()),
            $boosts,
        );

        return [$deleted === null ? $matches : $matches->andNot($deleted), $scores];
    }

    /**
     * The candidates a filter keeps.
     *
     * @internal
     * @throws CorruptSegmentException
     * @throws FilterException
     */
    public function narrow(Bitset $candidates, Filter $filter): Bitset
    {
        return $candidates->and($this->compile($filter));
    }

    /**
     * One facet, counted over a set of matches.
     *
     * Term counts are gathered without a size limit whatever the facet asks
     * for, because the top twenty of the index are not the top twenty of each
     * segment added together. Truncation happens once, after every segment has
     * contributed.
     *
     * @return array<string|int, mixed>
     * @internal
     * @throws CorruptSegmentException
     * @throws FilterException
     */
    public function facet(Facet $facet, string $name, Bitset $matches): array
    {
        $field  = $facet->fieldFor($name);
        $column = $this->column($field);

        if ($column === null) {
            throw new FilterException("No facetable column for field '$field'" . $this->becauseInferred($field));
        }

        if ($facet->kind === 'terms' && !$column instanceof KeywordColumn && !$column instanceof TagColumn) {
            throw new FilterException(
                "Facet '$name' asks for term counts, but '$field' is a numeric column."
                . ' Use Facet::stats() for a number, or Facet::ranges() once it exists'
            );
        }

        if ($facet->kind === 'stats' && !$column instanceof NumericColumn) {
            throw new FilterException(
                "Facet '$name' asks for statistics, but '$field' is not a numeric column."
                . ' Use Facet::terms() for an exact value'
            );
        }

        // Size is applied by the caller, once, over the counts of every
        // segment added together — the top twenty of the index are not the top
        // twenty of each segment.
        return $column instanceof NumericColumn
            ? $column->stats($matches)
            : $column->facet($matches, size: 0);
    }

    // -------------------------------------------------------------------------
    // What a merge reads out of a finished segment.
    //
    // A merge used to re-index: it read the documents back and analysed them
    // again. That cannot work, because the documents are not necessarily there
    // — `source()` decides what is stored, and a field excluded from it still
    // has terms and a column. Re-indexing dropped both, so an index using
    // `source(false)` stopped matching anything at its first merge.
    //
    // What is on disk is enough without the text: the postings *are* the terms,
    // the column holds the value, the lengths are recorded per field. These
    // read them back out so the merger can carry them across as they are. See
    // SegmentMerger.
    // -------------------------------------------------------------------------

    /**
     * Every term in this segment, with the documents holding it and the fields
     * they hold it in.
     *
     * A generator, and deliberately per term rather than per document: that is
     * the order the dictionary is laid out in, and it is also the shape the
     * writer accumulates. Transposing it to per-document would hold the whole
     * thing twice at the peak of a merge, which on a memory-bound host is the
     * difference between merging and dying.
     *
     * @internal for SegmentMerger
     * @return \Generator<string, array<int, string>> term => ordinal => frequency record
     * @throws CorruptSegmentException
     */
    public function postingsByTerm(): \Generator
    {
        // A schema with one searchable field writes no masks, because every
        // mask would hold the same single bit. Carried across, that bit still
        // has to be set: bit 0 is that one field.
        foreach ($this->terms()->iterate() as $term => $payload) {
            $entry  = self::decodeEntry($payload);
            $cursor = PostingsCursor::open(
                $this->segment->read('postings', $entry['offset'], $entry['length'])
            );

            $records   = $this->frequenciesFor($entry);
            $byOrdinal = [];
            $index     = 0;

            while ($cursor->current() !== PostingsFormat::END) {
                // Carried across as the bytes they are, not decoded into
                // per-field numbers and re-encoded. A merge's job here is to
                // renumber documents, not to reinterpret what was measured.
                $byOrdinal[$cursor->current()] = substr(
                    $records,
                    $index * $this->frequencyWidth,
                    $this->frequencyWidth
                );

                $index++;
                $cursor->next();
            }

            yield (string) $term => $byOrdinal;
        }
    }

    /**
     * How many terms one document holds in each field.
     *
     * @internal for SegmentMerger
     * @return array<int, int> bit => length
     * @throws CorruptSegmentException
     */
    public function fieldLengths(int $ordinal): array
    {
        return $this->fieldLengthsOf($ordinal);
    }

    /**
     * The value one document holds in one column, ready to be written to the
     * same kind of column again.
     *
     * This is what makes a filter survive a merge on a field the schema does
     * not store: the value never leaves the columnar side of the segment.
     *
     * @internal for SegmentMerger
     * @throws CorruptSegmentException
     */
    public function columnValue(string $field, int $ordinal): mixed
    {
        $column = $this->column($field);

        return match (true) {
            $column instanceof NumericColumn => $column->get($ordinal),
            $column instanceof KeywordColumn => $column->get($ordinal),
            $column instanceof TagColumn     => $column->get($ordinal),
            default                          => null,
        };
    }

    /**
     * The mask vocabulary this segment was written with: bit => field name.
     *
     * A merge compares it across its sources, because a carried mask is only
     * meaningful if every segment numbers its fields the same way.
     *
     * @internal for SegmentMerger
     * @return string[]
     */
    public function maskVocabulary(): array
    {
        return $this->searchableFields;
    }

    /**
     * @return array<string|int, mixed> term counts for a keyword field, statistics for a numeric one
     * @internal
     * @throws CorruptSegmentException
     */
    public function facetOn(string $field, Bitset $matches): array
    {
        $column = $this->column($field);

        return match (true) {
            $column instanceof KeywordColumn => $column->facet($matches, size: 0),
            $column instanceof TagColumn     => $column->facet($matches, size: 0),
            $column instanceof NumericColumn => $column->stats($matches),
            default                          => [],
        };
    }

    /**
     * @return array<string, mixed>|null
     * @internal
     * @throws CorruptSegmentException
     */
    public function documentAt(int $ordinal): ?array
    {
        return $this->documents->get($ordinal);
    }

    /**
     * @internal
     * @throws CorruptSegmentException
     */
    public function keyAt(int $ordinal): string
    {
        return $this->keyOf($ordinal);
    }

    /**
     * @internal
     * @throws CorruptSegmentException
     */
    public function ordinalFor(string $id): ?int
    {
        return $this->ordinalOf($id);
    }

    /**
     * How many of this segment's documents hold each of these terms.
     *
     * Asked of every segment before any of them scores, so that IDF describes
     * the index rather than one segment. The lookups are cached, so the pass the
     * search was going to make anyway is not repeated.
     *
     * @param string[] $terms
     * @return array<string, int>
     * @internal
     * @throws CorruptSegmentException
     */
    public function documentFrequencies(array $terms): array
    {
        $frequencies = [];

        foreach ($terms as $term) {
            $entry = $this->entryFor((string) $term);

            if ($entry !== null) {
                $frequencies[$term] = $entry['documents'];
            }
        }

        return $frequencies;
    }

    /**
     * For each of the query's words, the terms *this segment* holds that are
     * within its edit budget.
     *
     * Positional rather than keyed by the word, because PHP would turn a
     * numeric word into an integer key — and a word can now be `21` or a model
     * number. The caller pairs the result back up with the words it passed.
     *
     * ── How the candidates are found ───────────────────────────────────────
     *
     * Through the segment's second tier: the grams of its own vocabulary, so a
     * typed word reaches its neighbours through a handful of short lookups.
     * Only terms sharing enough grams to still be within the edit budget are
     * measured, and the share required is a sound bound rather than a tuned
     * one — see TermGramIndex::shareRequired().
     *
     * A segment written before that section existed has none, and falls back
     * to reading the whole dictionary. Correct, and slow enough that it is
     * worth reindexing: measured at 2.9 seconds a search on 45 000 products,
     * against milliseconds through the section.
     *
     * @param string[] $typed the query's terms, from the analyzer
     * @return array<int, array<string, float>> by position, term => weight
     * @internal for IndexDirectory, which unions these across segments
     * @throws CorruptSegmentException
     */
    public function expandTerms(array $typed): array
    {
        $found      = [];
        $expansions = [];
        $characters = [];

        foreach (array_values($typed) as $position => $word) {
            $word             = (string) $word;
            // Weight 1.0, not 0: the word itself counts for everything. It read
            // as a *distance* of zero until candidates started carrying weights
            // instead, and left as it was it would have made every exact match
            // contribute nothing at all.
            $found[$position] = $this->entryFor($word) === null ? [] : [$word => 1.0];

            if (!TermExpansion::tolerates($word)) {
                // A single character of a continuous script is a word — 茶,
                // 水, 书 are ordinary Chinese — and it was the one query shape
                // this analyzer could not answer. A document saying 緑茶
                // produces the bigram 緑茶 and nothing else, so 茶 found only
                // the documents where it happened to stand alone.
                //
                // Two characters or more need none of this: they *are* the
                // bigrams the documents produced, and the exact lookup above
                // finds them.
                if (Utf8::length($word) === 1) {
                    $characters[$position] = $word;
                }

                continue;
            }

            $expansion = new TermExpansion($word);

            if ($expansion->budget() > 0) {
                $expansions[$position] = $expansion;
            }
        }

        if ($expansions === [] && $characters === []) {
            return $found;
        }

        if (!$this->segment->has('termgrams')) {
            // No second tier, so no character index either — a segment written
            // before this cannot answer the single-character question at all,
            // and scanning the dictionary for a substring is not a fallback,
            // it is a different order of cost. Reindexing is the answer.
            return $this->expandByScan($found, $expansions);
        }

        foreach ($characters as $position => $character) {
            $payload = $this->termGrams()->get($character);

            if ($payload === null) {
                continue;
            }

            foreach (TermGramIndex::decode($payload) as $candidate) {
                if (isset($found[$position][$candidate])) {
                    continue;
                }

                // The share of the candidate the query accounts for. A bigram
                // holding the character asked for is half about it, which is
                // the most defensible thing to say without a corpus to measure
                // against — and it is deliberately a partial match, so a
                // document using the character as a word of its own still
                // outranks one that merely contains it.
                $length = Utf8::length($candidate);

                $found[$position][$candidate] = $length > 0 ? 1.0 / $length : 1.0;
            }
        }

        foreach ($expansions as $position => $expansion) {
            $grams  = TermGramIndex::of($expansion->typed);
            $shared = [];

            foreach ($grams as $gram) {
                $payload = $this->termGrams()->get($gram);

                if ($payload === null) {
                    continue;
                }

                foreach (TermGramIndex::decode($payload) as $candidate) {
                    $shared[$candidate] = ($shared[$candidate] ?? 0) + 1;
                }
            }

            $required = TermGramIndex::shareRequired(count($grams), $expansion->budget());

            foreach ($shared as $candidate => $grammes) {
                if ($grammes < $required) {
                    continue;
                }

                $candidate = (string) $candidate;

                if (isset($found[$position][$candidate])) {
                    continue;
                }

                $weight = $expansion->accepts($candidate);

                if ($weight !== null) {
                    $found[$position][$candidate] = $weight;
                }
            }
        }

        return $found;
    }

    /**
     * Expansion for a segment with no second tier, by reading every term.
     *
     * One traversal however many words the query has, because the dictionary
     * is the expensive thing to walk and a word is cheap to test against.
     *
     * @param array<int, array<string, float>> $found
     * @param array<int, TermExpansion>        $expansions
     * @return array<int, array<string, float>>
     * @throws CorruptSegmentException
     */
    private function expandByScan(array $found, array $expansions): array
    {
        foreach ($this->terms()->iterate() as $term => $payload) {
            $term = (string) $term;

            foreach ($expansions as $position => $expansion) {
                if (isset($found[$position][$term])) {
                    continue;
                }

                $weight = $expansion->accepts($term);

                if ($weight !== null) {
                    $found[$position][$term] = $weight;
                }
            }
        }

        return $found;
    }

    private function termGrams(): BlockDictionaryReader
    {
        return $this->termGrams ??= new BlockDictionaryReader($this->segment, 'termgrams');
    }

    /**
     * A plan for a query, resolved against this segment alone.
     *
     * Correct only when this segment *is* the index, which is the case
     * `search()` serves and the case the tests exercise. The multi-segment
     * layer builds its own plan from every segment's vocabulary — see
     * QueryPlan, which explains why that distinction is not optional.
     *
     * @throws CorruptSegmentException
     */
    public function planFor(string $query, ?CollectionStatistics $statistics = null): QueryPlan
    {
        $typed = $this->analyzer->analyze($query);

        if ($typed === []) {
            return QueryPlan::matchAll();
        }

        $candidates = $this->expandTerms($typed);
        $terms      = [];

        foreach ($candidates as $byTerm) {
            foreach (array_keys($byTerm) as $term) {
                $terms[] = (string) $term;
            }
        }

        // No field averages: fieldAverages() falls back to this segment's own
        // sums when the statistics carry none, which is exactly right when the
        // segment is the index.
        $statistics ??= new CollectionStatistics(
            $this->documentCount,
            $this->averageLength(),
            $this->documentFrequencies($terms),
        );

        return QueryPlan::build($typed, $candidates, $statistics, $this->scorer);
    }

    /**
     * Summed document lengths, so an average can be taken across segments.
     *
     * @internal
     */
    public function termLengthSum(): int
    {
        return $this->termLengthSum;
    }

    /**
     * Summed field lengths, by field **name**, so per-field averages can be
     * taken across segments.
     *
     * Translated out of mask bits here, because this is the last place that
     * knows what this segment's bits mean — `§ meta` records the mapping per
     * segment, and nothing above cares to. See CollectionStatistics, which
     * explains why a bit cannot cross a segment boundary.
     *
     * @return array<string, int>
     * @internal
     */
    public function fieldLengthSums(): array
    {
        $sums = [];

        foreach ($this->searchableFields as $bit => $field) {
            $sums[$field] = $this->fieldLengthSums[$bit] ?? 0;
        }

        return $sums;
    }

    /**
     * The terms a query analyses to, using this segment's analyzer.
     *
     * @return string[]
     * @internal
     */
    public function analyze(string $query): array
    {
        return $this->analyzer->analyze($query);
    }

    // -------------------------------------------------------------------------

    /**
     * Turns the query into a set of candidate documents, plus how many query
     * terms each one holds.
     *
     * An empty query matches everything, which is what makes filters and facets
     * usable on their own — a category page with no search box.
     *
     * @return array{0: Bitset, 1: array<int, float>} the matches, and their BM25 scores
     * @throws CorruptSegmentException
     */
    private function matchQuery(QueryPlan $plan, CollectionStatistics $statistics, array $boosts = []): array
    {
        if ($plan->matchesEverything) {
            return [Bitset::full($this->documentCount), []];
        }

        if ($plan->matchesNothing()) {
            return [Bitset::empty($this->documentCount), []];
        }

        /** @var array<int, float> ordinal => IDF it has gathered across slots */
        $gathered = [];

        /** @var array<int, float> ordinal => score so far */
        $weights = [];

        $weightByBit = $this->boostsByBit($boosts);
        $averages    = $this->fieldAverages($statistics);
        $bByBit      = $this->bByBit();

        // The slots no match can do without, if a cheap enough set of them
        // exists. See driverSlots(): it turns the walk from "read every list"
        // into "read the cheapest ones that cannot all be missed, then jump
        // through the others", which is what the skip tables were written for.
        $driver = $plan->driven ? $this->driverSlots($plan) : null;

        if ($driver === []) {
            // A slot mandatory on its own that this segment holds nothing for.
            return [Bitset::empty($this->documentCount), []];
        }

        if ($driver !== null) {
            return $this->matchDriven($plan, $driver, $weightByBit, $averages, $bByBit);
        }

        foreach ($plan->slots as $slot) {
            // Gathered per slot rather than per term, because the variants of
            // one typed word are one match and not several — and kept as the
            // **best** reading of the slot in each field, not the sum of them.
            //
            // Summing was the first rule and it inverted the ranking. `plan`,
            // `plat` and `point` are each two edits from `pliant`, so each
            // counts for 1 − 2/6 = 0.667; added together they make 2.0 against
            // the exact word's 1.0, and BM25's saturation turns that into a
            // higher score. Measured: a product named "Plan plat point" scored
            // 4.4597 against 4.2952 for one named "Truc pliant", and on the
            // reference catalogue the first result for `pliant` was
            // "Ranger Point Precision" — three wrong words beating one right
            // one. A maximum cannot do that, and needs no constant to say so:
            // a slot counts once, for the closest thing to what was typed.
            //
            // What it costs, stated: a document saying `couteau` twice and
            // `couteaux` once carries 2.0 rather than 2.857. The plural stops
            // adding on top of the singular. That is the honest price of the
            // property, and `tests/Query/RelevanceTest.php` holds both ends of
            // it — near misses may not stack, and the typed word's own
            // repetition must still count.
            //
            // ── Why the weight is applied to the score, not to the frequency ─
            //
            // Because those are different axes and BM25 has one slot for them.
            // A variant's weight says how sure the reader is that this is the
            // word that was meant; term frequency says how much the document
            // talks about it. Multiplying the weight into `tf` trades the first
            // against the second, and `tf` is unbounded, so it always loses:
            // `Ranger Point Precision Ranger Point Precision` says `point`
            // twice, and 0.667 × 2 = 1.334 beat the exact word's 1.0. A doubt
            // about *which word this is* cannot be repaired by repetition, so
            // it must not be expressible in the same units as repetition.
            //
            // So each variant is scored whole — its own raw frequencies,
            // normalised per field and saturated — and the weight multiplies
            // the finished number. A slot then takes the best of those, which
            // is what "one word, one match, read as well as it can be" means.
            // One posting carries a variant's frequency in every field at once,
            // so this needs no extra pass and no per-variant map: the score is
            // finished where the posting is read.
            //
            // The remaining exposure, since it should be written down rather
            // than discovered: no multiplicative weight makes *every* exact
            // match beat *every* near miss, because saturation tops out at
            // k1 + 1 and 0.667 × 2.2 is still 1.47. A near miss said ten times
            // outranks the word said once. That is arguably correct, and it is
            // out of reach of anything but refusing the candidate — which is
            // what the prefix anchor in TermExpansion does.
            //
            // The same walk slotScores() performs for a driving slot, and it
            // used to be written out again here — the two loops were identical
            // line for line, which is a thing DrivenWalkTest existed to police
            // rather than a thing the code prevented. One copy now.
            //
            // @var array<int, float> ordinal => this slot's best variant score
            $reached = $this->slotScores($slot, $weightByBit, $averages, $bByBit);

            foreach ($reached as $ordinal => $slotScore) {
                $gathered[$ordinal] = ($gathered[$ordinal] ?? 0.0) + $slot->idf;
                $weights[$ordinal]  = ($weights[$ordinal] ?? 0.0) + $slotScore;
            }
        }

        if ($gathered === []) {
            return [Bitset::empty($this->documentCount), []];
        }

        // A document has to account for most of what the query was about.
        //
        // The threshold comes from the plan, which was built from the whole
        // index before any segment was asked anything — never from what this
        // segment happens to contain. That is what makes the answer
        // independent of write history, and it is not a theoretical concern:
        // an earlier version decided per segment, so the same query answered
        // differently depending on how documents had been spread across
        // segments, and merging them changed the result. A test comparing a
        // search before and after a merge caught it.
        //
        // Compared with a tolerance because both sides are sums of floats: a
        // document holding every slot gathers exactly the total the threshold
        // was taken from, and must not lose to the last bit of a mantissa.
        $required = $plan->required - 1e-9;
        $matches  = Bitset::empty($this->documentCount);
        $scores   = [];

        foreach ($gathered as $ordinal => $carried) {
            if ($carried < $required) {
                continue;
            }

            $matches->set($ordinal);

            // Already normalised per field and saturated, so the per-slot
            // scores only need adding up. There is no longer an unfielded
            // path: frequencies are written whatever the schema, because a
            // frequency on a single field still says something a mask bit
            // could not.
            $scores[$ordinal] = $weights[$ordinal];
        }

        return [$matches, $scores];
    }

    /**
     * Query boosts, translated from field names to mask bits.
     *
     * Every searchable field appears, so a field nobody boosted still
     * contributes at weight 1. A boost naming a field that does not exist is
     * ignored rather than rejected: a caller reusing one set of boosts across
     * several indexes should not have to know which fields each one holds.
     *
     * @param array<string, float> $boosts
     * @return array<int, float>
     */
    private function boostsByBit(array $boosts): array
    {
        // The schema's boosts are defaults; a query overrides them per field.
        // So declaring `->text('title', boost: 3.0)` once saves repeating
        // `['title' => 3.0]` at every call site, and a query that wants
        // something else for one search still gets it.
        $boosts = $boosts + $this->schema->boosts();

        $byBit = [];

        foreach ($this->searchableFields as $bit => $field) {
            $byBit[$bit] = (float) ($boosts[$field] ?? 1.0);
        }

        return $byBit;
    }

    /**
     * Per-field length normalisation, by mask bit.
     *
     * Every searchable bit appears, the scorer's own `b` standing in for a
     * field the schema gave none. It used to hold only the fields with an
     * explicit `b` and let the fallback happen per posting, inside
     * `Scorer::fieldedFrequency()` — a `??` on the innermost loop of the whole
     * search, resolved to the same value every time. Resolving it once here
     * costs nothing and is what lets the scoring loop hold no `??` at all.
     *
     * @return array<int, float>
     */
    private function bByBit(): array
    {
        $definitions = $this->schema->fields();
        $byBit       = [];

        foreach ($this->searchableFields as $bit => $field) {
            $byBit[$bit] = (float) ($definitions[$field]['b'] ?? $this->scorer->b);
        }

        return $byBit;
    }

    /**
     * The packed per-field lengths, read once.
     *
     * One `u16` per field per document, addressed by document then field. The
     * scoring loop reads it with `ord()` rather than through
     * `fieldLengthsOf()`, which builds an array per document and caches it —
     * see slotScores() for why that stopped being worth it.
     */
    private function lengths(): string
    {
        return $this->lengths ??= $this->segment->has('lengths')
            ? $this->segment->read('lengths')
            : '';
    }

    /**
     * The index-wide mean length of each field, in this segment's own bits.
     *
     * The statistics answer by name; the scorer works in bits, because a
     * posting's frequency record is one byte per bit. This is the translation,
     * and it is the only place it belongs.
     *
     * @return array<int, float>
     */
    private function fieldAverages(CollectionStatistics $statistics): array
    {
        $averages = [];

        foreach ($this->searchableFields as $bit => $field) {
            $averages[$bit] = $statistics->fieldAverage($field)
                ?: ($this->documentCount > 0 ? ($this->fieldLengthSums[$bit] ?? 0) / $this->documentCount : 0.0);
        }

        return $averages;
    }

    /**
     * One term's field masks, or null when the segment has none.
     *
     * Read in one go per term: the masks sit in their own section in posting
     * order, so a term's slice is contiguous.
     *
     * @param array{documents: int, offset: int, length: int, masks: int} $entry
     * @throws CorruptSegmentException
     */
    private function frequenciesFor(array $entry): string
    {
        return $this->segment->read(
            'fieldfreq',
            $entry['masks'],
            $entry['documents'] * $this->frequencyWidth
        );
    }

    /**
     * The cheapest set of slots no matching document can do without.
     *
     * The threshold is an amount of IDF, so a document may miss at most
     * `slack()` of it. **Any set of slots worth more than the slack therefore
     * cannot be missed entirely** — a document holding none of them falls short
     * by more than it is allowed to. Every match is somewhere in the union of
     * that set's posting lists, which makes it a place to start from: read
     * those, then jump the rest through with `advance()`.
     *
     * ── A set was tried instead of one slot, and is worse ──────────────────
     *
     * The arithmetic generalises cleanly: any *set* of slots worth more than
     * the slack cannot be missed entirely either, and where no single slot
     * clears the bar two or three together do. That is exactly the case the
     * driver never reaches — the slack is a fixed fraction of the total, so
     * the more words a query has the smaller each one's share — and
     * `couteau pliant lame acier` walks 119 ms of postings entirely unanchored
     * because not one of its four slots clears a slack of 1.64.
     *
     * Built, measured on nine runs against the reference catalogue, reverted:
     *
     *     couteau pliant                     84.0 -> 69.2 ms
     *     couteau de cuisine inox            43.2 -> 45.9
     *     couteau pliant lame acier         145.7 -> 147.3
     *     five words                        152.8 -> 228.1
     *     seven words                       168.5 -> 187.8
     *
     * The reason is what driving *costs*. It swaps a sequential decode of the
     * other lists for one `advance()` per candidate per variant, and a set
     * assembled from common words is a large union — twenty thousand
     * candidates, each pushed through sixty-five variants. The cheapest set by
     * information is not the cheapest set to drive from, and on this shape
     * nothing is.
     *
     * **What the experiment did find is the gate below.** `couteau pliant`
     * got 18% faster in the table above, and not from the set: from the driver
     * being *disabled*. Driving it had been a loss all along — one common word
     * anchoring another common word — and nothing measured it until something
     * else made it measurable.
     *
     * Exact by construction rather than by measurement: nothing is pruned on
     * an estimate, only on the fact that a document missing every slot of the
     * set cannot reach the threshold. `tests/Index/DrivenWalkTest.php` asserts
     * the driven and undriven walks agree hit for hit and score for score.
     *
     * @return int[]|null slot positions to drive from; an empty array when a
     *         slot mandatory on its own has nothing here, so the segment
     *         matches nothing at all; null when driving would read as much as
     *         the full walk and cost more to arrange
     *
     * @throws CorruptSegmentException
     */
    private function driverSlots(QueryPlan $plan): ?array
    {
        if (count($plan->slots) < 2) {
            return null;
        }

        $slack = $plan->slack() + 1e-9;
        $costs = [];

        foreach ($plan->slots as $position => $slot) {
            $postings = 0;

            foreach ($slot->candidates as [$term]) {
                $entry = $this->entryFor($term);

                if ($entry !== null) {
                    $postings += $entry['documents'];
                }
            }

            // A slot mandatory *on its own* that this segment holds nothing for
            // means no document here can match at all — worth answering before
            // walking anything.
            if ($postings === 0 && $slot->idf > $slack) {
                return [];
            }

            $costs[$position] = $postings;
        }

        $best     = null;
        $cheapest = PHP_INT_MAX;

        foreach ($plan->slots as $position => $slot) {
            if ($slot->idf > $slack && $costs[$position] < $cheapest) {
                $cheapest = $costs[$position];
                $best     = $position;
            }
        }

        if ($best === null) {
            return null;
        }

        // Being able to drive is not the same as it being worth doing, and this
        // gate is the whole finding of the experiment above it.
        return $cheapest * self::DRIVER_SHARE <= array_sum($costs) ? [$best] : null;
    }

    /**
     * The match set, driven from one mandatory slot.
     *
     * Every match holds the driver, so its postings are the only documents
     * worth asking the other slots about — and asking is `advance()`, which
     * bisects a skip table and decodes one block instead of every gap along the
     * way. The saving is the difference between the driver's length and the
     * others': it is large exactly when one word of the query is rare and the
     * rest are common, which is the shape of most real searches.
     *
     * Identical results to the full walk by construction rather than by
     * measurement: nothing is pruned on a score estimate, only on the fact that
     * a document without the driver cannot reach the threshold. That is
     * arithmetic on the plan, not a heuristic — which is what lets `total` stay
     * exact while the walk gets cheaper.
     *
     * @param int[]             $driver slot positions to seed the walk from
     * @param array<int, float> $weightByBit
     * @param array<int, float>           $averages
     * @param array<int, float>           $bByBit
     *
     * @return array{0: Bitset, 1: array<int, float>}
     * @throws CorruptSegmentException
     */
    private function matchDriven(
        QueryPlan $plan,
        array $driver,
        array $weightByBit,
        array $averages,
        array $bByBit,
    ): array {
        $driving = array_fill_keys($driver, true);

        /** @var array<int, array<int, float>> ordinal => slot => that slot's best variant score */
        $reached = [];

        // The union of the driving slots' postings. Every match is in it, by
        // the arithmetic driverSlots() explains, so nothing outside it needs
        // asking — and the slots are scored while they are read rather than
        // revisited afterwards.
        foreach ($driver as $position) {
            $scores = $this->slotScores($plan->slots[$position], $weightByBit, $averages, $bByBit);

            foreach ($scores as $ordinal => $score) {
                $reached[$ordinal][$position] = $score;
            }
        }

        if ($reached === []) {
            return [Bitset::empty($this->documentCount), []];
        }

        // Ascending, because that is the only order a forward-only cursor can
        // be pushed through.
        ksort($reached);
        $candidates = array_keys($reached);

        $k1      = $this->scorer->k1;
        $k1Plus1 = $k1 + 1.0;
        $width   = $this->frequencyWidth;
        $lengths = $this->lengths();
        $known   = strlen($lengths);
        $row     = $width * 2;

        foreach ($plan->slots as $position => $slot) {
            if (isset($driving[$position])) {
                continue;
            }

            $idf = $slot->idf;

            foreach ($slot->candidates as [$term, $weight]) {
                $entry = $this->entryFor($term);

                if ($entry === null) {
                    continue;
                }

                $cursor = PostingsCursor::open(
                    $this->segment->read('postings', $entry['offset'], $entry['length'])
                );

                $records = $this->frequenciesFor($entry);
                $bytes   = strlen($records);

                foreach ($candidates as $ordinal) {
                    if ($cursor->advance($ordinal) === PostingsFormat::END) {
                        break;
                    }

                    if ($cursor->current() !== $ordinal) {
                        continue;
                    }

                    // Each variant scored whole, the slot keeping the best —
                    // see matchQuery(), which explains why the weight belongs
                    // on the score rather than on the frequency. The two walks
                    // have to agree hit for hit *and score for score*, so the
                    // arithmetic here is the arithmetic in slotScores(),
                    // written out for the same reason and in the same order.
                    // DrivenWalkTest is what says they still agree.
                    $combined = 0.0;
                    $at       = $cursor->index() * $width;
                    $base     = $ordinal * $row;
                    $sized    = $base >= 0 && $base + $row <= $known;

                    for ($bit = 0; $bit < $width; $bit++) {
                        $position2 = $at + $bit;

                        if ($position2 >= $bytes) {
                            break;
                        }

                        $frequency = ord($records[$position2]);

                        if ($frequency === 0) {
                            continue;
                        }

                        $average = $averages[$bit];

                        if ($average > 0.0) {
                            $offset = $base + $bit * 2;
                            $length = $sized ? ord($lengths[$offset]) | (ord($lengths[$offset + 1]) << 8) : 0;
                            $b      = $bByBit[$bit];

                            $combined += $weightByBit[$bit] * $frequency / (1.0 - $b + $b * ($length / $average));

                            continue;
                        }

                        $combined += $weightByBit[$bit] * $frequency;
                    }

                    $score = $combined > 0.0
                        ? $weight * $idf * $combined * $k1Plus1 / ($k1 + $combined)
                        : 0.0;

                    if (!isset($reached[$ordinal][$position]) || $score > $reached[$ordinal][$position]) {
                        $reached[$ordinal][$position] = $score;
                    }
                }
            }
        }

        $required = $plan->required - 1e-9;
        $matches  = Bitset::empty($this->documentCount);
        $scores   = [];

        foreach ($reached as $ordinal => $bySlot) {
            $gathered = 0.0;
            $score    = 0.0;

            // The slots a document reached, and what they came to. Both are
            // read off the same map now that a slot's contribution is finished
            // where its posting was read — the threshold and the score are one
            // pass rather than two.
            foreach ($bySlot as $position => $slotScore) {
                $gathered += $plan->slots[$position]->idf;
                $score    += $slotScore;
            }

            if ($gathered < $required) {
                continue;
            }

            $matches->set($ordinal);
            $scores[$ordinal] = $score;
        }

        return [$matches, $scores];
    }

    /**
     * One slot's postings in this segment: ordinal => what the slot came to
     * there, its variants already reduced to the best reading.
     *
     * The driver's half of the walk. Its counterpart is the loop in
     * matchDriven() that jumps the other slots through, and the two have to
     * produce the same shape — a finished per-slot score — because the caller
     * adds them up without knowing which walk produced which.
     *
     * @param array<int, float> $weightByBit
     * @param array<int, float> $averages
     * @param array<int, float> $bByBit
     *
     * @return array<int, float>
     * @throws CorruptSegmentException
     */
    private function slotScores(QuerySlot $slot, array $weightByBit, array $averages, array $bByBit): array
    {
        $found = [];

        // ── Why the formula is spelled out here instead of called ──────────
        //
        // Because this loop runs once per posting, and measurement said that
        // is where the time goes. Profiled on the reference catalogue,
        // `acier lame longueur` — three words each sitting in about 46% of
        // 45 000 products, which is what someone shopping for a knife types:
        //
        //     whole search                583.5 ms
        //     of which plan building       37.7
        //     of which matching           473.4
        //     of which posting decode      128.0   (measured separately)
        //
        // So decoding 65 689 postings cost 128 ms and *scoring* them cost
        // around 345 — **5.3 µs a posting**, for four floating-point
        // operations. The cost was not the arithmetic. It was the shape:
        // `frequenciesAt()` allocated an array per posting, `fieldedFrequency()`
        // walked that array back with a `??` on four separate maps, and
        // `fieldLengthsOf()` returned another array from a cache that grew with
        // every document touched. Three allocations and a dozen hash lookups
        // to multiply five numbers.
        //
        // Spelled out, the loop allocates nothing per posting and reads both
        // the frequencies and the lengths straight out of their sections with
        // `ord()`.
        //
        // ── What this owes Scorer ──────────────────────────────────────────
        //
        // The arithmetic below is BM25F exactly as {@see Scorer::fieldedFrequency()}
        // and {@see Scorer::fieldedScore()} define it, in the same order, so
        // that the two agree to the last bit rather than to a tolerance. That
        // ordering is deliberate and fragile: `1 - b + b * (len / avg)` is not
        // the same float as `(1 - b) + (b / avg) * len`, and hoisting `b / avg`
        // out of the loop — which is tempting, and would save a division —
        // would shift scores in their last places. Scorer stays the definition
        // of the formula; this is the same formula written for the innermost
        // loop, and `Scorer` is what a reader should consult to understand it.
        //
        // Do not simplify the expression. Change Scorer and this together, or
        // neither.
        $idf     = $slot->idf;
        $k1      = $this->scorer->k1;
        $k1Plus1 = $k1 + 1.0;
        $width   = $this->frequencyWidth;
        $lengths = $this->lengths();
        $known   = strlen($lengths);
        $row     = $width * 2;

        foreach ($slot->candidates as [$term, $weight]) {
            $entry = $this->entryFor($term);

            if ($entry === null) {
                continue;
            }

            $cursor = PostingsCursor::open(
                $this->segment->read('postings', $entry['offset'], $entry['length'])
            );

            $records = $this->frequenciesFor($entry);
            $bytes   = strlen($records);
            $index   = 0;

            while ($cursor->current() !== PostingsFormat::END) {
                $ordinal = $cursor->current();

                $combined = 0.0;
                $at       = $index * $width;

                // A document whose length row is short or missing counts as
                // zero in every field, which is what fieldLengthsOf() did with
                // its all-or-nothing check on the row.
                $base  = $ordinal * $row;
                $sized = $base >= 0 && $base + $row <= $known;

                for ($bit = 0; $bit < $width; $bit++) {
                    $position = $at + $bit;

                    if ($position >= $bytes) {
                        break;
                    }

                    $frequency = ord($records[$position]);

                    if ($frequency === 0) {
                        continue;
                    }

                    $average = $averages[$bit];

                    if ($average > 0.0) {
                        $offset = $base + $bit * 2;
                        $length = $sized ? ord($lengths[$offset]) | (ord($lengths[$offset + 1]) << 8) : 0;
                        $b      = $bByBit[$bit];

                        $combined += $weightByBit[$bit] * $frequency / (1.0 - $b + $b * ($length / $average));

                        continue;
                    }

                    // Scorer uses a normalisation of exactly 1.0 when a field
                    // has no average, and dividing by 1.0 is exact.
                    $combined += $weightByBit[$bit] * $frequency;
                }

                // Same rule as the driven walk: each variant scored whole, the
                // slot keeping the best. See matchQuery().
                //
                // `isset` rather than `max(… ?? 0.0, …)` because a score of
                // zero still has to *set* the ordinal: it says the document
                // holds the slot, which is what the threshold counts, even when
                // the slot came to nothing.
                $score = $combined > 0.0
                    ? $weight * $idf * $combined * $k1Plus1 / ($k1 + $combined)
                    : 0.0;

                if (!isset($found[$ordinal]) || $score > $found[$ordinal]) {
                    $found[$ordinal] = $score;
                }

                $index++;
                $cursor->next();
            }
        }

        return $found;
    }

    /**
     * How many terms one document holds in each field.
     *
     * @return array<int, int>
     * @throws CorruptSegmentException
     */
    private function fieldLengthsOf(int $ordinal): array
    {
        if (isset($this->fieldLengthCache[$ordinal])) {
            return $this->fieldLengthCache[$ordinal];
        }

        $count = max(1, count($this->searchableFields));

        $this->lengths ??= $this->segment->has('lengths')
            ? $this->segment->read('lengths')
            : '';

        $packed = substr($this->lengths, $ordinal * $count * 2, $count * 2);
        $values = strlen($packed) === $count * 2
            ? array_values(unpack('v' . $count, $packed))
            : array_fill(0, $count, 0);

        return $this->fieldLengthCache[$ordinal] = $values;
    }

    /**
     * A term's document frequency and where its posting list is, cached.
     *
     * Cached because a search asks twice: once to gather document frequencies
     * across every segment, so that IDF describes the index rather than one
     * segment, and once to walk the postings. Without the cache that would be
     * two dictionary lookups per term per segment.
     *
     * @return array{documents: int, offset: int, length: int, masks: int}|null
     * @throws CorruptSegmentException
     */
    private function entryFor(string $term): ?array
    {
        if (array_key_exists($term, $this->termCache)) {
            return $this->termCache[$term];
        }

        $payload = $this->terms()->get($term);

        return $this->termCache[$term] = $payload === null ? null : self::decodeEntry($payload);
    }

    /**
     * @return array{documents: int, offset: int, length: int, masks: int}
     */
    private static function decodeEntry(string $payload): array
    {
        // Decoded in separate statements rather than inside one array literal:
        // every call advances $position, and relying on evaluation order to get
        // them in sequence is the kind of cleverness that breaks silently.
        $position = 0;

        $documents = Varint::decode($payload, $position);
        $offset    = Varint::decode($payload, $position);
        $length    = Varint::decode($payload, $position);

        // Segments written before field masks existed stop here.
        $masks = $position < strlen($payload) ? Varint::decode($payload, $position) : 0;

        return [
            'documents' => $documents,
            'offset'    => $offset,
            'length'    => $length,
            'masks'     => $masks,
        ];
    }


    private function averageLength(): float
    {
        return $this->documentCount > 0 ? $this->termLengthSum / $this->documentCount : 0.0;
    }

    /**
     * Turns a filter tree into the set of documents it selects.
     *
     * The tree itself knows nothing about this segment — it is a value object
     * the caller built, or one that arrived as JSON. Compilation has to happen
     * here because a segment numbers its own documents and holds its own
     * columns, so the same tree becomes a different bitmap in each one.
     *
     * Everything is set arithmetic on strings of bits: `all` intersects, `any`
     * unions, `not` complements. Nothing reads a document.
     *
     * @throws FilterException
     * @throws FieldTypeException
     * @throws CorruptSegmentException
     */
    private function compile(Filter $filter): Bitset
    {
        if ($filter->isGroup()) {
            return match ($filter->operator) {
                // An `all` with nothing in it is the identity, so a filter
                // built by a loop that added no clauses narrows nothing.
                'all' => array_reduce(
                    $filter->children,
                    fn(Bitset $set, Filter $child): Bitset => $set->and($this->compile($child)),
                    Bitset::full($this->documentCount),
                ),
                // An `any` with nothing in it matches nothing, which is what
                // "one of these zero things" has to mean.
                'any' => array_reduce(
                    $filter->children,
                    fn(Bitset $set, Filter $child): Bitset => $set->or($this->compile($child)),
                    Bitset::empty($this->documentCount),
                ),
                default => $this->compile($filter->children[0])->not(),
            };
        }

        $field = (string) $filter->field;

        // The same clause, met again in another facet's view of the tree. See
        // $leaves: this is a whole column scan, and the variants of one search
        // differ by a single clause.
        //
        // json_encode rather than serialize: the value is a scalar or a list of
        // them — Filter::normalise() and the schema's coercion have already had
        // it — so this is short, and it distinguishes 1 from '1' and from true,
        // which the compilation below deliberately does too.
        $key = $filter->operator . '|' . $field . '|' . json_encode($filter->value);

        if (isset($this->leaves[$key])) {
            return $this->leaves[$key];
        }

        $column = $this->column($field);

        if ($column === null) {
            throw new FilterException("No filterable column for field '$field'" . $this->becauseInferred($field));
        }

        // The same rules that decide what may be *stored* in the field decide
        // what may be compared against it, so filtering by '129.90' and
        // indexing '129.90' are one act. It is also where strictness comes
        // from: eq('brand', true) is refused rather than quietly matching '1'.
        //
        // Applied per value, because `in` and `between` carry a list of them
        // and the field's type describes each element, not the list.
        try {
            $value = match ($filter->operator) {
                'exists', 'missing' => null,
                'in', 'notIn', 'between' => is_array($filter->value)
                    ? array_map(
                        fn(mixed $one): mixed => $one === null ? null : $this->schema->coerceComparand($field, $one),
                        $filter->value,
                    )
                    : $filter->value,
                default => $this->schema->coerceComparand($field, $filter->value),
            };
        } catch (FieldTypeException $e) {
            // Re-thrown as a filter problem, because that is what the caller
            // was doing. The type rules are shared with indexing, but someone
            // catching bad query input should not have to know that. The
            // original is chained, and its message is already the good one.
            throw new FilterException($e->getMessage(), 0, $e);
        }

        if ($column instanceof KeywordColumn) {
            return $this->leaves[$key] = $this->compileKeyword($column, $filter->operator, $field, $value);
        }

        if ($column instanceof TagColumn) {
            return $this->leaves[$key] = $this->compileTags($column, $filter->operator, $field, $value);
        }

        return $this->leaves[$key] = $this->compileNumeric($column, $filter->operator, $field, $value);
    }

    /**
     * A filter on a multi-valued field.
     *
     * `eq` is containment, because a list of three tags does not equal one tag
     * and there is nothing else the comparison could usefully mean. The
     * negations follow the same SQL reading as everywhere else in this file: a
     * document with no tags at all is not "tagged something other than
     * summer", so `neq` excludes it and `not(eq(...))` — the plain complement
     * — includes it.
     *
     * "Summer *and* luxury" needs no operator: `Filter::all()` of two `eq`
     * clauses is two bitsets and one AND, which the tree already expresses.
     *
     * @throws FilterException
     * @throws CorruptSegmentException
     */
    private function compileTags(TagColumn $column, string $operator, string $field, mixed $value): Bitset
    {
        return match ($operator) {
            'eq'      => $column->contains((string) $value),
            'neq'     => $column->contains((string) $value)->not()->and($column->exists()),
            'in'      => $column->containsAny($this->strings($field, $value)),
            'notIn'   => $column->containsAny($this->strings($field, $value))->not()->and($column->exists()),
            'exists'  => $column->exists(),
            'missing' => $column->missing(),
            default   => throw new FilterException($this->wrongOperator($operator, $field, 'tags')),
        };
    }

    /**
     * @throws FilterException
     * @throws CorruptSegmentException
     */
    private function compileKeyword(KeywordColumn $column, string $operator, string $field, mixed $value): Bitset
    {
        return match ($operator) {
            'eq'      => $column->equals((string) $value),
            // Excludes documents with no value, as SQL does: a comparison
            // against nothing is not true. `not(eq(...))` is the plain
            // complement, and does include them.
            'neq'     => $column->equals((string) $value)->not()->and($column->exists()),
            'in'      => $column->in($this->strings($field, $value)),
            'notIn'   => $column->in($this->strings($field, $value))->not()->and($column->exists()),
            'exists'  => $column->exists(),
            'missing' => $column->missing(),
            default   => throw new FilterException($this->wrongOperator($operator, $field, 'keyword')),
        };
    }

    /**
     * @throws FilterException
     * @throws CorruptSegmentException
     */
    private function compileNumeric(NumericColumn $column, string $operator, string $field, mixed $value): Bitset
    {
        if ($operator === 'in' || $operator === 'notIn') {
            $matched = Bitset::empty($this->documentCount);

            foreach ($this->numbers($field, $value) as $number) {
                $matched = $matched->or($column->equals($number));
            }

            return $operator === 'in' ? $matched : $matched->not()->and($column->exists());
        }

        if ($operator === 'between') {
            [$min, $max] = $this->bounds($field, $value);

            return $column->range($min, $max);
        }

        return match ($operator) {
            'eq'      => $column->equals($this->number($field, $value)),
            'neq'     => $column->equals($this->number($field, $value))->not()->and($column->exists()),
            'gt'      => $column->range($this->number($field, $value), null, minInclusive: false),
            'gte'     => $column->range($this->number($field, $value), null),
            'lt'      => $column->range(null, $this->number($field, $value), maxInclusive: false),
            'lte'     => $column->range(null, $this->number($field, $value)),
            'exists'  => $column->exists(),
            'missing' => $column->missing(),
            default   => throw new FilterException($this->wrongOperator($operator, $field, 'numeric')),
        };
    }

    /**
     * One filter value as the float a numeric column compares against.
     *
     * A declared field has already been through the schema, so this only has
     * to catch the inferred case — where the column is numeric but nothing
     * vouched for the value.
     *
     * @throws FilterException
     */
    private function number(string $field, mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        // A price slider in a web form sends '60', never 60.
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new FilterException(
            "Filter on '$field' expects a number, got " . get_debug_type($value)
            . (is_string($value) ? ' ' . var_export($value, true) : '')
        );
    }

    /**
     * @return float[]
     * @throws FilterException
     */
    private function numbers(string $field, mixed $value): array
    {
        if (!is_array($value)) {
            throw new FilterException("Filter on '$field' expects a list of values");
        }

        return array_map(fn(mixed $one): float => $this->number($field, $one), array_values($value));
    }

    /**
     * @return string[]
     * @throws FilterException
     */
    private function strings(string $field, mixed $value): array
    {
        if (!is_array($value)) {
            throw new FilterException("Filter on '$field' expects a list of values");
        }

        $strings = [];

        foreach ($value as $one) {
            if (is_string($one) || is_int($one) || is_float($one)) {
                $strings[] = (string) $one;
                continue;
            }

            throw new FilterException(
                "Filter on '$field' expects a list of exact values, got " . get_debug_type($one) . ' in it'
            );
        }

        return $strings;
    }

    /**
     * @return array{float|null, float|null}
     * @throws FilterException
     */
    private function bounds(string $field, mixed $value): array
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new FilterException("Filter between on '$field' expects a minimum and a maximum");
        }

        [$min, $max] = array_values($value);

        // An open end is useful: between(50, null) is "50 and up" without
        // needing a separate clause.
        return [
            $min === null ? null : $this->number($field, $min),
            $max === null ? null : $this->number($field, $max),
        ];
    }

    /**
     * Why an operator was refused, and what to do about it.
     *
     * A range operator on a keyword column is almost always the same story: the
     * values arrived as strings, so inference called the field a keyword, and
     * `<=` has no meaning over a value dictionary. Saying only that the
     * operator does not apply leaves the caller to work that out themselves.
     */
    private function wrongOperator(string $op, string $field, string $kind): string
    {
        return "Operator '$op' does not apply to the $kind field '$field'"
            . $this->becauseInferred($field);
    }

    /**
     * The hint to append when a field's type was guessed rather than declared.
     *
     * Only for an inferred schema: if the caller declared the type themselves,
     * telling them what they declared is noise.
     */
    private function becauseInferred(string $field): string
    {
        if (!$this->schema->isInferred()) {
            return '';
        }

        $type = $this->schema->typeOf($field);

        if ($type === null) {
            return ". The schema was inferred and holds no field '$field'"
                . ' — a field absent from the schema is stored but never filtered';
        }

        return ". Its type was inferred as $type from the values it was given"
            . ' — pass numbers as int or float rather than as strings, or declare the field'
            . ' with Schema::number() to settle it';
    }

    /**
     * The requested page, best first.
     *
     * Selection is bounded: a query matching eight thousand documents used to
     * build eight thousand scores and sort them to return twenty. TopK keeps
     * only offset+limit candidates, so the memory is fixed whatever the query
     * matches.
     *
     * @param array<int, float> $scores
     * @param Sort[]            $criteria
     * @return array<int, float> ordinal => score
     * @throws SortException
     */
    private function page(Bitset $matches, array $scores, int $limit, int $offset, array $criteria = []): array
    {
        $top  = new TopK(max(0, $offset) + max(0, $limit));
        $keys = $this->sortKeys($criteria, $matches);

        foreach ($matches->iterate() as $ordinal) {
            $score = $scores[$ordinal] ?? 0.0;

            $top->offer($this->rankOf($criteria, $keys, $scores, $ordinal), 0, $ordinal, $score);
        }

        $page = [];

        foreach (array_slice($top->drain(), max(0, $offset)) as [, , $ordinal, $score]) {
            $page[$ordinal] = $score;
        }

        return $page;
    }

    /**
     * The column values every sort criterion needs, read once per criterion.
     *
     * @param Sort[] $criteria
     * @return array<int, array<int, float>> criterion position => ordinal => value
     * @throws SortException
     * @throws CorruptSegmentException
     * @internal used by the multi-segment layer too
     */
    public function sortKeys(array $criteria, Bitset $matches): array
    {
        $keys = [];

        foreach ($criteria as $position => $criterion) {
            if ($criterion->isScore()) {
                continue;
            }

            $field  = (string) $criterion->field;
            $column = $this->column($field);

            if ($column === null) {
                throw new SortException("No sortable column for field '$field'" . $this->becauseInferred($field));
            }

            if (!$column instanceof NumericColumn) {
                // Deliberate, and stated in Sort: ordering text means ordering
                // it *correctly*, which is a different job per script and is
                // not one this release does.
                throw new SortException(
                    "Cannot sort by '$field': only numeric fields can be sorted. "
                    . 'Sort on a number, or on a numeric field your application derives from the text'
                );
            }

            $keys[$position] = $column->values($matches);
        }

        return $keys;
    }

    /**
     * One candidate's rank: every criterion reduced to bigger-is-better.
     *
     * @param Sort[]                          $criteria
     * @param array<int, array<int, float>>   $keys
     * @param array<int, float>               $scores
     * @return float[]
     * @internal used by the multi-segment layer too
     */
    public function rankOf(array $criteria, array $keys, array $scores, int $ordinal): array
    {
        if ($criteria === []) {
            return [$scores[$ordinal] ?? 0.0];
        }

        $rank = [];

        foreach ($criteria as $position => $criterion) {
            $rank[] = $criterion->rankOf(
                $criterion->isScore()
                    ? ($scores[$ordinal] ?? 0.0)
                    : ($keys[$position][$ordinal] ?? null)
            );
        }

        return $rank;
    }

    private function terms(): BlockDictionaryReader
    {
        return $this->terms ??= new BlockDictionaryReader($this->segment, 'terms');
    }

    private function keys(): BlockDictionaryReader
    {
        return $this->keys ??= new BlockDictionaryReader($this->segment, 'keys');
    }

    /**
     * @throws CorruptSegmentException
     */
    private function ordinalOf(string $id): ?int
    {
        $payload = $this->keys()->get($id);

        if ($payload === null) {
            return null;
        }

        $position = 0;

        return Varint::decode($payload, $position);
    }

    /**
     * Ordinal back to the caller's id.
     *
     * The key dictionary maps id → ordinal, which is the direction lookups
     * need and the wrong one for reporting results. `§ keyfwd` records the
     * other direction outright: one `u32` offset per document, then the keys
     * back to back, so a lookup is an unpack and a read of exactly the bytes
     * wanted.
     *
     * ── The fallback, and why it is still here ─────────────────────────────
     *
     * A segment written before the section existed has none, and inverting the
     * dictionary is the only way left. That path is what this replaces, and it
     * is worth stating what it cost, because it looked free: **60.7 ms and
     * 5.4 MB, per request, per segment, to serve the twenty ids of one page**,
     * and growing with the index rather than with the page. On the reference
     * catalogue it was the largest single cost in a cold request — larger than
     * matching the query.
     *
     * Reindexing is therefore worth it, and nothing forces it: the two paths
     * return the same ids.
     *
     * @throws CorruptSegmentException
     */
    private function keyOf(int $ordinal): string
    {
        $offsets = $this->forwardKeys();

        if ($offsets !== false && $ordinal >= 0 && $ordinal < $this->documentCount) {
            $start = unpack('V', substr($offsets, $ordinal * 4, 4))[1];
            $end   = unpack('V', substr($offsets, ($ordinal + 1) * 4, 4))[1];

            // An empty key cannot be written — put() refuses one — so this can
            // only mean a truncated table, and answering the ordinal is the
            // same thing the dictionary path does when it finds nothing.
            return $end > $start
                ? $this->segment->read('keyfwd', strlen($offsets) + $start, $end - $start)
                : (string) $ordinal;
        }

        if ($this->keysByOrdinal === null) {
            $this->keysByOrdinal = [];

            foreach ($this->keys()->iterate() as $key => $payload) {
                $position = 0;
                $this->keysByOrdinal[Varint::decode($payload, $position)] = $key;
            }
        }

        return $this->keysByOrdinal[$ordinal] ?? (string) $ordinal;
    }

    /**
     * `§ keyfwd`'s offset table, or false when the segment predates it.
     *
     * The table alone is read, not the keys with it: four bytes a document
     * against the whole of them, which is the same trade `DocumentStore` makes
     * for the same reason — a page wants twenty of the payloads and none of
     * the rest.
     *
     * @throws CorruptSegmentException
     */
    private function forwardKeys(): string|false
    {
        if ($this->keyOffsets !== null) {
            return $this->keyOffsets;
        }

        if (!$this->segment->has('keyfwd')) {
            return $this->keyOffsets = false;
        }

        $wanted = ($this->documentCount + 1) * 4;
        $table  = $this->segment->read('keyfwd', 0, min($wanted, $this->segment->length('keyfwd')));

        // A table shorter than the document count is a truncated section rather
        // than an old one, and the dictionary still holds every key — so fall
        // back rather than hand out ordinals as ids.
        return $this->keyOffsets = strlen($table) === $wanted ? $table : false;
    }

    private function column(string $field): NumericColumn|KeywordColumn|TagColumn|null
    {
        if (array_key_exists($field, $this->columns)) {
            return $this->columns[$field];
        }

        $definition = $this->schema->fields()[$field] ?? null;

        // A field the schema declares but did not make filterable has no
        // column, and neither does one the schema never mentioned. Both answer
        // null, and the caller turns that into an explicit error rather than a
        // silent mismatch.
        if ($definition === null || !$definition['filterable']) {
            return $this->columns[$field] = null;
        }

        return $this->columns[$field] = match ($definition['type']) {
            'number', 'boolean' => NumericColumn::open($this->segment, 'dv.' . $field),
            'keyword'           => KeywordColumn::open($this->segment, 'dv.' . $field),
            'tags'              => TagColumn::open($this->segment, 'dv.' . $field),
            default             => null,
        };
    }
}
