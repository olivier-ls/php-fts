<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Index\IndexDirectory;
use Ols\PhpFts\Index\MergePolicy;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A merge must cost the memory of one term, not of every posting in the index.
 *
 * This is the only property in the suite measured in bytes, and it is here
 * because it is the one an automatic merge can violate without anybody asking
 * it to. `optimize()` is a decision — whoever calls it has chosen to wait, and
 * can give the process whatever it needs. A tiered merge is not: it fires
 * inside somebody's `put()`, on a schedule the segment sizes decide, in an
 * HTTP request that on shared hosting has 128 MB and an application already
 * living in it.
 *
 * The merger used to build the whole `term => ordinal => mask` map before
 * writing a byte of it. A posting is a slot in a nested PHP array and costs
 * about 75 bytes there, so on a real catalogue of 45 000 products the map
 * alone reached 700 MB — for an operation that produces a 70 MB file. Nothing
 * about it needed the map: every source dictionary is sorted, so the sources
 * can be walked in lockstep and each term written the moment it is complete.
 *
 * ── What is actually asserted, and why it is this ───────────────────────────
 *
 * Not "a merge is small". Some of what a merge holds is genuinely per
 * document — the keys, the field lengths, the column values — and some is
 * genuinely per term, because a block dictionary is assembled before it is
 * written. Both are bounded by things the caller can see. What was not bounded
 * by anything was the *postings*, and a posting is the one thing an index has
 * in millions.
 *
 * So the corpus below holds the documents and the vocabulary fixed and varies
 * only how many of those words each document uses. Everything else a merge
 * pays for is identical between the runs; the only difference is the number of
 * postings. Measured across an eightfold increase:
 *
 *     40 000 postings   12.5 MB accumulated → 2.9 MB streamed
 *    160 000 postings   32.6 MB            → 2.9 MB
 *    320 000 postings   45.1 MB            → 3.1 MB
 *
 * A stream barely notices. That is the property, and it is the one that makes
 * the size of an index stop being the size of the request that maintains it.
 */
class MergeMemoryTest extends TestCase
{
    /** Documents, held constant: their bookkeeping is not what is measured. */
    private const DOCUMENTS = 2000;

    /** Distinct words, held constant: nor is the dictionary. */
    private const VOCABULARY = 2000;

    /**
     * Comfortably above what the streaming merge uses on the densest corpus
     * below — about 3 MB — and far below the 45 MB the accumulating one needed
     * for the same documents. The gap is wide on purpose: this test exists to
     * catch a return to accumulating, not to police a megabyte.
     */
    private const CEILING = 12 * 1024 * 1024;

    /** @var string[] */
    private array $directories = [];

    protected function setUp(): void
    {
        if (!function_exists('memory_reset_peak_usage')) {
            // PHP 8.2. Without it the build's own peak is still standing when
            // the merge starts, and there is nothing left to measure.
            $this->markTestSkipped('memory_reset_peak_usage() needs PHP 8.2');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory . '/.lock');
            @rmdir($directory);
        }

        $this->directories = [];
    }

    #[Test]
    public function a_dense_index_merges_in_a_shared_hosting_request(): void
    {
        [$cost, $index] = $this->measure(160);

        $this->assertSame(1, $index->stats()['segments'], 'the merge did not happen');
        $this->assertSame(self::DOCUMENTS, $index->count());

        $this->assertLessThan(
            self::CEILING,
            $cost,
            sprintf(
                'merging %s postings took %.1f MB; a merge must stream its terms, not accumulate them',
                number_format(self::DOCUMENTS * 160),
                $cost / 1048576
            )
        );
    }

    /**
     * The same measurement at an eighth of the postings, and the same
     * everything else.
     *
     * On its own the ceiling above is a number somebody picked. This is what
     * gives it a meaning: the accumulating merger's cost *was* the number of
     * postings, so eight times as many cost between three and four times as
     * much. A streaming one holds a term, and a term is not bigger in a denser
     * index.
     */
    #[Test]
    public function eight_times_the_postings_do_not_cost_eight_times_the_memory(): void
    {
        [$sparse] = $this->measure(20);
        [$dense]  = $this->measure(160);

        $this->assertLessThan(
            $sparse * 1.5,
            $dense,
            sprintf(
                'the sparse index cost %.1f MB and the dense one %.1f MB, over the same documents '
                . 'and the same vocabulary; that is the postings being held',
                $sparse / 1048576,
                $dense / 1048576
            )
        );
    }

    // -------------------------------------------------------------------------

    /**
     * What one merge of a whole index costs, and the index it left.
     *
     * @return array{0: float, 1: IndexDirectory}
     */
    private function measure(int $termsPerDocument): array
    {
        $index = $this->built($termsPerDocument);

        $this->assertGreaterThan(1, $index->stats()['segments'], 'nothing would be merged');

        memory_reset_peak_usage();
        $before = memory_get_usage();

        $index->optimize();

        // Never zero, so the ratio above stays meaningful on a run where the
        // merge happens to allocate nothing new.
        return [max(1.0, (float) (memory_get_peak_usage() - $before)), $index];
    }

    /**
     * An index of fixed size and fixed vocabulary, at the chosen density.
     *
     * The words are drawn by a stride rather than at random, so two runs at the
     * same density are the same index and a failure is reproducible.
     *
     * The tiers are turned off while it is built, and only while it is built.
     * Left on, they would collapse the batches as the loop ran and leave one
     * segment with nothing to merge — the pathological case they exist to
     * prevent, which is the wrong thing to measure here. What is wanted is one
     * merge over every segment at once: the widest k-way walk the design can be
     * asked for, and the shape `optimize()` takes on a real index.
     */
    private function built(int $termsPerDocument): IndexDirectory
    {
        $directory = $this->directories[] =
            sys_get_temp_dir() . '/fts_mem_' . uniqid((string) count($this->directories));

        $index = IndexDirectory::open(
            $directory,
            Schema::make()->text('title')->number('rank'),
            new MergePolicy(segmentsPerTier: 1000),
        );

        $batch = [];

        for ($document = 0; $document < self::DOCUMENTS; $document++) {
            $words = [];

            for ($word = 0; $word < $termsPerDocument; $word++) {
                $words[] = 'trm' . (($document * 7 + $word * 13) % self::VOCABULARY);
            }

            $batch['doc-' . $document] = ['title' => implode(' ', $words), 'rank' => $document];

            if (count($batch) === 500) {
                $index->putMany($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $index->putMany($batch);
        }

        return $index;
    }
}
