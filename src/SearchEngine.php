<?php

declare(strict_types=1);

namespace Ols\PhpFts;

require_once __DIR__ . '/Exception/FtsException.php';
require_once __DIR__ . '/Exception/StorageException.php';
require_once __DIR__ . '/Exception/LockException.php';
require_once __DIR__ . '/Exception/FilterException.php';
require_once __DIR__ . '/OpensIndexFile.php';
require_once __DIR__ . '/DocumentStorage.php';
require_once __DIR__ . '/TombstoneStorage.php';
require_once __DIR__ . '/PostingsStorage.php';
require_once __DIR__ . '/TrigramIndex.php';
require_once __DIR__ . '/Tokenizer.php';
require_once __DIR__ . '/LockManager.php';

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Exception\StorageException;

/**
 * SearchEngine
 *
 * Single responsibility: orchestrate the components to expose
 * a simple API — insert, delete, search, compact.
 */
class SearchEngine
{
    private DocumentStorage  $documents;
    private TombstoneStorage $tombstones;
    private PostingsStorage  $postings;
    private TrigramIndex     $trigrams;
    private Tokenizer        $tokenizer;

    private bool        $open        = false;
    private string      $directory   = '';
    private LockManager $lock;
    private int         $lockTimeout = 5;

    public function __construct()
    {
        $this->documents  = new DocumentStorage();
        $this->tombstones = new TombstoneStorage();
        $this->postings   = new PostingsStorage();
        $this->trigrams   = new TrigramIndex();
        $this->tokenizer  = new Tokenizer();
    }

    /**
     * Opens all files from a directory.
     * Creates the directory and files if they do not exist.
     *
     * If a bulk sentinel file is found (.bulk_in_progress), it means a previous
     * insertBulk() was interrupted before flushing the index to disk.
     * The sentinel is removed and compact() is run automatically to rebuild a
     * consistent index from the documents already written to documents.bin.
     *
     * @throws FtsException
     */
    public function open(string $directory, int $lockTimeout = 5): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new StorageException("Unable to create directory: $directory");
        }

        $this->directory   = rtrim($directory, '/');
        $this->lockTimeout = $lockTimeout;
        $this->lock        = new LockManager($this->directory, $lockTimeout);

        $this->documents->open($this->directory  . '/documents.bin');
        $this->tombstones->open($this->directory . '/tombstones.bin');
        $this->postings->open($this->directory   . '/postings.bin');
        $this->trigrams->open($this->directory   . '/trigrams.bin');

        $this->open = true;

        // Auto-recovery: a leftover sentinel means insertBulk() crashed before
        // flushing trigrams and postings. Remove it first so that the compact()
        // call below — which internally calls open() — does not loop.
        $sentinel = $this->sentinelPath();

        if (file_exists($sentinel)) {
            @unlink($sentinel);
            $this->compact();
        }
    }

    /**
     * Inserts a document and updates the index.
     *
     * @return int doc_id
     * @throws FtsException
     */
    public function insert(array $document): int
    {
        $this->assertOpen();

        return $this->lock->withLock(fn() => $this->doInsert($document));
    }

    /**
     * Inserts multiple documents under a single lock.
     *
     * Bulk optimisations over repeated insert() calls:
     *   - DocumentStorage : persistHeaderStats() deferred → 1 write at the end
     *   - TrigramIndex    : set() skips disk writes → 1 sequential flush (~810 KB)
     *   - PostingsStorage : doc_ids accumulated per trigram → 1 appendBatch() per unique trigram
     *
     * Crash safety:
     *   A sentinel file (.bulk_in_progress) is written before any data hits the disk
     *   and removed only on success. If the process dies mid-bulk, the next open()
     *   detects the sentinel, removes it, and runs compact() to rebuild a consistent
     *   index from the documents already persisted in documents.bin.
     *
     * @return int[] doc_ids
     * @throws FtsException
     */
    public function insertBulk(array $documents): array
    {
        $this->assertOpen();

        return $this->lock->withLock(function () use ($documents): array {
            $docIds          = [];
            $pendingPostings = []; // trigram => [docId, ...]

            // Write the sentinel before any data hits the disk.
            // It is removed only on success — if the process dies before that,
            // open() will detect it and trigger compact() automatically.
            $sentinel = $this->sentinelPath();
            file_put_contents($sentinel, (string) getmypid());

            $this->documents->beginBulk();
            $this->trigrams->beginBulk();

            // Phase 1: write documents + accumulate postings per trigram
            foreach ($documents as $document) {
                $trigramList  = $this->extractTrigrams($document);
                $trigramCount = count($trigramList);
                $docId        = $this->documents->write($document, $trigramCount);
                $docIds[]     = $docId;

                foreach ($trigramList as $trigram) {
                    $pendingPostings[$trigram][] = $docId;
                }
            }

            // Phase 2: flush postings — 1 appendBatch() per unique trigram
            foreach ($pendingPostings as $trigram => $batchDocIds) {
                $trigram  = (string) $trigram; // PHP casts numeric string keys to int ("123" → 123)
                $entry    = $this->trigrams->get($trigram);
                $newEntry = $this->postings->appendBatch(
                    $batchDocIds,
                    $entry['offset'],
                    $entry['capacity'],
                    $entry['count']
                );

                if ($newEntry !== $entry) {
                    $this->trigrams->set(
                        $trigram,
                        $newEntry['offset'],
                        $newEntry['capacity'],
                        $newEntry['count']
                    );
                }
            }

            // Phase 3: single-pass disk flush for each component
            $this->documents->endBulk(); // 1 fseek + fwrite to documents.bin header
            $this->trigrams->flush();    // 1 sequential fwrite of ~810 KB to trigrams.bin

            // Everything is safely on disk — remove the sentinel
            @unlink($sentinel);

            return $docIds;
        });
    }

    /**
     * Updates an existing document.
     * Atomic: delete + insert under a single lock.
     * Returns the new doc_id — different from the old one.
     *
     * @throws FtsException
     */
    public function update(int $docId, array $newDocument): int
    {
        $this->assertOpen();

        return $this->lock->withLock(function () use ($docId, $newDocument): int {
            $this->doDelete($docId);
            return $this->doInsert($newDocument);
        });
    }

    /**
     * Deletes a document (soft delete via tombstone).
     *
     * @throws FtsException
     */
    public function delete(int $docId): void
    {
        $this->assertOpen();

        $this->lock->withLock(fn() => $this->doDelete($docId));
    }

    /**
     * Searches for documents matching the query.
     *
     * @param string $query         Search text
     * @param int    $limit         Maximum number of results returned (default: 20)
     * @param int    $maxCandidates Maximum number of doc_ids read per trigram (default: 5000)
     * @param array  $boosts        Per-field weighting — e.g. ['title' => 3.0]
     * @param array  $filters       Numeric/boolean/array filters — e.g.:
     *                              [
     *                                'and' => [
     *                                  ['field' => 'active',   'op' => '=',            'value' => true],
     *                                  ['field' => 'price',    'op' => '>=',           'value' => 50],
     *                                  ['field' => 'price',    'op' => '<=',           'value' => 300],
     *                                  ['field' => 'category', 'op' => 'in',           'value' => ['Shoes', 'Sport']],
     *                                  ['field' => 'brand',    'op' => 'not in',       'value' => ['Nike']],
     *                                  ['field' => 'tags',     'op' => 'contains',     'value' => 'luxury'],
     *                                  ['field' => 'tags',     'op' => 'not contains', 'value' => 'promo'],
     *                                ],
     *                                'or' => [
     *                                  ['field' => 'brand', 'op' => '=', 'value' => 'Adidas'],
     *                                  ['field' => 'brand', 'op' => '=', 'value' => 'Puma'],
     *                                ],
     *                              ]
     *                              Operators: = != > >= < <= in not in contains not contains
     *                              Field absent from document → document rejected.
     *                              'and' and 'or' are optional but at least one must be present.
     *                              'and' + 'or': all and conditions AND at least one or must pass.
     *
     * @param bool   $highlight        Whether to include highlighted excerpts in results (default: false)
     * @param array  $highlightOptions Highlighting configuration:
     *                                 [
     *                                   'tags'    => ['<mark>', '</mark>'],  // open/close tags
     *                                   'excerpt' => true,                   // true = extract a window around the match
     *                                                                        // false = full field with matched words wrapped
     *                                   'window'  => 5,                      // words of context on each side (excerpt mode only)
     *                                   'escape'  => true,                   // HTML-escape field text before wrapping it
     *                                 ]
     *
     * @return array<array{docId: int, score: float, document: array, highlights?: array}>
     * @throws FtsException
     */
    public function search(string $query, int $limit = 20, int $maxCandidates = 5000, array $boosts = [], array $filters = [], bool $highlight = false, array $highlightOptions = []): array
    {
        $this->assertOpen();

        $queryTrigrams = $this->tokenizer->tokenize($query);

        if (empty($queryTrigrams)) {
            return [];
        }

        // Fetch entries and sort by ascending count (rarest first)
        $trigramEntries = [];

        foreach ($queryTrigrams as $trigram) {
            $entry = $this->trigrams->get($trigram);
            if ($entry['count'] > 0) {
                $trigramEntries[$trigram] = $entry;
            }
        }

        if (empty($trigramEntries)) {
            return [];
        }

        uasort($trigramEntries, fn($a, $b) => $a['count'] <=> $b['count']);

        // --- Phase 1: score building ---

        // Attempt intersection mode
        $scores = $this->searchByIntersection($trigramEntries, $maxCandidates);

        // Union fallback if not enough results
        if (count($scores) < $limit) {
            $scores = $this->searchByUnion($trigramEntries, $maxCandidates);
        }

        if (empty($scores)) {
            return [];
        }

        // --- Phase 2: sorting ---
        arsort($scores);

        // --- Phase 3: document reading + filtering + scoring ---
        $avgdl      = $this->documents->avgTrigramCount();
        $hasFilters = !empty($filters);

        $results = [];
        $i       = 0;

        foreach ($scores as $docId => $rawScore) {
            if ($i >= $limit) {
                break;
            }

            $entry    = $this->documents->read($docId);
            $document = $entry['document'];

            // Filtering — keep iterating without incrementing $i if document is rejected
            if ($hasFilters && !$this->matchesFilters($document, $filters)) {
                continue;
            }

            $result = [
                'docId'    => $docId,
                'score'    => $this->computeScore($document, $queryTrigrams, $entry['trigramCount'], $avgdl, $boosts),
                'document' => $document,
            ];

            if ($highlight) {
                $result['highlights'] = $this->buildHighlights($document, $queryTrigrams, $highlightOptions);
            }

            $results[] = $result;

            $i++;
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Returns the number of indexed documents (excluding deletions).
     *
     * @throws FtsException
     */
    public function count(): int
    {
        $this->assertOpen();

        return $this->documents->count() - $this->tombstones->count();
    }

    /**
     * Returns the fragmentation rate as a percentage (0-100).
     * 0 = no deletions, 100 = all documents are deleted.
     *
     * @throws FtsException
     */
    public function fragmentationRate(): int
    {
        $this->assertOpen();

        $total = $this->documents->count();

        if ($total === 0) {
            return 0;
        }

        return (int) round($this->tombstones->count() / $total * 100);
    }

    /**
     * Compacts the binary files.
     *
     * - Rewrites documents.bin and postings.bin without deleted documents
     *   or holes left by reallocations
     * - Rebuilds trigrams.bin from scratch
     * - Clears tombstones.bin
     * - Atomic: works on temporary files, then rename()
     *
     * Fixes:
     *   - Lock acquired at the start, released in finally
     *   - $acquiredLock captured before $this->open() overwrites $this->lock
     *   - glob() ?: [] — avoids foreach on false if directory is empty or unreadable
     *
     * @throws FtsException
     */
    public function compact(): void
    {
        $this->assertOpen();

        // Lock acquired before any operation.
        // $acquiredLock is captured here because $this->open() below will reassign
        // $this->lock with a new instance — the reference would be lost.
        $this->lock->acquire();
        $acquiredLock = $this->lock;

        $tmpDir = $this->directory . '/_compact_tmp';

        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true)) {
            $acquiredLock->release();
            throw new StorageException("Unable to create temporary directory: $tmpDir");
        }

        try {
            $newDocs       = new DocumentStorage();
            $newPostings   = new PostingsStorage();
            $newTrigrams   = new TrigramIndex();
            $newTombstones = new TombstoneStorage();

            $newDocs->open($tmpDir       . '/documents.bin');
            $newPostings->open($tmpDir   . '/postings.bin');
            $newTrigrams->open($tmpDir   . '/trigrams.bin');
            $newTombstones->open($tmpDir . '/tombstones.bin');

            $tombstones      = $this->tombstones;
            $pendingPostings = []; // trigram => [docId, ...]

            $newDocs->beginBulk();
            $newTrigrams->beginBulk();

            // Phase 1: iterate documents, write them, accumulate postings per trigram.
            // Same batch strategy as insertBulk(): no individual fseek/fwrite per trigram,
            // no cascading reallocations in the freshly created postings.bin.
            $this->documents->iterate(
                function (int $offset, array $document, int $trigramCount) use (
                    $newDocs, $tombstones, &$pendingPostings
                ): void {
                    if ($tombstones->isDeleted($offset)) {
                        return;
                    }

                    $trigramList  = $this->extractTrigrams($document);
                    $trigramCount = count($trigramList);
                    $newDocId     = $newDocs->write($document, $trigramCount);

                    foreach ($trigramList as $trigram) {
                        $pendingPostings[$trigram][] = $newDocId;
                    }
                }
            );

            // Phase 2: flush postings — 1 appendBatch() per unique trigram, no reallocation churn.
            foreach ($pendingPostings as $trigram => $batchDocIds) {
                $trigram  = (string) $trigram; // PHP casts numeric string keys to int ("123" → 123)
                $entry    = $newTrigrams->get($trigram);
                $newEntry = $newPostings->appendBatch(
                    $batchDocIds,
                    $entry['offset'],
                    $entry['capacity'],
                    $entry['count']
                );

                if ($newEntry !== $entry) {
                    $newTrigrams->set(
                        $trigram,
                        $newEntry['offset'],
                        $newEntry['capacity'],
                        $newEntry['count']
                    );
                }
            }

            // Phase 3: single-pass disk flush for each component
            $newDocs->endBulk();    // 1 fseek + fwrite to documents.bin header
            $newTrigrams->flush();  // 1 sequential fwrite of ~810 KB to trigrams.bin

            $newDocs->close();
            $newPostings->close();
            $newTrigrams->close();
            $newTombstones->close();

            $this->documents->close();
            $this->tombstones->close();
            $this->postings->close();
            $this->trigrams->close();
            $this->open = false;

            $files = ['documents.bin', 'postings.bin', 'trigrams.bin', 'tombstones.bin'];

            foreach ($files as $file) {
                if (!rename($tmpDir . '/' . $file, $this->directory . '/' . $file)) {
                    throw new StorageException("Unable to rename $file — index may be partially corrupted");
                }
            }

            rmdir($tmpDir);

            // $this->lock is reassigned here — that is why $acquiredLock was captured above
            $this->open($this->directory, $this->lockTimeout);

        } catch (\Throwable $e) {
            foreach (glob($tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }

            throw new StorageException('Compaction failed: ' . $e->getMessage(), 0, $e);

        } finally {
            // Guaranteed release in all cases — success or exception
            $acquiredLock->release();
        }
    }

    /**
     * Resets the engine.
     * Deletes all binary files and recreates them empty.
     *
     * @throws FtsException
     */
    public function reset(): void
    {
        $this->assertOpen();

        $this->lock->withLock(function (): void {
            $this->documents->close();
            $this->tombstones->close();
            $this->postings->close();
            $this->trigrams->close();

            $files = ['documents.bin', 'postings.bin', 'trigrams.bin', 'tombstones.bin'];

            foreach ($files as $file) {
                $path = $this->directory . '/' . $file;
                if (file_exists($path)) {
                    unlink($path);
                }
            }

            $this->documents->open($this->directory  . '/documents.bin');
            $this->tombstones->open($this->directory . '/tombstones.bin');
            $this->postings->open($this->directory   . '/postings.bin');
            $this->trigrams->open($this->directory   . '/trigrams.bin');
        });
    }

    /**
     * Properly closes all components.
     */
    public function close(): void
    {
        if ($this->open) {
            $this->documents->close();
            $this->tombstones->close();
            $this->postings->close();
            $this->trigrams->close();
            $this->open = false;
        }
    }

    // -------------------------------------------------------------------------
    // Private methods — business logic (lock-free)
    // -------------------------------------------------------------------------

    /**
     * Insert logic without lock.
     * Called by insert(), insertBulk() and update().
     */
    private function doInsert(array $document): int
    {
        $trigramList  = $this->extractTrigrams($document);
        $trigramCount = count($trigramList);
        $docId        = $this->documents->write($document, $trigramCount);

        foreach ($trigramList as $trigram) {
            $entry    = $this->trigrams->get($trigram);
            $newEntry = $this->postings->append(
                $docId,
                $entry['offset'],
                $entry['capacity'],
                $entry['count']
            );

            if ($newEntry !== $entry) {
                $this->trigrams->set(
                    $trigram,
                    $newEntry['offset'],
                    $newEntry['capacity'],
                    $newEntry['count']
                );
            }
        }

        return $docId;
    }

    /**
     * Delete logic without lock.
     * Called by delete() and update().
     */
    private function doDelete(int $docId): void
    {
        $this->tombstones->add($docId);
    }

    // -------------------------------------------------------------------------
    // Private methods — scoring
    // -------------------------------------------------------------------------

    /**
     * Computes the BM25+IDF relevance score of a document for a query.
     *
     * Full formula:
     *   score(d, q) = Σ  IDF(t) × (tf × (k1 + 1)) / (tf + k1 × norm)
     *                t∈q
     *
     *   IDF(t) = log( (N - df + 0.5) / (df + 0.5) + 1 )
     *
     * Standard BM25 parameters:
     *   k1 = 1.5  — TF saturation (higher = less saturation)
     *   b  = 0.75 — length impact (0 = disabled, 1 = strong)
     *
     * IDF:
     *   N  = total number of documents in the index
     *   df = number of documents containing the trigram (= count in TrigramIndex)
     *   Memory access only — TrigramIndex is fully loaded in RAM.
     *
     * With boosts:
     *   Each field is scored separately and weighted by its boost.
     *   A $dfCache avoids repeated accesses to the same trigram across fields.
     *
     * @param array  $document      Full document
     * @param array  $queryTrigrams Query trigrams
     * @param int    $trigramCount  Number of trigrams in the document
     * @param float  $avgdl         Average document length
     * @param array  $boosts        ['field' => float] — e.g. ['title' => 3.0]
     * @return float BM25+IDF score normalized between 0 and 100
     */
    private function computeScore(
        array  $document,
        array  $queryTrigrams,
        int    $trigramCount,
        float  $avgdl,
        array  $boosts
    ): float {
        $k1 = 1.5;
        $b  = 0.75;

        $querySet = array_flip($queryTrigrams);
        $queryLen = count($queryTrigrams);

        if ($queryLen === 0 || $trigramCount === 0) {
            return 0.0;
        }

        // N = total number of documents — used for IDF calculation
        // Includes deleted documents (tombstones), which is standard in BM25:
        // the term frequency distribution applies to the full original corpus.
        $N = max(1, $this->documents->count());

        // Theoretical maximum BM25+IDF score used to normalize between 0 and 100.
        // When df → 0 and tf → ∞:
        //   IDF_max ≈ log(N / 0.5 + 1) ≈ log(2N + 1)
        //   BM25_max = k1 + 1  (TF saturation)
        // This bound is used to bring the score into [0, 100].
        $idfMax   = log(($N + 0.5) / 0.5 + 1);
        $scoreMax = $idfMax * ($k1 + 1);

        // Without boosts — global scoring over the entire document
        if (empty($boosts)) {
            $docTrigrams = $this->extractTrigrams($document);
            $norm        = $avgdl > 0 ? (1 - $b + $b * $trigramCount / $avgdl) : 1.0;
            $score       = 0.0;

            foreach ($docTrigrams as $trigram) {
                if (!isset($querySet[$trigram])) {
                    continue;
                }

                $df    = $this->trigrams->get($trigram)['count'];
                $idf   = log(($N - $df + 0.5) / ($df + 0.5) + 1);
                // tf = 1 per trigram — each unique trigram is counted once
                // (array_unique is applied by extractTrigrams)
                $score += $idf * (1 * ($k1 + 1)) / (1 + $k1 * $norm);
            }

            if ($score === 0.0) {
                return 0.0;
            }

            return round(min($score / $scoreMax * 100, 100.0), 2);
        }

        // With boosts — per-field weighted scoring
        // $dfCache avoids repeated calls to trigrams->get() for the same trigram
        // when it appears in multiple fields.
        $dfCache       = [];
        $weightedScore = 0.0;
        $weightedTotal = 0.0;

        foreach ($document as $field => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $boost         = (float) ($boosts[$field] ?? 1.0);
            $fieldTrigrams = $this->tokenizer->tokenize($value);
            $fieldTotal    = count($fieldTrigrams);

            if ($fieldTotal === 0) {
                continue;
            }

            $norm       = $avgdl > 0 ? (1 - $b + $b * $fieldTotal / $avgdl) : 1.0;
            $fieldScore = 0.0;

            foreach ($fieldTrigrams as $trigram) {
                if (!isset($querySet[$trigram])) {
                    continue;
                }

                if (!isset($dfCache[$trigram])) {
                    $dfCache[$trigram] = $this->trigrams->get($trigram)['count'];
                }

                $df          = $dfCache[$trigram];
                $idf         = log(($N - $df + 0.5) / ($df + 0.5) + 1);
                $fieldScore += $idf * (1 * ($k1 + 1)) / (1 + $k1 * $norm);
            }

            if ($fieldScore > 0.0) {
                $weightedScore += ($fieldScore / $scoreMax) * $boost;
            }

            $weightedTotal += $boost;
        }

        if ($weightedTotal === 0.0) {
            return 0.0;
        }

        return round(min($weightedScore / $weightedTotal * 100, 100.0), 2);
    }

    // -------------------------------------------------------------------------
    // Private methods — search
    // -------------------------------------------------------------------------

    private function searchByIntersection(array $trigramEntries, int $maxCandidates): array
    {
        $scores    = null;
        $firstPass = true;

        foreach ($trigramEntries as $trigram => $entry) {
            $count  = min($entry['count'], $maxCandidates);
            $offset = $entry['offset'] + ($entry['count'] - $count) * 4;
            $docIds = $this->postings->read($offset, $count);

            if ($firstPass) {
                $scores = [];
                foreach ($docIds as $docId) {
                    if (!$this->tombstones->isDeleted($docId)) {
                        $scores[$docId] = 1;
                    }
                }
                $firstPass = false;
            } else {
                $docIdSet = array_flip($docIds);
                foreach ($scores as $docId => $score) {
                    if (isset($docIdSet[$docId])) {
                        $scores[$docId]++;
                    } else {
                        unset($scores[$docId]);
                    }
                }
            }

            if (empty($scores)) {
                break;
            }
        }

        return $scores ?? [];
    }

    private function searchByUnion(array $trigramEntries, int $maxCandidates): array
    {
        $scores = [];

        foreach ($trigramEntries as $trigram => $entry) {
            $count  = min($entry['count'], $maxCandidates);
            $offset = $entry['offset'] + ($entry['count'] - $count) * 4;
            $docIds = $this->postings->read($offset, $count);

            foreach ($docIds as $docId) {
                if ($this->tombstones->isDeleted($docId)) {
                    continue;
                }
                $scores[$docId] = ($scores[$docId] ?? 0) + 1;
            }
        }

        return $scores;
    }


    // -------------------------------------------------------------------------
    // Private methods — filtering
    // -------------------------------------------------------------------------

    /**
     * Tests whether a document passes all filters.
     *
     * Logic:
     *   - 'and' : all filters must pass
     *   - 'or'  : at least one filter must pass
     *   - 'and' + 'or' : both conditions must be true
     *   - Field absent from document → document rejected
     */
    private function matchesFilters(array $document, array $filters): bool
    {
        // AND block — all must pass
        foreach ($filters['and'] ?? [] as $filter) {
            [$field, $op, $value] = $this->normaliseFilter($filter);

            if (!array_key_exists($field, $document)) {
                return false;
            }

            if (!$this->matchesSingleFilter($document[$field], $op, $value)) {
                return false;
            }
        }

        // OR block — at least one must pass (ignored if empty)
        $orFilters = $filters['or'] ?? [];

        if (!empty($orFilters)) {
            $orPassed = false;

            foreach ($orFilters as $filter) {
                [$field, $op, $value] = $this->normaliseFilter($filter);

                if (!array_key_exists($field, $document)) {
                    continue;
                }

                if ($this->matchesSingleFilter($document[$field], $op, $value)) {
                    $orPassed = true;
                    break;
                }
            }

            if (!$orPassed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates the shape of a single filter and returns [field, op, value].
     *
     * A malformed filter used to surface as an "Undefined array key" warning
     * and a silent mismatch; it is now an explicit error.
     *
     * @return array{0: string, 1: string, 2: mixed}
     * @throws FilterException
     */
    private function normaliseFilter(mixed $filter): array
    {
        if (!is_array($filter)) {
            throw new FilterException('Each filter must be an array with keys: field, op, value');
        }

        foreach (['field', 'op', 'value'] as $key) {
            if (!array_key_exists($key, $filter)) {
                throw new FilterException("Filter is missing the '$key' key");
            }
        }

        if (!is_string($filter['field']) || !is_string($filter['op'])) {
            throw new FilterException("Filter keys 'field' and 'op' must be strings");
        }

        return [$filter['field'], $filter['op'], $filter['value']];
    }

    /**
     * Tests a single filter against a field value.
     *
     * Supported operators:
     *   =, !=               → int, float, bool, string
     *   >, >=, <, <=        → int, float
     *   in, not in          → int, float, string  (value must be an array)
     *   contains, not contains → array            (fieldValue must be an array)
     *
     * @throws FtsException if the operator is unknown
     */
    private function matchesSingleFilter(mixed $fieldValue, string $op, mixed $expected): bool
    {
        return match ($op) {
            '='  => $this->valuesEqual($fieldValue, $expected),
            '!=' => !$this->valuesEqual($fieldValue, $expected),

            '>'  => $this->bothNumeric($fieldValue, $expected) && $fieldValue >  $expected,
            '>=' => $this->bothNumeric($fieldValue, $expected) && $fieldValue >= $expected,
            '<'  => $this->bothNumeric($fieldValue, $expected) && $fieldValue <  $expected,
            '<=' => $this->bothNumeric($fieldValue, $expected) && $fieldValue <= $expected,

            'in'     => $this->valueInList($fieldValue, $expected),
            'not in' => !$this->valueInList($fieldValue, $expected),

            // Both require the document field to actually be an array: a
            // wrongly-typed field rejects the document, as in 1.1.2.
            'contains'     => is_array($fieldValue) && $this->listContains($fieldValue, $expected),
            'not contains' => is_array($fieldValue) && !$this->listContains($fieldValue, $expected),

            default => throw new FilterException("Unknown filter operator: '$op'"),
        };
    }

    /**
     * Type-safe equality.
     *
     * PHP's `==` treats any non-empty string as equal to `true`, so a filter
     * value of `true` used to match every document with a non-empty string in
     * that field — enough to defeat a filter used for ownership or tenant
     * scoping. Comparison is therefore identity-based, with one deliberate
     * exception: int and float are compared numerically, because JSON round
     * trips move values between the two.
     */
    private function valuesEqual(mixed $fieldValue, mixed $expected): bool
    {
        if ($this->bothNumeric($fieldValue, $expected)) {
            return (float) $fieldValue === (float) $expected;
        }

        return $fieldValue === $expected;
    }

    private function bothNumeric(mixed $a, mixed $b): bool
    {
        return (is_int($a) || is_float($a)) && (is_int($b) || is_float($b));
    }

    /**
     * @throws FilterException if the expected value is not a list
     */
    private function valueInList(mixed $fieldValue, mixed $expected): bool
    {
        if (!is_array($expected)) {
            throw new FilterException("Operator 'in' expects an array of values");
        }

        foreach ($expected as $candidate) {
            if ($this->valuesEqual($fieldValue, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function listContains(mixed $fieldValue, mixed $expected): bool
    {
        if (!is_array($fieldValue)) {
            return false;
        }

        foreach ($fieldValue as $candidate) {
            if ($this->valuesEqual($candidate, $expected)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Private methods — utilities
    // -------------------------------------------------------------------------

    private function extractTrigrams(mixed $value): array
    {
        if (is_string($value)) {
            return $this->tokenizer->tokenize($value);
        }

        if (is_array($value)) {
            $trigrams = [];

            foreach ($value as $item) {
                foreach ($this->extractTrigrams($item) as $trigram) {
                    $trigrams[] = $trigram;
                }
            }

            return array_values(array_unique($trigrams));
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Private methods — highlighting
    // -------------------------------------------------------------------------

    /**
     * Builds highlighted excerpts for all string fields of a document.
     *
     * For each string field, words whose trigrams overlap with the query trigrams
     * are wrapped with the configured open/close tags.
     *
     * Two modes (controlled by $options['excerpt']):
     *   - excerpt: true  — returns a window of N words around the first match,
     *                       with "…" if the field is truncated
     *   - excerpt: false — returns the full field content with matched words wrapped
     *
     * @param array  $document      Full document
     * @param array  $queryTrigrams Query trigrams (from tokenizer->tokenize($query))
     * @param array  $options       Highlighting options (tags, excerpt, window)
     * @return array<string, string> ['field' => 'highlighted text', ...]
     */
    private function buildHighlights(array $document, array $queryTrigrams, array $options): array
    {
        $openTag     = $options['tags'][0]  ?? '<mark>';
        $closeTag    = $options['tags'][1]  ?? '</mark>';
        $excerptMode = $options['excerpt'] ?? true;
        $window      = max(1, (int) ($options['window'] ?? 5));

        // Highlights are HTML by construction — they carry the open/close tags —
        // and indexed content is routinely user-supplied. Field text is therefore
        // escaped before the tags are inserted, so the result is safe to render.
        // Set 'escape' => false only if the caller escapes downstream itself.
        $escape = $options['escape'] ?? true;

        $querySet   = array_flip($queryTrigrams);
        $highlights = [];

        foreach ($document as $field => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            // Split on whitespace, preserving original words
            $words = preg_split('/\s+/', trim($value));

            if (empty($words)) {
                continue;
            }

            // Identify matched positions and build the processed word list
            $matchedPositions = [];
            $processedWords   = [];

            foreach ($words as $i => $word) {
                $wordTrigrams = $this->tokenizer->tokenize($word);
                $hasMatch     = false;

                foreach ($wordTrigrams as $trigram) {
                    if (isset($querySet[$trigram])) {
                        $hasMatch = true;
                        break;
                    }
                }

                $safeWord = $escape
                    ? htmlspecialchars($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : $word;

                $processedWords[$i] = $hasMatch ? $openTag . $safeWord . $closeTag : $safeWord;

                if ($hasMatch) {
                    $matchedPositions[] = $i;
                }
            }

            // No match in this field — skip it
            if (empty($matchedPositions)) {
                continue;
            }

            if (!$excerptMode) {
                // Full field with all matched words wrapped
                $highlights[$field] = implode(' ', $processedWords);
                continue;
            }

            // Excerpt mode: window around the first matched word
            $center = $matchedPositions[0];
            $start  = max(0, $center - $window);
            $end    = min(count($words) - 1, $center + $window);

            $slice = array_slice($processedWords, $start, $end - $start + 1);
            $text  = implode(' ', $slice);

            if ($start > 0) {
                $text = '…' . $text;
            }

            if ($end < count($words) - 1) {
                $text .= '…';
            }

            $highlights[$field] = $text;
        }

        return $highlights;
    }

    private function assertOpen(): void
    {
        if (!$this->open) {
            throw new FtsException("Engine is not open. Call open() first.");
        }
    }

    /**
     * Returns the path of the bulk sentinel file.
     * Presence of this file on disk means a previous insertBulk() did not complete.
     */
    private function sentinelPath(): string
    {
        return $this->directory . '/.bulk_in_progress';
    }
}
