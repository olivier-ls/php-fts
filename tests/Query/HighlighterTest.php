<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Query;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\HighlightException;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Query\Highlighter;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Highlighting, which is span arithmetic over the analyzer's own n-grams.
 *
 * The interesting half of this file is not "does `leather` get marked" — it is
 * the two ways an n-gram engine marks text that is not a match, and the fact
 * that a partial match which *is* the reason a document ranked stays marked.
 */
class HighlighterTest extends TestCase
{
    private Analyzer $analyzer;
    private Highlighter $highlighter;

    protected function setUp(): void
    {
        $this->analyzer    = new Analyzer();
        $this->highlighter = new Highlighter($this->analyzer);
    }

    /**
     * @param string[] $fields
     */
    private function mark(string $query, string $text, ?Highlight $options = null): string|array|null
    {
        return $this->highlighter->field(
            $text,
            array_fill_keys($this->analyzer->analyze($query), true),
            $options ?? Highlight::fields(['field']),
        );
    }

    // =========================================================================
    // The whole word, reassembled from the n-grams that tile it
    // =========================================================================

    #[Test]
    public function a_matching_word_is_marked_whole(): void
    {
        $this->assertSame(
            'Brown <mark>leather</mark> shoe',
            $this->mark('leather', 'Brown leather shoe')
        );
    }

    #[Test]
    public function several_query_words_are_each_marked(): void
    {
        $this->assertSame(
            'Brown <mark>leather</mark> <mark>shoe</mark>',
            $this->mark('leather shoe', 'Brown leather shoe')
        );
    }

    #[Test]
    public function two_words_marked_side_by_side_stay_two_marks(): void
    {
        // The space between them is not part of either span, so the marks do
        // not merge into one that swallows it.
        $this->assertSame(
            '<mark>cuir</mark> <mark>marron</mark>',
            $this->mark('cuir marron', 'cuir marron')
        );
    }

    #[Test]
    public function a_word_of_two_letters_is_marked(): void
    {
        // Both of its trigrams carry a boundary marker, and each spans the
        // whole word — which is what makes them content rather than edges.
        $this->assertSame('<mark>en</mark> cuir', $this->mark('en', 'en cuir'));
    }

    #[Test]
    public function a_word_of_one_letter_is_marked(): void
    {
        $this->assertSame('<mark>a</mark> b', $this->mark('a', 'a b'));
    }

    #[Test]
    public function a_fragment_of_a_word_is_not_a_match_here(): void
    {
        // It used to be: a document was trigrammed, so `eath` shared `eat` and
        // `ath` with `leather` and came back marked as `l<mark>eath</mark>er`.
        // Terms are whole words now, and this class only ever sees the terms
        // the query planner resolved — so a fragment reaching it means the
        // planner decided it meant nothing, and marking it anyway would invent
        // a match the search did not make.
        //
        // The useful half of that old behaviour is a *prefix*, and it lives
        // where it belongs: `inox` finds and marks `inoxydable` because the
        // planner expands it, not because the highlighter guesses. See
        // SearchEngineTest.
        $this->assertNull($this->mark('eath', 'leather'));
    }

    #[Test]
    public function nothing_matching_gives_no_highlight_at_all(): void
    {
        // Null, not the unmarked field: a caller has to be able to tell the
        // difference to decide whether to show the field at all.
        $this->assertNull($this->mark('sandal', 'Brown leather shoe'));
    }

    #[Test]
    public function an_empty_query_matches_nothing(): void
    {
        $this->assertNull($this->mark('', 'Brown leather shoe'));
    }

    // =========================================================================
    // The over-matching an n-gram engine had to defend against, and which
    // whole-word terms remove instead
    // =========================================================================
    //
    // These three used to be the hard cases of this file, and each needed a
    // rule to close it: `leather` and `The` shared the trigram `the`, `leather`
    // and `over` shared the word-ending `er#`, and telling those apart from a
    // real partial match took the edge-anchoring flag and the span-agreement
    // check. None of it is load-bearing any more — two different words share no
    // term at all — so they are kept as the regression they now guard rather
    // than as the puzzle they were.

    #[Test]
    public function two_different_words_share_nothing(): void
    {
        $this->assertNull($this->mark('leather', 'The quick brown fox'));
        $this->assertNull($this->mark('leather', 'jumps over the lazy dog'));
        $this->assertNull($this->mark('leather', 'The weather today'));
    }

    #[Test]
    public function a_word_inside_a_longer_word_is_not_marked(): void
    {
        // `shoe` and `snowshoes` are separate words to the index, so nothing
        // is marked. Deliberate: a query that is a *prefix* is expanded by the
        // planner and does match, while one buried in the middle of a longer
        // word is the case that used to return `victorinox` for `inox`.
        $this->assertNull($this->mark('shoe', 'A pair of snowshoes'));
    }

    #[Test]
    public function a_query_that_is_a_whole_word_of_the_document_matches_it(): void
    {
        $this->assertSame('<mark>The</mark> shoe', $this->mark('the', 'The shoe'));
    }

    // =========================================================================
    // Scripts without word boundaries
    // =========================================================================

    #[Test]
    public function japanese_is_marked_from_its_bigrams(): void
    {
        $this->assertSame('<mark>革靴</mark> ブラウン', $this->mark('革靴', '革靴 ブラウン'));
    }

    #[Test]
    public function a_japanese_word_inside_a_longer_run_is_marked(): void
    {
        // No word boundary exists to anchor to, and none is needed: a bigram
        // is content wherever it falls.
        $this->assertSame('日本<mark>革靴</mark>業界', $this->mark('革靴', '日本革靴業界'));
    }

    #[Test]
    public function cyrillic_is_marked_like_any_other_word_script(): void
    {
        $this->assertSame(
            'Коричневые <mark>туфли</mark>',
            $this->mark('туфли', 'Коричневые туфли')
        );
    }

    // =========================================================================
    // Folding, markup and entities: the text a span points into
    // =========================================================================

    #[Test]
    public function a_match_is_found_through_case_and_accents(): void
    {
        $this->assertSame('Chaussure en <mark>CUIR</mark>', $this->mark('cuir', 'Chaussure en CUIR'));
        $this->assertSame('Un <mark>café</mark> serré', $this->mark('cafe', 'Un café serré'));
    }

    #[Test]
    public function a_decomposed_accent_is_marked_with_its_letter(): void
    {
        // The folder deletes the combining mark, so nothing in the analyzer
        // corresponds to those two bytes. They still have to end up inside the
        // mark, or the highlight renders as "caf<mark>e</mark>´".
        $this->assertSame(
            '<mark>cafe' . "\xcc\x81" . '</mark> noir',
            $this->mark('cafe', 'cafe' . "\xcc\x81" . ' noir')
        );
    }

    #[Test]
    public function markup_is_stripped_and_entities_decoded_before_marking(): void
    {
        // The mark lands on the text, and the text is what the reader sees.
        // Splicing tags back around a span would mean re-nesting markup at
        // arbitrary offsets, which is how highlighters emit broken HTML.
        $this->assertSame(
            'Chaussure en <mark>cuir</mark> &amp; daim',
            $this->mark('cuir', 'Chaussure en <b>cuir</b> &amp; daim')
        );
    }

    #[Test]
    public function the_field_is_escaped_before_the_tags_go_in(): void
    {
        $marked = $this->mark('script', 'A script <img onerror="x"> here');

        $this->assertSame('A <mark>script</mark>  here', $marked);
        $this->assertStringNotContainsString('onerror', (string) $marked);
    }

    #[Test]
    public function quotes_in_the_text_are_escaped(): void
    {
        $this->assertSame(
            '&quot;<mark>cuir</mark>&quot;',
            $this->mark('cuir', '"cuir"')
        );
    }

    #[Test]
    public function raw_leaves_the_text_alone(): void
    {
        $this->assertSame(
            '"<mark>cuir</mark>"',
            $this->mark('cuir', '"cuir"', Highlight::fields(['f'])->raw())
        );
    }

    #[Test]
    public function a_stray_angle_bracket_truncates_the_text_the_analyzer_sees(): void
    {
        // Documented, not endorsed. The analyzer opens with strip_tags(), which
        // reads `<3` as the beginning of a tag and drops everything after it.
        // Indexing has always done this — `euros` is not in the index either —
        // but a highlight is where it becomes visible to a reader. Worth
        // revisiting where it belongs, in Analyzer::plain().
        $this->assertSame('Sac en <mark>cuir</mark> ', $this->mark('cuir', 'Sac en cuir <3 euros'));

        // A `<` followed by a space is left alone, which is why this is a
        // sharp edge rather than a constant nuisance.
        $this->assertSame(
            'Sac en <mark>cuir</mark>, &lt; 100 euros',
            $this->mark('cuir', 'Sac en cuir, < 100 euros')
        );
    }

    #[Test]
    public function the_tags_are_the_callers_to_choose(): void
    {
        $this->assertSame(
            'Brown [leather] shoe',
            $this->mark('leather', 'Brown leather shoe', Highlight::fields(['f'])->tags('[', ']'))
        );
    }

    // =========================================================================
    // Excerpts
    // =========================================================================

    private const LONG = 'The quick brown fox jumps over the lazy dog and then finds a '
        . 'leather shoe under the very long bench standing in the garden';

    #[Test]
    public function an_excerpt_is_a_window_around_the_match(): void
    {
        $excerpt = $this->mark('leather', self::LONG, Highlight::fields(['f'])->excerpt(20));

        $this->assertIsString($excerpt);
        $this->assertStringContainsString('<mark>leather</mark>', $excerpt);
        $this->assertStringStartsWith('…', $excerpt);
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertStringNotContainsString('quick', $excerpt);
    }

    #[Test]
    public function an_excerpt_begins_and_ends_on_a_word(): void
    {
        $excerpt = (string) $this->mark('leather', self::LONG, Highlight::fields(['f'])->excerpt(20));

        // Trimmed of its ellipses, an excerpt is whole words: a window counted
        // in characters would otherwise cut one in half at each end.
        $words = explode(' ', trim($excerpt, '…'));

        $this->assertStringContainsString(reset($words), self::LONG);
        $this->assertStringContainsString(end($words), self::LONG);
        $this->assertSame(trim($excerpt, '…'), trim(trim($excerpt, '…')));
    }

    #[Test]
    public function a_field_shorter_than_the_window_gets_no_ellipsis(): void
    {
        $this->assertSame(
            'Brown <mark>leather</mark> shoe',
            $this->mark('leather', 'Brown leather shoe', Highlight::fields(['f'])->excerpt(40))
        );
    }

    #[Test]
    public function a_second_match_inside_the_window_is_marked_too(): void
    {
        $excerpt = (string) $this->mark(
            'cuir',
            'Sac en cuir, doublure en cuir, finition soignée',
            Highlight::fields(['f'])->excerpt(40)
        );

        $this->assertSame(2, substr_count($excerpt, '<mark>'));
    }

    #[Test]
    public function an_excerpt_never_cuts_a_mark_in_half(): void
    {
        // The window ends inside the second match, so the window gives way:
        // half a marked word is worse than a slightly wider excerpt.
        $excerpt = (string) $this->mark(
            'cuir',
            'cuir puis un long passage de remplissage sans intérêt puis encore du cuir',
            Highlight::fields(['f'])->excerpt(60)
        );

        $this->assertSame(
            substr_count($excerpt, '<mark>'),
            substr_count($excerpt, '</mark>')
        );
    }

    #[Test]
    public function a_japanese_excerpt_cuts_between_characters(): void
    {
        $text    = '革靴ブラウンの靴です、とても長い説明文がここに続きます、さらに続きます';
        $excerpt = (string) $this->mark('ブラウン', $text, Highlight::fields(['f'])->excerpt(6));

        $this->assertStringContainsString('<mark>ブラウン</mark>', $excerpt);
        $this->assertStringEndsWith('…', $excerpt);

        // No spaces to snap to, so the cut falls between two characters — and
        // never inside one.
        $this->assertTrue(mb_check_encoding(str_replace('…', '', $excerpt), 'UTF-8'));
    }

    // =========================================================================
    // Offsets, for output that is not HTML
    // =========================================================================

    #[Test]
    public function positions_gives_byte_spans_into_the_plain_text(): void
    {
        $marked = $this->mark('leather', 'Brown leather shoe', Highlight::fields(['f'])->positions());

        $this->assertSame(
            ['text' => 'Brown leather shoe', 'spans' => [[6, 13]]],
            $marked
        );
    }

    #[Test]
    public function a_span_is_a_substr_of_the_text_it_comes_with(): void
    {
        $marked = $this->mark(
            'cuir',
            'Chaussure en <b>cuir</b> &amp; daim',
            Highlight::fields(['f'])->positions()
        );

        $this->assertIsArray($marked);

        [$start, $end] = $marked['spans'][0];

        // The text is the plain text, not the caller's value — which is
        // exactly why it is handed back with the offsets.
        $this->assertSame('cuir', substr($marked['text'], $start, $end - $start));
        $this->assertSame('Chaussure en cuir & daim', $marked['text']);
    }

    #[Test]
    public function positions_reports_every_match_in_the_field(): void
    {
        $marked = $this->mark(
            'cuir',
            'Sac en cuir, doublure en cuir',
            Highlight::fields(['f'])->excerpt(5)->positions()
        );

        // The window is ignored here: where to cut belongs to whatever is
        // rendering, which knows its medium.
        $this->assertIsArray($marked);
        $this->assertCount(2, $marked['spans']);
    }

    // =========================================================================
    // A document, and the fields a schema will allow
    // =========================================================================

    #[Test]
    public function only_the_requested_fields_are_highlighted(): void
    {
        $document = ['title' => 'Leather shoe', 'description' => 'A leather shoe', 'price' => 120.0];

        $highlights = $this->highlighter->document(
            $document,
            array_fill_keys($this->analyzer->analyze('leather'), true),
            Highlight::fields(['title']),
        );

        $this->assertSame(['title' => '<mark>Leather</mark> shoe'], $highlights);
    }

    #[Test]
    public function a_field_that_did_not_match_is_absent_rather_than_unmarked(): void
    {
        $highlights = $this->highlighter->document(
            ['title' => 'Leather shoe', 'description' => 'Comfortable and warm'],
            array_fill_keys($this->analyzer->analyze('leather'), true),
            Highlight::fields(['title', 'description']),
        );

        $this->assertSame(['title'], array_keys($highlights));
    }

    #[Test]
    public function a_missing_or_non_text_field_is_skipped_quietly(): void
    {
        $highlights = $this->highlighter->document(
            ['price' => 120.0, 'tags' => ['cuir'], 'title' => ''],
            array_fill_keys($this->analyzer->analyze('cuir'), true),
            Highlight::fields(['price', 'tags', 'title', 'absent']),
        );

        $this->assertSame([], $highlights);
    }

    #[Test]
    public function highlighting_a_field_the_schema_does_not_store_is_refused(): void
    {
        $schema = Schema::make()->text('title')->keyword('sku')->source(['title']);

        $this->expectException(HighlightException::class);
        $this->expectExceptionMessage("Cannot highlight 'sku'");

        Highlighter::verify(Highlight::fields(['sku']), $schema);
    }

    #[Test]
    public function an_index_that_stores_no_documents_can_highlight_nothing(): void
    {
        $this->expectException(HighlightException::class);
        $this->expectExceptionMessage('stores no documents at all');

        Highlighter::verify(Highlight::fields(['title']), Schema::make()->text('title')->source(false));
    }

    #[Test]
    public function a_field_declared_not_stored_is_refused(): void
    {
        $this->expectException(HighlightException::class);
        $this->expectExceptionMessage("Cannot highlight 'body'");

        Highlighter::verify(
            Highlight::fields(['body']),
            Schema::make()->text('title')->text('body', stored: false)
        );
    }

    #[Test]
    public function a_stored_field_is_allowed_whether_or_not_it_is_searched(): void
    {
        // `stored()` means kept but never indexed, and it highlights fine: the
        // query's terms are the index's, and finding them in this field's text
        // does not require this field to have contributed them.
        Highlighter::verify(
            Highlight::fields(['title', 'body', 'undeclared']),
            Schema::make()->text('title')->stored('body')
        );

        $this->expectNotToPerformAssertions();
    }
}
