<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Schema;

/**
 * Combines several segments into one, dropping the documents they no longer
 * hold.
 *
 * ── Why it does not re-index ────────────────────────────────────────────────
 *
 * It used to. The live documents were read back out of the source segments and
 * fed to a fresh SegmentIndexWriter, which analysed them again — appealing,
 * because a merge could then not produce a segment that differed from what a
 * fresh index would have produced.
 *
 * It was also wrong, and not subtly. **The documents are not necessarily
 * there.** `source()` decides what the docstore keeps, and a field left out of
 * it is still indexed and still has a column — that is the whole point of it:
 * search the description, do not store a second copy of it. Re-indexing read
 * back a document with that field missing and produced a segment without its
 * terms and without its column. With `source(false)` there were no documents at
 * all, so the first automatic merge turned a working index into one that
 * matched nothing. Silently, because a merge needs no permission and reports no
 * result.
 *
 * So a document is **carried** instead. Everything a merge needs is already on
 * disk in a form that does not involve text:
 *
 *   - the postings *are* the terms — walking them yields (term, ordinal, field
 *     mask), which is exactly what the writer accumulates;
 *   - the field lengths BM25F needs were measured once and recorded;
 *   - a column holds its own values, so it can be read into the next column;
 *   - the stored document, the keys — copied as they are.
 *
 * Nothing is analysed, so nothing depends on what `source()` kept. It is also
 * several times quicker, which was the other argument for doing it this way,
 * and the one that mattered less.
 *
 * What it costs is that the ordinal remapping has to be right, and there is no
 * cheap way to be sure by inspection. Hence the differential test: an index
 * merged from three segments must answer identically to the same documents
 * indexed in one commit, term by term, filter by filter, facet by facet, and
 * with `source()` set every way it can be.
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
        $schema ??= $this->schemaOf($sources);
        $writer   = new SegmentIndexWriter($this->analyzer, $schema);

        $columns = [];

        foreach ($schema->fields() as $field => $definition) {
            if ($definition['filterable']) {
                $columns[] = (string) $field;
            }
        }

        /** @var array<int, array<int, int>> segment position => source ordinal => new ordinal */
        $remaps = [];

        foreach ($sources as $position => $segment) {
            $deleted = $deletions[$position] ?? null;
            $count   = $segment->count();

            // The new ordinal each surviving document lands on. Built before
            // the postings are walked, because a posting names a source
            // ordinal and has to be translated as it is read — and because a
            // deleted document has no new ordinal at all, which is how its
            // postings stop existing rather than being filtered out forever.
            $remap = [];
            $next  = $writer->count();

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                if ($deleted !== null && $deleted->has($ordinal)) {
                    continue;
                }

                $carried = [];

                foreach ($columns as $field) {
                    $carried[$field] = $segment->columnValue($field, $ordinal);
                }

                $writer->carry(
                    $segment->keyAt($ordinal),
                    $segment->documentAt($ordinal) ?? [],
                    $segment->fieldLengths($ordinal),
                    $carried,
                );

                $remap[$ordinal] = $next++;
            }

            // An entirely deleted segment keeps no remap, and so is left out of
            // the dictionary walk below: none of its postings could survive it.
            if ($remap !== []) {
                $remaps[$position] = $remap;
            }
        }

        // Handed over as a stream, run when the writer reaches its postings
        // section. The remaps have to exist by then and they do — they were
        // built above — but nothing is read out of the sources until write()
        // asks, and nothing is kept once it has.
        $writer->carryPostings(fn (): \Generator => $this->mergedPostings($sources, $remaps));

        if ($writer->count() === 0) {
            // Every source was entirely deleted. There is nothing to write, and
            // the caller drops the sources from the manifest.
            return 0;
        }

        $writer->write($path);

        return $writer->count();
    }

    /**
     * Every source's postings, as one ordered stream of translated terms.
     *
     * ── Why this is a k-way merge and not a map ────────────────────────────
     *
     * It used to be a map: each source's dictionary was walked, its postings
     * remapped, and the result handed to the writer to accumulate. Correct,
     * and unaffordable. A posting is a slot in a nested PHP array and costs
     * about 75 bytes there; a real catalogue of 45 000 products holds some
     * nine million of them, so the accumulation alone reached 700 MB — past
     * the limit of every shared host measured, and a merge is the one
     * operation whose size the caller never chose: it happens on somebody's
     * write, on a schedule the tiers decide. (What a host actually gives is
     * measured in MergePolicy, and it is not the 128 MB this project assumed
     * for a long time.)
     *
     * Nothing about it needed the map. Every source dictionary is sorted, and
     * `postingsByTerm()` yields in that order, so the sources can be walked in
     * lockstep: take the smallest term at the heads, union the postings of
     * every source holding it, yield it, advance those heads. The writer
     * encodes it and lets it go. What is live at any moment is one term's
     * posting list, bounded by the number of documents rather than by the
     * vocabulary — and the vocabulary is the thing that grows.
     *
     * The heads are scanned linearly rather than kept in a heap. There are at
     * most `segmentsPerTier` of them, eight by default, and a heap of eight
     * costs more to maintain in PHP than eight `strcmp`s.
     *
     * A term whose every document was deleted disappears here rather than
     * being written with an empty list: `$live` ends up empty and the term is
     * never yielded.
     *
     * @param SegmentIndex[]                    $sources segment position => segment
     * @param array<int, array<int, int>>       $remaps  position => source ordinal => new ordinal
     *
     * @return \Generator<string, array<int, int>>
     * @throws CorruptSegmentException
     */
    private function mergedPostings(array $sources, array $remaps): \Generator
    {
        /** @var \Generator<string, array<int, int>>[] */
        $heads = [];

        foreach ($remaps as $position => $_) {
            $walk = $sources[$position]->postingsByTerm();

            if ($walk->valid()) {
                $heads[$position] = $walk;
            }
        }

        while ($heads !== []) {
            $smallest = null;

            foreach ($heads as $walk) {
                $term = (string) $walk->key();

                if ($smallest === null || strcmp($term, $smallest) < 0) {
                    $smallest = $term;
                }
            }

            $live = [];

            foreach ($heads as $position => $walk) {
                if ((string) $walk->key() !== $smallest) {
                    continue;
                }

                $remap = $remaps[$position];

                foreach ($walk->current() as $ordinal => $mask) {
                    if (isset($remap[$ordinal])) {
                        // A term one source holds in the title and another in
                        // the description keeps one posting per document with
                        // both bits set — but only ever for the same document,
                        // which cannot come from two sources. The union is
                        // there because the shape says it may, not because it
                        // does.
                        $new        = $remap[$ordinal];
                        $live[$new] = ($live[$new] ?? 0) | $mask;
                    }
                }

                $walk->next();

                if (!$walk->valid()) {
                    unset($heads[$position]);
                }
            }

            if ($live !== []) {
                yield (string) $smallest => $live;
            }
        }
    }

    /**
     * The schema to write, when the caller did not say.
     *
     * Taken from the sources rather than inferred, and they agree by
     * construction: an index freezes its schema at its first commit and hands
     * that one to every segment written afterwards. A merge that re-inferred
     * could give a field a different type than the segments it is replacing —
     * which is the drift the freeze exists to prevent.
     *
     * @param SegmentIndex[] $sources
     */
    private function schemaOf(array $sources): ?Schema
    {
        foreach ($sources as $segment) {
            return $segment->schema();
        }

        return null;
    }
}
