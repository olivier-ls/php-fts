<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\FilterException;

/**
 * What to count, over every match rather than over the page.
 *
 *     $result = $engine->search('shoe', facets: [
 *         'brand'    => Facet::terms(size: 20),
 *         'category' => Facet::terms(),
 *         'cheapest' => Facet::stats('price'),
 *     ]);
 *
 *     $result->facets['brand'];      // ['Nike' => 42, 'Adidas' => 31, …]
 *     $result->facets['cheapest'];   // ['count' => 73, 'min' => 12.5, …]
 *
 * The array key names the result, and `field` says what to read — so the same
 * column can be counted twice under two names, and a facet's name in your
 * template need not be a column name.
 *
 * A bare field name still works and picks the kind for you: a keyword column
 * gets term counts, a numeric one gets statistics.
 *
 *     facets: ['brand', 'price']
 *
 * ── Disjunctive facets ──────────────────────────────────────────────────────
 *
 * The problem every catalogue has. A shopper picks Nike; the brand facet is
 * now counted over Nike products only, so it shows `Nike (42)` and nothing
 * else — and the shopper cannot see that there are 31 Adidas to switch to.
 * The facet that made the choice possible has destroyed itself.
 *
 * The fix is to count the brand facet as though the brand clause were not
 * there, while every *other* clause still narrows it. Tag the clause, then
 * exclude that tag from its own facet:
 *
 *     $engine->search('shoe',
 *         filters: Filter::all(
 *             Filter::eq('active', true),
 *             Filter::in('brand', ['Nike'])->tag('brand'),
 *             Filter::in('category', ['Sneakers'])->tag('category'),
 *         ),
 *         facets: [
 *             'brand'    => Facet::terms(exclude: 'brand'),
 *             'category' => Facet::terms(exclude: 'category'),
 *         ],
 *     );
 *
 * The brand facet then counts active Sneakers of every brand; the category
 * facet counts active Nike products of every category. One query, and both
 * facets stay usable.
 *
 * It is not free: each distinct excluded tag costs one more pass of the filter
 * over the query's candidates, per segment. Nothing re-reads a posting list
 * and nothing reads a document — the query is matched once and only the bit
 * arithmetic is repeated.
 */
final class Facet
{
    public const KINDS = ['auto', 'terms', 'stats'];

    /**
     * @param string|null $field  the column to read; null means the name it is
     *        registered under
     * @param int         $size   how many values to return, 0 for all
     * @param string|null $exclude a filter tag to ignore while counting
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $field = null,
        public readonly int $size = 20,
        public readonly ?string $exclude = null,
    ) {
    }

    /**
     * Counts per distinct value, most frequent first.
     *
     * @param int $size 0 returns every value. Counting is always done over the
     *        whole index and truncated at the end, never per segment — cutting
     *        each segment to twenty and adding those up gives the wrong twenty.
     * @throws FilterException
     */
    public static function terms(int $size = 20, ?string $field = null, ?string $exclude = null): self
    {
        if ($size < 0) {
            throw new FilterException("A facet size cannot be negative, got $size");
        }

        return new self('terms', $field, $size, self::checkTag($exclude));
    }

    /**
     * Count, minimum, maximum, sum and mean of a numeric column.
     *
     * @throws FilterException
     */
    public static function stats(?string $field = null, ?string $exclude = null): self
    {
        return new self('stats', $field, 0, self::checkTag($exclude));
    }

    /**
     * Term counts for a keyword column, statistics for a numeric one.
     *
     * What a bare field name in the `facets` array gives you.
     *
     * @throws FilterException
     */
    public static function auto(?string $field = null, ?string $exclude = null): self
    {
        return new self('auto', $field, 0, self::checkTag($exclude));
    }

    /**
     * The column this facet reads, given the name it is registered under.
     */
    public function fieldFor(string $name): string
    {
        return $this->field ?? $name;
    }

    /**
     * Orders term counts: most frequent first, ties by value.
     *
     * The tie-break is not cosmetic. Counts are gathered per segment and added
     * up, so two values with the same count would otherwise come out in
     * whatever order the segments happened to be traversed in — and that order
     * changes when segments merge. A facet whose values reshuffle after a
     * merge, or between two identical requests, is a facet a UI cannot render
     * stably or cache.
     *
     * @param array<string|int, int> $counted
     * @return array<string|int, int>
     */
    public static function rank(array $counted): array
    {
        $keys = array_keys($counted);

        usort(
            $keys,
            static fn(string|int $a, string|int $b): int
                => ($counted[$b] <=> $counted[$a]) ?: strcmp((string) $a, (string) $b)
        );

        $ranked = [];

        foreach ($keys as $key) {
            $ranked[$key] = $counted[$key];
        }

        return $ranked;
    }

    /**
     * Cuts a finished facet down to its size.
     *
     * Applied once, after every segment has contributed. Cutting each segment
     * to twenty and adding those up gives the wrong twenty: a value ranked
     * twenty-first everywhere can outrank one that is first in a single small
     * segment. Only `terms` carries a size, so a statistics facet falls
     * through untouched.
     *
     * @param array<string|int, mixed> $counted
     * @return array<string|int, mixed>
     */
    public function limit(array $counted): array
    {
        return $this->size <= 0 ? $counted : array_slice($counted, 0, $this->size, preserve_keys: true);
    }

    public function withExclude(?string $tag): self
    {
        return new self($this->kind, $this->field, $this->size, self::checkTag($tag));
    }

    /**
     * Accepts what the caller passed to `search()`.
     *
     * A list of field names, a map of name => Facet, or a mixture — because
     * `facets: ['brand', 'price' => Facet::stats()]` is a reasonable thing to
     * write and there is no reason to refuse it.
     *
     * @param array<mixed> $facets
     * @return array<string, self>
     * @throws FilterException
     */
    public static function normalise(array $facets): array
    {
        $normalised = [];

        foreach ($facets as $name => $facet) {
            if (is_int($name)) {
                // A bare field name: the key is the position, so the value has
                // to be the name and the kind is worked out from the column.
                if (!is_string($facet) || $facet === '') {
                    throw new FilterException(
                        'A facet given without a name must be a field name, got ' . get_debug_type($facet)
                    );
                }

                $normalised[$facet] = self::auto();
                continue;
            }

            if ($facet instanceof self) {
                $normalised[(string) $name] = $facet;
                continue;
            }

            if (is_string($facet) && $facet !== '') {
                // 'cheapest' => 'price', the short way of naming a column.
                $normalised[(string) $name] = self::auto($facet);
                continue;
            }

            throw new FilterException(
                "Facet '$name' must be a Facet or a field name, got " . get_debug_type($facet)
            );
        }

        return $normalised;
    }

    /**
     * @throws FilterException
     */
    private static function checkTag(?string $tag): ?string
    {
        if ($tag === '') {
            throw new FilterException('A facet cannot exclude an empty tag');
        }

        return $tag;
    }
}
