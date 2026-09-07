<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Exception\FieldTypeException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declaration itself: what each type implies, and what the builder refuses.
 *
 * The schema is the one object a user of the library writes by hand, so its
 * mistakes have to be loud. Most of what is asserted here is a rejection.
 */
class SchemaTest extends TestCase
{
    // =========================================================================
    // What a type implies
    // =========================================================================

    #[Test]
    public function text_is_searchable_but_never_filterable(): void
    {
        $schema = Schema::make()->text('description');

        $this->assertSame('text', $schema->typeOf('description'));
        $this->assertSame(['description'], $schema->searchableFields());
        $this->assertFalse($schema->fields()['description']['filterable'], 'prose is not a value');
        $this->assertTrue($schema->isStored('description'));
    }

    #[Test]
    public function keyword_is_both_searchable_and_filterable(): void
    {
        $schema = Schema::make()->keyword('brand');

        $this->assertSame(['brand'], $schema->searchableFields());
        $this->assertTrue($schema->fields()['brand']['filterable']);
    }

    #[Test]
    public function a_keyword_can_be_filterable_without_being_searchable(): void
    {
        // A status code is the case: you filter on it, you never type it.
        $schema = Schema::make()->keyword('status', indexed: false);

        $this->assertSame([], $schema->searchableFields());
        $this->assertTrue($schema->fields()['status']['filterable']);
    }

    #[Test]
    public function a_number_is_filterable_and_not_searchable(): void
    {
        $schema = Schema::make()->number('price');

        $this->assertSame([], $schema->searchableFields());
        $this->assertTrue($schema->fields()['price']['filterable']);
    }

    #[Test]
    public function stored_is_neither_searched_nor_filtered(): void
    {
        $schema = Schema::make()->stored('image_url');

        $this->assertSame([], $schema->searchableFields());
        $this->assertFalse($schema->fields()['image_url']['filterable']);
        $this->assertTrue($schema->isStored('image_url'));
    }

    #[Test]
    public function a_field_the_schema_does_not_mention_is_still_stored(): void
    {
        // The agreed arbitration: an export that gains a column must not stop
        // a document being indexed, and inferring the straggler would bring
        // back the drift the schema exists to remove.
        $schema = Schema::make()->text('title');

        $this->assertFalse($schema->has('supplier_ref'));
        $this->assertTrue($schema->isStored('supplier_ref'));
        $this->assertSame(['title'], $schema->searchableFields());
    }

    // =========================================================================
    // The mask vocabulary
    // =========================================================================

    #[Test]
    public function searchable_fields_are_sorted_so_mask_bits_are_stable(): void
    {
        $one = Schema::make()->text('title')->keyword('brand')->tags('tags');
        $two = Schema::make()->tags('tags')->text('title')->keyword('brand');

        $this->assertSame(['brand', 'tags', 'title'], $one->searchableFields());
        $this->assertSame($one->searchableFields(), $two->searchableFields());
    }

    #[Test]
    public function only_boosts_that_differ_from_the_default_are_carried(): void
    {
        $schema = Schema::make()->text('title', boost: 3.0)->text('description');

        // A boost of exactly 1.0 is the absence of a boost; carrying it would
        // make every field look overridden to the code merging query boosts in.
        $this->assertSame(['title' => 3.0], $schema->boosts());
    }

    #[Test]
    public function a_field_that_is_not_indexed_contributes_no_boost(): void
    {
        $schema = Schema::make()->keyword('status', boost: 5.0, indexed: false);

        $this->assertSame([], $schema->boosts());
    }

    // =========================================================================
    // source()
    // =========================================================================

    #[Test]
    public function source_narrows_what_a_result_carries(): void
    {
        $schema = Schema::make()->text('title')->number('price')->source(['title']);

        $this->assertSame(
            ['title' => 'Shoe'],
            $schema->project(['title' => 'Shoe', 'price' => 12.0, 'extra' => 'x'])
        );
    }

    #[Test]
    public function source_false_stores_nothing_at_all(): void
    {
        $schema = Schema::make()->text('title')->source(false);

        $this->assertTrue($schema->storesNothing());
        $this->assertSame([], $schema->project(['title' => 'Shoe']));
        $this->assertFalse($schema->isStored('title'));
    }

    #[Test]
    public function without_source_everything_declared_stored_comes_back(): void
    {
        $schema = Schema::make()->text('title')->text('body', stored: false);

        $this->assertSame(
            ['title' => 'Shoe'],
            $schema->project(['title' => 'Shoe', 'body' => 'long prose'])
        );
    }

    // =========================================================================
    // Immutability
    // =========================================================================

    #[Test]
    public function every_call_returns_a_new_schema(): void
    {
        $empty = Schema::make();
        $one   = $empty->text('title');
        $two   = $one->number('price');

        $this->assertSame([], $empty->fields(), 'the builder must not mutate in place');
        $this->assertSame(['title'], array_keys($one->fields()));
        $this->assertSame(['title', 'price'], array_keys($two->fields()));
    }

    #[Test]
    public function an_untouched_schema_is_empty(): void
    {
        $this->assertTrue(Schema::make()->isEmpty());
        $this->assertFalse(Schema::make()->text('title')->isEmpty());
        $this->assertFalse(Schema::make()->source(false)->isEmpty(), 'source alone is a declaration');
    }

    // =========================================================================
    // What it refuses
    // =========================================================================

    #[Test]
    public function a_field_cannot_be_declared_twice(): void
    {
        $this->expectException(FtsException::class);
        $this->expectExceptionMessage("'title' is declared twice");

        Schema::make()->text('title')->keyword('title');
    }

    #[Test]
    public function a_field_must_have_a_name(): void
    {
        $this->expectException(FtsException::class);

        Schema::make()->text('');
    }

    #[Test]
    public function a_boost_must_be_positive(): void
    {
        $this->expectException(FtsException::class);
        $this->expectExceptionMessage('must be positive');

        Schema::make()->text('title', boost: 0.0);
    }

    #[Test]
    public function b_must_be_a_fraction(): void
    {
        $this->expectException(FtsException::class);
        $this->expectExceptionMessage('between 0 and 1');

        Schema::make()->text('title', b: 1.5);
    }

    #[Test]
    public function b_may_be_zero_for_a_field_of_uniform_length(): void
    {
        $schema = Schema::make()->text('title', b: 0.0);

        $this->assertSame(0.0, $schema->fields()['title']['b']);
    }

    // =========================================================================
    // Serialisation
    // =========================================================================

    #[Test]
    public function a_schema_survives_a_round_trip_through_the_manifest(): void
    {
        $schema = Schema::make()
            ->text('title', boost: 3.0, b: 0.4)
            ->keyword('brand')
            ->tags('tags')
            ->number('price')
            ->boolean('active')
            ->stored('image_url')
            ->source(['title', 'price']);

        // Through JSON, because that is what the manifest actually does to it.
        $json    = (string) json_encode($schema->toArray());
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame($schema->toArray(), Schema::fromArray($decoded)->toArray());
    }

    #[Test]
    public function a_malformed_entry_is_refused_rather_than_guessed(): void
    {
        $this->expectException(FtsException::class);
        $this->expectExceptionMessage('malformed');

        Schema::fromArray(['fields' => ['title' => ['boost' => 2.0]]]);
    }

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        $this->expectException(FtsException::class);
        $this->expectExceptionMessage("Unknown field type 'geo'");

        Schema::fromArray(['fields' => ['place' => ['type' => 'geo']]]);
    }

    #[Test]
    public function inference_produces_a_schema_like_any_other(): void
    {
        // Inferred and declared indexes take the same code path afterwards,
        // which is the whole reason inference is funnelled through here.
        $schema = Schema::inferred(['title' => 'text', 'brand' => 'keyword', 'price' => 'number']);

        $this->assertSame(['brand', 'title'], $schema->searchableFields());
        $this->assertTrue($schema->fields()['brand']['filterable']);
        $this->assertTrue($schema->fields()['price']['filterable']);
        $this->assertSame([], $schema->boosts(), 'inference never invents a boost');
    }

    #[Test]
    public function inference_can_produce_a_multi_valued_field(): void
    {
        $schema = Schema::inferred(['tags' => 'tags']);

        $this->assertSame('tags', $schema->typeOf('tags'));
        $this->assertSame(['tags'], $schema->searchableFields());
        $this->assertTrue($schema->fields()['tags']['filterable']);
    }

    #[Test]
    public function tags_are_filterable_and_searchable_by_default(): void
    {
        $definition = Schema::make()->tags('tags')->fields()['tags'];

        $this->assertSame('tags', $definition['type']);
        $this->assertTrue($definition['indexed']);
        $this->assertTrue($definition['filterable']);
        $this->assertTrue($definition['stored']);
    }

    #[Test]
    public function a_tag_field_can_give_up_either_capability(): void
    {
        // A catalogue with thousands of tags nobody filters on should not pay
        // for the column; one nobody types should not pay for the terms.
        $unfilterable = Schema::make()->tags('tags', filterable: false)->fields()['tags'];
        $unindexed    = Schema::make()->tags('tags', indexed: false)->fields()['tags'];

        $this->assertFalse($unfilterable['filterable']);
        $this->assertTrue($unfilterable['indexed']);

        $this->assertFalse($unindexed['indexed']);
        $this->assertTrue($unindexed['filterable']);
    }

    #[Test]
    public function a_filter_compares_against_one_tag_not_the_list(): void
    {
        $schema = Schema::make()->tags('tags');

        // Storing takes a list; filtering takes one item and asks whether the
        // list holds it. Coercion has to answer both questions differently.
        $this->assertSame(['summer'], $schema->coerceValue('tags', 'summer'));
        $this->assertSame('summer', $schema->coerceComparand('tags', 'summer'));
        $this->assertSame('2025', $schema->coerceComparand('tags', 2025));
    }

    #[Test]
    public function a_list_as_a_comparand_says_to_use_in(): void
    {
        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage('use in() for several');

        Schema::make()->tags('tags')->coerceComparand('tags', ['summer', 'winter']);
    }

    #[Test]
    public function a_comparand_of_any_other_type_is_coerced_as_the_value_would_be(): void
    {
        $schema = Schema::make()->number('price')->keyword('brand')->boolean('active');

        $this->assertSame(200, $schema->coerceComparand('price', '200'));
        $this->assertSame('Nike', $schema->coerceComparand('brand', 'Nike'));
        $this->assertTrue($schema->coerceComparand('active', '1'));
        $this->assertNull($schema->coerceComparand('price', null));
        $this->assertSame('anything', $schema->coerceComparand('undeclared', 'anything'));
    }
}
