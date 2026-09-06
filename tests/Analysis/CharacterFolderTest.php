<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Analysis;

use Ols\PhpFts\Analysis\CharacterFolder;
use Ols\PhpFts\Analysis\Utf8;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CharacterFolderTest extends TestCase
{
    // =========================================================================
    // Properties that must hold across whole ranges
    //
    // Case tables are written by hand from Unicode charts, so the interesting
    // failures are not "this letter is wrong" but "this stretch of the chart
    // has a layout I assumed away". Sweeping a range for a property catches
    // that; a list of examples does not.
    // =========================================================================

    #[Test]
    public function lowercasing_is_idempotent(): void
    {
        // The guard that would have caught the real bug in this file: Latin
        // Extended-A does not keep the same upper/lower parity throughout, so
        // an even *lowercase* letter such as ł (U+0142) was being "lowercased"
        // into Ń (U+0143). Folding twice would then have moved it again.
        for ($codepoint = 0; $codepoint <= 0x2FFF; $codepoint++) {
            $once  = CharacterFolder::caseFold($codepoint);
            $twice = CharacterFolder::caseFold($once);

            $this->assertSame(
                $once,
                $twice,
                sprintf('U+%04X folds to U+%04X, which folds again to U+%04X', $codepoint, $once, $twice)
            );
        }
    }

    #[Test]
    public function folding_is_idempotent(): void
    {
        for ($codepoint = 0; $codepoint <= 0x2FFF; $codepoint++) {
            $folded = CharacterFolder::fold($codepoint);

            if ($folded === '') {
                continue;
            }

            $again = '';
            foreach (Utf8::codepoints($folded) as $point) {
                $again .= CharacterFolder::fold($point);
            }

            $this->assertSame($folded, $again, sprintf('U+%04X', $codepoint));
        }
    }

    #[Test]
    public function no_lowercase_letter_is_moved_by_case_folding(): void
    {
        // Every lowercase Latin, Greek and Cyrillic letter must be a fixed
        // point. Checked against the code points the folder claims to know.
        $lowercase = array_merge(
            range(0x61, 0x7A),      // a-z
            range(0xE0, 0xFE),      // Latin-1 lowercase
            range(0x3B1, 0x3C9),    // Greek lowercase
            range(0x430, 0x44F),    // Cyrillic lowercase
            range(0x450, 0x45F),    // Cyrillic ѐ-џ
        );

        foreach ($lowercase as $codepoint) {
            if ($codepoint === 0xF7) {   // division sign, not a letter
                continue;
            }

            $this->assertSame(
                $codepoint,
                CharacterFolder::caseFold($codepoint),
                sprintf('U+%04X is already lowercase', $codepoint)
            );
        }
    }

    #[Test]
    public function every_capital_folds_onto_its_own_lowercase(): void
    {
        $pairs = [
            [0x41, 0x61], [0x5A, 0x7A],                 // A/a  Z/z
            [0xC0, 0xE0], [0xDE, 0xFE],                 // À/à  Þ/þ
            [0x100, 0x101], [0x141, 0x142],             // Ā/ā  Ł/ł
            [0x179, 0x17A], [0x17D, 0x17E],             // Ź/ź  Ž/ž
            [0x14C, 0x14D],                             // Ō/ō
            [0x218, 0x219], [0x21A, 0x21B],             // Ș/ș  Ț/ț
            [0x391, 0x3B1], [0x3A9, 0x3C9],             // Α/α  Ω/ω
            [0x410, 0x430], [0x42F, 0x44F],             // А/а  Я/я
            [0x401, 0x451],                             // Ё/ё
        ];

        foreach ($pairs as [$upper, $lower]) {
            $this->assertSame(
                CharacterFolder::fold($lower),
                CharacterFolder::fold($upper),
                sprintf('U+%04X and U+%04X must fold alike', $upper, $lower)
            );
        }
    }

    // =========================================================================
    // The individual rules
    // =========================================================================

    #[Test]
    public function full_width_forms_fold_to_ascii(): void
    {
        $this->assertSame(0x41, CharacterFolder::widthFold(0xFF21));   // Ａ
        $this->assertSame(0x7A, CharacterFolder::widthFold(0xFF5A));   // ｚ
        $this->assertSame(0x30, CharacterFolder::widthFold(0xFF10));   // ０
        $this->assertSame(0x20, CharacterFolder::widthFold(0x3000));   // ideographic space
    }

    #[Test]
    public function combining_marks_fold_to_nothing(): void
    {
        $this->assertSame('', CharacterFolder::fold(0x0301));   // combining acute
        $this->assertSame('', CharacterFolder::fold(0x0308));   // combining diaeresis
    }

    #[Test]
    public function letters_that_expand_produce_several_characters(): void
    {
        $this->assertSame('ss', CharacterFolder::fold(0x00DF));   // ß
        $this->assertSame('ae', CharacterFolder::fold(0x00E6));   // æ
        $this->assertSame('oe', CharacterFolder::fold(0x0153));   // œ
        $this->assertSame('ffi', CharacterFolder::fold(0xFB03));  // ﬃ
    }

    #[Test]
    public function kana_is_not_folded_between_the_two_syllabaries(): void
    {
        // Deliberate: katakana marks loanwords in Japanese, and erasing that
        // makes distinct words collide. Major Japanese search stacks keep them
        // apart too.
        $this->assertNotSame(
            CharacterFolder::fold(0x3042),   // あ
            CharacterFolder::fold(0x30A2),   // ア
        );
    }

    #[Test]
    public function han_and_kana_pass_through_untouched(): void
    {
        foreach ([0x9769, 0x9774, 0x3042, 0x30A2, 0xAC00] as $codepoint) {
            $this->assertSame(
                Utf8::chr($codepoint),
                CharacterFolder::fold($codepoint),
                sprintf('U+%04X', $codepoint)
            );
        }
    }
}
