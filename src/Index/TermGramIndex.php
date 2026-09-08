<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Analysis\Utf8;
use Ols\PhpFts\Query\TermExpansion;
use Ols\PhpFts\Storage\Varint;

/**
 * The n-grams of the *vocabulary*, so a typo can be resolved without reading
 * the vocabulary.
 *
 *     TermGramIndex::of('steel');     // ['#st', 'ste', 'tee', 'eel', 'el#']
 *
 * ── What this is for ───────────────────────────────────────────────────────
 *
 * Finding the words that resemble what someone typed means comparing the typed
 * word against candidates. Scanning the whole term dictionary to find them
 * works and is obviously correct, and it is what the query planner did first —
 * at a measured 2.9 seconds per search on a 45 000-product catalogue, which is
 * to say it does not work at all. This section is the index that makes the
 * comparison affordable: a trigram maps to the terms containing it, so `stel`
 * reaches its candidates through five short lookups instead of 87 000 edit
 * distances.
 *
 * The trigrams did not disappear when documents stopped being trigrammed; they
 * moved here. Trigramming 45 000 documents produced 8.5 million postings.
 * Trigramming their 87 000 distinct words produces 590 000 — and unlike a
 * catalogue, a vocabulary stops growing.
 *
 * ── Why it never needs the documents ───────────────────────────────────────
 *
 * It is a pure function of the term dictionary. That is worth stating because
 * it is what makes it safe under merges: a merge carries terms across without
 * re-reading any text — it has to, since `source(false)` means the text is not
 * there — and this section can be rebuilt from the terms alone. It is derived
 * data, and nothing about it has to survive anything.
 *
 * ── Why the payload is front-coded ─────────────────────────────────────────
 *
 * A trigram's terms are stored as the terms themselves rather than as numbers
 * pointing back into the dictionary, because a number would need a second
 * structure to resolve and the strings compress away almost entirely: the
 * terms sharing a trigram are alphabetically adjacent far more often than not,
 * so each one is written as "how much of the previous term to keep" plus what
 * is left. The same trick, and the same reason, as the dictionary's own keys.
 *
 * It is also what lets the writer build this section in memory bounded by the
 * *vocabulary* rather than by the postings. Terms reach the writer already
 * sorted, so each list can be encoded as it grows and never held as an array
 * of strings — 25 000 buffers of a few hundred bytes instead of 590 000 array
 * slots. On a host that merges inside a `memory_limit`, that is the difference
 * between a section and a crash.
 */
final class TermGramIndex
{
    /** Characters per gram. Three, as the document trigrams were. */
    public const SIZE = 3;

    /**
     * Marks where a word begins and ends, so that a prefix and a suffix are
     * grams of their own: `steel` yields `#st` and `el#`, which is what lets a
     * first-letter typo still find candidates.
     *
     * Safe as a marker because it can never occur inside a term: `#` folds to
     * a separator, and a separator is what ends a run.
     */
    private const PAD = '#';

    private function __construct()
    {
    }

    /**
     * The grams of one term, deduplicated.
     *
     * Empty for a term the query side will never expand — an n-gram of a
     * continuous script — so a Japanese vocabulary costs this section nothing.
     *
     * @return string[]
     */
    public static function of(string $term): array
    {
        if ($term === '' || !TermExpansion::tolerates($term)) {
            return [];
        }

        $characters = [self::PAD];

        foreach (Utf8::codepoints($term) as $codepoint) {
            $characters[] = Utf8::chr($codepoint);
        }

        $characters[] = self::PAD;
        $count        = count($characters);

        // A word so short that the padding alone does not fill one gram is
        // indexed whole, on the same principle the analyzer uses for a single
        // Japanese character: better one odd term than none.
        if ($count <= self::SIZE) {
            return [implode('', $characters)];
        }

        $grams = [];

        for ($i = 0; $i + self::SIZE <= $count; $i++) {
            $grams[implode('', array_slice($characters, $i, self::SIZE))] = true;
        }

        // Cast because a gram of digits — `123` from a model number — would
        // come back from array_keys() as an int. Same trap as Analyzer.
        return array_map('strval', array_keys($grams));
    }

    /**
     * How many grams a candidate has to share before it is worth measuring an
     * edit distance to.
     *
     * A sound bound rather than a tuned one: one edit can only disturb the
     * grams that overlap the character it touched, which is at most SIZE of
     * them, so a term within `budget` edits keeps at least `grams - SIZE *
     * budget` of them. Anything above that would be a guess that silently
     * loses recall — the class of bug that is invisible until someone notices
     * their search has been quietly wrong for a year.
     */
    public static function shareRequired(int $grams, int $budget): int
    {
        return max(1, $grams - self::SIZE * $budget);
    }

    /**
     * One term appended to a gram's list, given the term written before it.
     *
     * Front-coded against the previous term. Byte-wise rather than by
     * character, which is safe because the result is only ever reassembled as
     * bytes: a shared prefix cut in the middle of a UTF-8 sequence still
     * rebuilds the same string.
     */
    public static function append(string $term, string $previous): string
    {
        $shared  = 0;
        $shortest = min(strlen($previous), strlen($term));

        while ($shared < $shortest && $previous[$shared] === $term[$shared]) {
            $shared++;
        }

        $suffix = substr($term, $shared);

        return Varint::encode($shared) . Varint::encode(strlen($suffix)) . $suffix;
    }

    /**
     * A gram's list of terms, back from its payload.
     *
     * @return string[]
     */
    public static function decode(string $payload): array
    {
        $terms    = [];
        $previous = '';
        $position = 0;
        $length   = strlen($payload);

        while ($position < $length) {
            $shared = Varint::decode($payload, $position);
            $suffix = Varint::decode($payload, $position);

            $term     = substr($previous, 0, $shared) . substr($payload, $position, $suffix);
            $position += $suffix;

            $terms[]  = $term;
            $previous = $term;
        }

        return $terms;
    }
}
