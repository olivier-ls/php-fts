<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\SortException;

/**
 * The order a search returns its results in.
 *
 *     $engine->search('shoe', sort: [Sort::desc('price')]);
 *     $engine->search('', sort: [Sort::asc('price')]);              // a category page
 *     $engine->search('shoe', sort: [Sort::score(), Sort::asc('price')]);
 *
 * The default, when nothing is asked for, is relevance — which is what a
 * search box wants and what every version of this library has done.
 *
 * ── Why this is the engine's job and not the caller's ───────────────────────
 *
 * Because sorting and pagination are the same problem. A caller that receives
 * twenty hits and sorts them by price gets *the twenty best-scoring documents,
 * arranged by price* — which is not the twenty cheapest matches, and no amount
 * of local sorting turns one into the other. Getting it right means ordering
 * every match, and only the engine holds every match.
 *
 * The alternative is what 1.x's documentation suggested: ask for two thousand
 * results and sort them yourself. That is the pattern this library exists to
 * retire, so the ordering has to live here.
 *
 * ── Numbers only, for now ───────────────────────────────────────────────────
 *
 * `asc()` and `desc()` take a **numeric** field — a price, a stock level, a
 * timestamp. That covers what catalogues actually order by, and it has the
 * property that makes it safe to ship: 129.90 sorts the same in every
 * language.
 *
 * Alphabetical ordering is deliberately not here yet, because it is not one
 * feature. Done properly it sorts on the *folded* value — the analyzer already
 * computes it, and it is what makes `École` land next to `ecole` and `Ёлка`
 * next to `Ель` instead of before `Абрикос`. That works for Latin, Greek and
 * Cyrillic, and for Japanese written in kana, which Unicode happens to order
 * in gojūon sequence. It does *not* work for Han: the sort key of 革靴 is its
 * reading, which is not in the characters and is ambiguous even to a reader.
 * Chinese sorts by pinyin and Japanese kanji by reading, both of which need a
 * phonetic dictionary this library will not ship.
 *
 * The honest answer there is the one production systems use: sort on a reading
 * field your application supplies — which is a keyword field like any other,
 * and will sort like one.
 *
 * ── Missing values ──────────────────────────────────────────────────────────
 *
 * A document with no value in the sorted field goes **last**, whichever
 * direction was asked for. Not first in one direction and last in the other:
 * "cheapest first" should not open on a page of products with no price.
 */
final class Sort
{
    private function __construct(
        /** The field to order by, or null for relevance. */
        public readonly ?string $field,
        public readonly bool $descending,
    ) {
    }

    /** Most relevant first. The default, and the only order 1.x had. */
    public static function score(): self
    {
        return new self(null, true);
    }

    /** Smallest first: cheapest, oldest, least in stock. */
    public static function asc(string $field): self
    {
        return new self($field, false);
    }

    /** Largest first: most expensive, newest, most in stock. */
    public static function desc(string $field): self
    {
        return new self($field, true);
    }

    public function isScore(): bool
    {
        return $this->field === null;
    }

    /**
     * One criterion's value, turned into a number where **bigger is better**.
     *
     * Reducing every criterion to that one convention is what keeps the
     * comparison itself trivial: a candidate's rank is a list of floats, and
     * ordering candidates is comparing those lists. Direction, relevance and
     * absence all disappear into the numbers before anything is compared —
     * see TopK, which does not know what a field is.
     *
     * @param float|null $value null when the document has no value there
     */
    public function rankOf(?float $value): float
    {
        if ($value === null) {
            // Worse than any real value, in both directions, so a document
            // with no price is never the first thing a shopper sees.
            return -INF;
        }

        return $this->descending ? $value : -$value;
    }

    /**
     * What a `sort:` argument means, and what it refuses.
     *
     * An empty list is relevance, so a caller assembling criteria in a loop
     * that adds none needs no special case.
     *
     * @param self|array<mixed> $sort
     * @return self[]
     * @throws SortException
     */
    public static function normalise(self|array $sort): array
    {
        if ($sort instanceof self) {
            $sort = [$sort];
        }

        $criteria = [];
        $seen     = [];

        foreach ($sort as $criterion) {
            if (!$criterion instanceof self) {
                throw new SortException(
                    'A sort takes Sort::score(), Sort::asc() or Sort::desc(), not '
                    . (is_object($criterion) ? get_class($criterion) : gettype($criterion))
                );
            }

            $key = $criterion->field ?? '';

            if (isset($seen[$key])) {
                // Not harmless enough to ignore: the second one can never
                // decide anything, so a caller who wrote it meant something
                // else — probably a tie-break on another field.
                throw new SortException(
                    $criterion->isScore()
                        ? 'Relevance is already part of this sort'
                        : "Field '{$criterion->field}' is already part of this sort"
                );
            }

            $seen[$key] = true;
            $criteria[] = $criterion;
        }

        return $criteria;
    }
}
