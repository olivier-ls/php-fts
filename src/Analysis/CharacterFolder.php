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
 * Three steps, in order:
 *
 *   1. **Compatibility width.** Full-width ASCII (Ａ-Ｚ, ０-９) folds to plain
 *      ASCII, and the ideographic space folds to a space. This is the part of
 *      NFKC that matters in practice: Japanese and Chinese text mixes
 *      full-width Latin freely, and without this a product reference typed
 *      ＡＢ-１２３ would never match AB-123.
 *
 *   2. **Combining marks.** Text that arrives decomposed — a plain `e` followed
 *      by U+0301 — has its marks dropped, which is the same result folding the
 *      precomposed `é` gives. macOS filenames and some APIs produce this form.
 *
 *   3. **The table.** Case and diacritics in one lookup, from
 *      {@see FoldingTables::FOLD}.
 *
 * ── Why the table is generated ──────────────────────────────────────────────
 *
 * Because the hand-written one was wrong wherever nobody had looked, and
 * silently. It folded by arithmetic — "Latin Extended-A pairs each capital with
 * the code point above it, but the parity is not uniform across the block" —
 * with a table of accented letters beside it, and both were written against
 * French. Measured over every code point this engine claims: **916 folded
 * differently in upper and lower case**. Vietnamese `VIỆT` became `viỆt` and
 * never met `việt`, so a catalogue with capitalised titles — the norm in
 * e-commerce — was unfindable in lower case. Ukrainian `Ґ`, Kazakh `Ә` and
 * `Ң`, and every polytonic Greek capital kept their case too.
 *
 * `tools/generate-folding.php` derives the table from Unicode's own case
 * mappings and decompositions, using ext-intl and ext-mbstring — at generation
 * time only, so the library still needs no extension at runtime. What it
 * decides rather than derives is listed there: the letters Unicode declines to
 * decompose but search convention folds anyway (ß to `ss`, æ to `ae`, ø to
 * `o`), and how far to strip per script.
 *
 * `tests/Analysis/FoldingClosureTest.php` asserts the result is closed, over
 * ranges it reads back out of `Script` rather than repeating.
 *
 * ── What this deliberately does not do ──────────────────────────────────────
 *
 * Katakana is *not* folded to hiragana. It would raise recall slightly and cost
 * precision: in Japanese, katakana marks loanwords, and erasing that makes
 * distinct words collide. Major Japanese search stacks do not fold it either.
 *
 * Cyrillic folds ё to е, which is what Russian search conventionally does, and
 * does not fold й to и — that would merge two letters Russian considers
 * distinct, and it is the reason the generator strips marks per script rather
 * than everywhere.
 *
 * Half-width katakana (U+FF61–U+FF9F) is not yet folded to full-width. It
 * appears in legacy data and deserves handling, but doing it properly means
 * recombining voiced sound marks, which is a table of its own. Noted rather
 * than half-done.
 */
final class CharacterFolder
{
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

        // ASCII is not in the table — it would be 26 entries earning nothing,
        // since foldCodepoints() never reaches this far for it. It has to be
        // here rather than only there because widthFold() lands on ASCII: a
        // full-width Ａ arrives as U+FF21 and leaves as 0x41, and the caller
        // that short-circuits ASCII never saw it.
        if ($codepoint < 0x80) {
            return chr($codepoint >= 0x41 && $codepoint <= 0x5A ? $codepoint + 0x20 : $codepoint);
        }

        if (self::isCombiningMark($codepoint)) {
            return '';
        }

        return FoldingTables::FOLD[$codepoint] ?? Utf8::chr($codepoint);
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
     *
     * Arithmetic rather than a table entry, because it is a genuine rule with
     * no exceptions in it — unlike case, where believing that cost this file
     * 916 code points.
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
     * Combining diacritical marks, which fold to nothing.
     *
     * Handles text that arrives decomposed: `e` + U+0301 gives the same result
     * as the precomposed `é`, so both spellings reach the index as `e`.
     *
     * Deliberately not "everything Unicode calls a mark". A Devanagari matra
     * and a Thai vowel sign are marks and are *obligatory* — stripping them
     * would delete the vowels of the word. What belongs here is only the marks
     * a script treats as optional, which is a judgement per script rather than
     * a category lookup.
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
