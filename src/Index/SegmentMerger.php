<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Schema;

/**
 * Combines several segments into one, dropping the documents they no longer
 * hold.
 *
 * ── How it does it, and what that costs ─────────────────────────────────────
 *
 * By re-indexing: the live documents are read out of the source segments and
 * fed to a fresh SegmentIndexWriter. A faster merger would splice the term
 * dictionaries and posting lists together directly, without analysing any text
 * again — that is what a mature engine does, and it is several times quicker.
 *
 * Re-indexing was chosen anyway, for now, because it cannot produce a segment
 * that differs from what a fresh index would have produced. A dictionary splice
 * has to get ordinal remapping, skip tables and value dictionaries all exactly
 * right, and every one of those is a place to be subtly wrong in a way no test
 * would obviously catch. The direct merge is worth doing once there is a
 * differential test to hold it against.
 *
 * ── What a merge actually achieves ──────────────────────────────────────────
 *
 *   - Deleted documents stop existing, rather than being filtered out on every
 *     search, and the manifest's deletion bitmaps shrink back to nothing.
 *   - Searches stop paying per segment: one dictionary lookup per term instead
 *     of N, one posting list instead of N, one facet count instead of N merged.
 *   - Local ordinals are reassigned densely, so posting lists compress better.
 *
 * The caller's ids are untouched. That is the point of keeping them in their
 * own dictionary rather than using the ordinal as the identity — a merge
 * renumbers everything internally and no id changes, which is precisely what
 * 1.x got wrong when it made the document's byte offset its identity.
 */
final class SegmentMerger
{
    public function __construct(private readonly Analyzer $analyzer = new Analyzer())
    {
    }

    /**
     * Writes the live documents of several segments into one new segment.
     *
     * @param SegmentIndex[]             $sources   segment position => segment
     * @param Bitset[]                   $deletions same keys as $sources
     * @param Schema|null                $schema    the index's frozen schema
     *
     * @return int how many documents the new segment holds
     * @throws StorageException
     */
    public function merge(array $sources, array $deletions, string $path, ?Schema $schema = null): int
    {
        $writer = new SegmentIndexWriter($this->analyzer, $schema);

        foreach ($sources as $position => $segment) {
            $deleted = $deletions[$position] ?? null;
            $count   = $segment->count();

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                if ($deleted !== null && $deleted->has($ordinal)) {
                    continue;
                }

                $document = $segment->documentAt($ordinal);

                if ($document === null) {
                    continue;
                }

                $writer->put($segment->keyAt($ordinal), $document);
            }
        }

        if ($writer->count() === 0) {
            // Every source was entirely deleted. There is nothing to write, and
            // the caller drops the sources from the manifest.
            return 0;
        }

        $writer->write($path);

        return $writer->count();
    }
}
