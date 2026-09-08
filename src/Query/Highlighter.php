<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Exception\HighlightException;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Schema;

/**
 * Marks, in a document's own text, the places a query matched.
 *
 * ── There is no position list, and there does not need to be ────────────────
 *
 * The usual way to highlight is to store term positions in the index and read
 * them back. This engine does not: positions would grow the postings section
 * for every document, to serve at most `limit` of them per query. Instead the
 * field is re-analysed at highlight time, for the handful of documents that are
 * actually being returned. The index pays nothing.
 *
 * That is affordable because the analyzer is the *same object* that indexed the
 * field. Re-analysing cannot disagree with what was indexed — no rule can drift
 * between the two, because there is only one set of rules.
 *
 * ── Spans, not words ────────────────────────────────────────────────────────
 *
 * Every term the analyzer produces knows the bytes it was cut from. A query's
 * terms are looked up in that list, their spans are merged where they overlap,
 * and what is left is marked. In a script that separates words the term *is*
 * the word, so its span is the word and there is nothing to reconstruct; in a
 * continuous script 革靴 arrives as overlapping bigrams and merging them
 * rebuilds the phrase. The same three lines, with no special case for the
 * script — nothing here knows what a word is, which is precisely why it works
 * where there are none.
 *
 * A query for `shoe` therefore marks `shoe` and leaves `snowshoes` alone. That
 * is a change: while a document's terms were its trigrams, the two shared
 * enough of them to mark the inside of the longer word, and this file used to
 * describe that as a feature. Words are the terms now, `snowshoes` is a
 * different one, and it never matched to begin with.
 *
 * ── What is highlighted is the plain text ───────────────────────────────────
 *
 * The analyzer strips markup and decodes entities before it looks at anything,
 * so those are the bytes a span points into. A highlight is therefore built
 * from the field's *text*, not its markup: `<b>leather</b> shoe` highlights as
 * `<mark>leather</mark> shoe`. Re-inserting the original markup around merged
 * spans would mean splicing tags at arbitrary offsets and hoping the result
 * still nests — which is how highlighters produce broken HTML.
 */
final class Highlighter
{
    /**
     * How far past the requested window an excerpt may reach to end on a word
     * boundary. Beyond that the cut is simply made where it falls: a "word" of
     * twenty characters is a URL or a hash, and running to the end of it would
     * defeat the point of asking for a window.
     */
    private const SNAP = 16;

    public function __construct(private readonly Analyzer $analyzer)
    {
    }

    /**
     * The fields a schema will actually let a caller highlight.
     *
     * Only one thing is required, and it is the one the format documents: the
     * field has to be *stored*. A highlight is built by re-reading the field's
     * own text, so a field the docstore never kept cannot produce one, and
     * saying so beats returning nothing from every hit forever.
     *
     * Being *indexed* is not required. `stored('body')` — kept but never
     * searched — highlights perfectly well: the query's terms are terms of the
     * index, and whether this particular field contributed them changes
     * nothing about finding them in its text.
     *
     * Nor is being *declared*. A field the schema never mentions is still kept
     * (see Schema::isStored), so refusing it here would refuse something that
     * works. The cost is that a typo — `highlight: ['descriptoin']` — cannot be
     * told apart from an undeclared field, and comes back as no highlights.
     * The alternative is worse.
     *
     * Checked once per search rather than once per hit, and static because it
     * is a question about the schema and not about any one segment's analyzer:
     * the multi-segment layer asks it before it has decided which segments it
     * will even read.
     *
     * @throws HighlightException when a requested field is not stored
     */
    public static function verify(Highlight $highlight, Schema $schema): void
    {
        foreach ($highlight->fields as $field) {
            if ($schema->isStored($field)) {
                continue;
            }

            $reason = $schema->storesNothing()
                ? 'the schema stores no documents at all'
                : 'the schema does not store it, and a highlight is built from the stored text';

            throw new HighlightException("Cannot highlight '{$field}': {$reason}.");
        }
    }

    /**
     * The highlights for one document.
     *
     * @param array<string, mixed> $document
     * @param array<string, true>  $terms the query's terms, as a set
     *
     * @return array<string, string|array{text: string, spans: array<int, array{0: int, 1: int}>}>
     *         only the fields that matched, so that a caller can tell "no match
     *         here" from "matched, and here is where"
     */
    public function document(array $document, array $terms, Highlight $highlight): array
    {
        if ($terms === []) {
            return [];
        }

        $highlights = [];

        foreach ($highlight->fields as $field) {
            $value = $document[$field] ?? null;

            if (!is_string($value) || $value === '') {
                continue;
            }

            $marked = $this->field($value, $terms, $highlight);

            if ($marked !== null) {
                $highlights[$field] = $marked;
            }
        }

        return $highlights;
    }

    /**
     * One field: null when the query did not match anywhere in it.
     *
     * @param array<string, true> $terms
     *
     * @return string|array{text: string, spans: array<int, array{0: int, 1: int}>}|null
     */
    public function field(string $value, array $terms, Highlight $highlight): string|array|null
    {
        ['text' => $text, 'terms' => $occurrences] = $this->analyzer->occurrences($value);

        $spans = [];

        foreach ($occurrences as [$term, $start, $end]) {
            if ($end > $start && isset($terms[$term])) {
                $spans[] = [$start, $end];
            }
        }

        if ($spans === []) {
            return null;
        }

        $spans = self::supported(self::merge($spans), $occurrences, $terms);

        if ($spans === []) {
            return null;
        }

        if ($highlight->offsets) {
            // The window is deliberately not applied here. A caller rendering
            // its own output wants every match and the offsets of each; where
            // to cut is then its decision, made with knowledge of the medium
            // this engine does not have.
            return ['text' => $text, 'spans' => $spans];
        }

        return $this->render($text, $spans, $highlight);
    }

    // -------------------------------------------------------------------------

    /**
     * Overlapping and touching spans become one.
     *
     * Touching matters as much as overlapping: consecutive n-grams of the same
     * word share characters, so their spans overlap — but the first and last
     * trigram of a four-letter word only meet, and `#cu` plus `ir#` has to come
     * out as `cuir` rather than as two marks around one letter each.
     *
     * @param array<int, array{0: int, 1: int}> $spans
     * @return array<int, array{0: int, 1: int}>
     */
    private static function merge(array $spans): array
    {
        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $merged  = [];
        $current = array_shift($spans);

        foreach ($spans as $span) {
            if ($span[0] <= $current[1]) {
                $current[1] = max($current[1], $span[1]);
                continue;
            }

            $merged[]= $current;
            $current = $span;
        }

        $merged[] = $current;

        return $merged;
    }

    /**
     * Drops the spans that only *look* like matches.
     *
     * Searching `leather` for a document containing `The` finds one shared
     * trigram, `the`, and a span three characters wide sitting over a word the
     * reader would never call a match. Ranking absorbs coincidences like that
     * — one term out of seven barely scores — but a highlight has no such
     * cushion: it is either drawn or not, and drawn over `The` it makes the
     * engine look broken.
     *
     * **The document must not disagree.** A span is kept only when every term
     * the *document* produced inside it is also a term the query asked for.
     *
     * ── What this is still for, now that words are the terms ───────────────
     *
     * Very little in a script that separates words, and everything in one that
     * does not. A word-separated field produces one term per word and one span
     * per match, so no span can contain another term to disagree with it —
     * `shoe` marks `shoe` and does not mark `snowshoes`, because `snowshoes`
     * is a different term and never matched in the first place. That is a
     * change from the trigram index, where it did, and where the examples this
     * docblock used to give were drawn from.
     *
     * A continuous script is where it earns its place. `革靴` inside `高級革靴`
     * is a genuine bigram match with overlapping neighbours around it, and this
     * is what keeps a span honest about which of them the query actually asked
     * for.
     *
     * A second test stood here — that a span needed at least one term about a
     * word's *content* rather than only its edge, for cases like `leather` and
     * `over` sharing `er#`. There are no edge-anchored terms any more, so it
     * was a condition that could not be false, and it is gone along with the
     * flag it read.
     *
     * @param array<int, array{0: int, 1: int}>            $spans merged spans
     * @param array<int, array{0: string, 1: int, 2: int}> $occurrences every term the field produced
     * @param array<string, true>                          $terms
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function supported(array $spans, array $occurrences, array $terms): array
    {
        $contradictions = [];

        foreach ($occurrences as [$term, $start, $end]) {
            if ($end > $start && !isset($terms[$term])) {
                $contradictions[] = [$start, $end];
            }
        }

        $kept = [];

        foreach ($spans as [$from, $to]) {
            foreach ($contradictions as [$start, $end]) {
                if ($start >= $from && $end <= $to) {
                    continue 2;
                }
            }

            $kept[] = [$from, $to];
        }

        return $kept;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $spans
     */
    private function render(string $text, array $spans, Highlight $highlight): string
    {
        $from = 0;
        $to   = strlen($text);

        if ($highlight->window !== null) {
            [$from, $to, $spans] = $this->window($text, $spans, $highlight->window);
        }

        $rendered = $from > 0 ? '…' : '';
        $cursor   = $from;

        foreach ($spans as [$start, $end]) {
            $rendered .= $this->escape(substr($text, $cursor, $start - $cursor), $highlight)
                . $highlight->open
                . $this->escape(substr($text, $start, $end - $start), $highlight)
                . $highlight->close;

            $cursor = $end;
        }

        $rendered .= $this->escape(substr($text, $cursor, $to - $cursor), $highlight);

        return $to < strlen($text) ? $rendered . '…' : $rendered;
    }

    /**
     * The slice of text an excerpt shows, and the spans inside it.
     *
     * Centred on the first match, because that is the one the reader is looking
     * for; any further match that falls in the window is marked too, and one
     * that straddles the edge pushes the edge out rather than being cut in half.
     *
     * @param array<int, array{0: int, 1: int}> $spans
     * @return array{0: int, 1: int, 2: array<int, array{0: int, 1: int}>}
     */
    private function window(string $text, array $spans, int $window): array
    {
        [$first, $last] = $spans[0];

        $from = self::back($text, $first, $window);
        $to   = self::forward($text, $last, $window);

        $inside = [];

        foreach ($spans as $span) {
            if ($span[0] >= $to) {
                break;
            }

            if ($span[1] > $from) {
                $inside[] = $span;
                $to       = max($to, $span[1]);
            }
        }

        return [self::snapBack($text, $from), self::snapForward($text, $to), $inside];
    }

    private function escape(string $text, Highlight $highlight): string
    {
        return $highlight->escape
            ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : $text;
    }

    // -------------------------------------------------------------------------
    // Walking the text by characters rather than bytes. A continuation byte is
    // 10xxxxxx, so stepping over them is all it takes — no decode, and nothing
    // that can cut a multi-byte character in half.
    // -------------------------------------------------------------------------

    private static function back(string $text, int $offset, int $characters): int
    {
        while ($characters > 0 && $offset > 0) {
            $offset--;

            while ($offset > 0 && (ord($text[$offset]) & 0xC0) === 0x80) {
                $offset--;
            }

            $characters--;
        }

        return $offset;
    }

    private static function forward(string $text, int $offset, int $characters): int
    {
        $length = strlen($text);

        while ($characters > 0 && $offset < $length) {
            $offset++;

            while ($offset < $length && (ord($text[$offset]) & 0xC0) === 0x80) {
                $offset++;
            }

            $characters--;
        }

        return $offset;
    }

    /**
     * Move a cut back to just after a space, so an excerpt starts on a word.
     *
     * A script without spaces finds none and keeps the cut it had, which is
     * the right answer there: Japanese text has no boundary to snap to, and
     * cutting between two characters is what a Japanese reader expects.
     */
    private static function snapBack(string $text, int $offset): int
    {
        if ($offset === 0) {
            return 0;
        }

        $limit = self::back($text, $offset, self::SNAP);

        for ($at = $offset; $at > $limit; $at--) {
            if (self::isSpace($text[$at - 1])) {
                return $at;
            }
        }

        return $offset;
    }

    private static function snapForward(string $text, int $offset): int
    {
        $length = strlen($text);

        if ($offset >= $length) {
            return $length;
        }

        $limit = self::forward($text, $offset, self::SNAP);

        for ($at = $offset; $at < $limit; $at++) {
            if (self::isSpace($text[$at])) {
                return $at;
            }
        }

        return $offset;
    }

    private static function isSpace(string $byte): bool
    {
        return $byte === ' ' || $byte === "\n" || $byte === "\t" || $byte === "\r";
    }
}
