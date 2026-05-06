<?php

declare(strict_types=1);

namespace Ols\PhpFts;

require_once __DIR__ . '/DocumentStorage.php';
require_once __DIR__ . '/TombstoneStorage.php';
require_once __DIR__ . '/PostingsStorage.php';
require_once __DIR__ . '/TrigramIndex.php';
require_once __DIR__ . '/Tokenizer.php';
require_once __DIR__ . '/LockManager.php';

/**
 * SearchEngine
 *
 * Single responsibility: orchestrate the components to expose
 * a simple API — insert, delete, search, compact.
 *
 * Applied fixes:
 *   - compact()  : lock acquired before any operation + finally guarantees release
 *                  + $acquiredLock captured before $this->open() overwrites $this->lock
 *                  + glob() ?: [] to avoid foreach on false
 *   - update()   : atomic via doInsert() / doDelete() — single lock
 *   - insertBulk(): duplicated code removed, uses doInsert()
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
     * @throws RuntimeException
     */
    public function open(string $directory, int $lockTimeout = 5): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException("Unable to create directory: $directory");
        }

        $this->directory   = rtrim($directory, '/');
        $this->lockTimeout = $lockTimeout;
        $this->lock        = new LockManager($this->directory, $lockTimeout);

        $this->documents->open($this->directory  . '/documents.bin');
        $this->tombstones->open($this->directory . '/tombstones.bin');
        $this->postings->open($this->directory   . '/postings.bin');
        $this->trigrams->open($this->directory   . '/trigrams.bin');

        $this->open = true;
    }

    /**
     * Inserts a document and updates the index.
     *
     * @return int doc_id
     * @throws RuntimeException
     */
    public function insert(array $document): int
    {
        $this->assertOpen();

        return $this->lock->withLock(fn() => $this->doInsert($document));
    }

    /**
     * Inserts multiple documents under a single lock.
     *
     * @return int[] doc_ids
     * @throws RuntimeException
     */
    public function insertBulk(array $documents): array
    {
        $this->assertOpen();

        return $this->lock->withLock(function () use ($documents): array {
            $docIds = [];

            foreach ($documents as $document) {
                $docIds[] = $this->doInsert($document);
            }

            return $docIds;
        });
    }

    /**
     * Updates an existing document.
     * Atomic: delete + insert under a single lock.
     * Returns the new doc_id — different from the old one.
     *
     * @throws RuntimeException
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
     * @throws RuntimeException
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
     * @return array<array{docId: int, score: float, document: array}>
     * @throws RuntimeException
     */
    public function search(string $query, int $limit = 20, int $maxCandidates = 5000, array $boosts = [], array $filters = []): array
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

            $results[] = [
                'docId'    => $docId,
                'score'    => $this->computeScore($document, $queryTrigrams, $entry['trigramCount'], $avgdl, $boosts),
                'document' => $document,
            ];

            $i++;
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Returns the number of indexed documents (excluding deletions).
     *
     * @throws RuntimeException
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
     * @throws RuntimeException
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
     * @throws RuntimeException
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
            throw new RuntimeException("Unable to create temporary directory: $tmpDir");
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

            $tombstones = $this->tombstones;

            $this->documents->iterate(
                function (int $offset, array $document, int $trigramCount) use (
                    $newDocs, $newPostings, $newTrigrams, $tombstones
                ): void {
                    if ($tombstones->isDeleted($offset)) {
                        return;
                    }

                    $trigramList  = $this->extractTrigrams($document);
                    $trigramCount = count($trigramList);
                    $newDocId     = $newDocs->write($document, $trigramCount);

                    foreach ($trigramList as $trigram) {
                        $e        = $newTrigrams->get($trigram);
                        $newEntry = $newPostings->append(
                            $newDocId,
                            $e['offset'],
                            $e['capacity'],
                            $e['count']
                        );

                        if ($newEntry !== $e) {
                            $newTrigrams->set(
                                $trigram,
                                $newEntry['offset'],
                                $newEntry['capacity'],
                                $newEntry['count']
                            );
                        }
                    }
                }
            );

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
                rename($tmpDir . '/' . $file, $this->directory . '/' . $file);
            }

            rmdir($tmpDir);

            // $this->lock is reassigned here — that is why $acquiredLock was captured above
            $this->open($this->directory, $this->lockTimeout);

        } catch (Throwable $e) {
            foreach (glob($tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }

            throw new RuntimeException('Compaction failed: ' . $e->getMessage(), 0, $e);

        } finally {
            // Guaranteed release in all cases — success or exception
            $acquiredLock->release();
        }
    }

    /**
     * Resets the engine.
     * Deletes all binary files and recreates them empty.
     *
     * @throws RuntimeException
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
            $field = $filter['field'];

            if (!array_key_exists($field, $document)) {
                return false;
            }

            if (!$this->matchesSingleFilter($document[$field], $filter['op'], $filter['value'])) {
                return false;
            }
        }

        // OR block — at least one must pass (ignored if empty)
        $orFilters = $filters['or'] ?? [];

        if (!empty($orFilters)) {
            $orPassed = false;

            foreach ($orFilters as $filter) {
                $field = $filter['field'];

                if (!array_key_exists($field, $document)) {
                    continue;
                }

                if ($this->matchesSingleFilter($document[$field], $filter['op'], $filter['value'])) {
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
     * Tests a single filter against a field value.
     *
     * Supported operators:
     *   =, !=               → int, float, bool, string
     *   >, >=, <, <=        → int, float
     *   in, not in          → int, float, string  (value must be an array)
     *   contains, not contains → array            (fieldValue must be an array)
     *
     * @throws RuntimeException if the operator is unknown
     */
    private function matchesSingleFilter(mixed $fieldValue, string $op, mixed $expected): bool
    {
        return match ($op) {
            '='         => $fieldValue == $expected,
            '!='        => $fieldValue != $expected,
            '>'         => is_numeric($fieldValue) && $fieldValue > $expected,
            '>='        => is_numeric($fieldValue) && $fieldValue >= $expected,
            '<'         => is_numeric($fieldValue) && $fieldValue < $expected,
            '<='        => is_numeric($fieldValue) && $fieldValue <= $expected,
            'in'        => is_array($expected) && in_array($fieldValue, $expected, strict: false),
            'not in'    => is_array($expected) && !in_array($fieldValue, $expected, strict: false),
            'contains'     => is_array($fieldValue) && in_array($expected, $fieldValue, strict: false),
            'not contains' => is_array($fieldValue) && !in_array($expected, $fieldValue, strict: false),
            default     => throw new RuntimeException("Unknown filter operator: '$op'"),
        };
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

    private function assertOpen(): void
    {
        if (!$this->open) {
            throw new RuntimeException("Engine is not open. Call open() first.");
        }
    }
}
