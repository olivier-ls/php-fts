<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Query;

use Ols\PhpFts\Query\TopK;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TopKTest extends TestCase
{
    /**
     * @param array<int, array{0: float, 1: int, 2: int}> $drained
     * @return float[]
     */
    private function scores(array $drained): array
    {
        return array_map(static fn(array $entry): float => $entry[0], $drained);
    }

    #[Test]
    public function it_keeps_the_best_and_discards_the_rest(): void
    {
        $top = new TopK(3);

        foreach ([1.0, 9.0, 3.0, 7.0, 2.0, 8.0] as $ordinal => $score) {
            $top->offer($score, 0, $ordinal);
        }

        $this->assertSame([9.0, 8.0, 7.0], $this->scores($top->drain()));
    }

    #[Test]
    public function it_returns_them_best_first(): void
    {
        $top = new TopK(5);

        foreach ([3.0, 1.0, 5.0, 2.0, 4.0] as $ordinal => $score) {
            $top->offer($score, 0, $ordinal);
        }

        $this->assertSame([5.0, 4.0, 3.0, 2.0, 1.0], $this->scores($top->drain()));
    }

    #[Test]
    public function fewer_candidates_than_asked_for_is_fine(): void
    {
        $top = new TopK(100);

        $top->offer(2.0, 0, 0);
        $top->offer(1.0, 0, 1);

        $this->assertCount(2, $top->drain());
    }

    #[Test]
    public function a_size_of_zero_keeps_nothing(): void
    {
        $top = new TopK(0);

        $top->offer(9.0, 0, 0);

        $this->assertSame([], $top->drain());
        $this->assertSame(0, $top->count());
    }

    #[Test]
    public function it_never_holds_more_than_its_size(): void
    {
        // The point of the class: memory is fixed however many documents match.
        $top = new TopK(10);

        for ($i = 0; $i < 10_000; $i++) {
            $top->offer((float) $i, 0, $i);
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

        $top->offer(1.0, 1, 5);
        $top->offer(1.0, 0, 9);
        $top->offer(1.0, 0, 2);
        $top->offer(1.0, 1, 1);

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
            $ascending->offer($score, $segment, $ordinal);
        }

        $descending = new TopK(10);
        foreach (array_reverse($candidates) as [$score, $segment, $ordinal]) {
            $descending->offer($score, $segment, $ordinal);
        }

        $this->assertSame($ascending->drain(), $descending->drain());
    }
}
