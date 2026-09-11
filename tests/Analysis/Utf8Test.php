<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Analysis;

use Ols\PhpFts\Analysis\Utf8;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UTF-8 decoding without ext-mbstring.
 *
 * The tests below deliberately never call an mb_* function, including to build
 * their own fixtures: if they did, they would silently stop proving that the
 * library runs on a PHP without it.
 */
class Utf8Test extends TestCase
{
    // =========================================================================
    // Decoding what is valid
    // =========================================================================

    /**
     * @return array<string, array{string, int[]}>
     */
    public static function validSequences(): array
    {
        return [
            'empty'                 => ['', []],
            'ascii'                 => ['abc', [0x61, 0x62, 0x63]],
            'ascii boundary'        => ["\x7f", [0x7F]],
            'two bytes, latin'      => ['é', [0xE9]],
            'two bytes, cyrillic'   => ['ж', [0x436]],
            'two bytes boundary'    => ["\xdf\xbf", [0x7FF]],
            'three bytes boundary'  => ["\xe0\xa0\x80", [0x800]],
            'three bytes, han'      => ['革', [0x9769]],
            'three bytes, hiragana' => ['あ', [0x3042]],
            'three bytes, thai'     => ['ก', [0xE01]],
            'four bytes, emoji'     => ['😀', [0x1F600]],
            'four bytes boundary'   => ["\xf4\x8f\xbf\xbf", [0x10FFFF]],
            'mixed scripts'         => ['a革ж😀', [0x61, 0x9769, 0x436, 0x1F600]],
        ];
    }

    /** @param list<int> $expected */
    #[Test]
    #[DataProvider('validSequences')]
    public function it_decodes_valid_sequences(string $text, array $expected): void
    {
        $this->assertSame($expected, Utf8::codepoints($text));
    }

    /** @param list<int> $codepoints */
    #[Test]
    #[DataProvider('validSequences')]
    public function encoding_reverses_decoding(string $text, array $codepoints): void
    {
        $this->assertSame($text, Utf8::implode($codepoints));
    }

    #[Test]
    public function length_counts_characters_not_bytes(): void
    {
        $text = 'a革ж😀';

        $this->assertSame(10, strlen($text), 'ten bytes');
        $this->assertSame(4, Utf8::length($text), 'four characters');
    }

    #[Test]
    public function every_code_point_round_trips(): void
    {
        // Walks the whole space in steps, skipping the surrogate block, so that
        // a mistake in any of the four width branches shows up.
        for ($codepoint = 0; $codepoint <= 0x10FFFF; $codepoint += 997) {
            if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
                continue;
            }

            $this->assertSame(
                [$codepoint],
                Utf8::codepoints(Utf8::chr($codepoint)),
                sprintf('round trip of U+%04X', $codepoint)
            );
        }
    }

    // =========================================================================
    // Rejecting what is not
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSequences(): array
    {
        return [
            // A '/' padded into two bytes. The classic way of sneaking a
            // character past a filter that only looks at the literal byte.
            'overlong slash'          => ["\xc0\xaf"],
            'overlong nul'            => ["\xc1\x81"],
            'overlong three bytes'    => ["\xe0\x80\xaf"],
            'overlong four bytes'     => ["\xf0\x80\x80\xaf"],

            // UTF-16 surrogate halves are not characters.
            'high surrogate'          => ["\xed\xa0\x80"],
            'low surrogate'           => ["\xed\xb0\x80"],

            'above the maximum'       => ["\xf4\x90\x80\x80"],
            'lone continuation byte'  => ["\x80"],
            'truncated two bytes'     => ["\xc3"],
            'truncated three bytes'   => ["\xe9\x9d"],
            'missing continuation'    => ["\xe9\x41\x41"],
            'invalid leading byte'    => ["\xff"],
            'latin-1 mistaken for utf8' => ["caf\xe9"],
        ];
    }

    #[Test]
    #[DataProvider('invalidSequences')]
    public function it_replaces_invalid_sequences(string $text): void
    {
        $this->assertContains(
            Utf8::REPLACEMENT,
            Utf8::codepoints($text),
            'an invalid sequence must yield the replacement character'
        );

        $this->assertFalse(Utf8::isValid($text));
    }

    #[Test]
    public function decoding_resumes_after_a_bad_byte(): void
    {
        // One damaged byte must cost that character, not the rest of the field.
        // A product description with a stray Latin-1 byte still gets indexed.
        $codepoints = Utf8::codepoints("bon\xffjour");

        $this->assertSame(
            [0x62, 0x6F, 0x6E, Utf8::REPLACEMENT, 0x6A, 0x6F, 0x75, 0x72],
            $codepoints
        );
    }

    #[Test]
    public function a_truncated_multibyte_character_costs_one_character(): void
    {
        $this->assertSame(
            [Utf8::REPLACEMENT, 0x61],
            Utf8::codepoints("\xe9a"),
            'the incomplete sequence is replaced and the following byte survives'
        );
    }

    #[Test]
    public function valid_text_is_reported_as_valid(): void
    {
        $this->assertTrue(Utf8::isValid('Brown leather shoe'));
        $this->assertTrue(Utf8::isValid('革靴 ブラウン'));
        $this->assertTrue(Utf8::isValid('Коричневые кожаные туфли'));
        $this->assertTrue(Utf8::isValid('حذاء جلدي بني'));
    }

    // =========================================================================
    // Encoding edge cases
    // =========================================================================

    #[Test]
    public function encoding_an_impossible_code_point_yields_the_replacement(): void
    {
        $replacement = Utf8::chr(Utf8::REPLACEMENT);

        $this->assertSame($replacement, Utf8::chr(-1));
        $this->assertSame($replacement, Utf8::chr(0x110000));
        $this->assertSame($replacement, Utf8::chr(0xD800));
    }

    #[Test]
    public function encoding_produces_the_shortest_form(): void
    {
        // The mirror of the overlong check: the encoder must never produce what
        // the decoder would refuse.
        $this->assertSame(1, strlen(Utf8::chr(0x7F)));
        $this->assertSame(2, strlen(Utf8::chr(0x80)));
        $this->assertSame(2, strlen(Utf8::chr(0x7FF)));
        $this->assertSame(3, strlen(Utf8::chr(0x800)));
        $this->assertSame(3, strlen(Utf8::chr(0xFFFF)));
        $this->assertSame(4, strlen(Utf8::chr(0x10000)));
    }

    #[Test]
    public function offsets_are_reported_when_an_array_is_passed(): void
    {
        $offsets = [];
        $text    = "a\u{00e9}\u{9769}";   // 1 + 2 + 3 bytes

        $this->assertCount(3, Utf8::codepoints($text, $offsets));

        // One entry per code point, plus the length as a sentinel, so that a
        // character's bytes are always $offsets[$i] to $offsets[$i + 1].
        $this->assertSame([0, 1, 3, 6], $offsets);
    }

    #[Test]
    public function offsets_stay_true_across_an_invalid_byte(): void
    {
        // The reason offsets have to be reported rather than recomputed: this
        // decodes to three code points, but U+FFFD consumed one byte where
        // re-encoding it would take three, and everything after would drift.
        $offsets = [];
        $points  = Utf8::codepoints("a\xFFb", $offsets);

        $this->assertSame([0x61, Utf8::REPLACEMENT, 0x62], $points);
        $this->assertSame([0, 1, 2, 3], $offsets);
    }

    #[Test]
    public function nothing_is_collected_unless_it_is_asked_for(): void
    {
        $offsets = null;

        Utf8::codepoints('cuir', $offsets);

        $this->assertNull($offsets);
    }
}
