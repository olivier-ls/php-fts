<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Hit;
use Ols\PhpFts\Query\CollectionStatistics;
use Ols\PhpFts\Query\Scorer;
use Ols\PhpFts\Query\TopK;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchResult;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\Varint;

/**
 * Searches one segment.
 *
 *     $index  = SegmentIndex::open('/path/seg_a1f.fts');
 *     $result = $index->search('lether sho',
 *         filters: [['field' => 'price', 'op' => '<=', 'value' => 300]],
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
 * ── What is deliberately provisional ────────────────────────────────────────
 *
 * The filter format is a flat list of ANDed clauses, standing in for the
 * nested, fluent builder the public API will offer.
 */
final class SegmentIndex
{
    /**
     * Fraction of a query's terms a document must hold to match.
     *
     * Sets the balance between recall and precision, and it is the single knob
     * that decides whether "lether sho" still finds "leather shoe": those two
     * share six trigrams out of the query's nine, so anything up to 0.66 keeps
     * them together. Lower it and unrelated words creep in; raise it and typos
     * stop working.
     *
     * A placeholder for the tunable it should become, and for the scoring that
     * will make the cut-off matter less.
     */
    private const MIN_SHOULD_MATCH = 0.6;

    private SegmentReader $segment;
    private Analyzer $analyzer;

    private int $documentCount;

    private Schema $schema;

    private ?BlockDictionaryReader $terms = null;
    private ?BlockDictionaryReader $keys = null;

    /** Reverse of the key dictionary, built on first use. @var string[]|null */
    private ?array $keysByOrdinal = null;

    /** @var array<string, array{documents: int, offset: int, length: int, masks: int}|null> */
    private array $termCache = [];

    private ?string $lengths = null;

    /** @var array<int, array<int, int>> ordinal => bit => field length */
    private array $fieldLengthCache = [];

    /** @var string[] bit => field name */
    private array $searchableFields = [];

    /** @var array<int, int> bit => summed field length */
    private array $fieldLengthSums = [];

    private int $maskWidth = 1;

    private int $termLengthSum = 0;

    private Scorer $scorer;
    private DocumentStore $documents;

    /** @var array<string, NumericColumn|KeywordColumn> */
    private array $columns = [];

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
        $this->maskWidth        = max(1, (int) ($meta['maskWidth'] ?? 1));
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
     * @param array<int, array{field: string, op: string, value: mixed}> $filters ANDed together
     * @param string[]                                                  $facets  field names
     *
     * @throws CorruptSegmentException
     * @throws FilterException
     */
    public function search(
        string $query = '',
        int $limit = 20,
        int $offset = 0,
        array $filters = [],
        array $facets = [],
        array $boosts = [],
    ): SearchResult {
        $started = hrtime(true);

        [$matches, $scores] = $this->select($query, $filters, boosts: $boosts);

        $total = $matches->count();

        $counted = [];

        foreach ($facets as $field) {
            $counted[$field] = $this->facetOn($field, $matches);
        }

        $hits = [];

        foreach ($this->page($matches, $scores, $limit, $offset) as $ordinal => $score) {
            $document = $this->documents->get($ordinal);

            if ($document === null) {
                continue;
            }

            $hits[] = new Hit($this->keyOf($ordinal), $score, $document);
        }

        return new SearchResult($hits, $total, $counted, (hrtime(true) - $started) / 1e6);
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
        string $query,
        array $filters = [],
        ?Bitset $deleted = null,
        ?CollectionStatistics $statistics = null,
        array $boosts = [],
    ): array {
        [$matches, $scores] = $this->matchQuery(
            $query,
            $statistics ?? new CollectionStatistics($this->documentCount, $this->averageLength()),
            $boosts,
        );

        foreach ($filters as $filter) {
            $matches = $matches->and($this->evaluate($filter));
        }

        if ($deleted !== null) {
            $matches = $matches->andNot($deleted);
        }

        return [$matches, $scores];
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
            $entry = $this->entryFor($term);

            if ($entry !== null) {
                $frequencies[$term] = $entry['documents'];
            }
        }

        return $frequencies;
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
     * Summed field lengths, by mask bit, so per-field averages can be taken
     * across segments.
     *
     * @return array<int, int>
     * @internal
     */
    public function fieldLengthSums(): array
    {
        return $this->fieldLengthSums;
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
    private function matchQuery(string $query, CollectionStatistics $statistics, array $boosts = []): array
    {
        $terms = $this->analyzer->analyze($query);

        if ($terms === []) {
            return [Bitset::full($this->documentCount), []];
        }

        /** @var array<int, int> ordinal => how many query terms it holds */
        $held = [];

        /** @var array<int, float> ordinal => summed IDF of those terms */
        $weights = [];

        $weightByBit = $this->boostsByBit($boosts);
        $averages    = $this->fieldAverages($statistics);
        $bByBit      = $this->bByBit();

        foreach ($terms as $term) {
            $entry = $this->entryFor($term);

            if ($entry === null) {
                continue;
            }

            // Computed once per term, before any document is looked at. 1.x
            // called log() and a dictionary lookup inside the per-document loop.
            $idf = $this->scorer->idf(
                $statistics->documentFrequency($term) ?: $entry['documents'],
                $statistics->documentCount ?: $this->documentCount,
            );

            $cursor = PostingsCursor::open(
                $this->segment->read('postings', $entry['offset'], $entry['length'])
            );

            $masks = $this->masksFor($entry);
            $index = 0;

            while ($cursor->current() !== PostingsFormat::END) {
                $ordinal = $cursor->current();

                $held[$ordinal] = ($held[$ordinal] ?? 0) + 1;

                if ($masks === null) {
                    // A segment written before field masks existed: fall back to
                    // plain BM25, which normalises against the whole document.
                    $weights[$ordinal] = ($weights[$ordinal] ?? 0.0) + $idf;
                } else {
                    $combined = $this->scorer->fieldedFrequency(
                        $this->maskAt($masks, $index),
                        $weightByBit,
                        $this->fieldLengthsOf($ordinal),
                        $averages,
                        $bByBit,
                    );

                    $weights[$ordinal] = ($weights[$ordinal] ?? 0.0)
                        + $this->scorer->fieldedScore($idf, $combined);
                }

                $index++;
                $cursor->next();
            }
        }

        if ($held === []) {
            return [Bitset::empty($this->documentCount), []];
        }

        // A document has to hold enough of the query's terms, rather than all of
        // them or merely one.
        //
        // The first version of this jumped: it took the documents holding every
        // term, and if there were none it fell back to those holding any. That
        // decision was made per segment, so the same query answered differently
        // depending on how the documents happened to be spread across segments —
        // and merging them changed the result. A test comparing a search before
        // and after a merge caught it.
        //
        // The threshold is computed from the query alone, never from what this
        // segment happens to contain, which is what makes the answer independent
        // of write history. It also degrades smoothly: a typo costs a few
        // trigrams and stays above the bar, while an unrelated word does not.
        $required = max(1, (int) ceil(count($terms) * self::MIN_SHOULD_MATCH));

        $matches       = Bitset::empty($this->documentCount);
        $scores        = [];
        $fielded       = $this->usesFieldMasks();
        $averageLength = $statistics->averageLength > 0.0
            ? $statistics->averageLength
            : $this->averageLength();

        foreach ($held as $ordinal => $count) {
            if ($count < $required) {
                continue;
            }

            $matches->set($ordinal);

            // With masks the per-term scores are already normalised per field
            // and saturated, so they only need adding up. Without them the
            // accumulated IDF still has to be scaled by the document's length.
            $scores[$ordinal] = $fielded
                ? $weights[$ordinal]
                : $this->scorer->score($weights[$ordinal], $this->lengthOf($ordinal), $averageLength);
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
     * Only fields the schema gave an explicit `b` appear; the rest fall back to
     * the scorer's default inside fieldedFrequency().
     *
     * @return array<int, float>
     */
    private function bByBit(): array
    {
        $definitions = $this->schema->fields();
        $byBit       = [];

        foreach ($this->searchableFields as $bit => $field) {
            $b = $definitions[$field]['b'] ?? null;

            if ($b !== null) {
                $byBit[$bit] = $b;
            }
        }

        return $byBit;
    }

    /**
     * @return array<int, float>
     */
    private function fieldAverages(CollectionStatistics $statistics): array
    {
        $averages = [];

        foreach ($this->searchableFields as $bit => $field) {
            $averages[$bit] = $statistics->fieldAverage($bit)
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
    private function masksFor(array $entry): ?string
    {
        if (!$this->usesFieldMasks()) {
            return null;
        }

        return $this->segment->read(
            'fieldmask',
            $entry['masks'],
            $entry['documents'] * $this->maskWidth
        );
    }

    /**
     * Whether this segment carries field masks at all.
     *
     * A schema with one searchable field does not: every mask byte would hold
     * the same single bit, and BM25F over one neutral field gives exactly what
     * plain BM25 gives. Scoring then takes the unfielded path, which normalises
     * against the whole document instead of per field — the same answer, and
     * 23% of a segment saved.
     */
    private function usesFieldMasks(): bool
    {
        return count($this->searchableFields) > 1 && $this->segment->has("fieldmask");
    }

    private function maskAt(string $masks, int $index): int
    {
        $mask = 0;

        for ($byte = 0; $byte < $this->maskWidth; $byte++) {
            $position = $index * $this->maskWidth + $byte;

            if ($position < strlen($masks)) {
                $mask |= ord($masks[$position]) << ($byte * 8);
            }
        }

        return $mask;
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

        if ($payload === null) {
            return $this->termCache[$term] = null;
        }

        // Decoded in separate statements rather than inside one array literal:
        // every call advances $position, and relying on evaluation order to get
        // them in sequence is the kind of cleverness that breaks silently.
        $position = 0;

        $documents = Varint::decode($payload, $position);
        $offset    = Varint::decode($payload, $position);
        $length    = Varint::decode($payload, $position);

        // Segments written before field masks existed stop here.
        $masks = $position < strlen($payload) ? Varint::decode($payload, $position) : 0;

        return $this->termCache[$term] = [
            'documents' => $documents,
            'offset'    => $offset,
            'length'    => $length,
            'masks'     => $masks,
        ];
    }

    /**
     * How many terms one document produced.
     *
     * @throws CorruptSegmentException
     */
    private function lengthOf(int $ordinal): int
    {
        $this->lengths ??= $this->segment->has('lengths')
            ? $this->segment->read('lengths')
            : '';

        $packed = substr($this->lengths, $ordinal * 2, 2);

        return strlen($packed) === 2 ? unpack('v', $packed)[1] : 0;
    }

    private function averageLength(): float
    {
        return $this->documentCount > 0 ? $this->termLengthSum / $this->documentCount : 0.0;
    }

    /**
     * @param array{field: string, op: string, value: mixed} $filter
     * @throws FilterException
     * @throws CorruptSegmentException
     */
    private function evaluate(array $filter): Bitset
    {
        foreach (['field', 'op', 'value'] as $key) {
            if (!array_key_exists($key, $filter)) {
                throw new FilterException("Filter is missing the '$key' key");
            }
        }

        $field  = (string) $filter['field'];
        $column = $this->column($field);

        if ($column === null) {
            throw new FilterException("No filterable column for field '$field'" . $this->becauseInferred($field));
        }

        $value = $filter['value'];
        $op    = $filter['op'];

        if ($column instanceof KeywordColumn) {
            return match ($op) {
                '=', 'eq'     => $column->equals((string) $value),
                '!=', 'neq'   => $column->equals((string) $value)->not()->and($column->exists()),
                'in'          => $column->in((array) $value),
                'not in'      => $column->in((array) $value)->not()->and($column->exists()),
                'exists'      => $column->exists(),
                'missing'     => $column->missing(),
                default       => throw new FilterException($this->wrongOperator($op, $field, 'keyword')),
            };
        }

        $number = static function (mixed $value) use ($field): float {
            if (is_bool($value)) {
                return $value ? 1.0 : 0.0;
            }

            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            // A price slider in a web form sends '60', never 60. The column is
            // already known to be numeric, so there is nothing to guess at —
            // the same reasoning that lets a declared number field accept
            // '129.90' from a database driver.
            if (is_string($value) && is_numeric($value)) {
                return (float) $value;
            }

            throw new FilterException(
                "Filter on '$field' expects a number, got " . get_debug_type($value)
                . (is_string($value) ? ' ' . var_export($value, true) : '')
            );
        };

        return match ($op) {
            '=', 'eq'   => $column->equals($number($value)),
            '!=', 'neq' => $column->equals($number($value))->not()->and($column->exists()),
            '>', 'gt'   => $column->range($number($value), null, minInclusive: false),
            '>=', 'gte' => $column->range($number($value), null),
            '<', 'lt'   => $column->range(null, $number($value), maxInclusive: false),
            '<=', 'lte' => $column->range(null, $number($value)),
            'between'   => $column->range($number(((array) $value)[0]), $number(((array) $value)[1])),
            'exists'    => $column->exists(),
            'missing'   => $column->missing(),
            default     => throw new FilterException($this->wrongOperator($op, $field, 'numeric')),
        };
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
     * @return array<int, float> ordinal => score
     */
    private function page(Bitset $matches, array $scores, int $limit, int $offset): array
    {
        $top = new TopK(max(0, $offset) + max(0, $limit));

        foreach ($matches->iterate() as $ordinal) {
            $top->offer($scores[$ordinal] ?? 0.0, 0, $ordinal);
        }

        $page = [];

        foreach (array_slice($top->drain(), max(0, $offset)) as [$score, , $ordinal]) {
            $page[$ordinal] = $score;
        }

        return $page;
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
     * The key dictionary maps id → ordinal, which is the direction lookups need
     * and the wrong one for reporting results. Walking it per hit would be one
     * full pass over every key for each of twenty results, so the reverse map is
     * built once, the first time a search actually returns something.
     *
     * @throws CorruptSegmentException
     */
    private function keyOf(int $ordinal): string
    {
        if ($this->keysByOrdinal === null) {
            $this->keysByOrdinal = [];

            foreach ($this->keys()->iterate() as $key => $payload) {
                $position = 0;
                $this->keysByOrdinal[Varint::decode($payload, $position)] = $key;
            }
        }

        return $this->keysByOrdinal[$ordinal] ?? (string) $ordinal;
    }

    private function column(string $field): NumericColumn|KeywordColumn|null
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
            default             => null,
        };
    }
}
