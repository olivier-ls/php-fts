<?php

declare(strict_types=1);

namespace Ols\PhpFts\Analysis;

/**
 * Reduces a code point to the form the index stores.
 *
 * A search engine should not care that someone typed "CUIR", "cuir" or "cuír",
 * nor that a Japanese catalogue wrote its product codes in full-width digits.
 * Folding removes those distinctions before anything is indexed, so the same
 * rules apply to documents and to queries and the two cannot disagree.
 *
 * Four steps, in order:
 *
 *   1. **Compatibility width.** Full-width ASCII (Ａ-Ｚ, ０-９) folds to plain
 *      ASCII, and the ideographic space folds to a space. This is the part of
 *      NFKC that matters in practice: Japanese and Chinese text mixes
 *      full-width Latin freely, and without this a product reference typed
 *      ＡＢ-１２３ would never match AB-123.
 *
 *   2. **Case.** Lowercase, by rule rather than by table wherever the ranges
 *      allow it: ASCII, Latin-1, Latin Extended-A, Greek and Cyrillic all have
 *      regular upper/lower layouts, with a handful of exceptions worth naming.
 *
 *   3. **Combining marks.** Text that arrives decomposed — a plain `e` followed
 *      by U+0301 — has its marks dropped, which is the same result folding the
 *      precomposed `é` gives. macOS filenames and some APIs produce this form.
 *
 *   4. **Diacritics.** Precomposed Latin letters fold to their ASCII base, so
 *      "café" and "cafe" are one term. Greek keeps its letters but loses its
 *      accents; Cyrillic folds ё to е, which is what Russian search
 *      conventionally does.
 *
 * ── What this deliberately does not do ──────────────────────────────────────
 *
 * Katakana is *not* folded to hiragana. It would raise recall slightly and cost
 * precision: in Japanese, katakana marks loanwords, and erasing that makes
 * distinct words collide. Major Japanese search stacks do not fold it either.
 *
 * Half-width katakana (U+FF61–U+FF9F) is not yet folded to full-width. It
 * appears in legacy data and deserves handling, but doing it properly means
 * recombining voiced sound marks, which is a table of its own. Noted rather
 * than half-done.
 */
final class CharacterFolder
{
    /**
     * Precomposed Latin letters to their ASCII base.
     *
     * Only cases where the base is not obtained by arithmetic. Multi-character
     * results are deliberate: German ß becomes "ss", the Latin ligatures become
     * their letters, so that "straße" and "strasse" meet.
     *
     * @var array<int, string>
     */
    private const LATIN_TO_ASCII = [
        0x00E0 => 'a', 0x00E1 => 'a', 0x00E2 => 'a', 0x00E3 => 'a', 0x00E4 => 'a', 0x00E5 => 'a',
        0x00E6 => 'ae', 0x00E7 => 'c',
        0x00E8 => 'e', 0x00E9 => 'e', 0x00EA => 'e', 0x00EB => 'e',
        0x00EC => 'i', 0x00ED => 'i', 0x00EE => 'i', 0x00EF => 'i',
        0x00F0 => 'd', 0x00F1 => 'n',
        0x00F2 => 'o', 0x00F3 => 'o', 0x00F4 => 'o', 0x00F5 => 'o', 0x00F6 => 'o', 0x00F8 => 'o',
        0x00F9 => 'u', 0x00FA => 'u', 0x00FB => 'u', 0x00FC => 'u',
        0x00FD => 'y', 0x00FE => 'th', 0x00FF => 'y',
        0x00DF => 'ss',

        // Latin Extended-A, lowercase halves.
        0x0101 => 'a', 0x0103 => 'a', 0x0105 => 'a',
        0x0107 => 'c', 0x0109 => 'c', 0x010B => 'c', 0x010D => 'c',
        0x010F => 'd', 0x0111 => 'd',
        0x0113 => 'e', 0x0115 => 'e', 0x0117 => 'e', 0x0119 => 'e', 0x011B => 'e',
        0x011D => 'g', 0x011F => 'g', 0x0121 => 'g', 0x0123 => 'g',
        0x0125 => 'h', 0x0127 => 'h',
        0x0129 => 'i', 0x012B => 'i', 0x012D => 'i', 0x012F => 'i', 0x0131 => 'i',
        0x0135 => 'j', 0x0137 => 'k',
        0x013A => 'l', 0x013C => 'l', 0x013E => 'l', 0x0140 => 'l', 0x0142 => 'l',
        0x0144 => 'n', 0x0146 => 'n', 0x0148 => 'n',
        0x014D => 'o', 0x014F => 'o', 0x0151 => 'o', 0x0153 => 'oe',
        0x0155 => 'r', 0x0157 => 'r', 0x0159 => 'r',
        0x015B => 's', 0x015D => 's', 0x015F => 's', 0x0161 => 's',
        0x0163 => 't', 0x0165 => 't', 0x0167 => 't',
        0x0169 => 'u', 0x016B => 'u', 0x016D => 'u', 0x016F => 'u', 0x0171 => 'u', 0x0173 => 'u',
        0x0175 => 'w', 0x0177 => 'y', 0x017A => 'z', 0x017C => 'z', 0x017E => 'z',
        0x017F => 's',

        // Latin Extended-B, the letters Romanian and Baltic languages need.
        0x0219 => 's', 0x021B => 't', 0x0233 => 'y',
        0x01CE => 'a', 0x01D0 => 'i', 0x01D2 => 'o', 0x01D4 => 'u',

        // Ligatures that turn up in PDF and CMS exports.
        0xFB00 => 'ff', 0xFB01 => 'fi', 0xFB02 => 'fl', 0xFB03 => 'ffi', 0xFB04 => 'ffl',
        0xFB05 => 'st', 0xFB06 => 'st',
    ];

    /**
     * Greek letters carrying an accent, folded to the plain letter.
     *
     * @var array<int, int>
     */
    private const GREEK_ACCENTS = [
        0x03AC => 0x03B1, 0x03AD => 0x03B5, 0x03AE => 0x03B7, 0x03AF => 0x03B9,
        0x03CC => 0x03BF, 0x03CD => 0x03C5, 0x03CE => 0x03C9,
        0x03CA => 0x03B9, 0x03CB => 0x03C5, 0x0390 => 0x03B9, 0x03B0 => 0x03C5,
        0x03C2 => 0x03C3,   // final sigma
    ];

    private function __construct()
    {
    }

    /**
     * Folds one code point, returning the UTF-8 bytes it becomes.
     *
     * Returns a string rather than a code point because a few characters fold
     * to more than one — ß to "ss", æ to "ae" — and an empty string because
     * combining marks fold to nothing at all.
     */
    public static function fold(int $codepoint): string
    {
        $codepoint = self::widthFold($codepoint);

        if (self::isCombiningMark($codepoint)) {
            return '';
        }

        $codepoint = self::caseFold($codepoint);

        if (isset(self::LATIN_TO_ASCII[$codepoint])) {
            return self::LATIN_TO_ASCII[$codepoint];
        }

        if (isset(self::GREEK_ACCENTS[$codepoint])) {
            return Utf8::chr(self::GREEK_ACCENTS[$codepoint]);
        }

        if ($codepoint === 0x0451) {        // ё
            return Utf8::chr(0x0435);       // е
        }

        return Utf8::chr($codepoint);
    }

    /**
     * Folds one code point to the code points it becomes.
     *
     * What the analyzer actually calls. Returns zero code points for a
     * combining mark, one for almost everything, and several for the handful
     * that expand — ß, æ, œ, the ligatures.
     *
     * @return int[]
     */
    public static function foldCodepoints(int $codepoint): array
    {
        // Unaccented ASCII is the overwhelming majority of indexed text, and
        // short-circuiting it here keeps the common case to one comparison.
        if ($codepoint < 0x80) {
            return [$codepoint >= 0x41 && $codepoint <= 0x5A ? $codepoint + 0x20 : $codepoint];
        }

        $folded = self::fold($codepoint);

        if ($folded === '') {
            return [];
        }

        if (strlen($folded) === 1) {
            return [ord($folded)];
        }

        return Utf8::codepoints($folded);
    }

    /**
     * Full-width forms to their ASCII equivalents.
     */
    public static function widthFold(int $codepoint): int
    {
        // Ａ-Ｚ ａ-ｚ ０-９ and full-width punctuation sit exactly 0xFEE0 above
        // their ASCII counterparts.
        if ($codepoint >= 0xFF01 && $codepoint <= 0xFF5E) {
            return $codepoint - 0xFEE0;
        }

        if ($codepoint === 0x3000) {        // ideographic space
            return 0x20;
        }

        return $codepoint;
    }

    /**
     * Lowercases a code point.
     */
    public static function caseFold(int $codepoint): int
    {
        // ASCII.
        if ($codepoint >= 0x41 && $codepoint <= 0x5A) {
            return $codepoint + 0x20;
        }

        // Latin-1 supplement. U+00D7 is the multiplication sign, not a letter.
        if ($codepoint >= 0x00C0 && $codepoint <= 0x00DE && $codepoint !== 0x00D7) {
            return $codepoint + 0x20;
        }

        // Latin Extended-A pairs each capital with the code point above it, but
        // the parity is not uniform across the block: it starts on even
        // capitals, flips to odd for Ĺ-ň and again for Ź-ž, because of the
        // caseless characters wedged in at U+0138, U+0149 and U+0178.
        //
        // Getting this wrong is silent and specific: an even *lowercase* letter
        // like ł (U+0142) would be "lowercased" into Ń (U+0143), so Polish and
        // Croatian text would index as a different language.
        if ($codepoint >= 0x0100 && $codepoint <= 0x017F) {
            return match (true) {
                $codepoint === 0x0130 => 0x0069,   // İ, Turkish dotted capital I
                $codepoint === 0x0178 => 0x00FF,   // Ÿ

                // No uppercase form: ı, ĸ, ŉ, ſ.
                $codepoint === 0x0131, $codepoint === 0x0138,
                $codepoint === 0x0149, $codepoint === 0x017F => $codepoint,

                // Stretches where the capital is the odd code point. The second
                // one needs no upper bound: U+017F, the last code point of the
                // block, was taken by the caseless arm above.
                $codepoint >= 0x0139 && $codepoint <= 0x0148,
                $codepoint >= 0x0179
                    => ($codepoint % 2 === 1) ? $codepoint + 1 : $codepoint,

                // Everywhere else the capital is the even code point.
                default => ($codepoint % 2 === 0) ? $codepoint + 1 : $codepoint,
            };
        }

        // Latin Extended-B, the pairs Romanian needs.
        if ($codepoint === 0x0218 || $codepoint === 0x021A) {
            return $codepoint + 1;
        }

        // Greek. U+03A2 is unassigned.
        if ($codepoint >= 0x0391 && $codepoint <= 0x03A9 && $codepoint !== 0x03A2) {
            return $codepoint + 0x20;
        }

        // Greek capitals carrying accents.
        if ($codepoint >= 0x0386 && $codepoint <= 0x038F) {
            return match ($codepoint) {
                0x0386 => 0x03AC, 0x0388 => 0x03AD, 0x0389 => 0x03AE, 0x038A => 0x03AF,
                0x038C => 0x03CC, 0x038E => 0x03CD, 0x038F => 0x03CE,
                default => $codepoint,
            };
        }

        // Cyrillic А-Я.
        if ($codepoint >= 0x0410 && $codepoint <= 0x042F) {
            return $codepoint + 0x20;
        }

        // Cyrillic Ѐ-Џ, which sit 0x50 above their lowercase forms.
        if ($codepoint >= 0x0400 && $codepoint <= 0x040F) {
            return $codepoint + 0x50;
        }

        return $codepoint;
    }

    /**
     * Combining diacritical marks, which fold to nothing.
     *
     * Handles text that arrives decomposed: `e` + U+0301 gives the same result
     * as the precomposed `é`, so both spellings reach the index as `e`.
     */
    public static function isCombiningMark(int $codepoint): bool
    {
        return ($codepoint >= 0x0300 && $codepoint <= 0x036F)
            || ($codepoint >= 0x1AB0 && $codepoint <= 0x1AFF)
            || ($codepoint >= 0x1DC0 && $codepoint <= 0x1DFF)
            || ($codepoint >= 0x20D0 && $codepoint <= 0x20FF)
            || ($codepoint >= 0xFE20 && $codepoint <= 0xFE2F);
    }
}
