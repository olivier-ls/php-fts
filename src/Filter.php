<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\FilterException;

/**
 * A condition documents must satisfy, composed as deeply as you like.
 *
 *     Filter::all(
 *         Filter::eq('active', true),
 *         Filter::gt('stock', 0),
 *         Filter::between('price', 50, 300),
 *         Filter::any(
 *             Filter::eq('brand', 'Nike'),
 *             Filter::eq('brand', 'Adidas'),
 *         ),
 *         Filter::not(Filter::exists('discontinued_at')),
 *     )
 *
 * ── A tree, and nothing more ────────────────────────────────────────────────
 *
 * This class knows nothing about segments, columns or bitmaps. It is a value
 * object: immutable, comparable, serialisable, and testable without an index
 * anywhere near it. Turning it into set operations is the index's job, and it
 * has to be, because each segment numbers its own documents and holds its own
 * columns — the same tree compiles to a different bitmap in every segment.
 *
 * That separation is also what makes `fromArray()` safe to point at an HTTP
 * request: the structure is validated as a structure, before any of it reaches
 * the code that reads files.
 *
 * ── `neq` and `not` are not the same thing ──────────────────────────────────
 *
 * A distinction worth knowing before it surprises you:
 *
 *     Filter::neq('brand', 'Nike')          // has a brand, and it is not Nike
 *     Filter::not(Filter::eq('brand', 'Nike'))  // is not a Nike — including
 *                                               // products with no brand at all
 *
 * `neq` follows SQL: a comparison against a missing value is not true, so a
 * product with no brand is not "a brand other than Nike". `not` is plain set
 * complement, which is what the word means. Both are useful, so both exist
 * rather than one being quietly picked for you.
 *
 * ── Tags, for facets that stay useful ───────────────────────────────────────
 *
 * `->tag('brand')` marks a clause so a facet can be counted as though that
 * clause were absent — the classic problem where picking Nike makes the brand
 * facet show only Nike, and the shopper can no longer see Adidas to switch to
 * it. `withoutTag()` is the mechanism: it returns the same tree with the
 * tagged clauses lifted out.
 */
final class Filter
{
    /** Conditions on one field. */
    public const OPERATORS = [
        'eq', 'neq', 'in', 'notIn',
        'gt', 'gte', 'lt', 'lte', 'between',
        'exists', 'missing',
    ];

    /** Ways of combining conditions. */
    public const GROUPS = ['all', 'any', 'not'];

    /**
     * Spellings accepted as well as the names, so a filter that arrives as
     * JSON from a front end can read the way the front end wrote it.
     */
    public const ALIASES = [
        '='      => 'eq',
        '=='     => 'eq',
        '!='     => 'neq',
        '<>'     => 'neq',
        '>'      => 'gt',
        '>='     => 'gte',
        '<'      => 'lt',
        '<='     => 'lte',
        'not in' => 'notIn',
        'not_in' => 'notIn',
        'and'    => 'all',
        'or'     => 'any',
    ];

    /** How deeply a filter may nest, so a hostile payload cannot exhaust the stack. */
    public const MAX_DEPTH = 32;

    /**
     * @param self[] $children empty for a condition on a field
     */
    private function __construct(
        public readonly string $operator,
        public readonly ?string $field,
        public readonly mixed $value,
        public readonly array $children = [],
        public readonly ?string $tag = null,
    ) {
    }

    public function isGroup(): bool
    {
        return in_array($this->operator, self::GROUPS, true);
    }

    // =========================================================================
    // Equality
    // =========================================================================

    public static function eq(string $field, mixed $value): self
    {
        return self::leaf('eq', $field, $value);
    }

    /**
     * Has a value, and it is not this one.
     *
     * SQL semantics: a document with no value for the field does not match. Use
     * `not(eq(...))` for plain complement, which does include it.
     */
    public static function neq(string $field, mixed $value): self
    {
        return self::leaf('neq', $field, $value);
    }

    /**
     * @param array<int, mixed> $values
     * @throws FilterException
     */
    public static function in(string $field, array $values): self
    {
        return self::leaf('in', $field, self::requireValues($field, 'in', $values));
    }

    /**
     * @param array<int, mixed> $values
     * @throws FilterException
     */
    public static function notIn(string $field, array $values): self
    {
        return self::leaf('notIn', $field, self::requireValues($field, 'notIn', $values));
    }

    // =========================================================================
    // Comparison
    // =========================================================================

    public static function gt(string $field, mixed $value): self
    {
        return self::leaf('gt', $field, $value);
    }

    public static function gte(string $field, mixed $value): self
    {
        return self::leaf('gte', $field, $value);
    }

    public static function lt(string $field, mixed $value): self
    {
        return self::leaf('lt', $field, $value);
    }

    public static function lte(string $field, mixed $value): self
    {
        return self::leaf('lte', $field, $value);
    }

    /**
     * Inclusive at both ends.
     *
     * @throws FilterException
     */
    public static function between(string $field, mixed $min, mixed $max): self
    {
        if (is_numeric($min) && is_numeric($max) && $min > $max) {
            throw new FilterException(
                "Filter between on '$field' has a minimum above its maximum, so it can never match"
            );
        }

        return self::leaf('between', $field, [$min, $max]);
    }

    // =========================================================================
    // Presence
    // =========================================================================

    public static function exists(string $field): self
    {
        return self::leaf('exists', $field, null);
    }

    public static function missing(string $field): self
    {
        return self::leaf('missing', $field, null);
    }

    // =========================================================================
    // Logic
    // =========================================================================

    /**
     * Every condition. With none, matches everything — the identity, so that
     * building a filter from a loop that adds nothing needs no special case.
     */
    public static function all(self ...$children): self
    {
        return new self('all', null, null, array_values($children));
    }

    /**
     * Any condition. With none, matches nothing, which is what "one of these
     * zero things" has to mean.
     */
    public static function any(self ...$children): self
    {
        return new self('any', null, null, array_values($children));
    }

    public static function not(self $child): self
    {
        return new self('not', null, null, [$child]);
    }

    // =========================================================================
    // Tags
    // =========================================================================

    /**
     * Marks this clause, so a facet can be counted as though it were absent.
     */
    public function tag(string $tag): self
    {
        if ($tag === '') {
            throw new FilterException('A filter tag cannot be empty');
        }

        return new self($this->operator, $this->field, $this->value, $this->children, $tag);
    }

    /**
     * The same tree with every clause carrying this tag lifted out, or null if
     * nothing is left of it.
     *
     * A group loses only the tagged children, so `all(active, brand→'brand')`
     * becomes `all(active)` rather than nothing: the shopper's other choices
     * still narrow the counts, which is the point.
     */
    public function withoutTag(string $tag): ?self
    {
        if ($this->tag === $tag) {
            return null;
        }

        if (!$this->isGroup()) {
            return $this;
        }

        $kept = [];

        foreach ($this->children as $child) {
            $stripped = $child->withoutTag($tag);

            if ($stripped !== null) {
                $kept[] = $stripped;
            }
        }

        // A `not` whose only child went away has nothing to negate, and an
        // empty `any` matches nothing — neither is what "count as though that
        // clause were absent" means, so both dissolve.
        if ($kept === [] && $this->operator !== 'all') {
            return null;
        }

        return new self($this->operator, null, null, $kept, $this->tag);
    }

    /**
     * Every tag used anywhere in the tree.
     *
     * @return string[]
     */
    public function tags(): array
    {
        $tags = $this->tag === null ? [] : [$this->tag];

        foreach ($this->children as $child) {
            $tags = [...$tags, ...$child->tags()];
        }

        return array_values(array_unique($tags));
    }

    // =========================================================================
    // Serialisation
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['op' => $this->operator];

        if ($this->isGroup()) {
            $data['filters'] = array_map(static fn(self $c): array => $c->toArray(), $this->children);
        } else {
            $data['field'] = $this->field;

            if ($this->operator !== 'exists' && $this->operator !== 'missing') {
                $data['value'] = $this->value;
            }
        }

        if ($this->tag !== null) {
            $data['tag'] = $this->tag;
        }

        return $data;
    }

    /**
     * Builds a filter from plain data — a decoded JSON request body, say.
     *
     *     {"op": "all", "filters": [
     *         {"op": "eq",      "field": "active", "value": true},
     *         {"op": "between", "field": "price",  "value": [50, 300]}
     *     ]}
     *
     * Validated as a structure before any of it reaches the index. Everything
     * unexpected is refused rather than ignored: a misspelled operator that
     * silently dropped its clause would widen a search instead of narrowing it,
     * which is the failure nobody notices.
     *
     * Also accepts a flat list of clauses, combined with `all`, which is what
     * a simple front end tends to send.
     *
     * @param array<mixed> $data
     * @throws FilterException
     */
    public static function fromArray(array $data, int $depth = 0): self
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FilterException('Filter nests deeper than ' . self::MAX_DEPTH . ' levels');
        }

        if (!isset($data['op'])) {
            // A list of clauses, ANDed. `array_is_list` is not enough on its
            // own: an empty array is a list too, and means "no filter".
            if ($data === []) {
                return self::all();
            }

            if (!array_is_list($data)) {
                throw new FilterException("Filter is missing its 'op'");
            }

            $children = [];

            foreach ($data as $clause) {
                if (!is_array($clause)) {
                    throw new FilterException('A filter clause must be an array');
                }

                $children[] = self::fromArray($clause, $depth + 1);
            }

            return self::all(...$children);
        }

        if (!is_string($data['op'])) {
            throw new FilterException("A filter's 'op' must be a string");
        }

        $operator = self::canonical($data['op']);
        $filter   = in_array($operator, self::GROUPS, true)
            ? self::groupFromArray($operator, $data, $depth)
            : self::leafFromArray($operator, $data);

        $tag = $data['tag'] ?? null;

        return is_string($tag) && $tag !== '' ? $filter->tag($tag) : $filter;
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<mixed> $data
     * @throws FilterException
     */
    private static function leafFromArray(string $operator, array $data): self
    {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new FilterException(
                "Unknown filter operator '{$data['op']}'. Known operators: "
                . implode(', ', [...self::OPERATORS, ...self::GROUPS])
            );
        }

        $field = $data['field'] ?? null;

        if (!is_string($field) || $field === '') {
            throw new FilterException("Filter '$operator' is missing its 'field'");
        }

        if ($operator === 'exists' || $operator === 'missing') {
            return self::leaf($operator, $field, null);
        }

        if (!array_key_exists('value', $data)) {
            throw new FilterException("Filter '$operator' on '$field' is missing its 'value'");
        }

        $value = $data['value'];

        if ($operator === 'in' || $operator === 'notIn') {
            if (!is_array($value)) {
                throw new FilterException("Filter '$operator' on '$field' expects a list of values");
            }

            return self::leaf($operator, $field, self::requireValues($field, $operator, $value));
        }

        if ($operator === 'between') {
            if (!is_array($value) || count($value) !== 2 || !array_is_list($value)) {
                throw new FilterException(
                    "Filter between on '$field' expects a list of exactly two values, a minimum and a maximum"
                );
            }

            return self::between($field, $value[0], $value[1]);
        }

        return self::leaf($operator, $field, $value);
    }

    /**
     * @param array<mixed> $data
     * @throws FilterException
     */
    private static function groupFromArray(string $operator, array $data, int $depth): self
    {
        $children = $data['filters'] ?? null;

        if (!is_array($children) || !array_is_list($children)) {
            throw new FilterException("Filter '$operator' expects a list under 'filters'");
        }

        $built = [];

        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new FilterException("Filter '$operator' expects each of its 'filters' to be an array");
            }

            $built[] = self::fromArray($child, $depth + 1);
        }

        if ($operator === 'not') {
            if (count($built) !== 1) {
                throw new FilterException("Filter 'not' negates exactly one filter, got " . count($built));
            }

            return self::not($built[0]);
        }

        return $operator === 'all' ? self::all(...$built) : self::any(...$built);
    }

    /**
     * Accepts what the caller passed to `search()`: a Filter, a nested array,
     * or a flat list of clauses. Null when there is nothing to apply.
     *
     * @param array<mixed>|self $filters
     * @throws FilterException
     */
    public static function normalise(array|self $filters): ?self
    {
        if ($filters instanceof self) {
            return $filters;
        }

        if ($filters === []) {
            return null;
        }

        return self::fromArray($filters);
    }

    private static function canonical(string $operator): string
    {
        return self::ALIASES[strtolower(trim($operator))] ?? $operator;
    }

    /**
     * @throws FilterException
     */
    private static function leaf(string $operator, string $field, mixed $value): self
    {
        if ($field === '') {
            throw new FilterException('A filter must name a field');
        }

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new FilterException("Unknown filter operator '$operator'");
        }

        return new self($operator, $field, $value);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     * @throws FilterException
     */
    private static function requireValues(string $field, string $operator, array $values): array
    {
        // An empty list is refused rather than defined as matching nothing or
        // everything: both readings are defensible, and code that builds
        // `in($field, $selected)` from a form would silently get whichever one
        // it did not want. Omit the clause when nothing is selected.
        if ($values === []) {
            throw new FilterException(
                "Filter '$operator' on '$field' was given no values."
                . ' Leave the filter out entirely when there is nothing to match against'
            );
        }

        return array_values($values);
    }
}
