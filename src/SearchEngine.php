<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Exception\HighlightException;
use Ols\PhpFts\Exception\SortException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\IndexDirectory;

/**
 * A full-text index in a directory.
 *
 *     $engine = SearchEngine::open('./search_data');
 *
 *     $engine->put('sku-4471', ['title' => 'Brown leather shoe', 'price' => 129.90]);
 *     $result = $engine->search('lether sho');
 *
 *     foreach ($result as $hit) {
 *         echo $hit->id, ' ', $hit->document['title'], PHP_EOL;
 *     }
 *
 * There is no open/close pairing, no connection, no id bookkeeping and no
 * maintenance to schedule. A request opens the index, does its work, and ends;
 * nothing has to be released, because nothing was held.
 *
 * ── Why this class exists, given that IndexDirectory does the work ──────────
 *
 * It is the whole public surface, and it is one name.
 *
 * Everything under `Index\`, `Storage\`, `Query\` and `Analysis\` is machinery:
 * segments, manifests, postings, bitsets, merge policies. Those are worth
 * reading and worth testing directly, and they are not worth *depending* on —
 * the format will change, and an application that reached into it would break
 * when it did. So the engine is the only class documented as an entry point,
 * and the rest is free to move.
 *
 * It is also where the conveniences live that the layer below has no business
 * knowing about: generating an id for a caller who has none, and emptying an
 * index. Nothing here re-implements anything; delegation with a reason.
 *
 * ── What the caller can rely on ─────────────────────────────────────────────
 *
 * Every write is one commit: it either happens whole or does not happen. A
 * crash cannot leave the index half-written, and there is no repair step,
 * because a half-written segment is named by no manifest and is therefore
 * invisible rather than broken.
 *
 * Every read takes no lock and sees one consistent snapshot. A search running
 * while an import commits sees the index as it was when the search began.
 *
 * Ids are the caller's own and never change, for the life of the index.
 *
 * ── Errors ──────────────────────────────────────────────────────────────────
 *
 * Everything this class throws descends from {@see FtsException}, which is a
 * `RuntimeException`. One catch is enough for an application that only wants
 * to know that indexing failed; the specific types are there for the ones that
 * want to tell a bad filter from a full disk.
 */
final class SearchEngine implements \Countable
{
    private function __construct(private readonly IndexDirectory $index)
    {
    }

    /**
     * Opens the index in a directory, creating it if it is not there.
     *
     * A schema may be declared, and is frozen the first time the index
     * commits. Passing none leaves the field types inferred from the documents
     * themselves, which is what a caller who just wants to search gets.
     *
     * Reopening with a schema that contradicts the frozen one is refused
     * rather than ignored: the segments on disk were written to the old one.
     *
     * @throws StorageException when the directory cannot be created or read
     * @throws FtsException     when the declared schema is not the frozen one
     */
    public static function open(string $directory, ?Schema $schema = null): self
    {
        return new self(IndexDirectory::open($directory, $schema));
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Adds or replaces one document under an id of your own.
     *
     * Idempotent: putting the same id twice leaves one document, the second
     * one. The replacement is a single commit, so no reader ever sees two
     * versions of a document or none.
     *
     * One document is one commit, which is one segment file. Fine for an
     * occasional edit; for anything more, putMany() writes the whole batch as
     * one segment and one commit, and is what an import should call.
     *
     * @param array<string, mixed> $document
     *
     * @throws StorageException
     * @throws Exception\FieldTypeException when a declared field gets a value
     *         of the wrong type
     */
    public function put(string|int $id, array $document): void
    {
        $this->index->put($id, $document);
    }

    /**
     * Adds a document under an id the engine generates, and returns that id.
     *
     * For callers with no natural key. Everyone else should use put() with
     * their own: an id you chose is one you can look up later from your own
     * database, put in a URL, and recognise in a log.
     *
     * The id is sixteen random hex characters rather than a counter. A counter
     * would have to be stored somewhere and kept correct across concurrent
     * writers and deletions, which is a lot of machinery for the case of "any
     * id will do" — and it would hand out an id that had been used before after
     * a clear(). This cannot collide in practice, and checks anyway.
     *
     * @param array<string, mixed> $document
     *
     * @throws StorageException
     * @throws FtsException when no free id was found, which cannot happen
     */
    public function insert(array $document): string
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $id = bin2hex(random_bytes(8));

            if (!$this->index->has($id)) {
                $this->index->put($id, $document);

                return $id;
            }
        }

        throw new FtsException('Could not generate a free document id. Pass your own id to put().');
    }

    /**
     * Adds or replaces many documents in a single commit.
     *
     * Transactional: the batch either lands whole or leaves the index exactly
     * as it was. A document the schema refuses takes its batch with it, on
     * purpose — half an import is worse than none, because nothing tells you
     * which half.
     *
     * Accepts a generator, and holds one segment's worth of data rather than
     * the whole input, so an import of any size runs in bounded memory. The
     * batch is written as several segments when it outgrows a share of what
     * `memory_limit` leaves, and all of them are published by the single commit
     * at the end — so spilling costs nothing in transactionality.
     *
     * Measured on a 45 000-product catalogue yielded straight from a cursor,
     * against a 128 MB limit: the whole import peaks at 64 MB, and 15 000 of
     * the same documents peak at 60. It used to hold ~8 KB per document and
     * die at about fifteen thousand of them.
     *
     *     $engine->putMany((function () use ($pdo) {
     *         foreach ($pdo->query('SELECT * FROM products') as $row) {
     *             yield $row['sku'] => $row;
     *         }
     *     })());
     *
     * @param iterable<string|int, array<string, mixed>> $documents id => document
     *
     * @throws StorageException
     * @throws Exception\FieldTypeException
     */
    public function putMany(iterable $documents): void
    {
        $this->index->putMany($documents);
    }

    /**
     * Removes a document. False when the id was not in the index.
     *
     * @throws StorageException
     */
    public function delete(string|int $id): bool
    {
        return $this->index->delete($id);
    }

    /**
     * Empties the index, keeping the directory.
     *
     * A commit like any other, so a search already in flight is unaffected and
     * finishes against the index as it was. The frozen schema goes with the
     * documents: it is frozen because segments were written to it, and after
     * this there are none.
     *
     * @throws StorageException
     */
    public function clear(): void
    {
        $this->index->clear();
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * Searches the index.
     *
     *     $engine->search(
     *         query:     'leather shoe',
     *         limit:     20,
     *         offset:    40,
     *         boosts:    ['title' => 3.0],
     *         filters:   Filter::eq('active', true),
     *         facets:    ['brand', 'category'],
     *         sort:      [Sort::asc('price')],
     *         highlight: ['title', 'description'],
     *     );
     *
     * An empty query matches everything, which is what makes filters and
     * facets usable on their own: a category page, an admin table, faceted
     * browsing with no search box.
     *
     * `$result->total` is the exact number of matches across the index, not
     * the size of the page — real pagination needs the real total, and a
     * capped one is a lie that shows up in the last page number.
     *
     * @param int                 $limit     documents on this page
     * @param int                 $offset    documents to skip; use it to paginate
     * @param Filter|array<mixed> $filters   a Filter tree, or a list of clauses, ANDed
     * @param array<mixed>        $facets    field names, or name => Facet
     * @param array<string,float> $boosts    per-field weights, overriding the schema's
     * @param Highlight|string[]  $highlight fields to highlight, or a Highlight
     *
     * @param Sort|array<mixed>   $sort      criteria, in order of precedence.
     *        Relevance by default. Sorting is the engine's job rather than the
     *        caller's because it is the same problem as pagination: twenty hits
     *        sorted by price are the twenty best-scoring documents arranged by
     *        price, not the twenty cheapest matches. See Sort.
     *
     * @throws FilterException        when a filter is malformed or mistyped
     * @throws HighlightException     when a field asked for cannot be highlighted
     * @throws SortException          when a field cannot be sorted on
     * @throws CorruptSegmentException
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
        return $this->index->search(
            $query,
            $limit,
            $offset,
            $filters,
            $facets,
            $boosts,
            $highlight,
            $sort,
        );
    }

    /**
     * One document by id, or null.
     *
     * @return array<string, mixed>|null
     * @throws CorruptSegmentException
     */
    public function get(string|int $id): ?array
    {
        return $this->index->get($id);
    }

    public function has(string|int $id): bool
    {
        return $this->index->has($id);
    }

    /**
     * Live documents: everything written, less everything deleted.
     */
    public function count(): int
    {
        return $this->index->count();
    }

    /**
     * The schema the index is using, declared or inferred.
     */
    public function schema(): Schema
    {
        return $this->index->schema();
    }

    /** The directory the index lives in. */
    public function directory(): string
    {
        return $this->index->directory();
    }

    // -------------------------------------------------------------------------
    // Maintenance, of which there is none
    // -------------------------------------------------------------------------

    /**
     * What the index looks like on disk.
     *
     *     ['documents' => 20000, 'deleted' => 143, 'segments' => 4,
     *      'bytes' => 18442137, 'generation' => 91, 'fields' => [...],
     *      'needsOptimize' => false]
     *
     * `needsOptimize` is reported rather than acted on, because merging needs
     * the write lock and a search that triggered one could end up waiting
     * behind an import. It lets an application schedule the work instead.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->index->stats();
    }

    /**
     * Merges everything into one segment.
     *
     * Never required — the engine merges as it writes, within a time budget
     * that keeps write requests fast. This is for the cases where someone
     * would rather pay the whole cost at once: after a bulk import, or from a
     * cron on a large index. Does nothing when there is nothing to gain.
     *
     * @throws StorageException
     */
    public function optimize(): void
    {
        $this->index->optimize();
    }
}
