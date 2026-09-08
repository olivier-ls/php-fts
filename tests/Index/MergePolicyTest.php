<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Index\MergePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * When to merge, and what.
 *
 * Pure decision-making, no files: given the sizes of the segments an index
 * holds, which of them should be collapsed. Keeping this separate from the
 * mechanics is what makes the policy adjustable later without touching
 * anything that writes to disk.
 */
class MergePolicyTest extends TestCase
{
    /**
     * @param array<int, array{0: int, 1: int}> $sizes [documents, deleted]
     * @return array<int, array{documents: int, deleted: int}>
     */
    private function segments(array $sizes): array
    {
        return array_map(
            static fn(array $pair): array => ['documents' => $pair[0], 'deleted' => $pair[1]],
            $sizes
        );
    }

    // =========================================================================
    // Tiers
    // =========================================================================

    #[Test]
    public function segments_smaller_than_the_factor_share_the_lowest_tier(): void
    {
        $policy = new MergePolicy(tierFactor: 8);

        foreach ([0, 1, 4, 7] as $size) {
            $this->assertSame(0, $policy->tierOf($size), "$size documents");
        }
    }

    #[Test]
    public function each_tier_holds_segments_a_factor_larger(): void
    {
        $policy = new MergePolicy(tierFactor: 8);

        $this->assertSame(1, $policy->tierOf(8));
        $this->assertSame(1, $policy->tierOf(63));
        $this->assertSame(2, $policy->tierOf(64));
        $this->assertSame(2, $policy->tierOf(511));
        $this->assertSame(3, $policy->tierOf(512));
    }

    // =========================================================================
    // Choosing what to merge
    // =========================================================================

    #[Test]
    public function nothing_is_merged_below_the_threshold(): void
    {
        $policy = new MergePolicy(segmentsPerTier: 8);

        $this->assertSame([], $policy->select($this->segments([[1, 0], [1, 0], [1, 0]])));
    }

    #[Test]
    public function a_full_tier_is_collapsed(): void
    {
        $policy = new MergePolicy(segmentsPerTier: 8);

        $selected = $policy->select($this->segments(array_fill(0, 8, [1, 0])));

        $this->assertCount(8, $selected);
        $this->assertSame(range(0, 7), $selected);
    }

    #[Test]
    public function the_cheapest_tier_is_collapsed_first(): void
    {
        // Eight large segments and eight tiny ones: the tiny ones cost least to
        // rewrite, so they go first and the amortised cost per document stays
        // low.
        $policy = new MergePolicy(segmentsPerTier: 8, tierFactor: 8);

        $sizes = array_merge(
            array_fill(0, 8, [1000, 0]),   // positions 0-7, tier 3
            array_fill(0, 8, [1, 0]),      // positions 8-15, tier 0
        );

        $this->assertSame(range(8, 15), $policy->select($this->segments($sizes)));
    }

    #[Test]
    public function an_automatic_merge_stops_at_the_document_cap(): void
    {
        // Four segments of a thousand are eligible, but the cap allows two:
        // a third would take the batch to three thousand. What is left over is
        // picked up by the next write.
        $policy = new MergePolicy(segmentsPerTier: 4, maxAutoMergeDocuments: 2500);

        $selected = $policy->select($this->segments(array_fill(0, 4, [1000, 0])));

        $this->assertCount(2, $selected);
    }

    #[Test]
    public function a_tier_of_segments_larger_than_the_cap_is_deferred_to_optimize(): void
    {
        // Deliberate, and worth pinning because it looks like a gap. Merging
        // these would be a hundred times over the cap, and the cap exists to
        // keep an automatic merge from running away inside somebody's HTTP
        // request. So nothing happens automatically — and the index says so, so
        // that a cron can call optimize() instead.
        $policy = new MergePolicy(segmentsPerTier: 2, maxAutoMergeDocuments: 100);

        $segments = $this->segments([[5000, 0], [5000, 0]]);

        $this->assertSame([], $policy->select($segments), 'no automatic merge');
        $this->assertNotSame([], $policy->selectAll($segments), 'but optimize() would');
    }

    #[Test]
    public function live_documents_decide_the_tier_not_written_ones(): void
    {
        // A segment of a thousand documents with all but one deleted is a
        // one-document segment, and belongs in the tier of the small ones — so
        // it completes their tier and the whole lot collapses together.
        $policy = new MergePolicy(segmentsPerTier: 8, tierFactor: 8);

        $sizes = array_merge(
            array_fill(0, 7, [1, 0]),
            [[1000, 999]],      // one live document
        );

        $this->assertCount(8, $policy->select($this->segments($sizes)));
    }

    // =========================================================================
    // Reclaiming space from deletions
    // =========================================================================

    #[Test]
    public function a_mostly_deleted_segment_is_rewritten_on_its_own(): void
    {
        // Below the tier threshold, so nothing would collapse — but two thirds
        // of this segment is tombstones, and searches keep reading documents
        // they then discard.
        $policy = new MergePolicy(segmentsPerTier: 8, deletedRatioThreshold: 0.5);

        $this->assertSame([1], $policy->select($this->segments([[100, 0], [90, 60]])));
    }

    #[Test]
    public function a_lightly_deleted_segment_is_left_alone(): void
    {
        $policy = new MergePolicy(segmentsPerTier: 8, deletedRatioThreshold: 0.5);

        $this->assertSame([], $policy->select($this->segments([[100, 10]])));
    }

    // =========================================================================
    // optimize()
    // =========================================================================

    #[Test]
    public function optimizing_takes_everything(): void
    {
        $policy = new MergePolicy();

        $this->assertSame(
            [0, 1, 2],
            $policy->selectAll($this->segments([[1000, 0], [2, 0], [50000, 0]])),
            'no caps and no tiers when the caller has decided to wait'
        );
    }

    #[Test]
    public function optimizing_an_already_optimal_index_does_nothing(): void
    {
        $policy = new MergePolicy();

        $this->assertSame([], $policy->selectAll($this->segments([[1000, 0]])));
        $this->assertSame([], $policy->selectAll([]));
    }

    #[Test]
    public function optimizing_rewrites_a_single_segment_holding_deletions(): void
    {
        $policy = new MergePolicy();

        $this->assertSame([0], $policy->selectAll($this->segments([[1000, 1]])));
    }

    // =========================================================================
    // The memory budget
    //
    // Every case here says what its budget is, because the point of the
    // parameter is that the decision can be made reproducible. Left to itself
    // the policy reads memory_get_usage(), which is the right default on a
    // shared host and the wrong one in a test.
    // =========================================================================

    #[Test]
    public function the_budget_becomes_a_number_of_documents(): void
    {
        // 10 MB at the measured 1 000 bytes a document, with nothing to carry
        // in a column.
        $policy = new MergePolicy(memoryBudgetBytes: 10 * 1024 * 1024);

        $this->assertSame(10485, $policy->documentCap(0));
    }

    #[Test]
    public function a_column_makes_every_document_dearer(): void
    {
        // The finding this whole mechanism exists for: what a merge costs is
        // linear in documents, and the slope is set by the columns. Sixteen of
        // them roughly triple it, so the same memory buys a third of the
        // documents.
        $policy = new MergePolicy(memoryBudgetBytes: 10 * 1024 * 1024);

        $this->assertSame(10485, $policy->documentCap(0));
        $this->assertSame(3440, $policy->documentCap(16));

        $this->assertLessThan(
            $policy->documentCap(0),
            $policy->documentCap(1),
            'one column already costs something'
        );
    }

    #[Test]
    public function the_ceiling_still_applies_when_memory_is_plentiful(): void
    {
        $policy = new MergePolicy(
            maxAutoMergeDocuments: 50_000,
            memoryBudgetBytes: 8 * 1024 * 1024 * 1024,
        );

        // Not five hundred thousand: the ceiling is a time guard, and time does
        // not get cheaper because memory did.
        $this->assertSame(50_000, $policy->documentCap(0));
    }

    #[Test]
    public function a_starved_process_merges_nothing_rather_than_failing(): void
    {
        // Four segments of a thousand, and room for two hundred documents. The
        // segments stay, search gets a little slower, and optimize() from a
        // cron fixes it — which is the right way round, because piling up
        // segments is a slow index and running out of memory is a failed
        // request.
        $policy = new MergePolicy(segmentsPerTier: 4, memoryBudgetBytes: 200 * 1000);

        $segments = $this->segments(array_fill(0, 4, [1000, 0]));

        $this->assertSame([], $policy->select($segments, 0), 'no automatic merge');
        $this->assertNotSame([], $policy->selectAll($segments), 'but optimize() would');
    }

    #[Test]
    public function the_budget_decides_how_many_segments_go_in(): void
    {
        // Room for two and a half thousand documents, so two segments of a
        // thousand and not the third. Exactly the behaviour the old fixed cap
        // had — the number is now derived rather than declared.
        $policy = new MergePolicy(segmentsPerTier: 4, memoryBudgetBytes: 2500 * 1000);

        $this->assertCount(2, $policy->select($this->segments(array_fill(0, 4, [1000, 0])), 0));
    }

    #[Test]
    public function a_mostly_deleted_segment_too_large_for_the_budget_is_left_alone(): void
    {
        // The other place the cap is used. A segment worth rewriting on its own
        // is still a merge, and still has to fit — four fifths of it being
        // tombstones does not make it cheaper to carry the fifth that is left,
        // because the postings are walked either way.
        $segment = $this->segments([[5000, 4000]]);

        $starved = new MergePolicy(segmentsPerTier: 8, memoryBudgetBytes: 500 * 1000);
        $roomy   = new MergePolicy(segmentsPerTier: 8, memoryBudgetBytes: 50 * 1000 * 1000);

        $this->assertSame([], $starved->select($segment, 0), 'no room to rewrite it');
        $this->assertSame([0], $roomy->select($segment, 0), 'room, so it is rewritten');
    }

    #[Test]
    public function the_memory_cap_can_be_turned_off(): void
    {
        // For somebody who has measured their own workload and would rather
        // count documents, as every version before this one did.
        $policy = new MergePolicy(maxAutoMergeDocuments: 30_000, memoryFraction: 0.0);

        $this->assertSame(30_000, $policy->documentCap(0));
        $this->assertSame(30_000, $policy->documentCap(50), 'columns stop mattering too');
    }

    #[Test]
    public function an_unlimited_memory_limit_leaves_only_the_ceiling(): void
    {
        $limit = ini_get('memory_limit');

        // Raised, never lowered: dropping the limit under what the process has
        // already allocated kills it on the next allocation, and a test that
        // can take the suite down with it is not worth the coverage.
        ini_set('memory_limit', '-1');

        try {
            $policy = new MergePolicy(maxAutoMergeDocuments: 12_345);

            $this->assertSame(12_345, $policy->documentCap(0));
        } finally {
            ini_set('memory_limit', (string) $limit);
        }
    }

    #[Test]
    public function the_shorthand_a_shared_host_writes_is_understood(): void
    {
        // No setAccessible() call: reflection has reached private members
        // without one since PHP 8.1, which is this library's floor, and 8.5
        // deprecates asking.
        $read = new \ReflectionMethod(MergePolicy::class, 'memoryLimit');

        $limit = ini_get('memory_limit');

        try {
            // All well above what the suite has allocated: see the note above
            // about lowering the limit under it.
            foreach (['1G' => 1073741824, '512M' => 536870912, '262144K' => 268435456] as $setting => $bytes) {
                ini_set('memory_limit', $setting);

                $this->assertSame($bytes, $read->invoke(null), "memory_limit=$setting");
            }

            ini_set('memory_limit', '-1');
            $this->assertNull($read->invoke(null), 'unlimited is not a number to take a share of');
        } finally {
            ini_set('memory_limit', (string) $limit);
        }
    }
}
