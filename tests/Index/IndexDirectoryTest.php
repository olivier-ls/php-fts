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
    // Ranking
    // =========================================================================

    #[Test]
    public function a_short_document_outranks_a_long_one_holding_the_same_words(): void
    {
        // What counting matched terms got wrong: both documents hold every term
        // of the query, so both used to score identically and whichever came
        // first won. A long description collides with more of a query by
        // accident, and BM25's length normalisation is what says so.
        $index = $this->index();

        $index->putMany([
            'court' => ['title' => 'Chaussure cuir marron'],
            'long'  => ['title' => 'chaussure de ville elegante en cuir marron veritable tannage vegetal '
                . str_repeat('confort qualite durabilite finition soignee coloris naturel ', 12)],
            'autre' => ['title' => 'Botte cuir noir'],
        ]);

        $result = $index->search('cuir marron');
        $scores = [];

        foreach ($result as $hit) {
            $scores[$hit->id] = $hit->score;
        }

        $this->assertArrayHasKey('court', $scores);
        $this->assertArrayHasKey('long', $scores);
        $this->assertGreaterThan($scores['long'], $scores['court'], 'the short one wins');
    }

    #[Test]
    public function matching_a_rare_word_counts_for_more_than_a_common_one(): void
    {
        $index = $this->index();

        $documents = [];

        for ($i = 0; $i < 50; $i++) {
            $documents["commun-$i"] = ['title' => "chaussure cuir modele $i"];
        }

        $documents['rare'] = ['title' => 'chaussure zibeline'];

        $index->putMany($documents);

        $result = $index->search('chaussure zibeline');

        $this->assertSame('rare', $result->hits[0]->id);
    }

    #[Test]
    public function scores_are_positive_numbers(): void
    {
        $result = $this->catalogue()->search('brown');

        foreach ($result as $hit) {
            $this->assertIsFloat($hit->score);
            $this->assertGreaterThan(0.0, $hit->score);
        }
    }

    #[Test]
    public function statistics_are_taken_across_segments_not_per_segment(): void
    {
        // IDF asks how rare a term is in the index. If each segment answered
        // from its own dictionary, the same document would score differently
        // depending on where it landed — and a merge would change the ranking.
        //
        // Here the rare document sits alone in its own segment while the common
        // ones fill another, which is exactly the arrangement that would expose
        // per-segment statistics.
        $index = $this->index();

        $common = [];

        for ($i = 0; $i < 40; $i++) {
            $common["commun-$i"] = ['title' => "chaussure cuir modele $i"];
        }

        $index->putMany($common);
        $index->putMany(['rare' => ['title' => 'chaussure zibeline']]);

        $this->assertGreaterThan(1, $index->stats()['segments'], 'really two segments');

        $before = $index->search('chaussure zibeline')->hits[0];

        $index->optimize();
        $this->assertSame(1, $index->stats()['segments']);

        $after = $index->search('chaussure zibeline')->hits[0];

        $this->assertSame($before->id, $after->id);
        $this->assertSame(
            round($before->score, 6),
            round($after->score, 6),
            'the score did not depend on how the documents were spread'
        );
    }

    // =========================================================================
    // Field weighting
    // =========================================================================

    private function twoFieldCatalogue(): IndexDirectory
    {
        $index = $this->index();

        $index->putMany([
            'titre'  => [
                'title'       => 'Cuir veritable',
                'description' => 'Chaussure de ville confortable et durable pour toutes occasions',
            ],
            'descr'  => [
                'title'       => 'Sandale ete',
                'description' => 'Semelle souple, dessus en cuir veritable tanne vegetal, finition soignee',
            ],
            'autre1' => ['title' => 'Botte pluie', 'description' => 'Caoutchouc impermeable'],
            'autre2' => ['title' => 'Basket toile', 'description' => 'Textile respirant'],
        ]);

        return $index;
    }

    /**
     * @param array<string, float> $boosts
     * @return array<string, float> id => score
     */
    private function scores(IndexDirectory $index, string $query, array $boosts = []): array
    {
        $scores = [];

        foreach ($index->search($query, boosts: $boosts) as $hit) {
            $scores[$hit->id] = $hit->score;
        }

        return $scores;
    }

    #[Test]
    public function boosting_a_field_can_reverse_the_ranking(): void
    {
        // The same two documents, one holding the query in its title and one in
        // its description. Which comes first is now the caller's decision.
        $index = $this->twoFieldCatalogue();

        $neutral     = $this->scores($index, 'cuir veritable');
        $titleFirst  = $this->scores($index, 'cuir veritable', ['title' => 5.0]);
        $bodyFirst   = $this->scores($index, 'cuir veritable', ['description' => 5.0]);

        $this->assertGreaterThan($neutral['descr'], $neutral['titre'], 'the shorter field wins by default');
        $this->assertGreaterThan($titleFirst['descr'], $titleFirst['titre']);
        $this->assertGreaterThan($bodyFirst['titre'], $bodyFirst['descr'], 'boosting the description flips it');
    }

    #[Test]
    public function no_boosts_means_every_field_weighs_the_same(): void
    {
        $index = $this->twoFieldCatalogue();

        $this->assertSame(
            $this->scores($index, 'cuir veritable'),
            $this->scores($index, 'cuir veritable', ['title' => 1.0, 'description' => 1.0])
        );
    }

    #[Test]
    public function a_boost_naming_an_unknown_field_is_ignored(): void
    {
        // A caller reusing one set of boosts across several indexes should not
        // have to know which fields each one holds.
        $index = $this->twoFieldCatalogue();

        $this->assertSame(
            $this->scores($index, 'cuir veritable'),
            $this->scores($index, 'cuir veritable', ['nonexistent' => 9.0])
        );
    }

    #[Test]
    public function a_list_of_tags_is_searchable_and_can_be_boosted(): void
    {
        // 1.x indexed array fields but dropped them the moment any boost was
        // passed, so `tags` silently stopped contributing — and its own demo
        // passed `'tags' => 1.5`, which therefore did nothing at all.
        $index = $this->index();

        $index->putMany([
            'a' => ['title' => 'Chaussure classique', 'tags' => ['luxe', 'artisanal']],
            'b' => ['title' => 'Chaussure sport', 'tags' => ['course', 'leger']],
        ]);

        $this->assertSame('a', $index->search('luxe')->hits[0]->id, 'tags are searchable');

        $boosted = $this->scores($index, 'luxe', ['tags' => 5.0]);
        $plain   = $this->scores($index, 'luxe');

        $this->assertGreaterThan($plain['a'], $boosted['a'], 'and boosting them changes the score');
    }

    #[Test]
    public function boosted_scores_survive_a_merge_unchanged(): void
    {
        // Field bits are assigned from the frozen schema in a fixed order, so a
        // mask written by one segment means the same thing to a merged one. If
        // it did not, boosts would quietly start weighting the wrong field.
        $index = $this->index();

        $index->putMany([
            'titre' => ['title' => 'Cuir veritable', 'description' => 'Chaussure de ville confortable'],
        ]);
        $index->putMany([
            'descr' => ['title' => 'Sandale ete', 'description' => 'Dessus en cuir veritable tanne'],
        ]);

        $this->assertGreaterThan(1, $index->stats()['segments']);

        $before = $this->scores($index, 'cuir veritable', ['title' => 5.0]);

        $index->optimize();
        $this->assertSame(1, $index->stats()['segments']);

        $after = $this->scores($index, 'cuir veritable', ['title' => 5.0]);

        $this->assertSame(array_keys($before), array_keys($after), 'same order');

        foreach ($before as $id => $score) {
            $this->assertEqualsWithDelta($score, $after[$id], 1.0E-9, "score of $id");
        }
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
