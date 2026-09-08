<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Analysis;

use Ols\PhpFts\Analysis\CharacterFolder;
use Ols\PhpFts\Analysis\Script;
use Ols\PhpFts\Analysis\Utf8;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two closure properties over every code point the engine claims to index.
 *
 * `CharacterFolder` and `Script` are hand-written tables, and a hand-written
 * table is complete only where somebody thought to look. Both were validated
 * against French. Everything else — Vietnamese, Ukrainian, Kazakh, polytonic
 * Greek, Arabic punctuation — was outside the corpus, so the gaps are silent:
 * text is indexed, under a form no query produces.
 *
 * Driving that by bug report is the endless job. These two tests close it
 * instead: the set they range over is **derived from the code**, by asking
 * `Script::of()` which code points it claims rather than by repeating a list
 * that would go stale. Extend the ranges and the tests extend themselves.
 *
 * ── Why mbstring, in a library that requires no extensions ──────────────────
 *
 * The library must fold without it; the *test* needs an independent authority
 * on what upper and lower case are, and re-deriving one here from
 * `UnicodeData.txt` would be marking our own homework with our own answers.
 * A dev-only dependency is the right place for that asymmetry.
 */
#[RequiresPhpExtension('mbstring')]
class FoldingClosureTest extends TestCase
{
    /** Beyond this there is nothing but unassigned planes and private use. */
    private const LAST_CODEPOINT = 0x2FA1F;

    /**
     * How far from closed the tables are today. **Both numbers are countdowns
     * to zero**, and neither may ever go up.
     *
     * They are here rather than as a plain `assertSame(0, …)` because closing
     * them is a body of work — a generated case-folding table, and a decision
     * per script about its punctuation — that will land over several commits
     * and possibly after 2.0. A test that stays red for weeks stops being a
     * signal and starts being noise, and the next real regression hides behind
     * it. A ceiling that only ever falls keeps the gate meaningful in the
     * meantime: it cannot get worse, and the message prints what is left.
     *
     * Lower them in the same commit that earns it.
     */
    private const CASE_GAPS = 916;
    private const PUNCTUATION_GAPS = 79;

    /**
     * Every code point `Script::of()` claims for a writing system.
     *
     * @return \Generator<int, array{0: int, 1: Script, 2: string}>
     */
    private function claimed(): \Generator
    {
        for ($codepoint = 0; $codepoint <= self::LAST_CODEPOINT; $codepoint++) {
            // Surrogate halves are a UTF-16 mechanism and are not characters.
            if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
                continue;
            }

            $script = Script::of($codepoint);

            if ($script === Script::Separator) {
                continue;
            }

            yield [$codepoint, $script, Utf8::chr($codepoint)];
        }
    }

    /**
     * A whole string folded, as the analyzer would fold it.
     *
     * Needed because case mapping is not one-to-one: `ß` uppercases to `SS`,
     * `İ` lowercases to `i` plus a combining dot. Comparing single code points
     * would call those a mismatch when they fold to the same thing.
     *
     * @return int[]
     */
    private function fold(string $text): array
    {
        $folded = [];

        foreach (Utf8::codepoints($text) as $codepoint) {
            foreach (CharacterFolder::foldCodepoints($codepoint) as $result) {
                $folded[] = $result;
            }
        }

        return $folded;
    }

    /**
     * The failure list, rendered so that it reads as a work list.
     *
     * Asserted against a *count* rather than against the array itself, because
     * PHPUnit renders an array mismatch as a full diff and would bury this
     * under several hundred lines of it. The number says how much is left; the
     * message says what.
     *
     * @param array<string, string[]> $byScript
     */
    private function report(array $byScript, string $what): string
    {
        $total = array_sum(array_map('count', $byScript));
        $lines = ["$total code points $what:"];

        ksort($byScript);

        foreach ($byScript as $script => $examples) {
            $lines[] = sprintf(
                '  %-12s %4d : %s%s',
                $script,
                count($examples),
                implode('  ', array_slice($examples, 0, 6)),
                count($examples) > 6 ? '  …' : ''
            );
        }

        return implode("\n", $lines);
    }

    // =========================================================================

    /**
     * Upper and lower case must fold to the same thing.
     *
     * This is the property that makes a search box work at all: a catalogue
     * writing its titles in capitals — the norm in e-commerce — has to be
     * findable in lower case. `CAFÉ` and `café` already meet, because Latin-1
     * is in the table. `VIỆT` folds to `viỆt` and never meets `việt`, because
     * Latin Extended Additional is not, and `caseFold()` has no arm for it.
     *
     * Failures are collected rather than asserted one at a time, on purpose:
     * the list is the scope of the work, and stopping at the first entry would
     * hide its size.
     */
    #[Test]
    public function every_claimed_code_point_folds_the_same_in_either_case(): void
    {
        $failures = [];

        foreach ($this->claimed() as [$codepoint, $script, $character]) {
            $upper = mb_strtoupper($character, 'UTF-8');
            $lower = mb_strtolower($character, 'UTF-8');

            // Caseless — Han, kana, Thai, Arabic, most of Unicode. Nothing to
            // compare, and skipping them keeps this test to a second.
            if ($upper === $character && $lower === $character) {
                continue;
            }

            if ($this->fold($upper) === $this->fold($lower)) {
                continue;
            }

            $failures[$script->name][] = sprintf('U+%04X %s/%s', $codepoint, $upper, $lower);
        }

        $this->assertLessThanOrEqual(
            self::CASE_GAPS,
            array_sum(array_map('count', $failures)),
            $this->report($failures, 'fold differently in upper and lower case')
        );
    }

    /**
     * A punctuation mark or a symbol must never be part of a word.
     *
     * `Script::of()` classifies by Unicode *block*, and a block is not a set of
     * letters — it holds the script's own punctuation too. So the Arabic comma
     * sits inside the Arabic range and is indexed as a letter: `كتاب، جديد`
     * yields the term `كتاب،`, which no query for `كتاب` will ever produce.
     * The Hebrew maqaf glues `בית־ספר` into one term, the Devanagari danda
     * ends every Hindi sentence, and `×` and `÷` make `3×4` a single word.
     *
     * Unassigned code points are deliberately not checked: they cannot appear
     * in real text, and demanding a block be free of them would be demanding
     * Unicode be tidy.
     */
    #[Test]
    public function no_claimed_code_point_is_punctuation_or_a_symbol(): void
    {
        $failures = [];

        foreach ($this->claimed() as [$codepoint, $script, $character]) {
            if (preg_match('/^[\p{P}\p{S}]$/u', $character) !== 1) {
                continue;
            }

            $failures[$script->name][] = sprintf('U+%04X %s', $codepoint, $character);
        }

        $this->assertLessThanOrEqual(
            self::PUNCTUATION_GAPS,
            array_sum(array_map('count', $failures)),
            $this->report($failures, 'are punctuation or symbols but are indexed as word characters')
        );
    }
}
