<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\SegmentIndex;
use Ols\PhpFts\Index\SegmentIndexWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Everything, together: documents in, searches out.
 *
 * The components each have their own tests; this file is about the seams
 * between them — that the analyzer used for indexing is the one used for
 * querying, that a term dictionary entry really points at its posting list,
 * that a column really lines up with the document numbers postings produced,
 * and that a document number really addresses the right record in the store.
 */
class SegmentIndexTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/fts_e2e_' . uniqid();
        $this->path = $this->dir . '/seg.fts';

        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    private function catalogue(): SegmentIndex
    {
        $writer = new SegmentIndexWriter();

        $writer->put('sku-1', ['title' => 'Brown leather shoe', 'brand' => 'Adidas', 'price' => 129.90, 'stock' => 42]);
        $writer->put('sku-2', ['title' => 'Blue suede boot', 'brand' => 'Puma', 'price' => 89.00, 'stock' => 0]);
        $writer->put('sku-3', ['title' => 'Brown suede shoe', 'brand' => 'Adidas', 'price' => 149.00, 'stock' => 7]);
        $writer->put('sku-4', ['title' => 'Red canvas sneaker', 'brand' => 'Nike', 'price' => 59.00, 'stock' => 12]);
        $writer->put('jp-1', ['title' => '革靴 ブラウン', 'brand' => 'アディダス', 'price' => 19800.0, 'stock' => 3]);

        $writer->write($this->path);

        return SegmentIndex::open($this->path);
    }

    // =========================================================================
    // Searching
    // =========================================================================

    #[Test]
    public function a_search_finds_the_expected_document(): void
    {
        $result = $this->catalogue()->search('leather');

        $this->assertSame(1, $result->total);
        $this->assertSame('sku-1', $result->hits[0]->id);
        $this->assertSame('Brown leather shoe', $result->hits[0]->document['title']);
    }

    #[Test]
    public function a_search_tolerates_typos(): void
    {
        // The reason for n-grams: "lether sho" shares most of its trigrams with
        // "leather shoe" even though no word is spelled correctly.
        $result = $this->catalogue()->search('lether sho');

        $this->assertSame('sku-1', $result->hits[0]->id);
    }

    #[Test]
    public function a_search_finds_several_documents_and_reports_an_exact_total(): void
    {
        $result = $this->catalogue()->search('brown');

        $this->assertSame(2, $result->total);
        $this->assertEqualsCanonicalizing(
            ['sku-1', 'sku-3'],
            array_map(static fn($hit): string => $hit->id, $result->hits)
        );
    }

    #[Test]
    public function japanese_documents_are_searchable_in_japanese(): void
    {
        $result = $this->catalogue()->search('革靴');

        $this->assertSame(1, $result->total);
        $this->assertSame('jp-1', $result->hits[0]->id);
    }

    #[Test]
    public function a_single_character_finds_the_words_that_contain_it(): void
    {
        // The one query shape a continuous script could not answer. 茶, 水, 书
        // and 車 are ordinary words, and a document saying 緑茶 produces the
        // bigram 緑茶 and nothing else — so searching 茶 used to find only the
        // documents where it happened to stand on its own, which on a real
        // catalogue is almost none of them.
        //
        // `§ termgrams` indexes a continuous term by its characters now, which
        // is the same second tier a Latin typo goes through and a different
        // question asked of it: not "which words resemble this one" but "which
        // of the vocabulary's bigrams hold this character".
        $writer = new SegmentIndexWriter();

        foreach ([
            'a' => '緑茶',
            'b' => '烏龍茶',
            'c' => '茶',
            'd' => '革靴',
        ] as $id => $title) {
            $writer->put($id, ['title' => $title]);
        }

        $writer->write($this->path);
        $index = SegmentIndex::open($this->path);

        $tea = $index->search('茶');

        $this->assertSame(3, $tea->total, '緑茶, 烏龍茶 and 茶 itself');
        $this->assertSame(
            'c',
            $tea->hits[0]->id,
            'the document using it as a word of its own still ranks first'
        );

        // Two characters or more need none of this: they *are* the bigrams the
        // documents produced, so the exact lookup finds them.
        $this->assertSame(1, $index->search('緑茶')->total);

        // And it does not leak across scripts or into unrelated words.
        $this->assertSame(1, $index->search('靴')->total);
    }

    #[Test]
    public function a_query_matching_nothing_returns_an_empty_result(): void
    {
        $result = $this->catalogue()->search('bicycle');

        $this->assertSame(0, $result->total);
        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function an_empty_query_matches_everything(): void
    {
        // A category page: no search box, just filters and facets.
        $result = $this->catalogue()->search('');

        $this->assertSame(5, $result->total);
    }

    // =========================================================================
    // Filters
    // =========================================================================

    #[Test]
    public function numeric_and_keyword_filters_combine(): void
    {
        $result = $this->catalogue()->search('shoe', filters: [
            ['field' => 'price', 'op' => '<=', 'value' => 140],
            ['field' => 'brand', 'op' => 'in', 'value' => ['Adidas']],
        ]);

        $this->assertSame(1, $result->total);
        $this->assertSame('sku-1', $result->hits[0]->id);
    }

    #[Test]
    public function filters_work_without_a_query(): void
    {
        $result = $this->catalogue()->search('', filters: [
            ['field' => 'stock', 'op' => '>', 'value' => 0],
        ]);

        $this->assertSame(4, $result->total);
        $this->assertNotContains(
            'sku-2',
            array_map(static fn($hit): string => $hit->id, $result->hits),
            'the out-of-stock product must be excluded'
        );
    }

    #[Test]
    public function a_stock_of_zero_is_a_value_and_not_an_absence(): void
    {
        $result = $this->catalogue()->search('', filters: [
            ['field' => 'stock', 'op' => '>=', 'value' => 0],
        ]);

        $this->assertSame(5, $result->total, 'the product with stock 0 still has a stock');
    }

    #[Test]
    public function an_unfilterable_field_is_an_explicit_error(): void
    {
        $this->expectException(FilterException::class);
        $this->expectExceptionMessageMatches('/no filterable column/i');

        $this->catalogue()->search('', filters: [
            ['field' => 'nonexistent', 'op' => '=', 'value' => 1],
        ]);
    }

    // =========================================================================
    // Facets and totals
    // =========================================================================

    #[Test]
    public function facets_are_counted_over_every_match_not_the_page(): void
    {
        $result = $this->catalogue()->search('', limit: 1, facets: ['brand']);

        $this->assertCount(1, $result->hits, 'one hit on the page');
        $this->assertSame(5, $result->total, 'five matches in the index');
        $this->assertSame(5, array_sum($result->facets['brand']), 'facets count all five');
        $this->assertSame(2, $result->facets['brand']['Adidas']);
    }

    #[Test]
    public function a_facet_on_a_numeric_field_gives_its_range(): void
    {
        $result = $this->catalogue()->search('', filters: [
            ['field' => 'brand', 'op' => 'in', 'value' => ['Adidas', 'Nike']],
        ], facets: ['price']);

        $this->assertSame(3, $result->facets['price']['count']);
        $this->assertSame(59.0, $result->facets['price']['min']);
        $this->assertSame(149.0, $result->facets['price']['max']);
    }

    #[Test]
    public function facets_follow_the_filters(): void
    {
        $result = $this->catalogue()->search('', filters: [
            ['field' => 'price', 'op' => '<=', 'value' => 150],
        ], facets: ['brand']);

        $this->assertSame(4, $result->total);
        $this->assertArrayNotHasKey('アディダス', $result->facets['brand'], 'the 19 800 product is filtered out');
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    #[Test]
    public function a_page_can_be_requested_by_offset(): void
    {
        $index = $this->catalogue();

        $first  = $index->search('', limit: 2, offset: 0);
        $second = $index->search('', limit: 2, offset: 2);

        $this->assertCount(2, $first->hits);
        $this->assertCount(2, $second->hits);
        $this->assertSame(5, $first->total);
        $this->assertSame(5, $second->total, 'the total does not change with the page');

        $firstIds  = array_map(static fn($hit): string => $hit->id, $first->hits);
        $secondIds = array_map(static fn($hit): string => $hit->id, $second->hits);

        $this->assertSame([], array_intersect($firstIds, $secondIds), 'pages do not overlap');
    }

    #[Test]
    public function an_offset_past_the_end_returns_nothing_but_still_reports_the_total(): void
    {
        $result = $this->catalogue()->search('', limit: 10, offset: 100);

        $this->assertSame([], $result->hits);
        $this->assertSame(5, $result->total);
    }

    // =========================================================================
    // Identity
    // =========================================================================

    #[Test]
    public function documents_are_retrievable_by_the_id_they_were_given(): void
    {
        $index = $this->catalogue();

        $this->assertSame('革靴 ブラウン', $index->get('jp-1')['title']);
        $this->assertSame('Blue suede boot', $index->get('sku-2')['title']);
        $this->assertNull($index->get('nope'));

        $this->assertTrue($index->has('sku-1'));
        $this->assertFalse($index->has('sku-99'));
    }

    #[Test]
    public function results_carry_the_id_that_was_indexed(): void
    {
        $result = $this->catalogue()->search('sneaker');

        $this->assertSame('sku-4', $result->hits[0]->id);
    }

    #[Test]
    public function numeric_ids_are_accepted_and_returned_as_strings(): void
    {
        $writer = new SegmentIndexWriter();
        $writer->put(42, ['title' => 'Product forty-two']);
        $writer->write($this->path);

        $index = SegmentIndex::open($this->path);

        $this->assertSame('Product forty-two', $index->get(42)['title']);
        $this->assertSame('42', $index->search('forty')->hits[0]->id);
    }

    #[Test]
    public function a_duplicate_id_in_one_batch_is_refused(): void
    {
        $writer = new SegmentIndexWriter();
        $writer->put('sku-1', ['title' => 'First']);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');

        $writer->put('sku-1', ['title' => 'Second']);
    }

    // =========================================================================
    // The whole thing, on a larger catalogue
    // =========================================================================

    #[Test]
    public function it_holds_a_realistic_catalogue(): void
    {
        $brands = ['Adidas', 'Puma', 'Nike', 'Asics', 'Reebok'];
        $writer = new SegmentIndexWriter();

        for ($i = 0; $i < 2000; $i++) {
            $writer->put('sku-' . $i, [
                'title' => "Chaussure modele $i en cuir",
                'brand' => $brands[$i % 5],
                'price' => 50.0 + ($i % 400),
                'stock' => $i % 11,
            ]);
        }

        $writer->write($this->path);
        $index = SegmentIndex::open($this->path);

        $this->assertSame(2000, $index->count());

        $result = $index->search('cuir', limit: 10, facets: ['brand']);

        $this->assertSame(2000, $result->total, 'every product mentions cuir');
        $this->assertCount(10, $result->hits, 'but only one page is returned');
        $this->assertSame(2000, array_sum($result->facets['brand']));
        $this->assertSame(400, $result->facets['brand']['Adidas']);

        $filtered = $index->search('cuir', filters: [
            ['field' => 'price', 'op' => 'between', 'value' => [100, 200]],
            ['field' => 'stock', 'op' => '>', 'value' => 0],
        ]);

        $this->assertGreaterThan(0, $filtered->total);
        $this->assertLessThan(2000, $filtered->total);

        foreach ($filtered->hits as $hit) {
            $this->assertGreaterThanOrEqual(100, $hit->document['price']);
            $this->assertLessThanOrEqual(200, $hit->document['price']);
            $this->assertGreaterThan(0, $hit->document['stock']);
        }
    }
    // =========================================================================
    // Field inference
    // =========================================================================

    #[Test]
    public function a_field_whose_values_repeat_earns_a_column_and_a_unique_one_does_not(): void
    {
        // Length alone cannot tell a brand from a title: both are short. What
        // separates them is repetition — a brand appears across hundreds of
        // documents, a title once — and that is also exactly what makes a field
        // worth faceting on.
        $writer = new SegmentIndexWriter();

        for ($i = 0; $i < 2000; $i++) {
            $writer->put("sku-$i", [
                "title" => "Chaussure modele $i en cuir",
                "brand" => ["Adidas", "Puma", "Nike"][$i % 3],
            ]);
        }

        $writer->write($this->path);

        $fields = SegmentIndex::open($this->path)->fields();

        $this->assertSame("keyword", $fields["brand"], "three values over two thousand documents");
        $this->assertSame("text", $fields["title"], "every title is unique, so no column");
    }

    #[Test]
    public function a_long_string_never_earns_a_column(): void
    {
        $writer = new SegmentIndexWriter();

        for ($i = 0; $i < 200; $i++) {
            $writer->put("sku-$i", ["description" => str_repeat("texte de description ", 10)]);
        }

        $writer->write($this->path);

        $this->assertSame("text", SegmentIndex::open($this->path)->fields()["description"]);
    }
}