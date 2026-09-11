<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A merge must be invisible.
 *
 * This is the differential test the merger was waiting for. It builds the same
 * documents twice — once as three commits then merged into one segment, once as
 * a single commit that never merges — and asserts the two are the same index.
 * Not "close enough": the same answers, the same scores, and the same bytes on
 * disk.
 *
 * The reason it exists is a bug this catches and nothing else did. A merge used
 * to re-index the documents it read back out of its sources, and `source()`
 * decides which fields are in those documents. A field excluded from it — the
 * description you search but do not want a second copy of — came back missing,
 * so the merged segment had neither its terms nor its column. With
 * `source(false)` there was nothing to read at all and the first *automatic*
 * merge left an index that matched nothing. Every `source()` shape is therefore
 * exercised below, and the shapes are the point of the file.
 */
class MergeEquivalenceTest extends TestCase
{
    /** @var string[] */
    private array $directories = [];

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

    private function directory(): string
    {
        return $this->directories[] = sys_get_temp_dir() . '/fts_eq_' . uniqid((string) count($this->directories));
    }

    /**
     * Deliberately varied: several scripts, a boolean, a float, a missing
     * field, a one-element list given unwrapped, a field the schema never
     * mentions, and enough repetition for the terms to overlap between
     * documents.
     *
     * @return array<string, array<string, mixed>>
     */
    private function documents(): array
    {
        $documents = [];

        foreach (range(1, 12) as $n) {
            $documents["sku-$n"] = [
                'title'       => "Brown leather shoe number $n",
                'description' => 'Elegant city shoe in soft leather, cousu main',
                'brand'       => $n % 2 === 0 ? 'Nike' : 'Adidas',
                'tags'        => $n % 3 === 0 ? ['summer', 'luxury'] : ['winter'],
                'price'       => 100.0 + $n,
                'stock'       => $n,
                'active'      => $n % 4 !== 0,
                'note'        => "undeclared field $n",
            ];
        }

        $documents['jp-1'] = [
            'title'       => '革靴 ブラウン',
            'description' => '日本製の革靴です、とても丈夫',
            'brand'       => 'アディダス',
            'tags'        => 'summer',
            'price'       => 19800.0,
            'stock'       => 3,
            'active'      => true,
        ];

        $documents['ru-1'] = [
            'title'       => 'Коричневые кожаные туфли',
            'brand'       => 'Nike',
            'price'       => 90.0,
            'active'      => false,
        ];

        return $documents;
    }

    /** @param string[]|false|null $source fields to store, or false for none */
    private function schema(array|false|null $source = null): Schema
    {
        $schema = Schema::make()
            ->text('title', boost: 3.0)
            ->text('description')
            ->keyword('brand')
            ->tags('tags')
            ->number('price')
            ->number('stock')
            ->boolean('active');

        return $source === null ? $schema : $schema->source($source);
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     * @param string[]                            $delete ids to remove after writing
     */
    private function build(array $documents, Schema $schema, bool $split, array $delete = []): SearchEngine
    {
        $engine = SearchEngine::open($this->directory(), $schema);

        if ($split) {
            // Three commits, so there are three segments to merge — and in the
            // same document order as the single commit below, so the ordinals
            // the merge assigns are the ordinals a fresh index would assign.
            foreach (array_chunk($documents, (int) ceil(count($documents) / 3), true) as $chunk) {
                $engine->putMany($chunk);
            }
        } else {
            $engine->putMany($documents);
        }

        foreach ($delete as $id) {
            $engine->delete($id);
        }

        if ($split) {
            $engine->optimize();
        }

        return $engine;
    }

    /**
     * Everything about an index a caller can observe.
     *
     * Scores are in here on purpose: they are what proves the field masks and
     * the per-field lengths came across, since a lost mask or a length reset to
     * zero changes a number without changing which documents match.
     *
     * @return array<string, mixed>
     */
    private function observations(SearchEngine $engine): array
    {
        $seen = ['count' => $engine->count(), 'schema' => $engine->schema()->toArray()];

        foreach (['leather', 'shoe', 'cousu', '革靴', 'кожаные', 'lether', 'nonesuch', ''] as $query) {
            $result = $engine->search($query, limit: 50);
            $hits   = [];

            foreach ($result as $hit) {
                $hits[$hit->id] = $hit->score;
            }

            $seen["q:$query"] = ['total' => $result->total, 'hits' => $hits];
        }

        $filters = [
            'brand'    => Filter::eq('brand', 'Nike'),
            'tag'      => Filter::eq('tags', 'summer'),
            'tag-none' => Filter::missing('tags'),
            'range'    => Filter::between('price', 100, 110),
            'bool'     => Filter::eq('active', false),
            'nested'   => Filter::all(
                Filter::eq('brand', 'Adidas'),
                Filter::gt('stock', 3),
                Filter::any(Filter::eq('tags', 'summer'), Filter::eq('tags', 'winter')),
            ),
        ];

        foreach ($filters as $name => $filter) {
            $result = $engine->search('', filters: $filter, limit: 50);
            $ids    = array_map(static fn($hit): string => $hit->id, $result->hits);

            sort($ids);

            $seen["f:$name"] = ['total' => $result->total, 'ids' => $ids];
        }

        $faceted = $engine->search('', facets: [
            'brand'   => Facet::terms(),
            'tags'    => Facet::terms(),
            'pricing' => Facet::stats('price'),
        ]);

        $seen['facets'] = $faceted->facets;

        // Only where there is something to highlight: a highlight is built by
        // re-reading the stored text, so an index storing nothing refuses —
        // which is its own behaviour, tested elsewhere.
        if ($engine->schema()->isStored('title')) {
            $highlighted = $engine->search('leather', highlight: ['title'], limit: 50);

            foreach ($highlighted as $hit) {
                $seen['highlights'][$hit->id] = $hit->highlights['title'] ?? null;
            }
        }

        foreach (array_keys($this->documents()) as $id) {
            $seen["doc:$id"] = $engine->get($id);
        }

        return $seen;
    }

    /**
     * The bytes of the one segment an index is *made of*.
     *
     * Read through the manifest rather than off the directory listing: a merge
     * retires its sources instead of unlinking them, so they are still on disk
     * for the length of the grace period. Which files are live is a question
     * only the newest commit answers.
     */
    private function segmentBytes(SearchEngine $engine): string
    {
        $commits = glob($engine->directory() . '/commit.*') ?: [];

        $generations = array_map(
            static fn(string $path): int => (int) substr($path, strrpos($path, '.') + 1),
            $commits
        );

        rsort($generations);

        $manifest = json_decode(
            (string) file_get_contents($engine->directory() . '/commit.' . $generations[0]),
            true
        );

        $this->assertIsArray($manifest);
        $this->assertCount(1, $manifest['segments'], 'expected exactly one live segment to compare');

        return (string) file_get_contents(
            $engine->directory() . '/' . $manifest['segments'][0]['name'] . '.fts'
        );
    }

    // =========================================================================
    // Every source() shape
    // =========================================================================

    /**
     * @return array<string, array{0: array<string>|false|null}>
     */
    public static function sources(): array
    {
        return [
            'everything stored'          => [null],
            'a whitelist'                => [['title', 'price']],
            'a whitelist of one field'   => [['title']],
            'nothing stored'             => [false],
        ];
    }

    #[Test]
    public function a_merged_index_answers_exactly_like_a_freshly_built_one(): void
    {
        foreach (self::sources() as $label => [$source]) {
            $documents = $this->documents();
            $schema    = $this->schema($source);

            $merged = $this->build($documents, $schema, split: true);
            $fresh  = $this->build($documents, $this->schema($source), split: false);

            $this->assertSame(1, $merged->stats()['segments'], "$label: the merge did not happen");
            $this->assertSame(
                $this->observations($fresh),
                $this->observations($merged),
                "$label: a merged index answers differently from a fresh one"
            );
        }
    }

    #[Test]
    public function a_merged_segment_is_byte_identical_to_a_freshly_written_one(): void
    {
        // The strongest form of the same statement, and the one that would
        // catch a difference no query happened to ask about. It holds because
        // a segment carries no timestamp and no identity of its own: the same
        // documents in the same order are the same bytes.
        foreach (self::sources() as $label => [$source]) {
            $documents = $this->documents();

            $merged = $this->build($documents, $this->schema($source), split: true);
            $fresh  = $this->build($documents, $this->schema($source), split: false);

            $this->assertSame(
                md5($this->segmentBytes($fresh)),
                md5($this->segmentBytes($merged)),
                "$label: the merged segment differs from a freshly written one"
            );
        }
    }

    #[Test]
    public function deletions_are_gone_rather_than_filtered_out(): void
    {
        foreach (self::sources() as $label => [$source]) {
            $documents = $this->documents();
            $deleted   = ['sku-2', 'sku-7', 'jp-1'];

            $merged = $this->build($documents, $this->schema($source), split: true, delete: $deleted);

            // The oracle: the same documents, minus the deleted ones, indexed
            // in one commit. A deleted document must leave no trace — not in
            // the counts, not in the postings, not in the columns.
            $remaining = $documents;

            foreach ($deleted as $id) {
                unset($remaining[$id]);
            }

            $fresh = $this->build($remaining, $this->schema($source), split: false);

            $this->assertSame(0, $merged->stats()['deleted'], "$label: tombstones survived the merge");
            $this->assertSame(count($remaining), $merged->count());
            $this->assertSame(
                md5($this->segmentBytes($fresh)),
                md5($this->segmentBytes($merged)),
                "$label: a merge with deletions differs from indexing what is left"
            );
        }
    }

    #[Test]
    public function a_field_the_schema_does_not_store_keeps_its_terms_and_its_column(): void
    {
        // Spelled out on its own, because it is the bug: `description` and
        // `brand` are searchable and filterable but not stored, so re-indexing
        // from the docstore lost both. `optimize()` is explicit here, but the
        // merge that broke indexes in the wild was the automatic one.
        $engine = $this->build($this->documents(), $this->schema(['title', 'price']), split: true);

        $this->assertGreaterThan(0, $engine->search('cousu')->total, 'terms of an unstored field');
        $this->assertGreaterThan(0, $engine->search('', filters: Filter::eq('brand', 'Nike'))->total);
        $this->assertSame(['title', 'price'], array_keys($engine->get('sku-1')));
    }

    #[Test]
    public function an_index_storing_nothing_still_searches_after_a_merge(): void
    {
        $engine = $this->build($this->documents(), $this->schema(false), split: true);

        $this->assertSame(1, $engine->stats()['segments']);
        $this->assertGreaterThan(0, $engine->search('leather')->total);
        $this->assertGreaterThan(0, $engine->search('革靴')->total);
        $this->assertGreaterThan(0, $engine->search('', filters: Filter::eq('tags', 'summer'))->total);
        $this->assertSame([], $engine->get('sku-1'), 'ids are all such an index returns');
    }

    // =========================================================================
    // The edges of a merge
    // =========================================================================

    #[Test]
    public function merging_segments_that_are_wholly_deleted_leaves_an_empty_index(): void
    {
        $documents = ['a' => ['title' => 'Shoe'], 'b' => ['title' => 'Boot']];

        $engine = SearchEngine::open($this->directory(), $this->schema());
        $engine->put('a', $documents['a']);
        $engine->put('b', $documents['b']);
        $engine->delete('a');
        $engine->delete('b');
        $engine->optimize();

        $this->assertSame(0, $engine->count());
        $this->assertSame(0, $engine->stats()['segments']);
        $this->assertSame(0, $engine->search('shoe')->total);

        // And it is still writable afterwards.
        $engine->put('c', ['title' => 'Sneaker']);

        $this->assertSame(1, $engine->search('sneaker')->total);
    }

    #[Test]
    public function a_term_whose_every_document_was_deleted_disappears(): void
    {
        $engine = SearchEngine::open($this->directory(), $this->schema());
        $engine->put('a', ['title' => 'Unique unrepeatable wording']);
        $engine->put('b', ['title' => 'Brown leather shoe']);
        $engine->put('c', ['title' => 'Black leather boot']);

        $this->assertSame(1, $engine->search('unrepeatable')->total);

        $engine->delete('a');
        $engine->optimize();

        $this->assertSame(0, $engine->search('unrepeatable')->total);
        $this->assertSame(2, $engine->search('leather')->total);

        // Not merely unmatched: the term is no longer in the dictionary, which
        // is what keeps a heavily edited index from growing forever.
        $this->assertSame(2, $engine->count());
    }

    #[Test]
    public function a_single_searchable_field_carries_its_postings_too(): void
    {
        // A schema with one searchable field writes no field masks at all, so
        // there is nothing to copy and the carried mask has to be reconstructed
        // — bit 0, the only field there is.
        $schema = Schema::make()->text('title')->number('price')->source(false);

        $merged = $this->build($this->documents(), $schema, split: true);
        $fresh  = $this->build($this->documents(), $schema, split: false);

        $this->assertSame(
            md5($this->segmentBytes($fresh)),
            md5($this->segmentBytes($merged))
        );
        $this->assertGreaterThan(0, $merged->search('leather')->total);
    }

    #[Test]
    public function an_inferred_schema_survives_a_merge_unchanged(): void
    {
        // The schema is frozen at the first commit, so a merge must write the
        // frozen one rather than re-inferring from a subset of the documents —
        // inference on twelve documents can call a field keyword where the same
        // rule on four would not.
        $engine = SearchEngine::open($this->directory());
        $before = null;

        foreach (array_chunk($this->documents(), 5, true) as $chunk) {
            $engine->putMany($chunk);
            $before ??= $engine->schema()->toArray();
        }

        $engine->optimize();

        $this->assertSame($before, $engine->schema()->toArray());
        $this->assertGreaterThan(0, $engine->search('leather')->total);
    }
}
