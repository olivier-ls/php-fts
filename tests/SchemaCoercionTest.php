<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Exception\FieldTypeException;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a declared schema accepts, converts, and refuses.
 *
 * The rule under test throughout: convert what is unambiguous, refuse what
 * would need a guess. And convert *once*, so that the term index, the
 * doc-values column and the document store cannot disagree about what a value
 * was — which is precisely how a brand became searchable by name yet absent
 * from the facet that would let you click it.
 */
class SchemaCoercionTest extends TestCase
{
    private function schema(): Schema
    {
        return Schema::make()
            ->text('title')
            ->keyword('brand')
            ->tags('labels')
            ->number('price')
            ->boolean('active')
            ->stored('payload');
    }

    // =========================================================================
    // What is always legal
    // =========================================================================

    #[Test]
    public function a_conforming_document_passes_through_unchanged(): void
    {
        $document = [
            'title'   => 'Brown leather shoe',
            'brand'   => 'Adidas',
            'labels'  => ['sale', 'leather'],
            'price'   => 129.90,
            'active'  => true,
            'payload' => ['meta' => ['weight' => 1.2]],
        ];

        $this->assertSame($document, $this->schema()->coerce($document, 'sku-1'));
    }

    #[Test]
    public function an_absent_field_is_legal(): void
    {
        // Not every product has every column, and a schema is not the place to
        // require one. That is application validation, not index validation.
        $this->assertSame(
            ['title' => 'Blue suede boot'],
            $this->schema()->coerce(['title' => 'Blue suede boot'], 'sku-2')
        );
    }

    #[Test]
    public function an_explicit_null_is_legal_for_every_type(): void
    {
        $document = [
            'title'   => null,
            'brand'   => null,
            'labels'  => null,
            'price'   => null,
            'active'  => null,
            'payload' => null,
        ];

        $this->assertSame($document, $this->schema()->coerce($document, 'sku-3'));
    }

    #[Test]
    public function a_field_the_schema_does_not_mention_is_untouched(): void
    {
        // The arbitration made everywhere else: kept and returned, never
        // interpreted — so never refused either.
        $this->assertSame(
            ['supplier' => ['ref' => 'XYZ']],
            $this->schema()->coerce(['supplier' => ['ref' => 'XYZ']], 'sku-4')
        );
    }

    // =========================================================================
    // number
    // =========================================================================

    /**
     * @return iterable<string, array{mixed, int|float}>
     */
    public static function acceptedNumbers(): iterable
    {
        // The numeric-string rows are the ones that matter: a DECIMAL column
        // arrives from PDO as a string until someone turns on native types, and
        // an engine that rejects the first SQL result set it is handed has
        // failed before it started.
        yield 'int'              => [12, 12];
        yield 'float'            => [129.90, 129.90];
        yield 'negative'         => [-3, -3];
        yield 'decimal string'   => ['129.90', 129.90];
        yield 'integer string'   => ['12', 12];
        yield 'padded string'    => [' 12 ', 12];
        yield 'exponent string'  => ['1e3', 1000.0];
        yield 'true'             => [true, 1];
        yield 'false'            => [false, 0];
    }

    #[Test]
    #[DataProvider('acceptedNumbers')]
    public function a_number_accepts_what_is_unambiguous(mixed $value, int|float $expected): void
    {
        $coerced = $this->schema()->coerce(['price' => $value], 'sku-1')['price'];

        $this->assertSame($expected, $coerced);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedNumbers(): iterable
    {
        yield 'word'         => ['cher'];
        yield 'empty string' => [''];
        yield 'part number'  => ['12 pieces'];
        yield 'list'         => [['from' => 10]];
        yield 'object'       => [new \stdClass()];
        yield 'nan'          => [NAN];
        yield 'infinity'     => [INF];
    }

    #[Test]
    #[DataProvider('refusedNumbers')]
    public function a_number_refuses_what_would_need_a_guess(mixed $value): void
    {
        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage("Field 'price' of document 'sku-1' is declared number");

        $this->schema()->coerce(['price' => $value], 'sku-1');
    }

    // =========================================================================
    // boolean
    // =========================================================================

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function acceptedBooleans(): iterable
    {
        yield 'true'        => [true, true];
        yield 'false'       => [false, false];
        yield 'one'         => [1, true];
        yield 'zero'        => [0, false];
        yield 'one string'  => ['1', true];
        yield 'zero string' => ['0', false];
        yield 'true string' => ['true', true];
        yield 'TRUE string' => ['TRUE', true];
        yield 'false string' => ['false', false];
    }

    #[Test]
    #[DataProvider('acceptedBooleans')]
    public function a_boolean_accepts_the_forms_a_database_returns(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, $this->schema()->coerce(['active' => $value], 'sku-1')['active']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedBooleans(): iterable
    {
        // 'yes' and 'on' are someone's convention rather than a fact. Guessing
        // at them is how a filter quietly inverts.
        yield 'yes'  => ['yes'];
        yield 'on'  => ['on'];
        yield 't'   => ['t'];
        yield 'two' => [2];
        yield 'list' => [[true]];
    }

    #[Test]
    #[DataProvider('refusedBooleans')]
    public function a_boolean_refuses_a_convention(mixed $value): void
    {
        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage("is declared boolean but received");

        $this->schema()->coerce(['active' => $value], 'sku-1');
    }

    // =========================================================================
    // keyword and tags
    // =========================================================================

    #[Test]
    public function a_keyword_stringifies_a_number(): void
    {
        // A reference that happens to be all digits is still a reference.
        $this->assertSame('4471', $this->schema()->coerce(['brand' => 4471], 'sku-1')['brand']);
    }

    #[Test]
    public function a_keyword_given_a_list_says_what_to_do_about_it(): void
    {
        // This is the case that produced the bug: the term index joined the
        // list and indexed it, the column dropped it, and the value was
        // findable by name but not filterable and missing from its own facet.
        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage('declare the field with tags() to hold several');

        $this->schema()->coerce(['brand' => ['Puma']], 'sku-1');
    }

    #[Test]
    public function tags_accept_a_lone_string_as_a_list_of_one(): void
    {
        $this->assertSame(['sale'], $this->schema()->coerce(['labels' => 'sale'], 'sku-1')['labels']);
    }

    #[Test]
    public function tags_stringify_their_items(): void
    {
        $this->assertSame(['sale', '2026'], $this->schema()->coerce(['labels' => ['sale', 2026]], 'sku-1')['labels']);
    }

    #[Test]
    public function tags_refuse_a_nested_list(): void
    {
        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage('a list containing a list');

        $this->schema()->coerce(['labels' => [['sale']]], 'sku-1');
    }

    // =========================================================================
    // text
    // =========================================================================

    #[Test]
    public function text_stringifies_a_number_rather_than_dropping_it(): void
    {
        // 42 used to be stored and never indexed, so searching for it found
        // nothing while the value sat there in the result.
        $this->assertSame('42', $this->schema()->coerce(['title' => 42], 'sku-1')['title']);
    }

    #[Test]
    public function text_keeps_a_list_as_a_list(): void
    {
        // The analyser joins it with a space of its own; the caller gets back
        // what they put in.
        $this->assertSame(
            ['Brown shoe', 'Chaussure brune'],
            $this->schema()->coerce(['title' => ['Brown shoe', 'Chaussure brune']], 'sku-1')['title']
        );
    }

    #[Test]
    public function text_refuses_a_boolean(): void
    {
        $this->expectException(FieldTypeException::class);

        $this->schema()->coerce(['title' => true], 'sku-1');
    }

    // =========================================================================
    // stored
    // =========================================================================

    #[Test]
    public function a_stored_field_holds_whatever_json_can_hold(): void
    {
        $value = ['a' => 1, 'b' => [1.5, 'x', null, false]];

        $this->assertSame($value, $this->schema()->coerce(['payload' => $value], 'sku-1')['payload']);
    }

    #[Test]
    public function a_stored_field_refuses_what_json_would_mangle_in_silence(): void
    {
        // An object becomes [], a closure becomes [], and the caller finds out
        // months later.
        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage('Closure');

        $this->schema()->coerce(['payload' => static fn(): int => 1], 'sku-1');
    }

    #[Test]
    public function a_stored_field_refuses_a_non_finite_float_however_deep(): void
    {
        $this->expectException(FieldTypeException::class);
        $this->expectExceptionMessage('not finite');

        $this->schema()->coerce(['payload' => ['weights' => [1.0, NAN]]], 'sku-1');
    }

    // =========================================================================
    // An inferred schema never refuses anything
    // =========================================================================

    #[Test]
    public function an_inferred_schema_leaves_every_document_alone(): void
    {
        // Refusing a value against a type a heuristic guessed from the first
        // batch would turn the turnkey path into the strict one. And converting
        // it leniently would silently drop the caller's own formatting.
        $schema = Schema::inferred(['title' => 'text', 'price' => 'number']);

        $document = ['title' => 42, 'price' => 'cher', 'brand' => ['Puma']];

        $this->assertTrue($schema->isInferred());
        $this->assertSame($document, $schema->coerce($document, 'sku-1'));
    }

    #[Test]
    public function a_declared_schema_is_not_inferred(): void
    {
        $this->assertFalse($this->schema()->isInferred());
        $this->assertFalse(Schema::make()->isInferred());
    }

    #[Test]
    public function whether_a_schema_was_inferred_survives_the_manifest(): void
    {
        // It has to: the index hands its frozen schema back to every later
        // batch, and that is where the decision not to validate is taken.
        $inferred = Schema::inferred(['title' => 'text']);
        $declared = Schema::make()->text('title');

        $roundTrip = static fn(Schema $s): Schema => Schema::fromArray(
            (array) json_decode((string) json_encode($s->toArray()), true)
        );

        $this->assertTrue($roundTrip($inferred)->isInferred());
        $this->assertFalse($roundTrip($declared)->isInferred());
    }
}
