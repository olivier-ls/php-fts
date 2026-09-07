<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

/**
 * Keeps the best K candidates seen, and no more.
 *
 *     $top = new TopK(20);
 *     foreach ($candidates as [$score, $segment, $ordinal]) {
 *         $top->offer($score, $segment, $ordinal);
 *     }
 *     $top->drain();      // best first
 *
 * ── Why not score everything and sort ──────────────────────────────────────
 *
 * That is what the code did before, and it is fine until a query matches a lot
 * of documents. Returning a page of twenty out of eight thousand matches meant
 * building an array of eight thousand scores and sorting it — O(n log n) time
 * and O(n) memory to throw away 99.75% of the work. On shared hosting with a
 * 128 MB limit, the memory is the part that bites.
 *
 * A bounded min-heap costs O(n log K) time and O(K) memory: each candidate is
 * compared against the worst one currently held, and only gets in if it beats
 * it. For K = 20 that comparison is against a heap of depth five.
 *
 * ── Ties ────────────────────────────────────────────────────────────────────
 *
 * Equal scores are broken by segment and then by document number, so a query
 * run twice returns the same page in the same order. Without that, two
 * documents scoring identically could swap places between requests for no
 * reason a user could see — and a paginated list would show one of them twice
 * and the other never.
 */
final class TopK
{
    /** @var \SplMinHeap<array{0: float, 1: int, 2: int}> */
    private \SplMinHeap $heap;

    private int $size;

    public function __construct(int $size)
    {
        $this->size = max(0, $size);

        $this->heap = new class extends \SplMinHeap {
            /**
             * @param array{0: float, 1: int, 2: int} $a
             * @param array{0: float, 1: int, 2: int} $b
             */
            protected function compare(mixed $a, mixed $b): int
            {
                // SplMinHeap wants a positive result when $a belongs closer to
                // the top, and the top is the one that gets evicted — so the
                // *worst* candidate must compare highest.
                return self::rank($b) <=> self::rank($a);
            }

            /**
             * @param array{0: float, 1: int, 2: int} $candidate
             * @return array{0: float, 1: int, 2: int}
             */
            private static function rank(array $candidate): array
            {
                // Higher score first; then the earlier segment, then the lower
                // document number, so the order never depends on chance.
                return [$candidate[0], -$candidate[1], -$candidate[2]];
            }
        };
    }

    public function offer(float $score, int $segment, int $ordinal): void
    {
        if ($this->size === 0) {
            return;
        }

        if ($this->heap->count() < $this->size) {
            $this->heap->insert([$score, $segment, $ordinal]);

            return;
        }

        // Only worth keeping if it beats the worst one held.
        $worst = $this->heap->top();

        if ([$score, -$segment, -$ordinal] <= [$worst[0], -$worst[1], -$worst[2]]) {
            return;
        }

        $this->heap->extract();
        $this->heap->insert([$score, $segment, $ordinal]);
    }

    public function count(): int
    {
        return $this->heap->count();
    }

    /**
     * The candidates held, best first. Consumes the heap.
     *
     * @return array<int, array{0: float, 1: int, 2: int}>
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
