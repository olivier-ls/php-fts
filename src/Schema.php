<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\FtsException;

/**
 * What each field is, declared rather than guessed.
 *
 *     Schema::make()
 *         ->text('title', boost: 3.0)
 *         ->text('description', b: 0.4)
 *         ->keyword('brand')
 *         ->tags('tags')
 *         ->number('price')
 *         ->boolean('active')
 *         ->stored('image_url')
 *         ->source(['title', 'price']);
 *
 * ── Why declaring beats inferring ───────────────────────────────────────────
 *
 * Inference stays the default, and it is what makes the engine usable without
 * reading any documentation. But it has been wrong twice already, in the same
 * way each time: a heuristic that has to be frozen at the first commit is a
 * schema chosen by a machine from the least information it will ever have. The
 * first batch might be ten documents.
 *
 * It also cannot see intent. There is no way for a guess to know that a status
 * code should be filterable but not searchable, that an image URL should come
 * back but never be analysed, or that the documents themselves need not be
 * stored at all because they are already in a database — which is the single
 * largest saving available, since the document store is around 58% of a
 * typical index.
 *
 * ── Capabilities, not just types ────────────────────────────────────────────
 *
 * A field's type implies what it can do, and each can be overridden:
 *
 *   text      analysed into terms. Not filterable: prose is not a value.
 *   keyword   analysed *and* given a column, so a brand is both searchable and
 *             filterable and facetable.
 *   tags      a list of keywords, analysed. Filtering on them needs a
 *             multi-valued column, which is not built yet.
 *   number    a column: filterable, sortable, and the basis of range facets.
 *   boolean   a column holding 0 or 1.
 *   stored    returned and nothing else — never analysed, never filtered.
 *
 * ── A field the schema does not mention ─────────────────────────────────────
 *
 * It is kept and returned, but neither searched nor filtered. A catalogue
 * export gains columns all the time, and a search engine should not reject a
 * product because someone added a field to it. The alternative — inferring the
 * stragglers — would reintroduce exactly the drift the schema exists to remove.
 */
final class Schema
{
    public const TYPES = ['text', 'keyword', 'tags', 'number', 'boolean', 'stored'];

    /** @var array<string, array{type: string, indexed: bool, filterable: bool, stored: bool, boost: float, b: float|null}> */
    private array $fields = [];

    /** @var string[]|null|false null = everything, false = nothing, list = those fields */
    private array|null|false $source = null;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Prose: analysed into terms, never filtered on.
     *
     * @param float      $boost how much a match here is worth by default; a
     *                          query may override it
     * @param float|null $b     how much this field's length matters, 0 to 1.
     *                          Null uses the scorer's default. A field whose
     *                          values are all about the same length gains
     *                          nothing from normalisation and can set 0.
     */
    public function text(string $field, float $boost = 1.0, ?float $b = null, bool $stored = true): self
    {
        return $this->with($field, 'text', indexed: true, filterable: false, stored: $stored, boost: $boost, b: $b);
    }

    /**
     * An exact value: searchable, filterable and facetable.
     */
    public function keyword(
        string $field,
        float $boost = 1.0,
        bool $indexed = true,
        bool $filterable = true,
        bool $stored = true,
    ): self {
        return $this->with($field, 'keyword', $indexed, $filterable, $stored, $boost, null);
    }

    /**
     * A list of exact values, analysed as text.
     *
     * Filtering on tags needs a multi-valued column, which is not built yet, so
     * `filterable` is accepted and ignored for now rather than silently
     * pretending to work.
     */
    public function tags(string $field, float $boost = 1.0, bool $stored = true): self
    {
        return $this->with($field, 'tags', indexed: true, filterable: false, stored: $stored, boost: $boost, b: null);
    }

    /**
     * A number: filterable, sortable, and what range and statistics facets read.
     */
    public function number(string $field, bool $filterable = true, bool $stored = true): self
    {
        return $this->with($field, 'number', indexed: false, filterable: $filterable, stored: $stored, boost: 1.0, b: null);
    }

    public function boolean(string $field, bool $filterable = true, bool $stored = true): self
    {
        return $this->with($field, 'boolean', indexed: false, filterable: $filterable, stored: $stored, boost: 1.0, b: null);
    }

    /**
     * Returned and nothing else.
     *
     * For an image URL, a serialised blob, an HTML body nobody searches: today
     * every string is analysed, so a 3 KB description produces hundreds of
     * n-grams that will never be looked up.
     */
    public function stored(string $field): self
    {
        return $this->with($field, 'stored', indexed: false, filterable: false, stored: true, boost: 1.0, b: null);
    }

    /**
     * Which fields come back in a result.
     *
     * Your data already lives somewhere. `source(false)` stores nothing at all:
     * a search returns ids and you fetch the rows you need from your own
     * database. On a 20 000-document catalogue the document store is roughly
     * 58% of the index, so this is the largest single saving available.
     *
     * One constraint: highlighting a field requires that field to be stored.
     *
     * @param string[]|false $fields
     */
    public function source(array|false $fields): self
    {
        $clone = clone $this;

        $clone->source = $fields === false ? false : array_values(array_map('strval', $fields));

        return $clone;
    }

    /**
     * @return array<string, array{type: string, indexed: bool, filterable: bool, stored: bool, boost: float, b: float|null}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function typeOf(string $field): ?string
    {
        return $this->fields[$field]['type'] ?? null;
    }

    /**
     * Fields that contribute terms, sorted by name.
     *
     * The order is the mask vocabulary: bit 0 is the first field here. Sorting
     * makes it stable, so a mask written by one segment means the same thing
     * when a merged segment reads it.
     *
     * @return string[]
     */
    public function searchableFields(): array
    {
        $searchable = [];

        foreach ($this->fields as $field => $definition) {
            if ($definition['indexed']) {
                $searchable[] = $field;
            }
        }

        sort($searchable, SORT_STRING);

        return $searchable;
    }

    /**
     * Default boosts by field name, for fields a query does not mention.
     *
     * @return array<string, float>
     */
    public function boosts(): array
    {
        $boosts = [];

        foreach ($this->fields as $field => $definition) {
            if ($definition['indexed'] && $definition['boost'] !== 1.0) {
                $boosts[$field] = $definition['boost'];
            }
        }

        return $boosts;
    }

    /**
     * Whether a field's value should be kept in the document store.
     */
    public function isStored(string $field): bool
    {
        if ($this->source === false) {
            return false;
        }

        if (is_array($this->source)) {
            return in_array($field, $this->source, true);
        }

        // Not mentioned by the schema: kept, but neither searched nor filtered.
        return $this->fields[$field]['stored'] ?? true;
    }

    public function storesNothing(): bool
    {
        return $this->source === false;
    }

    /**
     * Keeps only what a result should carry.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function project(array $document): array
    {
        if ($this->source === false) {
            return [];
        }

        $projected = [];

        foreach ($document as $field => $value) {
            if ($this->isStored((string) $field)) {
                $projected[$field] = $value;
            }
        }

        return $projected;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'source' => $this->source,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws FtsException
     */
    public static function fromArray(array $data): self
    {
        $schema = new self();

        foreach ($data['fields'] ?? [] as $field => $definition) {
            if (!is_array($definition) || !isset($definition['type'])) {
                throw new FtsException("Schema entry for '$field' is malformed");
            }

            $schema = $schema->with(
                (string) $field,
                (string) $definition['type'],
                (bool) ($definition['indexed'] ?? false),
                (bool) ($definition['filterable'] ?? false),
                (bool) ($definition['stored'] ?? true),
                (float) ($definition['boost'] ?? 1.0),
                isset($definition['b']) ? (float) $definition['b'] : null,
            );
        }

        $source = $data['source'] ?? null;

        if ($source === false || is_array($source)) {
            $schema = $schema->source($source === false ? false : $source);
        }

        return $schema;
    }

    /**
     * A schema built from types the engine inferred, so that inferred and
     * declared indexes take exactly the same code path afterwards.
     *
     * @param array<string, string> $types field => 'text' | 'keyword' | 'number'
     */
    public static function inferred(array $types): self
    {
        $schema = new self();

        foreach ($types as $field => $type) {
            $schema = match ($type) {
                'number'  => $schema->number((string) $field),
                'keyword' => $schema->keyword((string) $field),
                default   => $schema->text((string) $field),
            };
        }

        return $schema;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->source === null;
    }

    // -------------------------------------------------------------------------

    /**
     * @throws FtsException
     */
    private function with(
        string $field,
        string $type,
        bool $indexed,
        bool $filterable,
        bool $stored,
        float $boost,
        ?float $b,
    ): self {
        if ($field === '') {
            throw new FtsException('A schema field must have a name');
        }

        if (!in_array($type, self::TYPES, true)) {
            throw new FtsException("Unknown field type '$type' for '$field'");
        }

        if ($boost <= 0.0) {
            throw new FtsException("A boost must be positive, got $boost for '$field'");
        }

        if ($b !== null && ($b < 0.0 || $b > 1.0)) {
            throw new FtsException("b must be between 0 and 1, got $b for '$field'");
        }

        // Declaring a field twice is a mistake worth surfacing: the second
        // declaration would silently win, and the caller would be reading the
        // first one.
        if (isset($this->fields[$field])) {
            throw new FtsException("Field '$field' is declared twice");
        }

        $clone = clone $this;

        $clone->fields[$field] = [
            'type'       => $type,
            'indexed'    => $indexed,
            'filterable' => $filterable,
            'stored'     => $stored,
            'boost'      => $boost,
            'b'          => $b,
        ];

        return $clone;
    }
}
