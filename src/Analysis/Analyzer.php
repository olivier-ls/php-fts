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
            foreach ($this->nGrams($run) as $term) {
                $terms[$term] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * The runs a text is cut into, before n-gramming.
     *
     * Exposed because it is the part worth looking at when a language behaves
     * unexpectedly, and because the tests read far better against it than
     * against a flat list of n-grams.
     *
     * @return array<int, array{script: Script, bytes: string, offsets: int[]}>
     */
    public function runs(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $runs    = [];
        $script  = null;
        $bytes   = '';
        $offsets = [0];

        foreach (Utf8::codepoints($text) as $codepoint) {
            foreach (CharacterFolder::foldCodepoints($codepoint) as $folded) {
                $foldedScript = Script::of($folded);

                if ($foldedScript === Script::Separator) {
                    $this->close($runs, $script, $bytes, $offsets);
                    $script = null;
                    continue;
                }

                // A script change ends a run even without a separator. This is
                // what keeps 革靴ブラウン from producing a bigram straddling
                // the Han/Katakana seam, which would belong to neither word.
                if ($foldedScript !== $script) {
                    $this->close($runs, $script, $bytes, $offsets);
                    $script  = $foldedScript;
                    $bytes   = '';
                    $offsets = [0];
                }

                $bytes    .= Utf8::chr($folded);
                $offsets[] = strlen($bytes);
            }
        }

        $this->close($runs, $script, $bytes, $offsets);

        return $runs;
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{script: Script, bytes: string, offsets: int[]}> $runs
     * @param int[]                                                            $offsets
     */
    private function close(array &$runs, ?Script $script, string &$bytes, array &$offsets): void
    {
        if ($script !== null && $bytes !== '') {
            $runs[] = ['script' => $script, 'bytes' => $bytes, 'offsets' => $offsets];
        }

        $bytes   = '';
        $offsets = [0];
    }

    /**
     * Slices one run into n-grams.
     *
     * Character offsets were recorded while the run was built, so a term is a
     * substr rather than a re-encode — which matters, because this is the
     * innermost loop of indexing.
     *
     * @param array{script: Script, bytes: string, offsets: int[]} $run
     * @return string[]
     */
    private function nGrams(array $run): array
    {
        $script  = $run['script'];
        $bytes   = $run['bytes'];
        $offsets = $run['offsets'];

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
        }

        $characters = count($offsets) - 1;
        $size       = $script->nGramSize();

        // A run shorter than one n-gram is indexed whole, so that a single
        // Japanese character in a field is not simply lost.
        if ($characters < $size) {
            return $bytes === '' ? [] : [$bytes];
        }

        $terms = [];

        for ($i = 0; $i + $size <= $characters; $i++) {
            $terms[] = substr($bytes, $offsets[$i], $offsets[$i + $size] - $offsets[$i]);
        }

        return $terms;
    }
}
