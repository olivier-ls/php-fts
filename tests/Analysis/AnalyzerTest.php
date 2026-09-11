<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Analysis;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Analysis\Script;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Text in, terms out.
 *
 * The section on non-Latin scripts is the point of the whole v2 rewrite: 1.x
 * ran `preg_replace('/[^a-z0-9]+/', ' ', $text)` at the end of its tokenizer,
 * which turned Japanese, Chinese, Russian, Greek, Arabic and Thai into spaces
 * before the index ever saw them.
 */
class AnalyzerTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer();
    }

    /**
     * @return string[] the script of each run, for readability in assertions
     */
    private function scripts(string $text): array
    {
        return array_map(
            static fn(array $run): string => $run['script']->name,
            $this->analyzer->runs($text)
        );
    }

    // =========================================================================
    // Space-separated scripts: padded trigrams, as in 1.x
    // =========================================================================

    #[Test]
    public function a_latin_word_becomes_one_term(): void
    {
        // One word, one term. It used to be `#cu cui uir ir#`, which put the
        // cost of typo tolerance in the document postings and made a query
        // unable to say which word a match came from.
        $this->assertSame(['cuir'], $this->analyzer->analyze('cuir'));
    }

    #[Test]
    public function a_single_letter_still_produces_a_term(): void
    {
        $this->assertSame(['a'], $this->analyzer->analyze('a'));
    }

    #[Test]
    public function a_two_letter_word_is_one_term(): void
    {
        $this->assertSame(['en'], $this->analyzer->analyze('en'));
    }

    #[Test]
    public function words_are_separated_by_anything_that_is_not_a_letter(): void
    {
        $spaced = $this->analyzer->analyze('cuir marron');

        $this->assertSame($spaced, $this->analyzer->analyze('cuir, marron'));
        $this->assertSame($spaced, $this->analyzer->analyze('cuir---marron'));
        $this->assertSame($spaced, $this->analyzer->analyze("cuir\n\tmarron"));
    }

    #[Test]
    public function digits_stay_attached_to_letters(): void
    {
        // "iphone12" is one word, as it was in 1.x — splitting on the digit
        // would make the precise query "iphone12" no better than "iphone".
        $this->assertSame(['Latin'], $this->scripts('iphone12'));
        $this->assertSame(['iphone12'], $this->analyzer->analyze('iphone12'));
    }

    #[Test]
    public function a_bracket_that_cannot_open_a_tag_costs_nothing(): void
    {
        // `strip_tags()` treats every `<` as a tag start and drops everything
        // after it, so `lame <3 mm` indexed as `lame` and the rest of the
        // description was not searchable at all. A catalogue writes this.
        $this->assertSame(
            ['couteau', '3', 'cm', 'de', 'lame'],
            $this->analyzer->analyze('Couteau <3 cm de lame')
        );

        $this->assertSame(['prix', '30', 'euros'], $this->analyzer->analyze('prix <30 euros'));

        // Deduplicated, so the second `2` is not a second term.
        $this->assertSame(['1', '2', 'et', '3'], $this->analyzer->analyze('1<2 et 3>2'));
    }

    #[Test]
    public function a_bracket_that_does_open_a_tag_still_strips(): void
    {
        $this->assertSame(['sac', 'en', 'cuir'], $this->analyzer->analyze('Sac <b>en <i>cuir</i></b>'));
        $this->assertSame(['description'], $this->analyzer->analyze('<p style="color:red">Description</p>'));
        $this->assertSame(['visible'], $this->analyzer->analyze('<!-- caché -->visible'));

        // Entities still decode, and one written as an entity is text.
        $this->assertSame(['a', 'b', 'c'], $this->analyzer->analyze('a &amp; b &lt;c&gt;'));
    }

    #[Test]
    public function an_all_digit_word_comes_back_as_a_string(): void
    {
        // Deduplication goes through array keys, and PHP turns a key that looks
        // like an integer into one — so a model number would come back as an
        // int and be rejected by every string signature downstream. The `#`
        // padding used to hide this, because `#21#` is not numeric.
        $terms = $this->analyzer->analyze('opinel 12 2024');

        // assertSame is what enforces the type here: it compares strictly, so a
        // term that came back as int 12 rather than string '12' fails on this
        // line. A separate assertIsString() loop only restated it.
        $this->assertSame(['opinel', '12', '2024'], $terms);
    }

    #[Test]
    public function terms_are_deduplicated(): void
    {
        $this->assertSame(['cuir'], $this->analyzer->analyze('cuir cuir cuir'));
    }

    // =========================================================================
    // Folding
    // =========================================================================

    #[Test]
    public function case_is_folded(): void
    {
        $this->assertSame($this->analyzer->analyze('cuir'), $this->analyzer->analyze('CUIR'));
        $this->assertSame($this->analyzer->analyze('cuir'), $this->analyzer->analyze('CuIr'));
    }

    #[Test]
    public function latin_diacritics_fold_to_ascii(): void
    {
        $this->assertSame($this->analyzer->analyze('elegant'), $this->analyzer->analyze('élégant'));
        $this->assertSame($this->analyzer->analyze('cafe'), $this->analyzer->analyze('Café'));
    }

    #[Test]
    public function decomposed_text_folds_like_precomposed_text(): void
    {
        // "é" written as a plain e followed by a combining acute accent, which
        // is what macOS filenames and some APIs produce.
        $decomposed = "cafe\xcc\x81";

        $this->assertSame($this->analyzer->analyze('café'), $this->analyzer->analyze($decomposed));
        $this->assertSame($this->analyzer->analyze('cafe'), $this->analyzer->analyze($decomposed));
    }

    #[Test]
    public function letters_that_expand_are_expanded(): void
    {
        $this->assertSame($this->analyzer->analyze('strasse'), $this->analyzer->analyze('straße'));
        $this->assertSame($this->analyzer->analyze('soeur'), $this->analyzer->analyze('sœur'));
    }

    #[Test]
    public function central_and_eastern_european_letters_fold(): void
    {
        // The languages 1.x's hand-written table missed: Polish, Czech,
        // Romanian, Hungarian.
        $this->assertSame($this->analyzer->analyze('lodz'), $this->analyzer->analyze('łódź'), 'Polish');
        $this->assertSame($this->analyzer->analyze('LODZ'), $this->analyzer->analyze('ŁÓDŹ'), 'Polish, capitals');
        $this->assertSame($this->analyzer->analyze('reka'), $this->analyzer->analyze('řeka'), 'Czech');
        $this->assertSame($this->analyzer->analyze('siret'), $this->analyzer->analyze('șiret'), 'Romanian');
        $this->assertSame($this->analyzer->analyze('ho'), $this->analyzer->analyze('hő'), 'Hungarian');
        $this->assertSame($this->analyzer->analyze('nas'), $this->analyzer->analyze('naš'), 'Croatian');
    }

    #[Test]
    public function full_width_characters_fold_to_ascii(): void
    {
        // Japanese and Chinese catalogues mix full-width Latin freely, so a
        // reference typed ＡＢ１２３ has to meet AB123.
        $this->assertSame($this->analyzer->analyze('ab123'), $this->analyzer->analyze('ＡＢ１２３'));
    }

    #[Test]
    public function arabic_and_hebrew_meet_their_own_vocalised_spellings(): void
    {
        // Both write their vowels as optional marks and both normally leave
        // them out — harakat belong to scripture, poetry and schoolbooks, and
        // niqqud to the same. Kept, they split a word in two: a vocalised
        // catalogue could not be found by anyone typing the ordinary spelling,
        // and neither could the reverse.
        $plain = $this->analyzer->analyze('كتاب');

        $this->assertSame($plain, $this->analyzer->analyze('كِتَاب'), 'harakat');
        $this->assertSame($plain, $this->analyzer->analyze('كــتاب'), 'tatweel');

        $this->assertSame(
            $this->analyzer->analyze('נעל'),
            $this->analyzer->analyze('נַעַל'),
            'niqqud'
        );
    }

    #[Test]
    public function a_script_own_punctuation_ends_a_word(): void
    {
        // A Unicode block holds its script's punctuation alongside its letters,
        // so these used to ride on the word beside them: `كتاب،` is a term no
        // query for `كتاب` ever produces, and the maqaf glued two Hebrew words
        // into one.
        $this->assertSame(['كتاب', 'جديد'], $this->analyzer->analyze('كتاب، جديد'), 'Arabic comma');
        $this->assertSame(['בית', 'ספר'], $this->analyzer->analyze('בית־ספר'), 'Hebrew maqaf');
        $this->assertSame(['3', '4'], $this->analyzer->analyze('3×4'), 'multiplication sign');
    }

    #[Test]
    public function digits_meet_across_writing_systems(): void
    {
        // Unicode knows what each digit is worth, so this is derived rather
        // than decided: a catalogue writing ٢٠٢٤ and a shopper typing 2024 are
        // saying the same thing.
        $this->assertSame(['123'], $this->analyzer->analyze('١٢٣'), 'Arabic-Indic');
        $this->assertSame(['123'], $this->analyzer->analyze('۱۲۳'), 'Extended Arabic-Indic');
        $this->assertSame(['123'], $this->analyzer->analyze('१२३'), 'Devanagari');
    }

    #[Test]
    public function scripts_that_used_to_be_deleted_are_indexed(): void
    {
        // Every one of these analysed to nothing at all, silently — the same
        // deletion 1.x performed on Cyrillic and Japanese with a single
        // `[^a-z0-9]+`, and the reason this release exists.
        $samples = [
            'Bengali'   => 'চামড়ার জুতা',
            'Tamil'     => 'தோல் காலணி',
            'Telugu'    => 'తోలు బూట్లు',
            'Gujarati'  => 'ચામડાના જૂતા',
            'Gurmukhi'  => 'ਚਮੜੇ ਦੀ ਜੁੱਤੀ',
            'Kannada'   => 'ಚರ್ಮದ ಶೂ',
            'Malayalam' => 'ലെതർ ഷൂ',
            'Sinhala'   => 'සම් සපත්තු',
            'Georgian'  => 'ტყავის ფეხსაცმელი',
            'Armenian'  => 'կաշվե կոշիկ',
            'Ethiopic'  => 'የቆዳ ጫማ',
        ];

        foreach ($samples as $script => $text) {
            // Counted off the sample rather than written down beside it: these
            // are two and three words depending on the language, and hardcoding
            // the number tests my reading of Gurmukhi rather than the analyzer.
            $this->assertCount(
                count(explode(' ', $text)),
                $this->analyzer->analyze($text),
                $script
            );
        }
    }

    #[Test]
    public function html_is_stripped_and_entities_decoded(): void
    {
        $this->assertSame(
            $this->analyzer->analyze('cuir marron'),
            $this->analyzer->analyze('<p>cuir <strong>marron</strong></p>')
        );

        $this->assertSame(
            $this->analyzer->analyze('café'),
            $this->analyzer->analyze('caf&eacute;')
        );
    }

    #[Test]
    public function invalid_bytes_cost_one_character_not_the_field(): void
    {
        // A stray Latin-1 byte in a description must not lose the words around it.
        $terms = $this->analyzer->analyze("cuir \xff marron");

        $this->assertContains('cuir', $terms);
        $this->assertContains('marron', $terms);
    }

    // =========================================================================
    // Scripts written without spaces: bigrams over the run
    // =========================================================================

    #[Test]
    public function japanese_is_indexed(): void
    {
        $this->assertSame(
            ['革靴', 'ブラ', 'ラウ', 'ウン'],
            $this->analyzer->analyze('革靴 ブラウン')
        );
    }

    #[Test]
    public function a_script_change_ends_a_run_even_without_a_space(): void
    {
        // 革靴ブラウン has no space in it, but Han running into Katakana marks a
        // word edge just as clearly. A bigram straddling the seam would belong
        // to neither word.
        $this->assertSame(['Han', 'Katakana'], $this->scripts('革靴ブラウン'));

        $this->assertSame(
            $this->analyzer->analyze('革靴 ブラウン'),
            $this->analyzer->analyze('革靴ブラウン')
        );
    }

    #[Test]
    public function a_single_character_run_is_indexed_whole(): void
    {
        $this->assertSame(['革'], $this->analyzer->analyze('革'));
    }

    #[Test]
    public function chinese_is_indexed(): void
    {
        $this->assertSame(['棕色', '色皮', '皮鞋'], $this->analyzer->analyze('棕色皮鞋'));
    }

    #[Test]
    public function thai_is_indexed(): void
    {
        $terms = $this->analyzer->analyze('หนัง');

        $this->assertSame(['Thai'], $this->scripts('หนัง'));
        $this->assertNotEmpty($terms);
    }

    #[Test]
    public function korean_is_indexed(): void
    {
        $this->assertSame(['가죽', '죽신', '신발'], $this->analyzer->analyze('가죽신발'));
    }

    // =========================================================================
    // Other space-separated scripts
    // =========================================================================

    #[Test]
    public function russian_is_indexed(): void
    {
        $this->assertSame(['кожа'], $this->analyzer->analyze('кожа'));
    }

    #[Test]
    public function russian_case_and_yo_are_folded(): void
    {
        $this->assertSame($this->analyzer->analyze('кожа'), $this->analyzer->analyze('КОЖА'));
        $this->assertSame($this->analyzer->analyze('елка'), $this->analyzer->analyze('ёлка'));
    }

    #[Test]
    public function greek_is_indexed_and_its_accents_folded(): void
    {
        $this->assertSame(['Greek'], $this->scripts('δέρμα'));
        $this->assertSame($this->analyzer->analyze('δερμα'), $this->analyzer->analyze('δέρμα'));
        $this->assertSame($this->analyzer->analyze('δερμα'), $this->analyzer->analyze('ΔΕΡΜΑ'));
    }

    #[Test]
    public function arabic_is_indexed(): void
    {
        $this->assertSame(['Arabic'], $this->scripts('جلد'));
        $this->assertSame(['جلد'], $this->analyzer->analyze('جلد'));
    }

    #[Test]
    public function hebrew_is_indexed(): void
    {
        $this->assertSame(['Hebrew'], $this->scripts('עור'));
        $this->assertNotEmpty($this->analyzer->analyze('עור'));
    }

    #[Test]
    public function a_mixed_language_field_keeps_every_script(): void
    {
        $scripts = $this->scripts('Nike 革靴 кожа δέρμα');

        $this->assertSame(['Latin', 'Han', 'Cyrillic', 'Greek'], $scripts);
    }

    // =========================================================================
    // The regression that motivated the rewrite
    // =========================================================================

    #[Test]
    public function non_latin_text_is_no_longer_discarded(): void
    {
        // Under 1.x every one of these produced an empty term list, because the
        // tokenizer replaced anything outside [a-z0-9] with a space.
        foreach (['革靴', 'кожа', 'δέρμα', 'جلد', 'עור', 'หนัง', '가죽'] as $text) {
            $this->assertNotEmpty(
                $this->analyzer->analyze($text),
                "'$text' must produce terms"
            );
        }
    }

    #[Test]
    public function documents_and_queries_go_through_the_same_rules(): void
    {
        // A query is analysed by the same object as the document, so the two
        // cannot drift apart. Every term of the query must appear among the
        // document's.
        $document = $this->analyzer->analyze('Chaussure en cuir marron élégante');
        $query    = $this->analyzer->analyze('CUIR Élégante');

        foreach ($query as $term) {
            $this->assertContains($term, $document, "query term '$term'");
        }
    }

    // =========================================================================
    // Edges
    // =========================================================================

    #[Test]
    public function empty_and_meaningless_input_produces_nothing(): void
    {
        $this->assertSame([], $this->analyzer->analyze(''));
        $this->assertSame([], $this->analyzer->analyze('   '));
        $this->assertSame([], $this->analyzer->analyze('!!! ... ---'));
        $this->assertSame([], $this->analyzer->analyze('<br /><hr/>'));
    }

    #[Test]
    public function emoji_are_separators_rather_than_terms(): void
    {
        $this->assertSame(
            $this->analyzer->analyze('cuir marron'),
            $this->analyzer->analyze('cuir 😀 marron')
        );
    }

    #[Test]
    public function runs_expose_their_script_for_diagnosis(): void
    {
        $runs = $this->analyzer->runs('cuir 革靴');

        $this->assertCount(2, $runs);
        $this->assertSame(Script::Latin, $runs[0]['script']);
        $this->assertSame(Script::Han, $runs[1]['script']);
        $this->assertSame('cuir', $runs[0]['bytes']);
    }

    // =========================================================================
    // Occurrences: the same terms, each pointing back at the text it came from
    // =========================================================================

    #[Test]
    public function every_occurrence_spans_the_text_it_was_cut_from(): void
    {
        ['text' => $text, 'terms' => $terms] = $this->analyzer->occurrences('Brown leather shoe');

        $this->assertSame('Brown leather shoe', $text);

        foreach ($terms as [$term, $start, $end]) {
            $span = substr($text, $start, $end - $start);

            // A term is its own span, once the boundary markers — which are
            // not text — are taken back off.
            $this->assertSame(strtolower(trim($term, '#')), strtolower($span));
        }
    }

    #[Test]
    public function occurrences_are_not_deduplicated(): void
    {
        // Two places to mark, not one term. The offsets are what distinguishes
        // them, which is the whole reason this exists next to analyze().
        $terms = $this->analyzer->occurrences('cuir cuir')['terms'];

        $this->assertCount(2, $terms);
        $this->assertSame(['cuir', 0, 4], $terms[0]);
        $this->assertSame(['cuir', 5, 9], $terms[1]);
    }

    #[Test]
    public function the_text_of_an_occurrence_is_the_plain_text(): void
    {
        $raw = 'en <b>cuir</b> &amp; daim';

        ['text' => $text, 'terms' => $terms] = $this->analyzer->occurrences($raw);

        $this->assertSame('en cuir & daim', $text);

        $starts = [];

        foreach ($terms as [$term, $start]) {
            $starts[$term] = $start;
        }

        // `daim` begins at byte 10 of the plain text and at byte 21 of the
        // value the caller passed in. An offset means the former, which is why
        // the text it indexes into is handed back with it.
        $this->assertSame(10, $starts['daim']);
        $this->assertSame(21, strpos($raw, 'daim'));
    }

    #[Test]
    public function a_folded_away_mark_belongs_to_the_character_it_sat_on(): void
    {
        // "café" decomposed: the combining acute has no term of its own, so its
        // bytes are attached to the `e` before it. Anything else would leave an
        // orphan accent outside a highlighted span.
        $decomposed = 'cafe' . "\xcc\x81";

        ['text' => $text, 'terms' => $terms] = $this->analyzer->occurrences($decomposed);

        $last = end($terms);

        // The word is one term now, so its span is the whole word — accent
        // included, which is the property that mattered all along.
        $this->assertSame('cafe' . "\xcc\x81", substr($text, $last[1], $last[2] - $last[1]));
    }

    // `no_term_is_edge_anchored_now_that_a_word_is_a_term` and
    // `a_continuous_script_has_no_edges_to_be_anchored_to` stood here. Both
    // asserted that a flag was false, and it had been unconditionally false
    // since a word became its own term — a test of a constant. The flag is
    // gone from `occurrences()` and they go with it, rather than being kept as
    // a way of noticing that `false === false`.

    #[Test]
    public function occurrences_agree_with_analyze(): void
    {
        // The property that makes highlighting trustworthy: it cannot mark
        // anything the index did not index, because both come from here.
        foreach (['Chaussure en cuir', '革靴 ブラウン', 'Коричневые туфли', 'iphone12', ''] as $text) {
            $occurred = [];

            foreach ($this->analyzer->occurrences($text)['terms'] as [$term]) {
                $occurred[$term] = true;
            }

            $this->assertSame($this->analyzer->analyze($text), array_keys($occurred));
        }
    }
}
