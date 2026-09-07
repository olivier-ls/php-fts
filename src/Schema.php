<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\FieldTypeException;
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

    /** Whether the engine guessed this schema rather than the caller writing it. */
    private bool $inferred = false;

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
     * A list of exact values: searchable, filterable and facetable.
     *
     * Filtering asks whether the list *holds* a value, because there is no
     * useful sense in which a list of three tags equals one tag — see
     * TagColumn. Facet counts therefore add up to more than the number of
     * documents, which is what a tag facet is for.
     */
    public function tags(
        string $field,
        float $boost = 1.0,
        bool $indexed = true,
        bool $filterable = true,
        bool $stored = true,
    ): self {
        return $this->with($field, 'tags', $indexed, $filterable, $stored, $boost, null);
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
     * A document normalised to this schema's types, or an exception naming what
     * was wrong with it.
     *
     * ── Why coerce rather than merely check ─────────────────────────────────
     *
     * Because a converted value has to be the *only* value. The term index, the
     * doc-values column and the document store all read the document, and if
     * each interprets `['Puma']` its own way you get a brand that is findable
     * by typing its name, absent from the facet that would let you click it,
     * and invisible to the filter behind it. Normalising once, here, is what
     * makes those three agree by construction.
     *
     * So `$hit->document['price']` comes back as 129.9 even though `'129.90'`
     * went in. That is what declaring a schema buys: the index normalises.
     *
     * ── What is converted, and what is refused ──────────────────────────────
     *
     * The rule is: convert what is unambiguous, refuse what would need a guess.
     * Numeric strings are the case that matters most, because a DECIMAL column
     * arrives from PDO as `'129.90'` until someone turns on native types — and
     * an engine that rejects the first SQL result set it is handed has failed
     * before it started. `'yes'` for a boolean, by contrast, is someone's
     * convention, not a fact, and gets refused.
     *
     * A null, and an absent field, are always legal. Not every product has
     * every column, and a schema is not the place to require one.
     *
     * A field the schema does not mention passes through untouched, which is
     * the arbitration made everywhere else: kept and returned, never searched
     * or filtered.
     *
     * @param array<string, mixed> $document
     * @param string               $id       the caller's id, for the message
     *
     * @return array<string, mixed>
     * @throws FieldTypeException
     */
    public function coerce(array $document, string $id): array
    {
        // An inferred schema is a guess, and refusing a value against a guess
        // would turn the turnkey path into the strict one. Left untouched, too,
        // rather than leniently converted: normalising `'129.90'` into 129.9
        // when nobody asked would silently drop the caller's own formatting.
        if ($this->inferred) {
            return $document;
        }

        $coerced = [];

        foreach ($document as $field => $value) {
            $field      = (string) $field;
            $definition = $this->fields[$field] ?? null;

            $coerced[$field] = $definition === null
                ? $value
                : $this->coerceField($id, $field, $definition['type'], $value);
        }

        return $coerced;
    }

    /**
     * One value, coerced to a field's declared type.
     *
     * Used for the values a *query* filters with, so that filtering by
     * `'129.90'` and indexing `'129.90'` are the same act. A price slider in a
     * web form sends a string; a JSON body sends whatever it sends.
     *
     * Unlike `coerce()` this applies to an inferred schema too. The reason the
     * two differ: refusing to *store* a document against a guessed type would
     * throw away the caller's data on the strength of a heuristic, whereas a
     * query value is being compared against a column that already exists and
     * already has one kind. There is nothing left to guess at.
     *
     * @throws FieldTypeException
     */
    public function coerceValue(string $field, mixed $value): mixed
    {
        $definition = $this->fields[$field] ?? null;

        if ($definition === null || $value === null) {
            return $value;
        }

        return $this->coerceField(null, $field, $definition['type'], $value);
    }

    /**
     * One value of a field, as a *filter* compares against it.
     *
     * The same as coerceValue() for every type but `tags`, where the two part
     * company: storing a tags field takes a list, while filtering one takes a
     * single tag and asks whether the list holds it. Coercing `'summer'` for
     * storage gives `['summer']`, which is right there and useless here.
     *
     * A tag is coerced exactly as a keyword is, because that is what one item
     * of the list is.
     *
     * @throws FieldTypeException
     */
    public function coerceComparand(string $field, mixed $value): mixed
    {
        $definition = $this->fields[$field] ?? null;

        if ($definition === null || $value === null) {
            return $value;
        }

        if ($definition['type'] === 'tags') {
            if (is_array($value)) {
                throw $this->refuse(
                    null,
                    $field,
                    'tags',
                    'a list. A filter compares against one tag at a time; use in() for several'
                );
            }

            return $this->coerceKeyword(null, $field, $value);
        }

        return $this->coerceField(null, $field, $definition['type'], $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fields'   => $this->fields,
            'source'   => $this->source,
            'inferred' => $this->inferred,
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

        $schema->inferred = (bool) ($data['inferred'] ?? false);

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
                'tags'    => $schema->tags((string) $field),
                default   => $schema->text((string) $field),
            };
        }

        $schema->inferred = true;

        return $schema;
    }

    /**
     * Whether this schema was guessed rather than written.
     *
     * The one place it matters is coercion: an inferred schema never refuses a
     * value, because the type it would be refusing against is itself a guess
     * made from the first batch the index happened to receive.
     */
    public function isInferred(): bool
    {
        return $this->inferred;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->source === null;
    }

    // -------------------------------------------------------------------------

    /**
     * @throws FieldTypeException
     */
    private function coerceField(?string $id, string $field, string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'number'  => $this->coerceNumber($id, $field, $value),
            'boolean' => $this->coerceBoolean($id, $field, $value),
            'keyword' => $this->coerceKeyword($id, $field, $value),
            'tags'    => $this->coerceTags($id, $field, $value),
            'text'    => $this->coerceText($id, $field, $value),
            default   => $this->coerceStored($id, $field, $value),
        };
    }

    /**
     * @throws FieldTypeException
     */
    private function coerceNumber(?string $id, string $field, mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw $this->refuse($id, $field, 'number', 'a float that is not finite');
            }

            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        // is_numeric covers '12', '129.90', '1e3', ' 12' and '0x1A'-free hex,
        // which is exactly the set a database driver hands back as text. The
        // unary plus then yields an int or a float as the string warrants.
        if (is_string($value) && is_numeric($value)) {
            $number = +$value;

            if (is_float($number) && !is_finite($number)) {
                throw $this->refuse($id, $field, 'number', 'a value too large to hold');
            }

            return $number;
        }

        throw $this->refuse($id, $field, 'number', $this->describe($value));
    }

    /**
     * @throws FieldTypeException
     */
    private function coerceBoolean(?string $id, string $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        if (is_string($value)) {
            // The forms a database actually returns for a boolean column, and
            // nothing more: 'yes' and 'on' are someone's convention rather than
            // a fact, and guessing at them is how a filter quietly inverts.
            return match (strtolower(trim($value))) {
                '1', 'true'  => true,
                '0', 'false' => false,
                default      => throw $this->refuse(
                    $id,
                    $field,
                    'boolean',
                    'the string ' . var_export($value, true) . ", which is not one of '1', '0', 'true', 'false'"
                ),
            };
        }

        throw $this->refuse($id, $field, 'boolean', $this->describe($value));
    }

    /**
     * @throws FieldTypeException
     */
    private function coerceKeyword(?string $id, string $field, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (string) $value;
        }

        if (is_array($value)) {
            throw $this->refuse(
                $id,
                $field,
                'keyword',
                'a list. A keyword holds one value; declare the field with tags() to hold several'
            );
        }

        throw $this->refuse($id, $field, 'keyword', $this->describe($value));
    }

    /**
     * @return string[]
     * @throws FieldTypeException
     */
    private function coerceTags(?string $id, string $field, mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            throw $this->refuse($id, $field, 'tags', $this->describe($value));
        }

        $tags = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $tags[] = $item;
                continue;
            }

            if (is_int($item) || (is_float($item) && is_finite($item))) {
                $tags[] = (string) $item;
                continue;
            }

            throw $this->refuse($id, $field, 'tags', 'a list containing ' . $this->describe($item));
        }

        return $tags;
    }

    /**
     * @return string|string[]
     * @throws FieldTypeException
     */
    private function coerceText(?string $id, string $field, mixed $value): string|array
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (string) $value;
        }

        if (is_array($value)) {
            // Kept as a list rather than joined: the analyser joins it with a
            // space of its own, and the caller gets back what they put in.
            return $this->coerceTags($id, $field, $value);
        }

        throw $this->refuse($id, $field, 'text', $this->describe($value));
    }

    /**
     * A stored field is not interpreted, so the only question is whether JSON
     * can hold it. What is refused here is what JSON would otherwise mangle in
     * silence: an object becomes `[]`, a closure becomes `[]`, a resource
     * becomes 0, and the caller finds out months later.
     *
     * @throws FieldTypeException
     */
    private function coerceStored(?string $id, string $field, mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            if ($depth > 32) {
                throw $this->refuse($id, $field, 'stored', 'a structure nested too deeply to encode');
            }

            foreach ($value as $item) {
                $this->coerceStored($id, $field, $item, $depth + 1);
            }

            return $value;
        }

        if (is_float($value) && !is_finite($value)) {
            throw $this->refuse($id, $field, 'stored', 'a float that is not finite');
        }

        if (is_object($value) && !$value instanceof \JsonSerializable && !$value instanceof \stdClass) {
            throw $this->refuse($id, $field, 'stored', $this->describe($value));
        }

        if (is_resource($value)) {
            throw $this->refuse($id, $field, 'stored', 'a resource');
        }

        return $value;
    }

    /**
     * @param string|null $id the document being indexed, or null when the value
     *        came from a query rather than from a document
     */
    private function refuse(?string $id, string $field, string $type, string $received): FieldTypeException
    {
        $subject = $id === null ? "Field '$field'" : "Field '$field' of document '$id'";

        return new FieldTypeException("$subject is declared $type but received $received");
    }

    private function describe(mixed $value): string
    {
        if (is_string($value)) {
            $shown = strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value;

            return 'the string ' . var_export($shown, true);
        }

        if (is_bool($value)) {
            return 'a boolean';
        }

        if (is_array($value)) {
            return 'a list';
        }

        if (is_object($value)) {
            return 'an instance of ' . get_debug_type($value);
        }

        return get_debug_type($value);
    }

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
