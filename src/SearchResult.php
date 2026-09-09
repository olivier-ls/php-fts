<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * What a search returns.
 *
 * An object rather than an array, so that a total and facets have somewhere to
 * live. 1.x returned a plain list, which is why its demo reports a `total` that
 * is really just the page size — there was nowhere to put the real number, and
 * nothing capable of computing it.
 *
 * Iterating the result yields its hits, so the common case still reads like an
 * array.
 *
 * @implements \IteratorAggregate<int, Hit>
 */
final class SearchResult implements \IteratorAggregate, \Countable
{
    /**
     * @param Hit[]                     $hits   the requested page
     * @param int                       $total  matches across the whole index
     * @param array<string, array<string|int, mixed>> $facets
     * @param float                     $took   milliseconds
     *
     * @param string[] $unknown words of the query the index holds nothing
     *        resembling, in the order they were typed.
     *
     *        They are dropped from the search rather than made impossible —
     *        keeping one would empty the result instead of narrowing it, since
     *        no document could ever satisfy it — so a query naming a brand you
     *        do not stock quietly becomes a query without it. That is the right
     *        behaviour and a bad silence: the shopper who typed
     *        `couteau zwilling` gets the whole knife aisle and no hint that
     *        half of what they asked for was ignored.
     *
     *        Reported so an application can say what every search engine says:
     *        no results for *zwilling*, showing results for *couteau*. Empty
     *        for a query the index understood completely, which is the common
     *        case, so `if ($result->unknown)` is the whole test.
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly array $facets = [],
        public readonly float $took = 0.0,
        public readonly array $unknown = [],
    ) {
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->hits);
    }

    /** Hits on this page. Use ->total for matches across the index. */
    public function count(): int
    {
        return count($this->hits);
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }
}
