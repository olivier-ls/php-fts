<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Hit;
use Ols\PhpFts\LockManager;
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
 * The total is a sum, and facet counts add up per value, but ranking cannot be
 * concatenated: the best twenty documents overall are somewhere inside the best
 * twenty of each segment. So every segment is asked for its own best
 * `offset + limit`, and those are merged and cut. Asking each for everything
 * would be correct too, and would materialise the whole result set to return a
 * page of twenty.
 *
 * ── Not here yet ────────────────────────────────────────────────────────────
 *
 * Nothing merges segments, so an index written one document at a time
 * accumulates one segment per document and searches slow down in proportion.
 * The merge policy — tiered, automatic, bounded by a time budget, commit-first
 * so a write is never at risk from it — is the next piece. Until then,
 * `putMany()` on a whole batch is the way to keep an index to one segment.
 */
final class IndexDirectory
{
    private Manifest $manifest;

    /** @var SegmentIndex[] keyed by position in the manifest */
    private array $segments = [];

    /** @var Bitset[] deletions per segment, same keys */
    private array $deletions = [];

    private function __construct(
        private readonly string $directory,
        private readonly LockManager $lock,
    ) {
    }

    /**
     * Opens the newest readable commit.
     *
     * @throws StorageException
     */
    public static function open(string $directory): self
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new StorageException("Unable to create index directory: $directory");
        }

        $index = new self(rtrim($directory, '/\\'), new LockManager($directory));
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

            $writer   = new SegmentIndexWriter();
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

            $this->commit($segments);
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

            return true;
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

        $total     = 0;
        $merged    = [];
        $candidates = [];
        $wanted    = max(0, $offset) + max(0, $limit);

        foreach ($this->segments as $position => $segment) {
            [$matches, $scores] = $segment->select($query, $filters, $this->deletions[$position]);

            $total += $matches->count();

            foreach ($facets as $field) {
                $merged[$field] = $this->mergeFacet(
                    $merged[$field] ?? null,
                    $segment->facetOn($field, $matches)
                );
            }

            // Only this segment's own best can reach the merged page, so there
            // is no point carrying more of them across.
            $ranked = [];

            foreach ($matches->iterate() as $ordinal) {
                $ranked[$ordinal] = (float) ($scores[$ordinal] ?? 0);
            }

            arsort($ranked);

            foreach (array_slice($ranked, 0, $wanted, true) as $ordinal => $score) {
                $candidates[] = [$score, $position, $ordinal];
            }
        }

        usort($candidates, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        $hits = [];

        foreach (array_slice($candidates, max(0, $offset), max(0, $limit)) as [$score, $position, $ordinal]) {
            $document = $this->segments[$position]->documentAt($ordinal);

            if ($document !== null) {
                $hits[] = new Hit($this->segments[$position]->keyAt($ordinal), $score, $document);
            }
        }

        return new SearchResult($hits, $total, $merged, (hrtime(true) - $started) / 1e6);
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

        foreach (Manifest::generations($this->directory) as $generation) {
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

            return;
        }

        $this->manifest = Manifest::initial();
    }

    /**
     * @param array<int, array{name: string, documents: int, deleted: string}> $segments
     * @throws StorageException
     */
    private function commit(array $segments): void
    {
        $next = $this->manifest->next($segments, $this->manifest->retired);
        $next->write($this->directory);

        $this->load();
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
