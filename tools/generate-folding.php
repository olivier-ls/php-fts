<?php

declare(strict_types=1);

/*
 * Regenerates src/Analysis/FoldingTables.php.
 *
 *     php tools/generate-folding.php
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * `CharacterFolder` used to fold by hand-written arithmetic — "Latin Extended-A
 * pairs each capital with the code point above it, but the parity is not
 * uniform across the block" — and by hand-written tables of accented letters.
 * Both were written against French and both were incomplete everywhere else,
 * silently: Vietnamese `VIỆT` folded to `viỆt` and never met `việt`, Ukrainian
 * `Ґ` and Kazakh `Ә` kept their capitals, polytonic Greek kept its breathings.
 * 916 code points in the ranges this engine claims folded differently in upper
 * and lower case.
 *
 * A hand-written table is complete only where somebody thought to look, and
 * driving it by bug report is the endless job. Generating it makes the work
 * finite: this script is run once, and `tests/Analysis/FoldingClosureTest.php`
 * asserts the result is closed.
 *
 * ── Why the library still needs no extension ────────────────────────────────
 *
 * `ext-intl` and `ext-mbstring` are used *here*, to produce a PHP source file.
 * Nothing at runtime touches them. That asymmetry is the point: the authority
 * on what upper case means should not be a table this project wrote, and the
 * shipped library should not need the authority to be installed.
 *
 * ── What is generated and what is decided ───────────────────────────────────
 *
 * Generated: case mappings, mark stripping, and which claimed code points are
 * punctuation rather than letters. Unicode answers all three.
 *
 * Decided, and listed in SUPPLEMENT below: the letters Unicode does not
 * decompose but search convention folds anyway — ß to `ss`, æ to `ae`, ø to
 * `o`. Those are conventions, not facts, and they stay where a reader can
 * argue with them.
 *
 * Decided per script, in foldOf(): how far to strip. Latin folds to its ASCII
 * base, so `café` meets `cafe` and `Việt` meets `viet`. Greek loses its accents
 * and keeps its letters. Cyrillic folds ё to е — which is what Russian search
 * conventionally does — and does *not* strip й to и, which would merge two
 * letters Russian considers distinct. Everything else is lowercased and left
 * alone.
 */

require __DIR__ . '/../src/autoload.php';

use Ols\PhpFts\Analysis\Script;

foreach (['intl', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "This generator needs ext-$extension. The library does not.\n");
        exit(1);
    }
}

/** Beyond this there is nothing but unassigned planes and private use. */
const LAST_CODEPOINT = 0x2FA1F;

/**
 * Letters Unicode declines to decompose, and what search convention makes of
 * them. Every one of these is a judgement rather than a derivation.
 *
 * @var array<int, string>
 */
const SUPPLEMENT = [
    0x00DF => 'ss',   // ß — so straße meets strasse
    0x00E6 => 'ae',   0x0153 => 'oe',
    0x00F0 => 'd',    0x00FE => 'th',
    0x00F8 => 'o',    0x0111 => 'd',   0x0127 => 'h',
    0x0131 => 'i',    0x0138 => 'k',   0x0140 => 'l',
    0x0142 => 'l',    0x0167 => 't',   0x017F => 's',
    0x0180 => 'b',    0x019B => 'l',   0x01BF => 'w',
    0x0247 => 'e',    0x0249 => 'j',   0x024D => 'r',   0x024F => 'y',

    // Ligatures, which turn up in PDF and CMS exports.
    0xFB00 => 'ff',   0xFB01 => 'fi',  0xFB02 => 'fl',
    0xFB03 => 'ffi',  0xFB04 => 'ffl', 0xFB05 => 'st',  0xFB06 => 'st',

    // Final sigma. Greek writes the same letter differently at the end of a
    // word, and a search that told them apart would be telling apart one word
    // from itself.
    0x03C2 => "\u{03C3}",
];

/** Marks dropped when folding Latin and Greek. Everything Unicode calls one. */
function isMark(string $character): bool
{
    return preg_match('/^\p{M}$/u', $character) === 1;
}

/**
 * The code points a character decomposes to, with its marks removed.
 */
function withoutMarks(string $character): string
{
    $decomposed = Normalizer::normalize($character, Normalizer::FORM_D);

    if ($decomposed === false) {
        return $character;
    }

    $kept = '';

    foreach (preg_split('//u', $decomposed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
        if (!isMark($piece)) {
            $kept .= $piece;
        }
    }

    return $kept === '' ? $character : $kept;
}

/**
 * Every code point of a string put through SUPPLEMENT.
 *
 * Applied to the *lowercased and stripped* form rather than to the original,
 * which is what makes `Æ` and `æ` agree — the capital reaches the table only
 * after mb_strtolower has turned it into the letter the table names — and what
 * catches `ǽ`, whose acute comes off first and leaves an `æ` behind.
 */
function applySupplement(string $text): string
{
    $result = '';

    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
        $codepoint = mb_ord($piece, 'UTF-8');
        $result   .= ($codepoint !== false && isset(SUPPLEMENT[$codepoint]))
            ? SUPPLEMENT[$codepoint]
            : $piece;
    }

    return $result;
}

/**
 * What one code point folds to, or null when it folds to itself.
 */
function foldOf(int $codepoint, Script $script): ?string
{
    $character = mb_chr($codepoint, 'UTF-8');

    if ($character === false) {
        return null;
    }

    // Case *folding*, not lowercasing. They are different operations and only
    // one of them is defined for caseless matching: lowercasing is for display
    // and leaves the typographic variants alone, so ϐ (curled beta) stayed
    // itself while its capital folded to β — seventy Greek code points where
    // the two cases could not meet. Case folding maps them onto the canonical
    // letter, and throws in ẞ → ss and ς → σ for free.
    // Digits, in whatever system they are written. Unicode knows what each one
    // is worth, so this is derived rather than decided: a catalogue writing
    // ٢٠٢٤ and a shopper typing 2024 are saying the same thing, and so are
    // ١٢٣ and १२३ and 123. Folding them onto ASCII is also what makes them
    // *one* term across scripts instead of one per script.
    $value = IntlChar::charDigitValue($codepoint);

    if ($value >= 0 && $value <= 9) {
        return (string) $value;
    }

    $lower = mb_convert_case($character, MB_CASE_FOLD, 'UTF-8');

    $folded = match ($script) {
        // To the ASCII base, so `café` meets `cafe` and `Việt` meets `viet`.
        // Only when the whole result is ASCII: a letter whose base is not —
        // ə, ɣ — keeps itself rather than becoming something arbitrary.
        Script::Latin => (static function (string $lower): string {
            $stripped = applySupplement(withoutMarks($lower));

            return preg_match('/^[a-z0-9]+$/', $stripped) === 1
                ? $stripped
                : applySupplement($lower);
        })($lower),

        // Accents go, letters stay. Greek search conventionally ignores the
        // tonos, and polytonic breathings are editorial rather than lexical.
        Script::Greek => applySupplement(withoutMarks($lower)),

        // ё → е, and nothing else. Stripping marks wholesale here would also
        // fold й to и, which Russian considers two letters.
        Script::Cyrillic => $lower === "\u{0451}" ? "\u{0435}" : $lower,

        default => applySupplement($lower),
    };

    return $folded === $character ? null : $folded;
}

// ---------------------------------------------------------------------------

$folds      = [];
$notLetters = [];

for ($codepoint = 0x80; $codepoint <= LAST_CODEPOINT; $codepoint++) {
    if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
        continue;
    }

    $script = Script::blockOf($codepoint);

    if ($script === Script::Separator) {
        continue;
    }

    $character = mb_chr($codepoint, 'UTF-8');

    if ($character === false) {
        continue;
    }

    // Punctuation and symbols inside a script's own block. Unassigned code
    // points are deliberately not listed: they cannot appear in real text, and
    // demanding a block be free of them would be demanding Unicode be tidy.
    if (preg_match('/^[\p{P}\p{S}]$/u', $character) === 1) {
        $notLetters[$codepoint] = true;
        continue;
    }

    $folded = foldOf($codepoint, $script);

    if ($folded !== null) {
        $folds[$codepoint] = $folded;
    }
}

// The supplement is a decision list and every entry on it must land, whether or
// not its code point falls in a block `Script::blockOf()` claims. The ligatures
// do not: `ﬃ` lives in Alphabetic Presentation Forms, which is not a script
// range — and folding it to `ffi` is precisely what turns it into three
// characters that *are* Latin. Left out, it stayed itself, was classified as a
// separator, and a word carrying it lost its middle.
foreach (SUPPLEMENT as $codepoint => $folded) {
    $folds[$codepoint] ??= $folded;
}

ksort($folds);
ksort($notLetters);

// ---------------------------------------------------------------------------

/** @param array<int, string> $entries */
function renderFolds(array $entries): string
{
    $lines = [];

    foreach ($entries as $codepoint => $folded) {
        $lines[] = sprintf("        0x%04X => %s,", $codepoint, var_export($folded, true));
    }

    return implode("\n", $lines);
}

/** @param array<int, true> $entries */
function renderFlags(array $entries): string
{
    $lines = [];
    $row   = [];

    foreach (array_keys($entries) as $codepoint) {
        $row[] = sprintf('0x%04X => true,', $codepoint);

        if (count($row) === 6) {
            $lines[] = '        ' . implode(' ', $row);
            $row     = [];
        }
    }

    if ($row !== []) {
        $lines[] = '        ' . implode(' ', $row);
    }

    return implode("\n", $lines);
}

$target = __DIR__ . '/../src/Analysis/FoldingTables.php';

// A nowdoc, not a heredoc: the placeholders below must survive to the
// str_replace under it, and `{$…}` in a heredoc would be interpolated away
// against variables that do not exist.
$source = <<<'PHP'
<?php

declare(strict_types=1);

namespace Ols\PhpFts\Analysis;

/**
 * Generated. Do not edit by hand — run `php tools/generate-folding.php`.
 *
 * The tables `CharacterFolder` and `Script` read, derived from Unicode's own
 * case mappings, decompositions and general categories rather than written out
 * by someone who speaks one of the languages involved. The generator explains
 * what is derived and what is decided; this file is only the answer.
 *
 * Covers every code point `Script::blockOf()` claims. Regenerate it whenever
 * that changes, and `tests/Analysis/FoldingClosureTest.php` will say whether
 * the result is closed.
 */
final class FoldingTables
{
    /**
     * Code point => the UTF-8 it folds to: lowercased, and stripped as far as
     * its script allows. Absent means it folds to itself.
     *
     * @var array<int, string>
     */
    public const FOLD = [
{$foldsRendered}
    ];

    /**
     * Claimed code points that are punctuation or symbols rather than letters.
     *
     * A Unicode block is not a set of letters — it holds its script's own
     * punctuation too. Without this the Arabic comma is a letter and
     * `كتاب، جديد` yields a term no query for `كتاب` ever produces.
     *
     * @var array<int, true>
     */
    public const NOT_LETTERS = [
{$flagsRendered}
    ];

    private function __construct()
    {
    }
}

PHP;

// Rendered before the heredoc so the two long blocks stay out of it.
$source = str_replace(
    ['{$foldsRendered}', '{$flagsRendered}'],
    [renderFolds($folds), renderFlags($notLetters)],
    $source
);

file_put_contents($target, $source);

printf(
    "%s\n  %d fold entries\n  %d punctuation exclusions\n  %.1f KB\n",
    $target,
    count($folds),
    count($notLetters),
    strlen($source) / 1024
);
