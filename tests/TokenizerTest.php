<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Tokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    private Tokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new Tokenizer();
    }

    // =========================================================================
    // normalize()
    // =========================================================================

    // --- HTML cleanup --------------------------------------------------------

    #[Test]
    public function normalize_strips_html_tags(): void
    {
        $this->assertSame('hello world', $this->tokenizer->normalize('<p>hello <strong>world</strong></p>'));
    }

    #[Test]
    public function normalize_decodes_html_entities(): void
    {
        $this->assertSame('cafe', $this->tokenizer->normalize('caf&eacute;'));
    }

    #[Test]
    public function normalize_decodes_html_entities_after_stripping_tags(): void
    {
        $this->assertSame('prix en euros', $this->tokenizer->normalize('<p>prix en &euro;uros</p>'));
    }

    #[Test]
    public function normalize_returns_empty_string_when_input_is_only_html_tags(): void
    {
        $this->assertSame('', $this->tokenizer->normalize('<br /><hr/><div></div>'));
    }

    // --- Transliteration -----------------------------------------------------

    #[Test]
    #[DataProvider('provideAccentedChars')]
    public function normalize_transliterates_accented_characters(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->tokenizer->normalize($input));
    }

    public static function provideAccentedChars(): array
    {
        return [
            'é → e'             => ['étoile',     'etoile'],
            'è → e'             => ['crème',       'creme'],
            'ê → e'             => ['forêt',       'foret'],
            'ë → e'             => ['Noël',        'noel'],
            'à → a'             => ['à',           'a'],
            'â → a'             => ['château',     'chateau'],
            'ä → a'             => ['Mädchen',     'madchen'],
            'ç → c'             => ['façade',      'facade'],
            'î → i'             => ['île',         'ile'],
            'ï → i'             => ['naïf',        'naif'],
            'ô → o'             => ['côte',        'cote'],
            'ö → o'             => ['Köln',        'koln'],
            'ù → u'             => ['où',          'ou'],
            'û → u'             => ['sûr',         'sur'],
            'ü → u'             => ['über',        'uber'],
            'ñ → n'             => ['España',      'espana'],
            'æ → ae'            => ['æther',       'aether'],
            'Æ → ae'            => ['Æther',       'aether'],
            'œ → oe'            => ['œuvre',       'oeuvre'],
            'Œ → oe'            => ['Œuvre',       'oeuvre'],
            'ß → ss'            => ['Straße',      'strasse'],
            'š → s'             => ['Štefan',      'stefan'],
            'ž → z'             => ['žába',        'zaba'],
            'Uppercase É → e'   => ['ÉTOILE',      'etoile'],
            'Uppercase Ü → u'   => ['ÜBER',        'uber'],
        ];
    }

    // --- Lowercase -----------------------------------------------------------

    #[Test]
    public function normalize_lowercases_plain_ascii(): void
    {
        $this->assertSame('hello world', $this->tokenizer->normalize('Hello World'));
    }

    #[Test]
    public function normalize_lowercases_mixed_case(): void
    {
        $this->assertSame('phpunit', $this->tokenizer->normalize('PHPUnit'));
    }

    // --- Character cleanup ---------------------------------------------------

    #[Test]
    public function normalize_replaces_special_chars_with_space(): void
    {
        $this->assertSame('hello world', $this->tokenizer->normalize('hello-world'));
    }

    #[Test]
    public function normalize_collapses_multiple_spaces(): void
    {
        $this->assertSame('a b c', $this->tokenizer->normalize('a   b   c'));
    }

    #[Test]
    public function normalize_trims_leading_and_trailing_spaces(): void
    {
        $this->assertSame('hello', $this->tokenizer->normalize('  hello  '));
    }

    #[Test]
    public function normalize_keeps_digits(): void
    {
        $this->assertSame('ref 42 abc', $this->tokenizer->normalize('ref-42-abc'));
    }

    #[Test]
    public function normalize_returns_empty_string_when_only_special_chars(): void
    {
        $this->assertSame('', $this->tokenizer->normalize('!@#$%^&*()'));
    }

    // --- Edge cases ----------------------------------------------------------

    #[Test]
    public function normalize_returns_empty_string_on_empty_input(): void
    {
        $this->assertSame('', $this->tokenizer->normalize(''));
    }

    #[Test]
    public function normalize_handles_numeric_string(): void
    {
        $this->assertSame('42', $this->tokenizer->normalize('42'));
    }

    #[Test]
    public function normalize_handles_zero_string(): void
    {
        $this->assertSame('0', $this->tokenizer->normalize('0'));
    }

    #[Test]
    public function normalize_handles_whitespace_only_input(): void
    {
        $this->assertSame('', $this->tokenizer->normalize('   '));
    }

    #[Test]
    public function normalize_handles_newlines_and_tabs(): void
    {
        $this->assertSame('a b', $this->tokenizer->normalize("a\n\tb"));
    }

    // =========================================================================
    // tokenize()
    // =========================================================================

    // --- Empty / blank inputs ------------------------------------------------

    #[Test]
    public function tokenize_returns_empty_array_on_empty_string(): void
    {
        $this->assertSame([], $this->tokenizer->tokenize(''));
    }

    #[Test]
    public function tokenize_returns_empty_array_when_normalized_is_empty(): void
    {
        $this->assertSame([], $this->tokenizer->tokenize('!!!'));
    }

    #[Test]
    public function tokenize_returns_empty_array_on_only_html_tags(): void
    {
        $this->assertSame([], $this->tokenizer->tokenize('<br /><p></p>'));
    }

    // --- Single character word -----------------------------------------------

    #[Test]
    public function tokenize_produces_single_trigram_for_one_char_word(): void
    {
        // "a" → "#a#" → ['#a#']
        $this->assertSame(['#a#'], $this->tokenizer->tokenize('a'));
    }

    // --- Two-character word --------------------------------------------------

    #[Test]
    public function tokenize_produces_correct_trigrams_for_two_char_word(): void
    {
        // "en" → "#en#" → ['#en', 'en#']
        $this->assertSame(['#en', 'en#'], $this->tokenizer->tokenize('en'));
    }

    // --- Standard word -------------------------------------------------------

    #[Test]
    public function tokenize_produces_correct_trigrams_for_standard_word(): void
    {
        // "cuir" → "#cuir#" → ['#cu', 'cui', 'uir', 'ir#']
        $expected = ['#cu', 'cui', 'uir', 'ir#'];
        $this->assertSame($expected, $this->tokenizer->tokenize('cuir'));
    }

    // --- Multi-word input ----------------------------------------------------

    #[Test]
    public function tokenize_handles_multi_word_input(): void
    {
        // "ab cd" → "#ab#" + "#cd#"
        $result = $this->tokenizer->tokenize('ab cd');

        $this->assertContains('#ab', $result);
        $this->assertContains('ab#', $result);
        $this->assertContains('#cd', $result);
        $this->assertContains('cd#', $result);
    }

    // --- Deduplication -------------------------------------------------------

    #[Test]
    public function tokenize_deduplicates_identical_trigrams(): void
    {
        // "aa aa" → word "aa" appears twice → trigrams #aa, aa# deduplicated
        $result = $this->tokenizer->tokenize('aa aa');

        $this->assertCount(2, array_filter($result, fn($t) => $t === '#aa'));
        // After dedup, #aa must appear exactly once
        $this->assertSame(1, count(array_keys($result, '#aa')));
    }

    #[Test]
    public function tokenize_deduplicates_trigrams_shared_between_different_words(): void
    {
        // "bar bard" → both produce 'bar' trigram
        $result = $this->tokenizer->tokenize('bar bard');

        $this->assertSame(1, count(array_keys($result, 'bar')));
    }

    // --- Indexed array (array_values) ----------------------------------------

    #[Test]
    public function tokenize_returns_zero_indexed_array(): void
    {
        $result = $this->tokenizer->tokenize('hello');

        $this->assertSame(array_values($result), $result);
    }

    // --- Normalization pipeline applied before tokenizing --------------------

    #[Test]
    public function tokenize_normalizes_accents_before_generating_trigrams(): void
    {
        // "été" → "ete" → "#et", "ete", "te#"
        $resultAccented = $this->tokenizer->tokenize('été');
        $resultPlain    = $this->tokenizer->tokenize('ete');

        $this->assertSame($resultPlain, $resultAccented);
    }

    #[Test]
    public function tokenize_normalizes_html_before_generating_trigrams(): void
    {
        $resultHtml  = $this->tokenizer->tokenize('<b>chat</b>');
        $resultPlain = $this->tokenizer->tokenize('chat');

        $this->assertSame($resultPlain, $resultHtml);
    }

    #[Test]
    public function tokenize_handles_numeric_tokens(): void
    {
        // "42" → "#42", "42#" — must not be cast to int by array_unique
        $result = $this->tokenizer->tokenize('42');

        $this->assertContains('#42', $result);
        $this->assertContains('42#', $result);
        foreach ($result as $trigram) {
            $this->assertIsString($trigram);
        }
    }

    // --- Full pipeline integration -------------------------------------------

    #[Test]
    public function tokenize_full_pipeline_with_mixed_input(): void
    {
        // "<p>Sac à main</p>" → "sac a main"
        $result = $this->tokenizer->tokenize('<p>Sac à main</p>');

        // All expected trigrams from "sac", "a", "main" must be present
        $this->assertContains('#sa', $result);
        $this->assertContains('sac', $result);
        $this->assertContains('ac#', $result);
        $this->assertContains('#a#', $result);
        $this->assertContains('#ma', $result);
        $this->assertContains('mai', $result);
        $this->assertContains('ain', $result);
        $this->assertContains('in#', $result);

        // No duplicates
        $this->assertSame(count($result), count(array_unique($result)));
    }
}
