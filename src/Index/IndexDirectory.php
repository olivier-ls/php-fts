<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\FieldTypeException;
use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Exception\HighlightException;
use Ols\PhpFts\Exception\SortException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Hit;
use Ols\PhpFts\LockManager;
use Ols\PhpFts\Query\CollectionStatistics;
use Ols\PhpFts\Query\Highlighter;
use Ols\PhpFts\Query\QueryPlan;
use Ols\PhpFts\Query\Scorer;
use Ols\PhpFts\Query\TopK;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchResult;
use Ols\PhpFts\Sort;
use Ols\PhpFts\Storage\Manifest;

/**
 * An index living in a directory: several immutable segments, one numbered
 * manifest saying which of them are live.
 *
 *     $index = IndexDirectory::open('./search_data');
 *
 *     $index->putMany(['sku-1' => [...], 'sku-2' => [...]]);   // one commit
 *     $index->delete('sku-1');                                  // another
 *     $index->search('leather', filters: Filter::gt('stock', 0), facets: ['brand']);
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

    /**
     * How much more an import peaks at than the documents it is holding.
     *
     * `put()` keeps the document and nothing else; the term map is built inside
     * `write()`, and that is the peak. Measured on the reference catalogue,
     * three scales, documents held against peak allocation:
     *
     *      5 000 documents    10 MB ->  40 MB   4.0x
     *     15 000 documents    36 MB -> 120 MB   3.3x
     *     30 000 documents    72 MB -> 242 MB   3.4x
     *
     * Stable, and it should be: both sides scale with the same text. That is
     * what makes a ratio usable here where an absolute figure per document is
     * not — a catalogue of one-line titles and one of thousand-word
     * descriptions differ by two orders of magnitude on the second and not on
     * the first. Rounded up to 4, the safe way, as MergePolicy rounds its own.
     */
    private const WRITE_PEAK_FACTOR = 4;

    private function __construct(
        private readonly string $directory,
        private readonly LockManager $lock,
        private readonly MergePolicy $policy,
        private readonly SegmentMerger $merger,
        private readonly ?Schema $declared = null,
    ) {
    }

    /**
     * Opens the newest readable commit.
     *
     * A schema may be declared here. It is used the first time the index
     * commits and frozen into the manifest from then on. Passing none leaves
     * the types inferred from the documents, which is what a caller who just
     * wants to search gets.
     *
     * @throws StorageException
     * @throws FtsException when the declared schema is not the frozen one
     */
    public static function open(
        string $directory,
        ?Schema $schema = null,
        ?MergePolicy $policy = null,
    ): self {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new StorageException("Unable to create index directory: $directory");
        }

        $index = new self(
            rtrim($directory, '/\\'),
            new LockManager($directory),
            $policy ?? new MergePolicy(),
            new SegmentMerger(),
            $schema,
        );

        $index->load();
        $index->assertSchemaMatches();

        return $index;
    }

    /**
     * Refuses a schema that contradicts the one the index already holds.
     *
     * An index's schema is frozen at its first commit, so a later call passing
     * something different cannot be honoured — the segments already on disk
     * were written to the old one. Reopening with the same schema is fine and
     * expected, since the declaration usually sits next to the open() call.
     *
     * Loud rather than silent: quietly ignoring the new declaration is how
     * someone spends an afternoon wondering why their boost does nothing.
     *
     * @throws FtsException
     */
    private function assertSchemaMatches(): void
    {
        if ($this->declared === null || $this->manifest->schema === []) {
            return;
        }

        // Compared through fromArray() on both sides, not against the raw
        // manifest: JSON does not keep a float's zero fraction, so a boost of
        // 1.0 comes back as the integer 1 and a strict comparison against the
        // declared array would fail on every reopen.
        if ($this->declared->toArray() !== $this->schema()->toArray()) {
            throw new FtsException(
                "The index in {$this->directory} was created with a different schema. "
                . 'A schema is frozen at the first commit; reindex into a new directory to change it.'
            );
        }
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The schema this index is using, declared or inferred.
     */
    public function schema(): Schema
    {
        return Schema::fromArray($this->manifest->schema);
    }

    /**
     * @return array<string, string> field => type
     */
    private function fieldTypes(): array
    {
        $types = [];

        foreach ($this->schema()->fields() as $field => $definition) {
            $types[$field] = $definition['type'];
        }

        return $types;
    }

    /**
     * The schema to hand a writer: the frozen one, else what the caller
     * declared at open(), else null so the writer infers.
     *
     * Frozen at the first commit and carried forward, because inference depends
     * on the batch: left to re-infer, a merge could change a field's type and a
     * filter that worked before it would fail after.
     */
    private function frozenSchema(): ?Schema
    {
        return $this->manifest->schema === [] ? $this->declared : $this->schema();
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
            'fields'     => $this->fieldTypes(),
            'schema'     => $this->manifest->schema,

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
     * @throws FieldTypeException when a declared field's value is not of its type
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
     * @throws FieldTypeException when a declared field's value is not of its type
     */
    public function putMany(iterable $documents): void
    {
        $this->lock->withLock(function () use ($documents): void {
            // Re-read inside the lock: another process may have committed since
            // this object was opened, and its work must not be dropped.
            $this->load();

            // ── Why this spills into several segments ─────────────────────
            //
            // Because one segment used to mean one batch, and a batch is the
            // caller's whole input. Analysing a document holds it as a PHP
            // array and holds its postings as a nested one, so the writer grew
            // at a measured ~8 KB per document: 10 MB at a thousand documents,
            // 38 MB at five thousand, **120 MB at fifteen thousand**. On a
            // shared host with 128 MB the documented example — yielding rows
            // straight out of a PDO cursor — died at about fifteen thousand of
            // them, and the docblock on SearchEngine::putMany() promised the
            // opposite in as many words.
            //
            // A segment is written when the writer's own growth crosses the
            // budget, and a new one started. Nothing is published until the
            // end, so the transaction is unharmed: a segment named by no
            // manifest is invisible, which is the same property that lets a
            // half-finished merge be debris rather than damage.
            //
            // Watched rather than predicted, unlike a merge's cap. What a
            // document costs to analyse depends on how much text it holds, and
            // a catalogue of one-line titles and one of thousand-word
            // descriptions differ by two orders of magnitude — so a coefficient
            // per document would be a guess where a subtraction is a fact.
            //
            // ── Two things measurement corrected here ──────────────────────
            //
            // **What the loop holds is not what the import costs.** `put()`
            // only keeps the document; the postings map is built inside
            // `write()`, and that is where the peak is. Watching the loop and
            // comparing it against the whole budget therefore never fired: at
            // five thousand documents the loop had grown 10 MB and the write
            // peaked at 40. So the budget is divided by what the write
            // multiplies it by — see WRITE_PEAK_FACTOR, which is a ratio
            // between two quantities that both scale with the same text, and
            // is measured stable where a per-document figure would not be.
            //
            // **`memory_get_usage(true)` cannot see a spill happen.** It
            // reports what the allocator holds from the OS, and that never
            // shrinks: after the first segment is written the high-water mark
            // stays, so the next document would look like it had already blown
            // the budget and every one after it would spill alone. The
            // accounted figure drops when the writer is released, which is
            // exactly the event being waited on.
            $budget   = $this->policy->budgetBytes();
            $baseline = memory_get_usage();

            if ($budget !== null) {
                $budget = intdiv($budget, self::WRITE_PEAK_FACTOR);
            }

            // Fixed for the whole import. The first segment may infer it; every
            // one after that is handed what the first decided, because
            // inference reads the batch it is given and two segments of one
            // import must not disagree about a field's type.
            $schema = $this->frozenSchema();

            $writer    = new SegmentIndexWriter(null, $schema);
            $written   = [];
            $replacing = [];
            $inferred  = null;

            // Batch-wide, because the writer's own duplicate check is
            // per-segment and would stop seeing across a spill. One small entry
            // per id, against the ~8 KB the writer holds for the same document.
            $seen = [];

            $spill = function (SegmentIndexWriter $full) use (&$written, &$inferred): void {
                $name = $this->newSegmentName();
                $full->write($this->segmentPath($name));

                // A large import holds the write lock for a long time, and on a
                // host where `posix_kill()` is unavailable the only evidence a
                // waiting process has that this one is alive is the clock. Each
                // segment is a natural place to put something on it.
                $this->lock->heartbeat();

                $inferred ??= $full->schema();
                $written[]  = ['name' => $name, 'documents' => $full->count(), 'deleted' => ''];
            };

            try {
                foreach ($documents as $id => $document) {
                    $id = (string) $id;

                    if (isset($seen[$id])) {
                        throw new StorageException("Duplicate document id in this batch: '$id'");
                    }

                    $seen[$id] = true;
                    $writer->put($id, $document);

                    $located = $this->locate($id);

                    if ($located !== null) {
                        $replacing[] = $located;
                    }

                    if ($budget !== null && memory_get_usage() - $baseline > $budget) {
                        $spill($writer);
                        $writer = new SegmentIndexWriter(null, $schema ?? $inferred);
                    }
                }

                if ($writer->count() > 0) {
                    $spill($writer);
                }
            } catch (\Throwable $failure) {
                // The segments written so far are named by no manifest and are
                // therefore already invisible. Removing them anyway keeps a
                // refused import from leaving its debris on disk for good —
                // nothing else would ever collect them.
                foreach ($written as $entry) {
                    @unlink($this->segmentPath($entry['name']));
                }

                throw $failure;
            }

            if ($written === []) {
                return;
            }

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

            foreach ($written as $entry) {
                $segments[] = $entry;
            }

            // One commit for every segment the import produced, so the batch
            // still lands whole or not at all.
            //
            // The schema is frozen at the first commit and carried forward, so
            // that a later merge cannot re-infer a field into a different type.
            $this->commit($segments, schema: $this->manifest->schema === []
                ? ($inferred ?? Schema::make())->toArray()
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
     * Empties the index.
     *
     * A commit like any other: it publishes a manifest naming no segments, and
     * retires the ones that were live rather than deleting their files. A
     * search that began a moment ago is reading those files, and is entitled to
     * finish reading them — it sees the index as it was when it started, which
     * is the same guarantee every other write gives it. The files go with the
     * grace period, like a merge's inputs.
     *
     * The frozen schema goes too. Freezing exists because segments already on
     * disk were written to it, and after this commit there are none — so an
     * index wiped and reopened with a different schema is a legitimate way to
     * change one, and the only one that does not need a second directory.
     *
     * @throws StorageException
     */
    public function clear(): void
    {
        $this->lock->withLock(function (): void {
            $this->load();

            $now     = time();
            $retired = $this->manifest->retired;

            foreach ($this->manifest->segments as $entry) {
                $retired[] = ['name' => $entry['name'], 'at' => $now];
            }

            $this->commit([], $retired, schema: []);
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
     * @param Filter|array<mixed> $filters a Filter tree, a nested array, or a
     *        flat list of clauses, which are ANDed
     * @param array<mixed>        $facets  field names, or name => Facet
     * @param Highlight|string[]  $highlight fields to highlight, or a Highlight
     * @param Sort|array<mixed>   $sort      criteria, in order of precedence
     *
     * @throws CorruptSegmentException
     * @throws FilterException
     * @throws HighlightException
     * @throws SortException
     */
    public function search(
        string $query = '',
        int $limit = 20,
        int $offset = 0,
        Filter|array $filters = [],
        array $facets = [],
        array $boosts = [],
        Highlight|array $highlight = [],
        Sort|array $sort = [],
    ): SearchResult {
        $started  = hrtime(true);
        $criteria = Sort::normalise($sort);

        // Parsed once here rather than once per segment: a malformed filter is
        // the caller's mistake, and it should be reported before any file is
        // touched rather than by whichever segment happened to be read first.
        $filters   = Filter::normalise($filters) ?? Filter::all();
        $highlight = Highlight::normalise($highlight);
        if ($highlight !== null) {
            // Also checked here, and not only per segment, so that the answer
            // does not depend on which segments happen to hold the page — and
            // so that an index with no segments at all still reports a field
            // name the schema does not have.
            Highlighter::verify($highlight, $this->schema());
        }

        // Resolved before anything is scored, and once for the whole index.
        //
        // Both halves of this have to be index-wide for the same reason. IDF
        // asks how rare a term is *in the index*, and expansion asks which
        // words the index holds that the typed one might have meant — and
        // segments hold different vocabularies. Answered per segment, either
        // one would make the same document match, or score, according to which
        // segment it happened to land in, and a merge would change the result.
        [$plan, $statistics] = $this->planFor($query);

        // What the documents say, not what was typed: a search for `stel`
        // finds documents containing `steel`, so `steel` is what gets marked.
        $terms = $highlight === null ? [] : $plan->terms();

        $wanted = Facet::normalise($facets);

        // The filter as each excluded tag sees it, worked out once for the
        // whole search. Two facets excluding the same tag are one extra pass,
        // not two, and a tag nothing excludes costs nothing at all.
        $variants = [];

        foreach ($wanted as $facet) {
            if ($facet->exclude !== null && !array_key_exists($facet->exclude, $variants)) {
                $variants[$facet->exclude] = $filters->withoutTag($facet->exclude) ?? Filter::all();
            }
        }

        $total  = 0;
        $merged = [];

        // One bounded heap for the whole search rather than a ranked list per
        // segment: the best twenty overall are somewhere among the segments, and
        // there is no need to materialise more than twenty to find them.
        $top = new TopK(max(0, $offset) + max(0, $limit));

        foreach ($this->segments as $position => $segment) {
            // The query is matched once per segment. Every filter variant is
            // then bit arithmetic over the same candidates — no posting list is
            // walked twice, which is what keeps disjunctive facets affordable.
            [$candidates, $scores] = $segment->candidates(
                $plan,
                $this->deletions[$position],
                $statistics,
                $boosts,
            );

            $matches = $segment->narrow($candidates, $filters);
            $total  += $matches->count();

            $narrowed = [];

            foreach ($variants as $tag => $variant) {
                $narrowed[$tag] = $segment->narrow($candidates, $variant);
            }

            foreach ($wanted as $name => $facet) {
                // Not `$narrowed[$facet->exclude] ?? $matches`: a null offset
                // is deprecated in 8.5, and a facet excluding nothing is the
                // common case rather than the exception.
                $over = $facet->exclude === null ? $matches : ($narrowed[$facet->exclude] ?? $matches);

                $merged[$name] = $this->mergeFacet(
                    $merged[$name] ?? null,
                    $segment->facet($facet, $name, $over)
                );
            }

            // One chunked read per sort criterion per segment, then a rank per
            // matching document. The heap stays bounded, so a category page
             // ordered by price still materialises only its own page.
            $keys = $segment->sortKeys($criteria, $matches);

            foreach ($matches->iterate() as $ordinal) {
                $top->offer(
                    $segment->rankOf($criteria, $keys, $scores, $ordinal),
                    $position,
                    $ordinal,
                    $scores[$ordinal] ?? 0.0,
                );
            }
        }

        // Truncated only now. The top twenty of the index are not the top
        // twenty of each segment added together.
        foreach ($wanted as $name => $facet) {
            $merged[$name] = $facet->limit($merged[$name] ?? []);
        }

        $hits    = [];
        $markers = [];
        $marked  = $highlight === null ? [] : array_fill_keys($terms, true);

        foreach (array_slice($top->drain(), max(0, $offset)) as [, $position, $ordinal, $score]) {
            $document = $this->segments[$position]->documentAt($ordinal);

            if ($document === null) {
                continue;
            }

            $highlights = [];

            if ($highlight !== null) {
                // One highlighter per segment that actually contributed a hit,
                // because each holds that segment's own analyzer. A search whose
                // page comes from one segment builds one.
                $markers[$position] ??= $this->segments[$position]->highlighter($highlight);
                $highlights           = $markers[$position]->document($document, $marked, $highlight);
            }

            $hits[] = new Hit($this->segments[$position]->keyAt($ordinal), $score, $document, $highlights);
        }

        return new SearchResult($hits, $total, $merged, (hrtime(true) - $started) / 1e6);
    }

    /**
     * Resolves a query against the whole index: what its words could have
     * meant, and how rare each of those readings is.
     *
     * Two passes over the segments, and the order is forced: expansion
     * discovers which terms exist to be counted, and only then can their
     * document frequencies be summed. Both results travel together because
     * every segment needs both, identically.
     *
     * @return array{0: QueryPlan, 1: CollectionStatistics}
     * @throws CorruptSegmentException
     */
    private function planFor(string $query): array
    {
        if ($this->segments === []) {
            return [QueryPlan::matchAll(), CollectionStatistics::empty()];
        }

        $typed = reset($this->segments)->analyze($query);

        if ($typed === []) {
            return [QueryPlan::matchAll(), $this->statisticsOver([])];
        }

        // First pass: what could each typed word have meant? Every segment
        // offers the terms its own dictionary holds within the word's edit
        // budget, and the union is what the index as a whole could have meant.
        // A word present in one segment and absent from another therefore ends
        // up a candidate for both, which is the point.
        $candidates = [];
        $terms      = [];

        foreach ($this->segments as $segment) {
            foreach ($segment->expandTerms($typed) as $position => $found) {
                foreach ($found as $term => $distance) {
                    $term = (string) $term;

                    if (!isset($candidates[$position][$term])) {
                        $candidates[$position][$term] = $distance;
                        $terms[]                      = $term;
                    }
                }
            }
        }

        // Second pass: how rare is each of those candidates across the index?
        // It has to be a second pass, because the first one is what discovered
        // which terms there were to count.
        $statistics = $this->statisticsOver($terms);

        return [
            QueryPlan::build($typed, $candidates, $statistics, new Scorer()),
            $statistics,
        ];
    }

    /**
     * The index-wide numbers BM25 needs, for a known set of terms.
     *
     * @param string[] $terms
     * @throws CorruptSegmentException
     */
    private function statisticsOver(array $terms): CollectionStatistics
    {
        $statistics = CollectionStatistics::empty();

        foreach ($this->segments as $position => $segment) {
            // Live documents, not written ones: a term present only in deleted
            // documents should not look common.
            $live = $segment->count() - $this->deletions[$position]->count();

            $statistics = $statistics->plus(
                max(0, $live),
                $segment->termLengthSum(),
                $terms === [] ? [] : $segment->documentFrequencies($terms),
                $segment->fieldLengthSums(),
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
     * @param array<string, mixed>|null                                        $schema
     * @throws StorageException
     */
    private function commit(array $segments, ?array $retired = null, ?array $schema = null): void
    {
        // Garbage collection rides along on the commit rather than needing one
        // of its own: expired retirements are dropped from the list being
        // written, and their files removed.
        $retired = $this->pruneRetired($retired ?? $this->manifest->retired);

        $this->manifest->next($segments, $retired, $schema)->write($this->directory);

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

            $positions = $this->policy->select($this->descriptors(), $this->filterableFieldCount());

            if ($positions === []) {
                return;
            }

            $this->performMerge($positions);

            // See the same call in putMany(): a merge is the other operation
            // long enough for a waiting process to start wondering whether this
            // one is still alive.
            $this->lock->heartbeat();
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
            $this->frozenSchema(),
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
     * How many fields have a column.
     *
     * The policy's other input: a column is carried across a merge as one
     * array slot per document, so it is what makes one index's documents
     * dearer to merge than another's. Free to work out — the schema is already
     * loaded — and stable, because it is frozen at the first commit.
     */
    private function filterableFieldCount(): int
    {
        $count = 0;

        foreach ($this->schema()->fields() as $definition) {
            if ($definition['filterable']) {
                $count++;
            }
        }

        return $count;
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

        return Facet::rank($running);
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
