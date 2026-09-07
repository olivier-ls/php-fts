<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\FieldTypeException;
use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\IndexDirectory;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A declared schema, end to end.
 *
 * The unit tests next door check what the builder accepts. What matters here is
 * that the declaration actually reaches the segments: that a field declared
 * non-searchable really is absent from the term dictionary, that `source(false)`
 * really makes the index smaller, and that the schema frozen at the first
 * commit is the one every later batch and every merge uses.
 */
class SchemaIndexTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_schema_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function products(): array
    {
        return [
            'sku-1' => [
                'title'     => 'Brown leather shoe',
                'reference' => 'ADI-4471',
                'brand'     => 'Adidas',
                'price'     => 129.90,
                'active'    => true,
                'image_url' => 'https://example.test/1.jpg',
            ],
            'sku-2' => [
                'title'     => 'Blue suede boot',
                'reference' => 'PUM-9902',
                'brand'     => 'Puma',
                'price'     => 89.00,
                'active'    => false,
                'image_url' => 'https://example.test/2.jpg',
            ],
        ];
    }

    private function schema(): Schema
    {
        return Schema::make()
            ->text('title', boost: 3.0)
            ->keyword('reference')
            ->keyword('brand')
            ->number('price')
            ->boolean('active')
            ->stored('image_url');
    }

    // =========================================================================
    // The declaration reaches the segments
    // =========================================================================

    #[Test]
    public function a_declared_schema_is_frozen_at_the_first_commit(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->putMany($this->products());

        $this->assertSame($this->schema()->toArray(), $index->schema()->toArray());
        $this->assertSame('number', $index->stats()['fields']['price']);
        $this->assertSame('stored', $index->stats()['fields']['image_url']);
    }

    #[Test]
    public function a_stored_field_comes_back_but_is_not_searchable(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->putMany($this->products());

        $hit = $index->search('leather')->hits[0];

        $this->assertSame('sku-1', $hit->id);
        $this->assertSame('https://example.test/1.jpg', $hit->document['image_url']);

        // The URL is in the document store, so it comes back; it was never
        // analysed, so its host is not a term.
        $this->assertSame(0, $index->search('example.test')->total);
    }

    #[Test]
    public function a_field_declared_non_searchable_is_still_filterable(): void
    {
        $schema = Schema::make()
            ->text('title')
            ->keyword('status', indexed: false);

        $index = IndexDirectory::open($this->dir, $schema);
        $index->putMany([
            'a' => ['title' => 'Brown leather shoe', 'status' => 'draft'],
            'b' => ['title' => 'Blue suede boot', 'status' => 'published'],
        ]);

        $this->assertSame(0, $index->search('published')->total, 'a status is not typed into a search box');

        $filtered = $index->search('', filters: [['field' => 'status', 'op' => '=', 'value' => 'published']]);

        $this->assertSame(1, $filtered->total);
        $this->assertSame('b', $filtered->hits[0]->id);
        $this->assertSame(['draft' => 1, 'published' => 1], $index->search('', facets: ['status'])->facets['status']);
    }

    #[Test]
    public function a_field_the_schema_does_not_mention_is_returned_but_not_searched(): void
    {
        // The arbitration: a catalogue export that gains a column must not
        // stop a product being indexed.
        $index = IndexDirectory::open($this->dir, Schema::make()->text('title'));
        $index->putMany([
            'a' => ['title' => 'Brown leather shoe', 'supplier_ref' => 'XYZ-77'],
        ]);

        $hit = $index->search('leather')->hits[0];

        $this->assertSame('XYZ-77', $hit->document['supplier_ref'], 'kept and returned');
        $this->assertSame(0, $index->search('XYZ-77')->total, 'never analysed');

        $this->expectException(FilterException::class);
        $index->search('', filters: [['field' => 'supplier_ref', 'op' => '=', 'value' => 'XYZ-77']]);
    }

    #[Test]
    public function a_boost_declared_in_the_schema_applies_without_being_repeated_per_query(): void
    {
        $schema = Schema::make()->text('title', boost: 10.0)->text('description');

        $index = IndexDirectory::open($this->dir, $schema);
        $index->putMany([
            'in-title'       => ['title' => 'Leather satchel', 'description' => 'A bag for papers'],
            'in-description' => ['title' => 'Canvas satchel', 'description' => 'Trimmed with leather'],
        ]);

        $hits = $index->search('leather')->hits;

        $this->assertCount(2, $hits);
        $this->assertSame('in-title', $hits[0]->id, 'the schema boost decides the order');
    }

    #[Test]
    public function a_query_boost_overrides_the_schema(): void
    {
        $schema = Schema::make()->text('title', boost: 10.0)->text('description');

        $index = IndexDirectory::open($this->dir, $schema);
        $index->putMany([
            'in-title'       => ['title' => 'Leather satchel', 'description' => 'A bag for papers'],
            'in-description' => ['title' => 'Canvas satchel', 'description' => 'Trimmed with leather'],
        ]);

        $hits = $index->search('leather', boosts: ['description' => 100.0])->hits;

        $this->assertSame('in-description', $hits[0]->id);
    }

    // =========================================================================
    // source()
    // =========================================================================

    #[Test]
    public function source_false_stores_no_documents_at_all(): void
    {
        $schema = $this->schema()->source(false);

        $index = IndexDirectory::open($this->dir, $schema);
        $index->putMany($this->products());

        $result = $index->search('leather');

        $this->assertSame(1, $result->total, 'still searchable');
        $this->assertSame('sku-1', $result->hits[0]->id, 'the id is what you get');
        $this->assertSame([], $result->hits[0]->document);
    }

    #[Test]
    public function storing_nothing_makes_the_index_measurably_smaller(): void
    {
        // The document store is the largest part of an index, so a caller whose
        // rows already live in a database should be able to drop it. Asserted
        // as a comparison rather than a byte count: the point is the saving,
        // not its exact size.
        $documents = [];

        for ($i = 0; $i < 200; $i++) {
            $documents["sku-$i"] = [
                'title'     => "Brown leather shoe number $i",
                'reference' => sprintf('ADI-%04d', $i),
                'brand'     => $i % 2 === 0 ? 'Adidas' : 'Puma',
                'price'     => 50.0 + $i,
                'active'    => true,
                'image_url' => "https://example.test/very/long/path/to/an/image/$i.jpg",
            ];
        }

        $withSource = IndexDirectory::open($this->dir . '/with', $this->schema());
        $withSource->putMany($documents);

        $withoutSource = IndexDirectory::open($this->dir . '/without', $this->schema()->source(false));
        $withoutSource->putMany($documents);

        $with    = $withSource->stats()['bytes'];
        $without = $withoutSource->stats()['bytes'];

        $this->assertLessThan($with, $without);
        $this->assertLessThan(
            $with * 0.75,
            $without,
            "storing nothing should save a good deal; got $without against $with"
        );

        foreach ([$this->dir . '/with', $this->dir . '/without'] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory . '/.lock');
            @rmdir($directory);
        }
    }

    #[Test]
    public function source_keeps_only_the_named_fields(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema()->source(['title', 'price']));
        $index->putMany($this->products());

        $document = $index->search('leather')->hits[0]->document;

        $this->assertSame(['title', 'price'], array_keys($document));

        // Filtering still works: a column is not the document store.
        $this->assertSame(
            1,
            $index->search('', filters: [['field' => 'brand', 'op' => '=', 'value' => 'Adidas']])->total
        );
    }

    // =========================================================================
    // The frozen schema survives everything
    // =========================================================================

    #[Test]
    public function a_later_batch_uses_the_frozen_schema_not_its_own_inference(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->putMany($this->products());

        // A second batch, on its own, would infer nothing about `active` — one
        // document is too little to place a field. It has to inherit.
        $index->put('sku-3', [
            'title'     => 'Red canvas sneaker',
            'reference' => 'NIK-1200',
            'brand'     => 'Nike',
            'price'     => 59.00,
            'active'    => true,
            'image_url' => 'https://example.test/3.jpg',
        ]);

        $this->assertSame($this->schema()->toArray(), $index->schema()->toArray());

        $active = $index->search('', filters: [['field' => 'active', 'op' => '=', 'value' => true]]);

        $this->assertSame(2, $active->total, 'sku-2 is the inactive one');
        $this->assertContains('sku-3', array_map(static fn($hit): string => $hit->id, $active->hits));
    }

    #[Test]
    public function a_merge_does_not_change_the_schema_or_the_results(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());

        foreach ($this->products() as $id => $product) {
            $index->put($id, $product);
        }

        $before = $index->search('shoe', facets: ['brand']);

        $index->optimize();

        $after = $index->search('shoe', facets: ['brand']);

        $this->assertSame(1, $index->stats()['segments']);
        $this->assertSame($this->schema()->toArray(), $index->schema()->toArray());
        $this->assertSame($before->total, $after->total);
        $this->assertSame($before->facets, $after->facets);
        $this->assertSame(
            array_map(static fn($hit): string => $hit->id, $before->hits),
            array_map(static fn($hit): string => $hit->id, $after->hits)
        );
        $this->assertSame($before->hits[0]->document, $after->hits[0]->document);
    }

    #[Test]
    public function reopening_with_the_same_schema_is_fine(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->putMany($this->products());

        $reopened = IndexDirectory::open($this->dir, $this->schema());

        $this->assertSame(2, $reopened->count());
        $this->assertSame($this->schema()->toArray(), $reopened->schema()->toArray());
    }

    #[Test]
    public function reopening_without_a_schema_reads_the_frozen_one(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->putMany($this->products());

        $reopened = IndexDirectory::open($this->dir);

        $this->assertSame($this->schema()->toArray(), $reopened->schema()->toArray());
        $this->assertSame('sku-1', $reopened->search('leather')->hits[0]->id);
    }

    #[Test]
    public function reopening_with_a_different_schema_is_refused(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->putMany($this->products());

        // Silently ignoring the new declaration is how someone spends an
        // afternoon wondering why their boost does nothing.
        $this->expectException(FtsException::class);
        $this->expectExceptionMessage('created with a different schema');

        IndexDirectory::open($this->dir, $this->schema()->source(false));
    }

    #[Test]
    public function declaring_a_schema_on_an_index_that_has_no_commit_yet_is_allowed(): void
    {
        IndexDirectory::open($this->dir, $this->schema());

        $index = IndexDirectory::open($this->dir, Schema::make()->text('title'));
        $index->put('a', ['title' => 'Brown leather shoe']);

        $this->assertSame(['title'], array_keys($index->schema()->fields()));
    }

    // =========================================================================
    // A declared type is enforced
    // =========================================================================

    #[Test]
    public function a_value_of_the_wrong_type_names_the_document_and_the_field(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());

        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage("Field 'price' of document 'sku-9' is declared number but received the string 'sur devis'");

        $index->put('sku-9', ['title' => 'Bespoke boot', 'price' => 'sur devis']);
    }

    #[Test]
    public function a_refused_document_leaves_the_index_exactly_as_it_was(): void
    {
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->putMany($this->products());

        $generation = $index->generation();

        try {
            $index->putMany([
                'sku-3' => ['title' => 'Red canvas sneaker', 'price' => 59.00],
                'sku-4' => ['title' => 'Bespoke boot', 'price' => 'sur devis'],
            ]);
            $this->fail('the batch should have been refused');
        } catch (FieldTypeException) {
            // Expected. A batch is one commit, so a document it cannot hold
            // takes the whole batch with it rather than committing half of it.
        }

        $this->assertSame($generation, $index->generation(), 'nothing was published');
        $this->assertSame(2, $index->count());
        $this->assertFalse($index->has('sku-3'), 'not even the document that was fine');
    }

    #[Test]
    public function a_numeric_string_from_a_database_is_accepted_and_normalised(): void
    {
        // What PDO hands back for a DECIMAL column until someone turns on
        // native types. Refusing this would break the first thing every user
        // does with the library.
        $index = IndexDirectory::open($this->dir, $this->schema());
        $index->put('sku-1', [
            'title'  => 'Brown leather shoe',
            'price'  => '129.90',
            'active' => '1',
        ]);

        $document = $index->get('sku-1');

        $this->assertSame(129.90, $document['price'], 'stored as the number it is');
        $this->assertTrue($document['active']);
        $this->assertSame(
            1,
            $index->search('', filters: [['field' => 'price', 'op' => '<=', 'value' => 200]])->total
        );
    }

    #[Test]
    public function a_keyword_and_its_facet_can_no_longer_disagree(): void
    {
        // The bug this replaced: a list given to a keyword field was joined and
        // indexed by the term writer but dropped by the column writer, so the
        // value was findable by typing it, absent from its own facet, and
        // invisible to the filter behind that facet.
        $index = IndexDirectory::open($this->dir, $this->schema());

        try {
            $index->put('sku-1', ['title' => 'Brown leather shoe', 'brand' => ['Puma']]);
            $this->fail('a list given to a keyword should have been refused');
        } catch (FieldTypeException $e) {
            $this->assertStringContainsString('tags()', $e->getMessage(), 'and it says what to do instead');
        }

        // Declared properly, the three paths agree.
        $index->put('sku-2', ['title' => 'Blue suede boot', 'brand' => 'Puma']);

        $this->assertSame(1, $index->search('Puma')->total);
        $this->assertSame(
            1,
            $index->search('', filters: [['field' => 'brand', 'op' => '=', 'value' => 'Puma']])->total
        );
        $this->assertSame(['Puma' => 1], $index->search('', facets: ['brand'])->facets['brand']);
    }

    #[Test]
    public function a_document_json_cannot_hold_is_named(): void
    {
        // Invalid UTF-8 gets as far as the encoder, which used to report that
        // some document, somewhere in the batch, was unencodable.
        $index = IndexDirectory::open($this->dir, $this->schema());

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Document 'sku-9' could not be encoded");

        $index->put('sku-9', ['title' => "caf\xE9 crème"]);
    }

    // =========================================================================
    // Inference is still the default
    // =========================================================================

    #[Test]
    public function an_inferred_schema_refuses_nothing(): void
    {
        // The turnkey path keeps exactly the tolerance it had. Refusing a value
        // against a type guessed from the first batch would be the wrong way
        // round, and normalising it would drop the caller's own formatting.
        $index = IndexDirectory::open($this->dir);
        $index->putMany($this->products());

        $index->put('sku-3', ['title' => 42, 'brand' => ['Puma'], 'price' => 'cher']);

        $this->assertSame(3, $index->count());
        $this->assertSame(['title' => 42, 'brand' => ['Puma'], 'price' => 'cher'], $index->get('sku-3'));
        $this->assertTrue($index->schema()->isInferred());
    }


    #[Test]
    public function without_a_schema_the_types_are_still_inferred(): void
    {
        $index = IndexDirectory::open($this->dir);
        $index->putMany($this->products());

        $this->assertSame(1, $index->search('leather')->total);
        $this->assertSame('number', $index->stats()['fields']['price']);
        $this->assertNotSame([], $index->stats()['schema'], 'inference is frozen like a declaration');
    }
}
