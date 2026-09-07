<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

/**
 * Keeps the best K candidates seen, and no more.
 *
 *     $top = new TopK(20);
 *     foreach ($candidates as [$score, $segment, $ordinal]) {
 *         $top->offer([$score], $segment, $ordinal);
 *     }
 *     $top->drain();      // best first
 *
 * ── What "best" means here: nothing ─────────────────────────────────────────
 *
 * A candidate arrives with a **rank**: a list of numbers where bigger is
 * better, compared left to right. One number is relevance. `[-price]` is
 * cheapest first. `[$score, -$price]` is by relevance, ties broken by price.
 *
 * This class knows none of that. Direction, absent values and relevance are
 * all turned into numbers by Sort before they get here, which is why sorting
 * by a column costs this file nothing and why the heap stays bounded whatever
 * the order asked for — the property below is the one that matters, and it
 * survives sorting.
 *
 * ── Why not score everything and sort ──────────────────────────────────────
 *
 * That is what the code did before, and it is fine until a query matches a lot
 * of documents. Returning a page of twenty out of eight thousand matches meant
 * building an array of eight thousand scores and sorting it — O(n log n) time
 * and O(n) memory to throw away 99.75% of the work. On shared hosting, where
 * every request works to a `memory_limit`, the memory is the part that bites.
 *
 * A bounded min-heap costs O(n log K) time and O(K) memory: each candidate is
 * compared against the worst one currently held, and only gets in if it beats
 * it. For K = 20 that comparison is against a heap of depth five.
 *
 * ── Ties ────────────────────────────────────────────────────────────────────
 *
 * Equal ranks are broken by segment and then by document number, so a query
 * run twice returns the same page in the same order. Without that, two
 * documents ranking identically could swap places between requests for no
 * reason a user could see — and a paginated list would show one of them twice
 * and the other never.
 *
 * That tie-break is stable *within* an index but not across a merge, which
 * renumbers documents. It only shows when a sort leaves genuine ties — every
 * product at the same price, say — and the fix is the caller's: add a
 * criterion that distinguishes them.
 */
final class TopK
{
    /** @var \SplMinHeap<array{0: float[], 1: int, 2: int, 3: float}> */
    private \SplMinHeap $heap;

    private int $size;

    public function __construct(int $size)
    {
        $this->size = max(0, $size);

        $this->heap = new class extends \SplMinHeap {
            /**
             * @param array{0: float[], 1: int, 2: int, 3: float} $a
             * @param array{0: float[], 1: int, 2: int, 3: float} $b
             */
            protected function compare(mixed $a, mixed $b): int
            {
                // SplMinHeap wants a positive result when $a belongs closer to
                // the top, and the top is the one that gets evicted — so the
                // *worst* candidate must compare highest.
                return self::rank($b) <=> self::rank($a);
            }

            /**
             * @param array{0: float[], 1: int, 2: int, 3: float} $candidate
             * @return array<int, float|int>
             */
            private static function rank(array $candidate): array
            {
                // The rank vector first, then the earlier segment, then the
                // lower document number, so the order never depends on chance.
                // Flattened into one list because PHP compares lists element by
                // element and stops at the first difference, which is exactly
                // the ordering wanted.
                return [...$candidate[0], -$candidate[1], -$candidate[2]];
            }
        };
    }

    /**
     * @param float[] $rank  bigger is better, compared left to right
     * @param float   $score the relevance to report for this candidate, which
     *        is not necessarily what it is ranked by. It travels with the
     *        candidate because the caller cannot look it up afterwards: scores
     *        are computed per segment, and by the time a page is assembled the
     *        segment that produced them has been left behind. Ignored by every
     *        comparison here.
     */
    public function offer(array $rank, int $segment, int $ordinal, float $score = 0.0): void
    {
        if ($this->size === 0) {
            return;
        }

        if ($this->heap->count() < $this->size) {
            $this->heap->insert([$rank, $segment, $ordinal, $score]);

            return;
        }

        // Only worth keeping if it beats the worst one held.
        $worst = $this->heap->top();

        if ([...$rank, -$segment, -$ordinal] <= [...$worst[0], -$worst[1], -$worst[2]]) {
            return;
        }

        $this->heap->extract();
        $this->heap->insert([$rank, $segment, $ordinal, $score]);
    }

    public function count(): int
    {
        return $this->heap->count();
    }

    /**
     * The candidates held, best first. Consumes the heap.
     *
     * @return array<int, array{0: float[], 1: int, 2: int, 3: float}>
     */
    public function drain(): array
    {
        $ordered = [];

        // The heap yields its worst first, so the result is built backwards.
        while (!$this->heap->isEmpty()) {
            $ordered[] = $this->heap->extract();
        }

        return array_reverse($ordered);
    }
}
