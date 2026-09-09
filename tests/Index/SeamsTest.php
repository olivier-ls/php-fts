<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\IndexDirectory;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Three places where two correct pieces met and disagreed.
 *
 * None of these is a bug inside a component — every one of them was in the
 * *seam*, which is why the components' own tests all passed over them. They
 * are grouped because they share that shape, and because each one was silent:
 * no exception, no warning, a plausible-looking answer.
 *
 *   - statistics : a live document count met sums measured over written ones
 *   - inference  : a frozen guess met a batch holding a field it never saw
 *   - facets     : a merge met a term count and read it as statistics
 */
class SeamsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_seams_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    // -------------------------------------------------------------------------
    // The statistics seam
    // -------------------------------------------------------------------------

    #[Test]
    public function a_document_frequency_never_exceeds_the_count_it_is_weighed_against(): void
    {
        // The invariant BM25 rests on, and the one thing the two sides of
        // `idf()` must agree about. Broken, it is not a rounding error:
        // Scorer::idf() clamps df to N, so a term whose df ran past N came out
        // at ln(1 + 0.5/(N + 0.5)) — zero, to three decimal places. Every IDF
        // in the query collapsed with it, `required` collapsed too, and the
        // ranking became arbitrary while every test stayed green.
        //
        // Asserted directly on the seam rather than through a score, because
        // the damage is a discontinuity: it is invisible until the clamp
        // fires, and total once it does.
        $index = $this->withUnmergedTombstones();

        $method = new \ReflectionMethod(IndexDirectory::class, 'statisticsOver');
        $terms  = ['alpha', 'beta', 'gamma', 'delta'];

        /** @var \Ols\PhpFts\Query\CollectionStatistics $statistics */
        $statistics = $method->invoke($index, $terms);

        $this->assertGreaterThan(
            0,
            $statistics->documentCount,
            'the fixture must actually hold documents'
        );

        foreach ($terms as $term) {
            $this->assertLessThanOrEqual(
                $statistics->documentCount,
                $statistics->documentFrequency($term),
                "df('$term') ran past the document count it is weighed against"
            );
        }
    }

    #[Test]
    public function the_tombstones_the_previous_test_needs_are_really_still_there(): void
    {
        // If a future merge policy collected them, the test above would pass
        // for the wrong reason and assert nothing at all.
        $stats = $this->withUnmergedTombstones()->stats();

        $this->assertGreaterThan(0, $stats['deleted'], 'the fixture must hold unmerged tombstones');
    }

    #[Test]
    public function a_common_word_keeps_a_usable_weight_across_deletions(): void
    {
        // The observable half. `alpha` sits in most of the corpus and `delta`
        // in little of it, so a query naming both must still rank the document
        // holding the rarer one first. With the collapse above, every term
        // weighed the same — which is to say nothing — and the order was
        // whatever the walk happened to produce.
        $index = $this->withUnmergedTombstones();

        $result = $index->search('alpha delta', limit: 10);

        $this->assertGreaterThan(0, $result->total, 'the query must still match');
        $this->assertGreaterThan(
            0.0,
            $result->hits[0]->score,
            'a collapsed IDF shows up here as a score of zero'
        );
    }

    // -------------------------------------------------------------------------
    // The inference seam
    // -------------------------------------------------------------------------

    #[Test]
    public function a_field_an_inferred_schema_never_saw_is_refused_rather_than_dropped(): void
    {
        // It used to be stored and never indexed. `$hit->document['colour']`
        // came back, `search('rouge')` found nothing, and nothing anywhere
        // said so — for the life of the index.
        $engine = SearchEngine::open($this->dir);

        $engine->put('sku-1', ['title' => 'Chaussure en cuir', 'price' => 129.90]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches("/field 'colour'/");

        $engine->put('sku-2', ['title' => 'Botte en daim', 'price' => 89.00, 'colour' => 'rouge']);
    }

    #[Test]
    public function the_refusal_names_the_document_and_the_remedy(): void
    {
        $engine = SearchEngine::open($this->dir);
        $engine->put('sku-1', ['title' => 'Chaussure en cuir']);

        try {
            $engine->put('sku-2', ['title' => 'Botte en daim', 'colour' => 'rouge']);
            $this->fail('the field should have been refused');
        } catch (StorageException $refused) {
            $this->assertStringContainsString("'sku-2'", $refused->getMessage());
            $this->assertStringContainsString('Declare a schema', $refused->getMessage());
        }
    }

    #[Test]
    public function a_refused_field_leaves_the_index_exactly_as_it_was(): void
    {
        $engine = SearchEngine::open($this->dir);
        $engine->put('sku-1', ['title' => 'Chaussure en cuir']);

        try {
            $engine->putMany([
                'sku-2' => ['title' => 'Botte en daim'],
                'sku-3' => ['title' => 'Basket rouge', 'colour' => 'rouge'],
            ]);
        } catch (StorageException) {
            // Expected: a batch is one commit.
        }

        $this->assertSame(1, $engine->count(), 'the batch must land whole or not at all');
        $this->assertNull($engine->get('sku-2'));
    }

    #[Test]
    public function a_declared_schema_still_stores_a_field_it_does_not_mention(): void
    {
        // The feature the refusal must not have eaten. A field a *declared*
        // schema leaves out is stored-only on purpose, and is documented as
        // such — which is exactly why the check asks whether the schema was
        // inferred, and not merely whether it knows the field.
        $engine = SearchEngine::open($this->dir, Schema::make()->text('title'));

        $engine->put('sku-1', ['title' => 'Chaussure en cuir', 'image_url' => 'https://example.test/1.jpg']);

        $this->assertSame(
            'https://example.test/1.jpg',
            $engine->get('sku-1')['image_url'] ?? null
        );
    }

    #[Test]
    public function a_null_value_for_an_unknown_field_is_still_legal(): void
    {
        // A missing field and an explicit null are always legal, and neither
        // would have produced a term — so neither is worth refusing.
        $engine = SearchEngine::open($this->dir);
        $engine->put('sku-1', ['title' => 'Chaussure en cuir']);
        $engine->put('sku-2', ['title' => 'Botte en daim', 'colour' => null]);

        $this->assertSame(2, $engine->count());
    }

    // -------------------------------------------------------------------------
    // The facet seam
    // -------------------------------------------------------------------------

    #[Test]
    public function a_term_facet_whose_values_look_like_statistics_still_counts_terms(): void
    {
        // `count` and `sum` are the keys NumericColumn::stats() returns. They
        // are also two perfectly ordinary tags. A facet holding both was read
        // as statistics, `min` was not there, and the facet came back as an
        // undefined-key warning over nonsense. Two segments were needed for
        // it, which is why nothing had noticed.
        $engine = SearchEngine::open($this->dir);

        $engine->putMany([
            'a' => ['title' => 'Rapport annuel', 'tags' => ['count', 'sum']],
            'b' => ['title' => 'Rapport mensuel', 'tags' => ['count']],
        ]);

        // A second commit, so the facet really is merged across segments.
        $engine->putMany([
            'c' => ['title' => 'Rapport trimestriel', 'tags' => ['count', 'min']],
            'd' => ['title' => 'Bilan', 'tags' => ['sum']],
        ]);

        $facets = $engine->search('', facets: ['tags'])->facets;

        $this->assertSame(3, $facets['tags']['count'] ?? null, 'a, b and c are tagged count');
        $this->assertSame(2, $facets['tags']['sum'] ?? null, 'a and d are tagged sum');
        $this->assertSame(1, $facets['tags']['min'] ?? null, 'c alone is tagged min');
    }

    #[Test]
    public function a_numeric_facet_is_still_recombined_across_segments(): void
    {
        // The other half: the branch the shape-sniffing used to select is the
        // one that must still be selected, and its mean has to be worked out
        // again from the totals rather than averaged.
        $engine = SearchEngine::open($this->dir);

        $engine->putMany([
            'a' => ['title' => 'Un', 'price' => 10.0],
            'b' => ['title' => 'Deux', 'price' => 20.0],
            'c' => ['title' => 'Trois', 'price' => 30.0],
        ]);

        $engine->putMany([
            'd' => ['title' => 'Quatre', 'price' => 100.0],
        ]);

        $stats = $engine->search('', facets: ['price'])->facets['price'];

        $this->assertSame(4, $stats['count']);
        $this->assertSame(10.0, $stats['min']);
        $this->assertSame(100.0, $stats['max']);
        $this->assertSame(160.0, $stats['sum']);
        $this->assertSame(40.0, $stats['avg'], 'the mean of two means is not the mean');
    }

    // -------------------------------------------------------------------------

    /**
     * An index holding tombstones the merge policy will not collect.
     *
     * One segment, and a deletion ratio kept under `deletedRatioThreshold` so
     * that nothing rewrites it: a single segment is no tier to collapse, and
     * three deleted out of twelve is not enough to be worth reclaiming. So the
     * tombstones stay, which is the state the statistics have to survive.
     */
    private function withUnmergedTombstones(): IndexDirectory
    {
        $index     = IndexDirectory::open($this->dir);
        $documents = [];

        // `alpha` nearly everywhere, `delta` in one document: a query naming
        // both has something to weigh.
        for ($i = 0; $i < 12; $i++) {
            $words = ['alpha'];

            if ($i % 3 === 0) {
                $words[] = 'beta';
            }

            if ($i % 5 === 0) {
                $words[] = 'gamma';
            }

            if ($i === 7) {
                $words[] = 'delta';
            }

            $documents["doc-$i"] = ['title' => implode(' ', $words)];
        }

        $index->putMany($documents);

        $index->delete('doc-1');
        $index->delete('doc-2');
        $index->delete('doc-4');

        return $index;
    }
}
