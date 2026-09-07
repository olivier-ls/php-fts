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
}
