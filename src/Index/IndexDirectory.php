<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Hit;
use Ols\PhpFts\LockManager;
use Ols\PhpFts\Query\CollectionStatistics;
use Ols\PhpFts\Query\TopK;
use Ols\PhpFts\SearchResult;
use Ols\PhpFts\Storage\Manifest;

/**
 * An index living in a directory: several immutable segments, one numbered
 * manifest saying which of them are live.
 *
 *     $index = IndexDirectory::open('./search_data');
 *
 *     $index->putMany(['sku-1' => [...], 'sku-2' => [...]]);   // one commit
 *     $index->delete('sku-1');                                  // another
 *     $index->search('leather', facets: ['brand']);
 *
 * ── What changes once there is more than one segment ────────────────────────
 *
 * A segment cannot be modified, so writing means adding one and publishing a
 * manifest that names it. Deleting means recording a bit against the segment
 * the document lives in — again in a new manifest. Replacing a document is
 * both at once, in a single commit: the old copy is marked deleted and the new
 * one arrives in a new segment, so a reader never sees two versions or none.
 *
 * Reads take no lock at all. Segment files never change, so nothing a reader
 * holds can be rewritten underneath it; the only thing that moves is which
 * manifest is the newest, and a reader picks one at the start and works from
 * it. A search running while an import commits simply sees the index as it was
 * when it began.
 *
 * ── Why searching several segments is not just searching one, N times ───────
 *
 * Three things have to be done across the whole index rather than per segment,
 * and each of them was a bug before it was fixed:
 *
 *   - **Statistics.** IDF asks how rare a term is in the index. Answered per
 *     segment, the same document scores differently depending on where it
 *     landed, and merging changes the ranking. So document frequencies are
 *     summed over every segment before any of them scores.
 *
 *   - **Ranking.** The best twenty overall are spread across segments, so one
 *     bounded heap collects candidates from all of them. Building a ranked list
 *     per segment and concatenating would materialise far more than it returns.
 *
 *   - **Facets.** Term counts add up per value, but statistics have to be
 *     recombined: the minimum of the whole is the smallest of the minima, and
 *     the mean has to be worked out again from the totals.
 *
 * Totals, by contrast, really are just a sum.
 *
 * ── Maintenance ─────────────────────────────────────────────────────────────
 *
 * Every mutation publishes its commit and then checks whether segments should
 * be merged, within a time budget. Merging needs the write lock, so it can only
 * happen on a write — a search that could trigger one could wait behind an
 * import. Reads report the need instead, through `stats()['needsOptimize']`, so
 * an application can schedule `optimize()` rather than have the engine decide
 * while a visitor waits.
 */
final class IndexDirectory
{
    private Manifest $manifest;

    /** @var SegmentIndex[] keyed by position in the manifest */
    private array $segments = [];

    /** @var Bitset[] deletions per segment, same keys */
    private array $deletions = [];

    /** Commit files kept behind the newest, so a rollback has somewhere to land. */
    private const COMMITS_KEPT = 3;

    private function __construct(
        private readonly string $directory,
        private readonly LockManager $lock,
        private readonly MergePolicy $policy,
        private readonly SegmentMerger $merger,
    ) {
    }

    /**
     * Opens the newest readable commit.
     *
     * @throws StorageException
     */
    public static function open(string $directory, ?MergePolicy $policy = null): self
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new StorageException("Unable to create index directory: $directory");
        }

        $index = new self(
            rtrim($directory, '/\\'),
            new LockManager($directory),
            $policy ?? new MergePolicy(),
            new SegmentMerger(),
        );

        $index->load();

        return $index;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function generation(): int
    {
        return $this->manifest->generation;
    }

    /**
     * Live documents: everything written, less everything deleted.
     */
    public function count(): int
    {
        $total = 0;

        foreach ($this->manifest->segments as $position => $segment) {
            $total += $segment['documents'] - $this->deletions[$position]->count();
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $deleted = 0;
        $bytes   = 0;

        foreach ($this->manifest->segments as $position => $segment) {
            $deleted += $this->deletions[$position]->count();
            $bytes   += (int) @filesize($this->segmentPath($segment['name']));
        }

        return [
            'documents'  => $this->count(),
            'deleted'    => $deleted,
            'segments'   => count($this->manifest->segments),
            'generation' => $this->manifest->generation,
            'bytes'      => $bytes,
            'fields'     => $this->manifest->fields,

            // A read may say a merge would help; it may not perform one, since
            // that needs the write lock and would let a search wait behind an
            // import. This is what lets an application schedule optimize()
            // instead of the engine deciding while a visitor waits.
            'needsOptimize' => $this->policy->selectAll($this->descriptors()) !== [],
        ];
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Adds or replaces one document.
     *
     * One document is one commit, and therefore one segment. Fine for an
     * occasional edit; for anything more, putMany() writes the whole batch as a
     * single segment and a single commit.
     *
     * @param array<string, mixed> $document
     * @throws StorageException
     */
    public function put(string|int $id, array $document): void
    {
        $this->putMany([(string) $id => $document]);
    }

    /**
     * Adds or replaces a batch, as one segment and one commit.
     *
     * @param iterable<string|int, array<string, mixed>> $documents id => document
     * @throws StorageException
     */
    public function putMany(iterable $documents): void
    {
        $this->lock->withLock(function () use ($documents): void {
            // Re-read inside the lock: another process may have committed since
            // this object was opened, and its work must not be dropped.
            $this->load();

            // The frozen schema goes to every new segment, not only to merges:
            // otherwise a later batch could infer a different type for a field
            // and a filter would work on some segments and fail on others.
            $writer = new SegmentIndexWriter(
                null,
                $this->manifest->fields === [] ? null : $this->manifest->fields,
            );

            $replacing = [];

            foreach ($documents as $id => $document) {
                $id = (string) $id;
                $writer->put($id, $document);

                $located = $this->locate($id);

                if ($located !== null) {
                    $replacing[] = $located;
                }
            }

            if ($writer->count() === 0) {
                return;
            }

            $name = $this->newSegmentName();
            $writer->write($this->segmentPath($name));

            $segments = $this->manifest->segments;

            // The old copies go out in the same commit as the new ones come in,
            // so no reader ever sees a document twice or not at all.
            foreach ($replacing as [$position, $ordinal]) {
                $deleted = Bitset::fromBytes(
                    $segments[$position]['deleted'],
                    $segments[$position]['documents']
                );
                $deleted->set($ordinal);
                $segments[$position]['deleted'] = $deleted->bytes();
            }

            $segments[] = [
                'name'      => $name,
                'documents' => $writer->count(),
                'deleted'   => '',
            ];

            // The schema is frozen at the first commit and carried forward, so
            // that a later merge cannot re-infer a field into a different type.
            $this->commit($segments, fields: $this->manifest->fields === []
                ? $writer->fields()
                : null);

            // Commit first, maintain second. The write is durable before any
            // merge starts, so a merge killed halfway leaves an orphan segment
            // in no manifest — debris, not damage.
            $this->maintain();
        });
    }

    /**
     * Removes a document. Returns false when the id is not in the index.
     *
     * @throws StorageException
     */
    public function delete(string|int $id): bool
    {
        return (bool) $this->lock->withLock(function () use ($id): bool {
            $this->load();

            $located = $this->locate((string) $id);

            if ($located === null) {
                return false;
            }

            [$position, $ordinal] = $located;

            $segments = $this->manifest->segments;

            $deleted = Bitset::fromBytes(
                $segments[$position]['deleted'],
                $segments[$position]['documents']
            );
            $deleted->set($ordinal);
            $segments[$position]['deleted'] = $deleted->bytes();

            $this->commit($segments);
            $this->maintain();

            return true;
        });
    }

    /**
     * Merges everything into one segment, with no caps.
     *
     * Never required: automatic merges keep an index healthy on their own. This
     * is for the cases where someone would rather pay the cost now — after a
     * bulk import, or from a cron on a large index where a 250 ms budget inside
     * a web request would take a while to converge.
     *
     * Does nothing when there is nothing to gain.
     *
     * @throws StorageException
     */
    public function optimize(): void
    {
        $this->lock->withLock(function (): void {
            $this->load();

            $positions = $this->policy->selectAll($this->descriptors());

            if ($positions !== []) {
                $this->performMerge($positions);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     * @throws CorruptSegmentException
     */
    public function get(string|int $id): ?array
    {
        $located = $this->locate((string) $id);

        if ($located === null) {
            return null;
        }

        [$position, $ordinal] = $located;

        return $this->segments[$position]->documentAt($ordinal);
    }

    public function has(string|int $id): bool
    {
        return $this->locate((string) $id) !== null;
    }

    /**
     * @param array<int, array{field: string, op: string, value: mixed}> $filters
     * @param string[]                                                  $facets
     *
     * @throws CorruptSegmentException
     */
    public function search(
        string $query = '',
        int $limit = 20,
        int $offset = 0,
        array $filters = [],
        array $facets = [],
    ): SearchResult {
        $started = hrtime(true);

        // Gathered before anything is scored: IDF describes how rare a term is
        // in the index, so it cannot be answered segment by segment without the
        // same document scoring differently depending on where it landed — and
        // a merge then changing the ranking.
        $statistics = $this->statisticsFor($query);

        $total  = 0;
        $merged = [];

        // One bounded heap for the whole search rather than a ranked list per
        // segment: the best twenty overall are somewhere among the segments, and
        // there is no need to materialise more than twenty to find them.
        $top = new TopK(max(0, $offset) + max(0, $limit));

        foreach ($this->segments as $position => $segment) {
            [$matches, $scores] = $segment->select(
                $query,
                $filters,
                $this->deletions[$position],
                $statistics,
            );

            $total += $matches->count();

            foreach ($facets as $field) {
                $merged[$field] = $this->mergeFacet(
                    $merged[$field] ?? null,
                    $segment->facetOn($field, $matches)
                );
            }

            foreach ($matches->iterate() as $ordinal) {
                $top->offer($scores[$ordinal] ?? 0.0, $position, $ordinal);
            }
        }

        $hits = [];

        foreach (array_slice($top->drain(), max(0, $offset)) as [$score, $position, $ordinal]) {
            $document = $this->segments[$position]->documentAt($ordinal);

            if ($document !== null) {
                $hits[] = new Hit($this->segments[$position]->keyAt($ordinal), $score, $document);
            }
        }

        return new SearchResult($hits, $total, $merged, (hrtime(true) - $started) / 1e6);
    }

    /**
     * The index-wide numbers BM25 needs, summed over every segment.
     *
     * @throws CorruptSegmentException
     */
    private function statisticsFor(string $query): CollectionStatistics
    {
        $statistics = CollectionStatistics::empty();

        if ($this->segments === []) {
            return $statistics;
        }

        $terms = reset($this->segments)->analyze($query);

        foreach ($this->segments as $position => $segment) {
            // Live documents, not written ones: a term present only in deleted
            // documents should not look common.
            $live = $segment->count() - $this->deletions[$position]->count();

            $statistics = $statistics->plus(
                max(0, $live),
                $segment->termLengthSum(),
                $terms === [] ? [] : $segment->documentFrequencies($terms),
            );
        }

        return $statistics;
    }

    // -------------------------------------------------------------------------

    /**
     * Reads the newest commit whose segments all open.
     *
     * Walking generations downwards is the rollback: a commit whose segment was
     * cut short fails its length check, and the generation before it is tried
     * instead. An empty directory yields the initial, empty manifest.
     *
     * @throws StorageException
     */
    private function load(): void
    {
        $this->segments  = [];
        $this->deletions = [];

        $generations = Manifest::generations($this->directory);

        foreach ($generations as $generation) {
            try {
                $manifest = Manifest::read($this->directory, $generation);
                $segments = [];

                foreach ($manifest->segments as $entry) {
                    $segments[] = SegmentIndex::open($this->segmentPath($entry['name']));
                }
            } catch (CorruptSegmentException | StorageException) {
                // This commit cannot be trusted. Try the one before it.
                continue;
            }

            $this->manifest = $manifest;
            $this->segments = $segments;

            foreach ($manifest->segments as $position => $entry) {
                $this->deletions[$position] = Bitset::fromBytes($entry['deleted'], $entry['documents']);
            }

            // Retired segment files are removed by the commit that follows their
            // grace period — but an index written once and then only read would
            // never see another commit, and would keep every intermediate
            // segment on disk for good. So opening collects them too.
            //
            // Only when the newest generation loaded, though: a reader that had
            // to fall back may still be looking at segments a later commit
            // retired, and must not have them pulled out from under it.
            if ($generation === ($generations[0] ?? null)) {
                $this->collectRetiredFiles($manifest->retired);
            }

            return;
        }

        $this->manifest = Manifest::initial();
    }

    /**
     * Deletes retired segment files whose grace period has passed.
     *
     * Not a change to the index: these files are named by no live manifest, so
     * removing them alters nothing a reader can observe. The manifest's list of
     * them is tidied by the next commit, which finds the files already gone.
     *
     * @param array<int, array{name: string, at: int}> $retired
     */
    private function collectRetiredFiles(array $retired): void
    {
        $deadline = time() - Manifest::GRACE_SECONDS;

        foreach ($retired as $entry) {
            if ($entry['at'] <= $deadline) {
                @unlink($this->segmentPath($entry['name']));
            }
        }
    }

    /**
     * @param array<int, array{name: string, documents: int, deleted: string}> $segments
     * @param array<int, array{name: string, at: int}>|null                    $retired
     * @param array<string, string>|null                                       $fields
     * @throws StorageException
     */
    private function commit(array $segments, ?array $retired = null, ?array $fields = null): void
    {
        // Garbage collection rides along on the commit rather than needing one
        // of its own: expired retirements are dropped from the list being
        // written, and their files removed.
        $retired = $this->pruneRetired($retired ?? $this->manifest->retired);

        $this->manifest->next($segments, $retired, $fields)->write($this->directory);

        $this->load();
        $this->forgetOldCommits();
    }

    /**
     * Runs merges until the policy is satisfied or the budget runs out.
     *
     * Called after a commit, never before: see the note in putMany().
     *
     * @throws StorageException
     */
    private function maintain(): void
    {
        $started = hrtime(true);

        // A guard, not a policy: every merge strictly reduces the number of
        // segments or the number of deletions, so this cannot spin. The cap is
        // there so that a future policy bug costs a slow request rather than a
        // hung one.
        for ($pass = 0; $pass < 32; $pass++) {
            if ((hrtime(true) - $started) / 1e6 > $this->policy->timeBudgetMs) {
                return;
            }

            $positions = $this->policy->select($this->descriptors());

            if ($positions === []) {
                return;
            }

            $this->performMerge($positions);
        }
    }

    /**
     * Replaces some segments with one holding their live documents.
     *
     * @param int[] $positions
     * @throws StorageException
     */
    private function performMerge(array $positions): void
    {
        $chosen  = array_fill_keys($positions, true);
        $sources = [];

        foreach ($positions as $position) {
            $sources[$position] = $this->segments[$position];
        }

        $name  = $this->newSegmentName();
        $count = $this->merger->merge(
            $sources,
            $this->deletions,
            $this->segmentPath($name),
            $this->manifest->fields === [] ? null : $this->manifest->fields,
        );

        $segments = [];
        $retired  = $this->manifest->retired;
        $now      = time();

        foreach ($this->manifest->segments as $position => $entry) {
            if (isset($chosen[$position])) {
                // Not deleted yet: a search that started before this commit may
                // still be reading it. It goes out after the grace period.
                $retired[] = ['name' => $entry['name'], 'at' => $now];
                continue;
            }

            $segments[] = $entry;
        }

        if ($count > 0) {
            $segments[] = ['name' => $name, 'documents' => $count, 'deleted' => ''];
        }

        $this->commit($segments, $retired);
    }

    /**
     * @param array<int, array{name: string, at: int}> $retired
     * @return array<int, array{name: string, at: int}>
     */
    private function pruneRetired(array $retired): array
    {
        $deadline  = time() - Manifest::GRACE_SECONDS;
        $remaining = [];

        foreach ($retired as $entry) {
            if ($entry['at'] > $deadline) {
                $remaining[] = $entry;
                continue;
            }

            // On Windows an open file refuses to be deleted. The failure is
            // benign: the entry stays and the next commit tries again.
            if (!@unlink($this->segmentPath($entry['name'])) && file_exists($this->segmentPath($entry['name']))) {
                $remaining[] = $entry;
            }
        }

        return $remaining;
    }

    /**
     * Removes commit files well behind the newest.
     *
     * A few are kept so that rollback has somewhere to land: if the newest
     * commit turns out to be unreadable, the one before it must still exist.
     */
    private function forgetOldCommits(): void
    {
        $generations = Manifest::generations($this->directory);

        foreach (array_slice($generations, self::COMMITS_KEPT) as $generation) {
            @unlink($this->directory . '/commit.' . $generation);
        }
    }

    /**
     * The sizes the policy reasons about.
     *
     * @return array<int, array{documents: int, deleted: int}>
     */
    private function descriptors(): array
    {
        $descriptors = [];

        foreach ($this->manifest->segments as $position => $entry) {
            $descriptors[$position] = [
                'documents' => $entry['documents'],
                'deleted'   => $this->deletions[$position]->count(),
            ];
        }

        return $descriptors;
    }

    /**
     * Which segment holds a live document, and where in it.
     *
     * @return array{0: int, 1: int}|null [segment position, local ordinal]
     * @throws CorruptSegmentException
     */
    private function locate(string $id): ?array
    {
        foreach ($this->segments as $position => $segment) {
            $ordinal = $segment->ordinalFor($id);

            if ($ordinal !== null && !$this->deletions[$position]->has($ordinal)) {
                return [$position, $ordinal];
            }
        }

        return null;
    }

    /**
     * Adds one segment's facet result to the running total.
     *
     * Term counts add up per value. Statistics have to be recombined rather
     * than summed: the minimum of the whole is the smallest of the minima, and
     * the mean has to be worked out again from the totals.
     *
     * @param array<string|int, mixed>|null $running
     * @param array<string|int, mixed>      $addition
     * @return array<string|int, mixed>
     */
    private function mergeFacet(?array $running, array $addition): array
    {
        if ($running === null) {
            return $addition;
        }

        if (array_key_exists('count', $running) && array_key_exists('sum', $running)) {
            $count = $running['count'] + $addition['count'];
            $sum   = $running['sum'] + $addition['sum'];

            return [
                'count' => $count,
                'min'   => $this->smaller($running['min'], $addition['min']),
                'max'   => $this->larger($running['max'], $addition['max']),
                'sum'   => $sum,
                'avg'   => $count > 0 ? $sum / $count : null,
            ];
        }

        foreach ($addition as $value => $occurrences) {
            $running[$value] = ($running[$value] ?? 0) + $occurrences;
        }

        arsort($running);

        return $running;
    }

    private function smaller(?float $a, ?float $b): ?float
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default     => min($a, $b),
        };
    }

    private function larger(?float $a, ?float $b): ?float
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default     => max($a, $b),
        };
    }

    private function segmentPath(string $name): string
    {
        return $this->directory . '/' . $name . '.fts';
    }

    private function newSegmentName(): string
    {
        return 'seg_' . bin2hex(random_bytes(6));
    }
}
