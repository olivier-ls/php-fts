<?php

declare(strict_types=1);

namespace Ols\PhpFts\Analysis;

/**
 * Turns text into the terms the index stores.
 *
 *     $analyzer->analyze('Chaussure en cuir');
 *         → chaussure, en, cuir
 *
 *     $analyzer->analyze('革靴 ブラウン');
 *         → 革靴, ブラ, ラウ, ウン
 *
 *     $analyzer->analyze('Коричневые туфли');
 *         → коричневые, туфли
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
 *   5. Each run yields its terms: one word for a script that separates words,
 *      n-grams for one that does not (see Script, and termsOf() below).
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
    /**
     * @return string[] unique terms, in order of first appearance
     */
    public function analyze(string $text): array
    {
        // Deduplication goes through array keys, and PHP turns a key that
        // looks like an integer into one. A term that is all digits — `21`,
        // `2024`, a model number — would therefore come back as an int and be
        // rejected by every string signature downstream. The `#` markers used
        // to hide this, because `#21#` is not numeric; a word is.
        return array_map('strval', array_keys($this->frequencies($text)));
    }

    /**
     * The same terms, with how many times each one occurs.
     *
     * What indexing needs and {@see analyze()} throws away. BM25 weighs a term
     * by how often a document uses it, and until words became the terms there
     * was nothing to weigh: a document's trigrams were deduplicated, so every
     * frequency was 1 and Scorer said so plainly — `k1 therefore has no effect
     * on ranking here`. With words that silence is a real loss. Measured on
     * the catalogue, a search for `opinel` returned four products scoring
     * 10.29 each, indistinguishable, because every one of them said `opinel`
     * once as far as the index could tell — while one of them said it twice in
     * its own name.
     *
     * Note the keys: PHP will have turned an all-digit term into an integer,
     * so a caller reading them back has to cast. Returned as a map anyway,
     * because the count belongs *with* the term and every caller here wants
     * both.
     *
     * @return array<string, int> term => occurrences
     */
    public function frequencies(string $text): array
    {
        $counts = [];

        foreach ($this->runs($text) as $run) {
            foreach ($this->termsOf($run) as [$term]) {
                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }
        }

        return $counts;
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
     * why highlighting needs no separate notion of a word. In a script that
     * separates words the term *is* the word, so its span is the word and
     * there is nothing to reconstruct. In a continuous script `革靴` still
     * arrives as bigrams whose spans overlap, and merging them rebuilds the
     * phrase — the same three lines, with no special case for the script.
     *
     * A fourth value used to travel with each term, saying whether it was
     * *only* anchored to a word edge. That was a real distinction while `over`
     * produced `er#` — a term describing how a word ended and nothing about
     * what it said — and it stopped being one when a word became its own term.
     * It had been constant since, carried through three call sites and a
     * `!$edge` that could not be false. Gone.
     *
     * @return array{text: string, terms: array<int, array{0: string, 1: int, 2: int}>}
     */
    public function occurrences(string $text): array
    {
        $plain = $this->plain($text);
        $terms = [];

        foreach ($this->runsOf($plain) as $run) {
            foreach ($this->termsOf($run) as $term) {
                $terms[] = $term;
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
     *
     * ── Why `<` is escaped before the tags are stripped ────────────────────
     *
     * Because `strip_tags()` treats every `<` as the start of a tag and drops
     * everything up to the next `>` — or to the end of the string if there is
     * none. A catalogue writes `lame <3 mm` and `prix <30 euros`, and the
     * engine indexed `lame ` and `prix `. Everything after was gone from the
     * index, not merely from the highlight, so the product could not be found
     * by any word in the rest of its description. Silently, and for the whole
     * life of a shop's index.
     *
     * HTML's own rule is narrower than PHP's: a `<` opens a tag only when a
     * letter, `/`, `!` or `?` follows it. Anything else is text, and browsers
     * render it as text. So the ones that cannot open a tag are turned into
     * entities first, and the `html_entity_decode()` that was already here
     * turns them back — which is why this costs no extra pass.
     *
     * A `<` that *does* look like a tag start and is never closed still eats
     * the rest, as it does in a browser. That is malformed markup rather than
     * a number.
     */
    public function plain(string $text): string
    {
        $text = preg_replace('/<(?![a-zA-Z\/!?])/', '&lt;', $text) ?? $text;

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
            $from = $positions[$index];
            $to   = $positions[$index + 1];

            // ── The ASCII path, spelled out rather than called ─────────────
            //
            // Everything below this branch is correct for ASCII too and was
            // what ran: a call returning a one-element array, a static call to
            // classify it, and another to encode it back — three calls and an
            // allocation for a letter whose case and script are two
            // comparisons. Measured, that was most of the analyzer's time:
            // 2.55 MB/s against 18.1 MB/s for the UTF-8 decode underneath it,
            // so seven eighths of the cost was the loop around the decoder
            // rather than the decoder.
            //
            // It earns its duplication on the corpus this engine is for. A
            // French catalogue is almost entirely ASCII; so is an English,
            // Spanish or Indonesian one; and a Japanese one carries ASCII
            // product references throughout. Anything above 0x7F falls through
            // to the general path unchanged.
            if ($codepoint < 0x80) {
                if ($codepoint >= 0x41 && $codepoint <= 0x5A) {
                    $codepoint += 0x20;
                }

                if (($codepoint < 0x61 || $codepoint > 0x7A) && ($codepoint < 0x30 || $codepoint > 0x39)) {
                    $this->close($runs, $script, $bytes, $offsets, $sources, $end);
                    $script = null;
                    continue;
                }

                if ($script !== Script::Latin) {
                    $this->close($runs, $script, $bytes, $offsets, $sources, $end);
                    $script = Script::Latin;
                }

                $bytes    .= chr($codepoint);
                $offsets[] = strlen($bytes);
                $sources[] = $from;
                $end       = $to;

                continue;
            }

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
     * Slices one run into the terms the index stores.
     *
     * Two regimes, and which one applies is the whole architecture:
     *
     *     word-separated scripts   one run is one word, and one word is one term
     *     continuous scripts       one run is a phrase, and is cut into n-grams
     *
     * Character offsets were recorded while the run was built, so a term is a
     * substr rather than a re-encode — which matters, because this is the
     * innermost loop of indexing.
     *
     * ── Why a word, and not its trigrams ───────────────────────────────────
     *
     * Because the trigrams of a *document* are the wrong place to buy typo
     * tolerance, and that is what this used to do.
     *
     * Measured on a 45 000-product catalogue: trigramming the documents
     * produced 8.5 million postings whose lists averaged 336 documents, and
     * 38 trigrams sat in more than half the corpus. Trigramming the
     * *vocabulary* of the same catalogue produces 590 000 postings whose lists
     * average 24 words. Tolerance then costs the size of the vocabulary rather
     * than the size of the corpus — and a vocabulary stops growing long before
     * a catalogue does. The trigrams did not go away; they moved one floor up,
     * to the term dictionary, where a query expands what was typed into the
     * words that resemble it before any document is touched.
     *
     * It also buys back something a flat list of trigrams could not express:
     * which word a match came from. A four-word query used to become twenty
     * anonymous trigrams under a single threshold, so a document could clear
     * the bar on `couteau`'s trigrams plus five strays and match without
     * containing `cuisine` at all — which is why `couteau de cuisine inox`
     * reported 10 877 matches where the truthful answer was near 460. Terms
     * that are words make "every word present, each allowing a typo" sayable,
     * and make tolerance a dial rather than the substrate.
     *
     * ── Why continuous scripts keep their n-grams ──────────────────────────
     *
     * Because there is no alternative. Finding word boundaries in Japanese,
     * Chinese or Thai needs a segmentation dictionary, which this engine does
     * not have and will not ship — so a run of those scripts is a phrase, and
     * n-grams over the phrase are the only terms available. They keep the cost
     * profile that words escape. That is a known and accepted asymmetry, not
     * an oversight.
     *
     * @param array{script: Script, bytes: string, offsets: int[], sources: int[]} $run
     * @return array<int, array{0: string, 1: int, 2: int}> term, and the bytes
     *         of the plain text it was cut from
     */
    private function termsOf(array $run): array
    {
        $bytes   = $run['bytes'];
        $sources = $run['sources'];

        if ($bytes === '') {
            return [];
        }

        // One word, one term. The run is already folded, and its span is the
        // whole word — which is also what makes highlighting a word exact,
        // rather than a reconstruction from overlapping trigrams.
        if (!$run['script']->isContinuous()) {
            return [[$bytes, $sources[0], $sources[count($sources) - 1]]];
        }

        $offsets    = $run['offsets'];
        $characters = count($offsets) - 1;
        $size       = $run['script']->nGramSize();

        // A run shorter than one n-gram is indexed whole, so that a single
        // Japanese character in a field is not simply lost.
        if ($characters < $size) {
            return [[$bytes, $sources[0], $sources[count($sources) - 1]]];
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
