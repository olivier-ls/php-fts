<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\IndexDirectory;
use Ols\PhpFts\Index\MergePolicy;
use Ols\PhpFts\Storage\Manifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An index across several segments and several commits.
 *
 * What is being tested here is not any one component but the commit protocol:
 * that a new state becomes visible all at once or not at all, that a reader
 * always sees a coherent index, and that a commit whose segment did not finish
 * being written is stepped over rather than believed.
 */
class IndexDirectoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_id_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    private function index(): IndexDirectory
    {
        return IndexDirectory::open($this->dir);
    }

    private function catalogue(): IndexDirectory
    {
        $index = $this->index();

        $index->putMany([
            'sku-1' => ['title' => 'Brown leather shoe', 'brand' => 'Adidas', 'price' => 129.90],
            'sku-2' => ['title' => 'Blue suede boot', 'brand' => 'Puma', 'price' => 89.00],
        ]);

        // A second commit, so every read below really crosses segments.
        $index->putMany([
            'sku-3' => ['title' => 'Brown suede shoe', 'brand' => 'Adidas', 'price' => 149.00],
            'sku-4' => ['title' => 'Red canvas sneaker', 'brand' => 'Nike', 'price' => 59.00],
        ]);

        return $index;
    }

    // =========================================================================
    // Commits
    // =========================================================================

    #[Test]
    public function an_empty_directory_is_an_empty_index(): void
    {
        $index = $this->index();

        $this->assertSame(0, $index->count());
        $this->assertSame(0, $index->generation());
        $this->assertSame(0, $index->search('anything')->total);
    }

    #[Test]
    public function a_batch_becomes_one_segment_and_one_commit(): void
    {
        $index = $this->index();

        $index->putMany([
            'sku-1' => ['title' => 'One'],
            'sku-2' => ['title' => 'Two'],
            'sku-3' => ['title' => 'Three'],
        ]);

        $this->assertSame(3, $index->count());
        $this->assertSame(1, $index->generation());
        $this->assertSame(1, $index->stats()['segments'], 'a batch is a single segment');
    }

    #[Test]
    public function each_commit_adds_a_generation(): void
    {
        $index = $this->index();

        $index->put('sku-1', ['title' => 'One']);
        $index->put('sku-2', ['title' => 'Two']);

        $this->assertSame(2, $index->generation());
        $this->assertSame(2, $index->stats()['segments']);
        $this->assertSame(2, $index->count());
    }

    #[Test]
    public function a_committed_state_is_visible_to_another_reader(): void
    {
        $this->catalogue();

        $reader = $this->index();

        $this->assertSame(4, $reader->count());
        $this->assertSame(2, $reader->generation());
        $this->assertSame('Blue suede boot', $reader->get('sku-2')['title']);
    }

    #[Test]
    public function a_commit_is_never_overwritten(): void
    {
        $this->index()->put('sku-1', ['title' => 'One']);

        $manifest = Manifest::read($this->dir, 1);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/refusing to overwrite/i');

        $manifest->write($this->dir);
    }

    // =========================================================================
    // Rollback — the reason commits are numbered
    // =========================================================================

    #[Test]
    public function a_commit_whose_segment_is_incomplete_is_stepped_over(): void
    {
        $index = $this->index();
        $index->putMany(['sku-1' => ['title' => 'First batch']]);
        $index->putMany(['sku-2' => ['title' => 'Second batch']]);

        $this->assertSame(2, $index->generation());
        $this->assertSame(2, $index->count());

        // The machine loses power between writing the segment and finishing it.
        $newest = Manifest::read($this->dir, 2)->segments;
        $path   = $this->dir . '/' . end($newest)['name'] . '.fts';

        $handle = fopen($path, 'r+b');
        ftruncate($handle, (int) (filesize($path) / 2));
        fclose($handle);

        // The truncated segment fails its length check, so generation 2 cannot
        // be trusted and generation 1 is used instead. The index is smaller
        // than it was, and entirely coherent.
        $reopened = $this->index();

        $this->assertSame(1, $reopened->generation(), 'fell back to the previous commit');
        $this->assertSame(1, $reopened->count());
        $this->assertSame('First batch', $reopened->get('sku-1')['title']);
        $this->assertNull($reopened->get('sku-2'), 'the lost commit is simply not there');
    }

    #[Test]
    public function a_commit_referring_to_a_missing_segment_is_stepped_over(): void
    {
        $index = $this->index();
        $index->putMany(['sku-1' => ['title' => 'First']]);
        $index->putMany(['sku-2' => ['title' => 'Second']]);

        $newest = Manifest::read($this->dir, 2)->segments;
        unlink($this->dir . '/' . end($newest)['name'] . '.fts');

        $this->assertSame(1, $this->index()->generation());
    }

    #[Test]
    public function an_unreadable_manifest_is_stepped_over(): void
    {
        $index = $this->index();
        $index->putMany(['sku-1' => ['title' => 'First']]);
        $index->putMany(['sku-2' => ['title' => 'Second']]);

        file_put_contents($this->dir . '/commit.2', 'not json at all');

        $this->assertSame(1, $this->index()->generation());
        $this->assertSame(1, $this->index()->count());
    }

    // =========================================================================
    // Searching across segments
    // =========================================================================

    #[Test]
    public function a_search_spans_every_segment(): void
    {
        $result = $this->catalogue()->search('brown');

        $this->assertSame(2, $result->total, 'one match in each segment');
        $this->assertEqualsCanonicalizing(
            ['sku-1', 'sku-3'],
            array_map(static fn($hit): string => $hit->id, $result->hits)
        );
    }

    #[Test]
    public function totals_add_up_across_segments(): void
    {
        $this->assertSame(4, $this->catalogue()->search('')->total);
    }

    #[Test]
    public function keyword_facets_add_up_across_segments(): void
    {
        $facets = $this->catalogue()->search('', facets: ['brand'])->facets['brand'];

        $this->assertSame(2, $facets['Adidas'], 'one Adidas in each segment');
        $this->assertSame(1, $facets['Puma']);
        $this->assertSame(1, $facets['Nike']);
        $this->assertSame(4, array_sum($facets));
    }

    #[Test]
    public function numeric_facets_are_recombined_across_segments(): void
    {
        // Statistics cannot simply be summed: the minimum of the whole is the
        // smallest of the minima, and the mean has to be recomputed.
        $stats = $this->catalogue()->search('', facets: ['price'])->facets['price'];

        $this->assertSame(4, $stats['count']);
        $this->assertSame(59.0, $stats['min'], 'the cheapest is in the second segment');
        $this->assertSame(149.0, $stats['max']);
        $this->assertSame((129.90 + 89.00 + 149.00 + 59.00) / 4, $stats['avg']);
    }

    #[Test]
    public function a_page_is_the_best_across_segments_not_the_best_of_one(): void
    {
        $index = $this->index();

        // Spread ten documents over five commits, so ranking has to cross them.
        for ($i = 0; $i < 10; $i++) {
            $index->put("sku-$i", ['title' => 'chaussure en cuir marron numero ' . $i]);

            if ($i % 2 === 1) {
                $index->put("extra-$i", ['title' => 'sandale']);
            }
        }

        $result = $index->search('chaussure cuir', limit: 3);

        $this->assertSame(10, $result->total);
        $this->assertCount(3, $result->hits);

        foreach ($result->hits as $hit) {
            $this->assertStringContainsString('chaussure', $hit->document['title']);
        }
    }

    #[Test]
    public function pages_do_not_overlap_across_segments(): void
    {
        $index = $this->index();

        for ($i = 0; $i < 6; $i++) {
            $index->put("sku-$i", ['title' => "produit $i en cuir"]);
        }

        $first  = $index->search('cuir', limit: 3, offset: 0);
        $second = $index->search('cuir', limit: 3, offset: 3);

        $this->assertSame(6, $first->total);
        $this->assertSame(6, $second->total);

        $firstIds  = array_map(static fn($hit): string => $hit->id, $first->hits);
        $secondIds = array_map(static fn($hit): string => $hit->id, $second->hits);

        $this->assertCount(3, $firstIds);
        $this->assertCount(3, $secondIds);
        $this->assertSame([], array_intersect($firstIds, $secondIds));
    }

    #[Test]
    public function filters_apply_across_segments(): void
    {
        $result = $this->catalogue()->search('', filters: [
            ['field' => 'price', 'op' => '<=', 'value' => 100],
        ]);

        $this->assertSame(2, $result->total);
        $this->assertEqualsCanonicalizing(
            ['sku-2', 'sku-4'],
            array_map(static fn($hit): string => $hit->id, $result->hits)
        );
    }

    // =========================================================================
    // Replacing and deleting
    // =========================================================================

    #[Test]
    public function replacing_a_document_leaves_exactly_one_copy_visible(): void
    {
        $index = $this->catalogue();

        $index->put('sku-1', ['title' => 'Black leather shoe', 'brand' => 'Adidas', 'price' => 139.00]);

        $this->assertSame(4, $index->count(), 'still four documents, not five');
        $this->assertSame('Black leather shoe', $index->get('sku-1')['title']);

        // The old copy is gone from the index as well as from get().
        $brown = $index->search('brown');

        $this->assertSame(1, $brown->total);
        $this->assertSame('sku-3', $brown->hits[0]->id);
    }

    #[Test]
    public function replacing_happens_in_a_single_commit(): void
    {
        $index = $this->catalogue();
        $before = $index->generation();

        $index->put('sku-1', ['title' => 'Replaced']);

        $this->assertSame(
            $before + 1,
            $index->generation(),
            'the old copy going out and the new one coming in are one commit'
        );
    }

    #[Test]
    public function deleting_removes_a_document_from_everything(): void
    {
        $index = $this->catalogue();

        $this->assertTrue($index->delete('sku-2'));

        $this->assertSame(3, $index->count());
        $this->assertNull($index->get('sku-2'));
        $this->assertFalse($index->has('sku-2'));

        // "blue" belongs to that document alone. A partial phrase would not
        // isolate it: see the term-matching test below.
        $this->assertSame(0, $index->search('blue')->total);
        $this->assertArrayNotHasKey('Puma', $index->search('', facets: ['brand'])->facets['brand']);
    }

    #[Test]
    public function a_document_must_hold_enough_of_the_query_not_merely_part_of_it(): void
    {
        // "suede boot" shares five of its nine trigrams with "Brown suede shoe",
        // which is under the threshold. So once the boot is deleted the query
        // finds nothing, rather than dragging in a shoe because one word
        // overlapped.
        //
        // An earlier version took every term, or failing that any term. That
        // decision was made per segment, so the answer depended on how the
        // documents happened to be distributed — and merging changed it.
        $index = $this->catalogue();
        $index->delete('sku-2');

        $this->assertSame(0, $index->search('suede boot')->total);
        $this->assertSame(1, $index->search('suede shoe')->total, 'the shoe is still findable');
    }

    #[Test]
    public function the_threshold_still_tolerates_typos(): void
    {
        // The balance the threshold has to strike: "lether sho" misspells both
        // words and still shares six of its nine trigrams with "leather shoe".
        $index = $this->catalogue();

        $result = $index->search('lether sho');

        $this->assertSame('sku-1', $result->hits[0]->id);
    }

    #[Test]
    public function deleting_an_unknown_id_changes_nothing(): void
    {
        $index  = $this->catalogue();
        $before = $index->generation();

        $this->assertFalse($index->delete('nope'));
        $this->assertSame($before, $index->generation(), 'no commit for a no-op');
        $this->assertSame(4, $index->count());
    }

    #[Test]
    public function deletions_survive_reopening(): void
    {
        $this->catalogue()->delete('sku-2');

        $reopened = $this->index();

        $this->assertSame(3, $reopened->count());
        $this->assertSame(1, $reopened->stats()['deleted']);
        $this->assertNull($reopened->get('sku-2'));
    }

    #[Test]
    public function a_deleted_id_can_be_written_again(): void
    {
        $index = $this->catalogue();

        $index->delete('sku-2');
        $index->put('sku-2', ['title' => 'Back again', 'brand' => 'Puma', 'price' => 99.00]);

        $this->assertSame(4, $index->count());
        $this->assertSame('Back again', $index->get('sku-2')['title']);
    }

    // =========================================================================
    // Bookkeeping
    // =========================================================================

    #[Test]
    public function stats_describe_the_index(): void
    {
        $index = $this->catalogue();
        $index->delete('sku-1');

        $stats = $index->stats();

        $this->assertSame(3, $stats['documents']);
        $this->assertSame(1, $stats['deleted']);
        $this->assertSame(2, $stats['segments']);
        $this->assertSame(3, $stats['generation']);
        $this->assertGreaterThan(0, $stats['bytes']);
    }

    #[Test]
    public function an_index_at_rest_is_a_manifest_and_a_segment(): void
    {
        $this->index()->putMany([
            'sku-1' => ['title' => 'One'],
            'sku-2' => ['title' => 'Two'],
        ]);

        $files = array_map('basename', glob($this->dir . '/*') ?: []);

        $this->assertContains('commit.1', $files);
        $this->assertCount(2, $files, 'one manifest and one segment');
    }

    // =========================================================================
    // Merging
    // =========================================================================

    /**
     * @return array<string, array<string, mixed>>
     */
    private function products(int $count): array
    {
        $brands   = ['Adidas', 'Puma', 'Nike'];
        $products = [];

        for ($i = 0; $i < $count; $i++) {
            $products["sku-$i"] = [
                'title' => "chaussure modele $i en cuir",
                'brand' => $brands[$i % 3],
                'price' => 50.0 + ($i % 200),
            ];
        }

        return $products;
    }

    #[Test]
    public function segments_collapse_as_writes_accumulate(): void
    {
        // Written one at a time, an index does not end up with one segment per
        // document: the tiers collapse as the loop runs.
        $index = IndexDirectory::open($this->dir, new MergePolicy(segmentsPerTier: 4, tierFactor: 4));

        foreach ($this->products(40) as $id => $product) {
            $index->put($id, $product);
        }

        $this->assertSame(40, $index->count());
        $this->assertLessThan(10, $index->stats()['segments'], '40 writes must not leave 40 segments');
    }

    #[Test]
    public function optimize_reduces_the_index_to_one_segment(): void
    {
        $index = $this->index();

        foreach (array_chunk($this->products(20), 4, true) as $batch) {
            $index->putMany($batch);
        }

        $this->assertGreaterThan(1, $index->stats()['segments']);

        $index->optimize();

        $this->assertSame(1, $index->stats()['segments']);
        $this->assertSame(20, $index->count());
        $this->assertFalse($index->stats()['needsOptimize']);
    }

    #[Test]
    public function a_merge_does_not_change_what_a_search_returns(): void
    {
        // The guarantee that matters. A merge rewrites every structure in the
        // index and renumbers every document; none of that may be observable.
        $index = $this->index();

        foreach (array_chunk($this->products(30), 5, true) as $batch) {
            $index->putMany($batch);
        }

        $index->delete('sku-3');
        $index->delete('sku-17');

        $queries = ['cuir', 'chaussure modele 12', 'modele', 'cuire', ''];
        $before  = [];

        foreach ($queries as $query) {
            $result = $index->search($query, limit: 50, facets: ['brand', 'price']);

            $before[$query] = [
                'total'  => $result->total,
                'ids'    => array_map(static fn($hit): string => $hit->id, $result->hits),
                'facets' => $result->facets,
            ];
        }

        $index->optimize();
        $this->assertSame(1, $index->stats()['segments'], 'the merge really happened');

        foreach ($queries as $query) {
            $result = $index->search($query, limit: 50, facets: ['brand', 'price']);

            $this->assertSame($before[$query]['total'], $result->total, "total for [$query]");
            $this->assertEqualsCanonicalizing(
                $before[$query]['ids'],
                array_map(static fn($hit): string => $hit->id, $result->hits),
                "documents for [$query]"
            );
            $this->assertEquals($before[$query]['facets'], $result->facets, "facets for [$query]");
        }
    }

    #[Test]
    public function ids_survive_a_merge(): void
    {
        // What 1.x got wrong: it made a document's byte offset its identity, so
        // compaction silently invalidated every id an application had stored.
        // Here a merge renumbers everything internally and no id changes.
        $index = $this->index();

        foreach (array_chunk($this->products(20), 4, true) as $batch) {
            $index->putMany($batch);
        }

        $index->optimize();

        for ($i = 0; $i < 20; $i++) {
            $this->assertNotNull($index->get("sku-$i"), "sku-$i survived the merge");
            $this->assertSame("chaussure modele $i en cuir", $index->get("sku-$i")['title']);
        }
    }

    #[Test]
    public function a_merge_drops_deleted_documents_for_good(): void
    {
        $index = $this->index();
        $index->putMany($this->products(10));

        for ($i = 0; $i < 6; $i++) {
            $index->delete("sku-$i");
        }

        $this->assertSame(4, $index->count());

        $index->optimize();

        $stats = $index->stats();

        $this->assertSame(4, $stats['documents']);
        $this->assertSame(0, $stats['deleted'], 'the tombstones are gone, not just ignored');
        $this->assertSame(1, $stats['segments']);
        $this->assertNull($index->get('sku-0'));
    }

    #[Test]
    public function the_field_schema_is_frozen_and_survives_merging(): void
    {
        // Inference depends on the batch, so a merge left to re-infer could turn
        // a keyword field into a text one — and a filter that worked before the
        // merge would fail after it.
        $index = $this->index();

        $index->putMany($this->products(10));
        $frozen = $index->stats()['fields'];

        $this->assertSame('keyword', $frozen['brand']);

        for ($i = 10; $i < 300; $i++) {
            $index->putMany(["sku-$i" => [
                'title' => "chaussure modele $i en cuir",
                'brand' => 'Adidas',
                'price' => 99.0,
            ]]);
        }

        $index->optimize();

        $this->assertSame($frozen, $index->stats()['fields'], 'the schema did not drift');

        $this->assertGreaterThan(
            0,
            $index->search('', filters: [['field' => 'brand', 'op' => '=', 'value' => 'Adidas']])->total,
            'and the filter that depends on it still works'
        );
    }

    #[Test]
    public function optimizing_an_optimal_index_does_nothing(): void
    {
        $index = $this->index();
        $index->putMany($this->products(5));

        $before = $index->generation();

        $index->optimize();

        $this->assertSame($before, $index->generation(), 'no commit for no work');
    }

    #[Test]
    public function retired_segment_files_are_collected_once_their_grace_has_passed(): void
    {
        $index = $this->index();

        foreach (array_chunk($this->products(20), 4, true) as $batch) {
            $index->putMany($batch);
        }

        $index->optimize();

        $this->assertSame(1, $index->stats()['segments']);
        $this->assertGreaterThan(1, count(glob($this->dir . '/seg_*.fts') ?: []), 'retired files still there');

        // Age the retirements past the grace period.
        $generation = Manifest::generations($this->dir)[0];
        $path       = $this->dir . '/commit.' . $generation;
        $manifest   = json_decode((string) file_get_contents($path), true);

        foreach ($manifest['retired'] as $position => $_) {
            $manifest['retired'][$position]['at'] -= Manifest::GRACE_SECONDS * 2;
        }

        file_put_contents($path, (string) json_encode($manifest));

        // Opening collects them — an index written once and only read afterwards
        // would otherwise keep every intermediate segment for good.
        $reopened = IndexDirectory::open($this->dir);

        $this->assertCount(1, glob($this->dir . '/seg_*.fts') ?: []);
        $this->assertSame(20, $reopened->count(), 'and the index is still whole');
        $this->assertSame(20, $reopened->search('cuir')->total);
    }

    #[Test]
    public function old_commit_files_are_forgotten_but_a_few_are_kept(): void
    {
        $index = $this->index();

        for ($i = 0; $i < 12; $i++) {
            $index->put("sku-$i", ['title' => "produit $i"]);
        }

        $generations = Manifest::generations($this->dir);

        $this->assertLessThanOrEqual(3, count($generations), 'old commits are removed');
        $this->assertGreaterThanOrEqual(2, count($generations), 'but rollback has somewhere to land');
    }
}
