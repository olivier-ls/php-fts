<?php

declare(strict_types=1);

namespace Ols\PhpFts\Analysis;

/**
 * Turns text into the terms the index stores.
 *
 *     $analyzer->analyze('Chaussure en cuir');
 *         → #ch, cha, hau, aus, uss, ssu, sur, ure, re#, #en, en#, #cu, cui, uir, ir#
 *
 *     $analyzer->analyze('革靴 ブラウン');
 *         → 革靴, ブラ, ラウ, ウン
 *
 *     $analyzer->analyze('Коричневые туфли');
 *         → #ко, кор, ори, рич, …
 *
 * The same object analyses documents and queries, which is the only way the
 * two can be guaranteed to agree: any rule applied to one is applied to the
 * other by construction.
 *
 * ── The pipeline ────────────────────────────────────────────────────────────
 *
 *   1. HTML is stripped and entities decoded, because catalogue text usually
 *      arrives from a CMS with markup still in it.
 *   2. Bytes are decoded to code points (see Utf8), so that everything after
 *      this point reasons about characters.
 *   3. Every character is folded (see CharacterFolder): width, case, combining
 *      marks, diacritics.
 *   4. The result is cut into runs of a single script, breaking on separators
 *      and on script changes.
 *   5. Each run yields n-grams, with the size and the padding its script calls
 *      for (see Script).
 *   6. Terms are deduplicated.
 *
 * ── Why this replaces 1.x's tokenizer outright ──────────────────────────────
 *
 * The old one lowercased, transliterated accents through a hand-written table
 * and then ran `preg_replace('/[^a-z0-9]+/', ' ', $text)`. That last line is
 * where Japanese, Chinese, Russian, Greek, Arabic, Hebrew and Thai were turned
 * into spaces — silently, with no way for a caller to notice. The engine could
 * not index them because its tokenizer deleted them before the index ever saw
 * them.
 *
 * ── A known gap, worth stating ──────────────────────────────────────────────
 *
 * A one-character query in a continuous script produces one term of one
 * character, which will not match the bigrams a document produced. Searching
 * 革 alone therefore finds nothing, while 革靴 works. The fix belongs on the
 * query side — a prefix scan over the term dictionary, which its sorted block
 * layout already supports — not here.
 */
final class Analyzer
{
    private const WORD_BOUNDARY = 0x23;   // '#'

    /**
     * @return string[] unique terms, in order of first appearance
     */
    public function analyze(string $text): array
    {
        $terms = [];

        foreach ($this->runs($text) as $run) {
            foreach ($this->nGrams($run) as [$term]) {
                $terms[$term] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * The same terms, each with the bytes of the text it came from.
     *
     * This is what highlighting runs on. Nothing is deduplicated, because two
     * occurrences of a term are two places to mark, and the text returned
     * alongside is the *plain* text — the one the pipeline actually looked at,
     * with markup already stripped and entities already decoded. Offsets into
     * the caller's raw field value would be meaningless: `&eacute;` is nine
     * bytes there and two here.
     *
     * A term's span is the bytes of the characters it was cut from, which is
     * why highlighting needs no separate notion of a word. The query `leather`
     * analyses to `#le … er#`, whose seven spans tile the whole word, so
     * merging what overlaps reconstructs `leather` — and does the same for
     * `革靴` from its bigrams, in the same three lines and with no special
     * case for the script.
     *
     * The fourth value of each entry says whether the term is *only* anchored
     * to a word edge: it contains a boundary marker, and the text it spans is
     * not the whole word. `er#` from `over` is such a term — it describes how
     * the word ends and nothing about what it says. `#en` from the word `en`
     * is not, because a marker that is the only thing outside the span means
     * the span is the entire word. Highlighting needs the distinction and the
     * index does not, which is why it is computed here rather than carried
     * through the n-gram loop that indexing also walks.
     *
     * @return array{text: string, terms: array<int, array{0: string, 1: int, 2: int, 3: bool}>}
     */
    public function occurrences(string $text): array
    {
        $plain = $this->plain($text);
        $terms = [];

        foreach ($this->runsOf($plain) as $run) {
            $sources = $run['sources'];
            $from    = $sources[0];
            $to      = $sources[count($sources) - 1];

            foreach ($this->nGrams($run) as [$term, $start, $end]) {
                $marked = str_contains($term, chr(self::WORD_BOUNDARY));
                $whole  = $start === $from && $end === $to;

                $terms[] = [$term, $start, $end, $marked && !$whole];
            }
        }

        return ['text' => $plain, 'terms' => $terms];
    }

    /**
     * The text as the rest of the pipeline sees it: no markup, no entities.
     *
     * Exposed because it is what a highlight is a substring of, so a caller
     * asking for byte positions rather than HTML needs the string those
     * positions index into.
     */
    public function plain(string $text): string
    {
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The runs a text is cut into, before n-gramming.
     *
     * Exposed because it is the part worth looking at when a language behaves
     * unexpectedly, and because the tests read far better against it than
     * against a flat list of n-grams.
     *
     * @return array<int, array{script: Script, bytes: string, offsets: int[], sources: int[]}>
     */
    public function runs(string $text): array
    {
        return $this->runsOf($this->plain($text));
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{script: Script, bytes: string, offsets: int[], sources: int[]}>
     */
    private function runsOf(string $plain): array
    {
        $runs    = [];
        $script  = null;
        $bytes   = '';
        $offsets = [0];
        $sources = [];
        $end     = 0;

        $positions  = [];
        $codepoints = Utf8::codepoints($plain, $positions);

        foreach ($codepoints as $index => $codepoint) {
            $from   = $positions[$index];
            $to     = $positions[$index + 1];
            $folded = CharacterFolder::foldCodepoints($codepoint);

            // A character the folder deletes outright — a combining mark left
            // over from a decomposed é. Its bytes are given to the character it
            // was sitting on, so that a span ending there covers the accent
            // too, instead of marking the `e` and leaving its mark outside.
            if ($folded === []) {
                if ($sources !== []) {
                    $end = $to;
                }

                continue;
            }

            foreach ($folded as $character) {
                $foldedScript = Script::of($character);

                if ($foldedScript === Script::Separator) {
                    $this->close($runs, $script, $bytes, $offsets, $sources, $end);
                    $script = null;
                    continue;
                }

                // A script change ends a run even without a separator. This is
                // what keeps 革靴ブラウン from producing a bigram straddling
                // the Han/Katakana seam, which would belong to neither word.
                if ($foldedScript !== $script) {
                    $this->close($runs, $script, $bytes, $offsets, $sources, $end);
                    $script = $foldedScript;
                }

                $bytes    .= Utf8::chr($character);
                $offsets[] = strlen($bytes);
                $sources[] = $from;
                $end       = $to;
            }
        }

        $this->close($runs, $script, $bytes, $offsets, $sources, $end);

        return $runs;
    }

    /**
     * @param array<int, array{script: Script, bytes: string, offsets: int[], sources: int[]}> $runs
     * @param int[] $offsets
     * @param int[] $sources
     * @param int   $end     where the run's last character ends, in the plain text
     */
    private function close(array &$runs, ?Script $script, string &$bytes, array &$offsets, array &$sources, int $end): void
    {
        if ($script !== null && $bytes !== '') {
            // One more entry than there are characters, so that a character's
            // source span is always `sources[i]` to `sources[i + 1]`. The gap
            // between two characters therefore belongs to the earlier one,
            // which is what makes a folded-away mark fall inside a span rather
            // than between two of them.
            $sources[] = $end;

            $runs[] = ['script' => $script, 'bytes' => $bytes, 'offsets' => $offsets, 'sources' => $sources];
        }

        $bytes   = '';
        $offsets = [0];
        $sources = [];
    }

    /**
     * Slices one run into n-grams.
     *
     * Character offsets were recorded while the run was built, so a term is a
     * substr rather than a re-encode — which matters, because this is the
     * innermost loop of indexing.
     *
     * @param array{script: Script, bytes: string, offsets: int[], sources: int[]} $run
     * @return array<int, array{0: string, 1: int, 2: int}> term, and the bytes
     *         of the plain text it was cut from
     */
    private function nGrams(array $run): array
    {
        $script  = $run['script'];
        $bytes   = $run['bytes'];
        $offsets = $run['offsets'];
        $sources = $run['sources'];

        // Word-separated scripts get markers at both ends, so that a run of one
        // character still yields a term and so that prefixes and suffixes are
        // searchable: "cuir" gives #cu … ir#.
        if ($script->usesWordBoundaries()) {
            $marker  = chr(self::WORD_BOUNDARY);
            $bytes   = $marker . $bytes . $marker;
            $shifted = [0];

            foreach ($offsets as $offset) {
                $shifted[] = $offset + 1;
            }

            $shifted[] = strlen($bytes);
            $offsets   = $shifted;

            // The markers are text the run never contained, so they are given
            // no bytes of their own: the leading one collapses onto the start
            // of the first character, the trailing one onto the end of the
            // last. `#cu` and `ir#` then span exactly `cu` and `ir`.
            $sources = array_merge([$sources[0]], $sources, [$sources[count($sources) - 1]]);
        }

        $characters = count($offsets) - 1;
        $size       = $script->nGramSize();

        // A run shorter than one n-gram is indexed whole, so that a single
        // Japanese character in a field is not simply lost.
        if ($characters < $size) {
            return $bytes === '' ? [] : [[$bytes, $sources[0], $sources[count($sources) - 1]]];
        }

        $terms = [];

        for ($i = 0; $i + $size <= $characters; $i++) {
            $terms[] = [
                substr($bytes, $offsets[$i], $offsets[$i + $size] - $offsets[$i]),
                $sources[$i],
                $sources[$i + $size],
            ];
        }

        return $terms;
    }
}
