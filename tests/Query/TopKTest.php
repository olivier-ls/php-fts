<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Query;

use Ols\PhpFts\Query\TopK;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TopKTest extends TestCase
{
    /**
     * A candidate ranked by its score alone, which is what a search with no
     * sort offers. The score is passed twice on purpose: once as the rank the
     * heap orders by, once as the relevance to report.
     */
    private function offer(TopK $top, float $score, int $segment = 0, int $ordinal = 0): void
    {
        $top->offer([$score], $segment, $ordinal, $score);
    }

    /**
     * @param array<int, array{0: float[], 1: int, 2: int, 3: float}> $drained
     * @return float[]
     */
    private function scores(array $drained): array
    {
        return array_map(static fn(array $entry): float => $entry[3], $drained);
    }

    #[Test]
    public function it_keeps_the_best_and_discards_the_rest(): void
    {
        $top = new TopK(3);

        foreach ([1.0, 9.0, 3.0, 7.0, 2.0, 8.0] as $ordinal => $score) {
            $this->offer($top, $score, 0, $ordinal);
        }

        $this->assertSame([9.0, 8.0, 7.0], $this->scores($top->drain()));
    }

    #[Test]
    public function it_returns_them_best_first(): void
    {
        $top = new TopK(5);

        foreach ([3.0, 1.0, 5.0, 2.0, 4.0] as $ordinal => $score) {
            $this->offer($top, $score, 0, $ordinal);
        }

        $this->assertSame([5.0, 4.0, 3.0, 2.0, 1.0], $this->scores($top->drain()));
    }

    #[Test]
    public function fewer_candidates_than_asked_for_is_fine(): void
    {
        $top = new TopK(100);

        $this->offer($top, 2.0, 0, 0);
        $this->offer($top, 1.0, 0, 1);

        $this->assertCount(2, $top->drain());
    }

    #[Test]
    public function a_size_of_zero_keeps_nothing(): void
    {
        $top = new TopK(0);

        $this->offer($top, 9.0, 0, 0);

        $this->assertSame([], $top->drain());
        $this->assertSame(0, $top->count());
    }

    #[Test]
    public function it_never_holds_more_than_its_size(): void
    {
        // The point of the class: memory is fixed however many documents match.
        $top = new TopK(10);

        for ($i = 0; $i < 10_000; $i++) {
            $this->offer($top, (float) $i, 0, $i);
        }

        $this->assertSame(10, $top->count());
        $this->assertSame(9999.0, $this->scores($top->drain())[0]);
    }

    // =========================================================================
    // Determinism
    // =========================================================================

    #[Test]
    public function equal_scores_are_ordered_by_segment_then_document(): void
    {
        // Without a tie-break, two documents scoring the same could swap places
        // between requests — and a paginated list would show one twice and the
        // other never.
        $top = new TopK(4);

        $this->offer($top, 1.0, 1, 5);
        $this->offer($top, 1.0, 0, 9);
        $this->offer($top, 1.0, 0, 2);
        $this->offer($top, 1.0, 1, 1);

        $this->assertSame(
            [[0, 2], [0, 9], [1, 1], [1, 5]],
            array_map(static fn(array $e): array => [$e[1], $e[2]], $top->drain())
        );
    }

    #[Test]
    public function the_same_candidates_offered_in_any_order_give_the_same_page(): void
    {
        $candidates = [];

        for ($i = 0; $i < 200; $i++) {
            // Deliberately repetitive scores, so ties are the common case.
            $candidates[] = [(float) ($i % 5), $i % 3, $i];
        }

        $ascending = new TopK(10);
        foreach ($candidates as [$score, $segment, $ordinal]) {
            $this->offer($ascending, $score, $segment, $ordinal);
        }

        $descending = new TopK(10);
        foreach (array_reverse($candidates) as [$score, $segment, $ordinal]) {
            $this->offer($descending, $score, $segment, $ordinal);
        }

        $this->assertSame($ascending->drain(), $descending->drain());
    }

    // =========================================================================
    // Rank vectors — what a sort turns into before it gets here
    // =========================================================================

    #[Test]
    public function a_rank_is_compared_element_by_element(): void
    {
        $top = new TopK(3);

        // First element decides; the second only settles equals.
        $top->offer([1.0, 5.0], 0, 0);
        $top->offer([2.0, 1.0], 0, 1);
        $top->offer([1.0, 9.0], 0, 2);

        $this->assertSame(
            [1, 2, 0],
            array_map(static fn(array $entry): int => $entry[2], $top->drain())
        );
    }

    #[Test]
    public function a_rank_element_may_be_negative_infinity(): void
    {
        // Which is how Sort says "this document has no value here": worse than
        // anything real, in either direction.
        $top = new TopK(3);

        $top->offer([-INF], 0, 0);
        $top->offer([-5.0], 0, 1);
        $top->offer([0.0], 0, 2);

        $this->assertSame(
            [2, 1, 0],
            array_map(static fn(array $entry): int => $entry[2], $top->drain())
        );
    }

    #[Test]
    public function the_reported_score_is_not_what_the_heap_orders_by(): void
    {
        // A search sorted by price still reports relevance on every hit, so the
        // score travels with the candidate and takes no part in the ordering.
        $top = new TopK(2);

        $top->offer([-10.0], 0, 0, 0.5);   // cheapest, least relevant
        $top->offer([-90.0], 0, 1, 9.0);

        $drained = $top->drain();

        $this->assertSame([0, 1], array_map(static fn(array $e): int => $e[2], $drained));
        $this->assertSame([0.5, 9.0], array_map(static fn(array $e): float => $e[3], $drained));
    }

    #[Test]
    public function a_longer_rank_still_bounds_the_heap(): void
    {
        $top = new TopK(5);

        for ($i = 0; $i < 5_000; $i++) {
            $top->offer([(float) ($i % 7), (float) $i], 0, $i);
        }

        // The best rank is the largest `$i % 7`, which is 6, and then the
        // largest `$i` having it — 4997, then every seventh below it.
        $this->assertSame(5, $top->count());
        $this->assertSame(
            [4997, 4990, 4983, 4976, 4969],
            array_map(static fn(array $entry): int => $entry[2], $top->drain())
        );
    }
}
