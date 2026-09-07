<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Hit;
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
 * ── What is deliberately provisional ────────────────────────────────────────
 *
 * Ranking here is the number of query terms a document contains. It is enough
 * to put the right documents at the top of a small result set and nowhere near
 * enough in general — BM25F, the field-mask stream it needs and a proper
 * top-K selection are the next piece of work. The same goes for the filter
 * format: a flat list of ANDed clauses, standing in for the nested, fluent
 * builder the public API will offer.
 */
final class SegmentIndex
{
    private SegmentReader $segment;
    private Analyzer $analyzer;

    private int $documentCount;

    /** @var array<string, string> field => 'number' | 'keyword' | 'text' */
    private array $fields;

    private ?BlockDictionaryReader $terms = null;
    private ?BlockDictionaryReader $keys = null;

    /** Reverse of the key dictionary, built on first use. @var string[]|null */
    private ?array $keysByOrdinal = null;
    private DocumentStore $documents;

    /** @var array<string, NumericColumn|KeywordColumn> */
    private array $columns = [];

    private function __construct(SegmentReader $segment, ?Analyzer $analyzer)
    {
        $this->segment  = $segment;
        $this->analyzer = $analyzer ?? new Analyzer();

        $meta = json_decode($segment->read('meta'), true);

        if (!is_array($meta) || !isset($meta['documentCount'], $meta['fields'])) {
            throw new CorruptSegmentException('Segment metadata is missing or unreadable');
        }

        $this->documentCount = (int) $meta['documentCount'];
        $this->fields        = $meta['fields'];
        $this->documents     = DocumentStore::open($segment, 'docs');
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

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
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
    ): SearchResult {
        $started = hrtime(true);

        [$matches, $scores] = $this->select($query, $filters);

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
     * @return array{0: Bitset, 1: array<int, int>} the matches, and term counts
     * @internal
     * @throws CorruptSegmentException
     * @throws FilterException
     */
    public function select(string $query, array $filters = [], ?Bitset $deleted = null): array
    {
        [$matches, $scores] = $this->matchQuery($query);

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

    // -------------------------------------------------------------------------

    /**
     * Turns the query into a set of candidate documents, plus how many query
     * terms each one holds.
     *
     * An empty query matches everything, which is what makes filters and facets
     * usable on their own — a category page with no search box.
     *
     * @return array{0: Bitset, 1: array<int, int>}
     * @throws CorruptSegmentException
     */
    private function matchQuery(string $query): array
    {
        $terms = $this->analyzer->analyze($query);

        if ($terms === []) {
            return [Bitset::full($this->documentCount), []];
        }

        /** @var Bitset[] $sets one per term actually present in the index */
        $sets   = [];
        $scores = [];

        foreach ($terms as $term) {
            $payload = $this->terms()->get($term);

            if ($payload === null) {
                continue;
            }

            $position = 0;
            Varint::decode($payload, $position);                  // document frequency, unused for now
            $listOffset = Varint::decode($payload, $position);
            $listLength = Varint::decode($payload, $position);

            $cursor = PostingsCursor::open(
                $this->segment->read('postings', $listOffset, $listLength)
            );

            $set = Bitset::empty($this->documentCount);

            while ($cursor->current() !== PostingsFormat::END) {
                $ordinal = $cursor->current();
                $set->set($ordinal);
                $scores[$ordinal] = ($scores[$ordinal] ?? 0) + 1;
                $cursor->next();
            }

            $sets[] = $set;
        }

        if ($sets === []) {
            return [Bitset::empty($this->documentCount), []];
        }

        // Every term present is the precise answer; when nothing holds them all,
        // fall back to anything holding one. A real query planner would degrade
        // gradually between the two rather than jumping.
        $all = $sets[0];

        foreach (array_slice($sets, 1) as $set) {
            $all = $all->and($set);
        }

        if (!$all->isEmpty()) {
            return [$all, $scores];
        }

        $any = $sets[0];

        foreach (array_slice($sets, 1) as $set) {
            $any = $any->or($set);
        }

        return [$any, $scores];
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

        $column = $this->column($filter['field']);

        if ($column === null) {
            throw new FilterException("No filterable column for field '{$filter['field']}'");
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
                default       => throw new FilterException("Operator '$op' does not apply to a keyword field"),
            };
        }

        $number = static function (mixed $value): float {
            if (is_bool($value)) {
                return $value ? 1.0 : 0.0;
            }

            if (!is_int($value) && !is_float($value)) {
                throw new FilterException('Numeric filters expect an int or a float');
            }

            return (float) $value;
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
            default     => throw new FilterException("Operator '$op' does not apply to a numeric field"),
        };
    }

    /**
     * The requested page, best first.
     *
     * @param array<int, int> $scores
     * @return array<int, float> ordinal => score
     */
    private function page(Bitset $matches, array $scores, int $limit, int $offset): array
    {
        $ranked = [];

        foreach ($matches->iterate() as $ordinal) {
            $ranked[$ordinal] = (float) ($scores[$ordinal] ?? 0);
        }

        arsort($ranked);

        return array_slice($ranked, max(0, $offset), max(0, $limit), true);
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

        $type = $this->fields[$field] ?? null;

        return $this->columns[$field] = match ($type) {
            'number'  => NumericColumn::open($this->segment, 'dv.' . $field),
            'keyword' => KeywordColumn::open($this->segment, 'dv.' . $field),
            default   => null,
        };
    }
}
