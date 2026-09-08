<?php

declare(strict_types=1);

namespace Ols\PhpFts\Query;

use Ols\PhpFts\Analysis\Script;
use Ols\PhpFts\Analysis\Utf8;

/**
 * What one typed word is allowed to have meant.
 *
 *     $expansion = new TermExpansion('stel');
 *
 *     $expansion->accepts('steel');     // 1
 *     $expansion->accepts('stella');    // null — two edits away, out of budget
 *     $expansion->accepts('pastel');    // null
 *
 * ── Why tolerance lives here and not in the index ──────────────────────────
 *
 * Because a document's n-grams are the wrong place to buy it. The engine used
 * to index the trigrams of every document, so `stel` was matched by anything
 * sharing enough three-letter fragments — which is how `pastel` and `Chastel`
 * came back for it, and how `maxx` and `braxx` came back for `inoxx`. Nothing
 * could be tuned, because the tolerance *was* the index.
 *
 * Here a query term is compared against the words the index actually holds,
 * and a candidate has to be reachable within an edit budget. Tolerance becomes
 * a number this class owns, tightenable without reindexing anything.
 *
 * ── The budget ─────────────────────────────────────────────────────────────
 *
 * Elasticsearch's `fuzziness: AUTO`, which is the least surprising rule anyone
 * has settled on: nothing for a word of one or two characters, one edit up to
 * five, two beyond. A two-letter word has no room for a correction that does
 * not turn it into a different word, and a long one has room for two.
 *
 * ── Why the distance is not PHP's levenshtein() ────────────────────────────
 *
 * Because `levenshtein()` counts bytes. In UTF-8 a Cyrillic, Greek or Arabic
 * character is two bytes and a CJK one is three, so substituting a single
 * character would be charged as two or three edits and every non-Latin word
 * would fall out of budget. The engine indexes fourteen scripts; a tolerance
 * that only works for the Latin one is not a tolerance. So the distance below
 * runs over code points, which costs a decode the byte version avoids and is
 * the only version that means the same thing in every language.
 *
 * ── Continuous scripts are not expanded at all ─────────────────────────────
 *
 * A run of Japanese or Thai is a phrase rather than a word, so its terms are
 * n-grams (see Analyzer). An n-gram one edit away from another is a different
 * word, not a misspelling of the same one — `ブラ` and `プラ` have nothing to
 * do with each other. Expanding them would manufacture nonsense, so
 * {@see tolerates()} says no and those terms match exactly, which is what
 * n-grams already do well.
 */
final class TermExpansion
{
    /** Shortest typed word that may complete to a longer one. */
    private const MIN_PREFIX = 3;

    /** @var int[] the typed term, decoded once */
    private readonly array $codepoints;

    private readonly int $length;

    private readonly int $budget;

    public function __construct(public readonly string $typed)
    {
        $this->codepoints = Utf8::codepoints($typed);
        $this->length     = count($this->codepoints);

        // Elasticsearch's `fuzziness: AUTO`.
        $this->budget = $this->length <= 2 ? 0 : ($this->length <= 5 ? 1 : 2);
    }

    /**
     * Whether a term is the kind that can be expanded at all.
     *
     * False for a term from a continuous script, where an n-gram's neighbours
     * are unrelated words rather than misspellings of it. Judged from the
     * first code point, which is enough: a run never straddles two scripts,
     * because Analyzer breaks it at the seam.
     */
    public static function tolerates(string $term): bool
    {
        $codepoints = Utf8::codepoints($term);

        if ($codepoints === []) {
            return false;
        }

        return !Script::of($codepoints[0])->isContinuous();
    }

    /** How many edits this term is allowed to be wrong by. */
    public function budget(): int
    {
        return $this->budget;
    }

    /**
     * How much a candidate counts for, or null when it is not a candidate.
     *
     * One for the word itself, less for anything the reader had to guess at.
     * That weight is what stops a guess outranking what was actually typed: a
     * slot gives all its variants the same document frequency (see QueryPlan),
     * so without it `cuisne` typed exactly would score no better than
     * `cuisine` corrected — and a product genuinely named "Cuisne" fell out of
     * its own results until this existed.
     *
     * Two ways to be a candidate, and they answer different questions:
     *
     *   within the edit budget    "you meant this word and mistyped it"
     *   the typed word is a prefix "you have not finished typing it"
     *
     * ── Why a prefix and not any substring ─────────────────────────────────
     *
     * Because the vocabulary says so. Counted over the reference catalogue's
     * 87 000 words: `inox` has 16 prefix completions — `inoxydable`, `inox`,
     * `inoxdable` — and 14 further words merely containing it, which are
     * `victorinox`, `albainox`, `florinox`, `equinox`. `cuis` has 21 useful
     * completions and 2 junk containments. `stel` has `stella` and `stellar`
     * by prefix, and `pastel`, `chastel`, `compostelle`, `wasteland` by
     * containment. Someone typing `inox` wants stainless steel, not the
     * Victorinox brand; the substring rule cannot tell those apart and the
     * prefix rule does not have to.
     *
     * ── What this covers, and what it does not ─────────────────────────────
     *
     * It is also the safety net for the morphology an edit budget cannot
     * reach, which matters because most of the scripts this engine supports
     * were never validated against a corpus. It covers suffixing languages —
     * Turkish `ev` → `evlerimizden`, Finnish, Hungarian, and French plurals
     * past two letters.
     *
     * It does **not** cover a language that inflects by prefix. Arabic attaches
     * its definite article at the front, so `مطبخ` is a *suffix* of `المطبخ`
     * and neither rule here finds it: the article is two characters, and a
     * four-character word is allowed one edit. Hebrew is the same. The mirror
     * rule would fix it and would also drag `victorinox` back in, so it is not
     * the default — it belongs behind an option, the day someone searching in
     * Arabic asks for it. Stated rather than discovered.
     */
    public function accepts(string $candidate): ?float
    {
        if ($candidate === $this->typed) {
            return 1.0;
        }

        if ($this->budget > 0) {
            // A cheap gate before decoding, and a sound one: a UTF-8 string of
            // b bytes holds between ceil(b / 4) and b code points. If even the
            // most favourable count in that range is further from the typed
            // length than the budget allows, no decode can rescue it.
            $bytes = strlen($candidate);
            $tooFar = (int) ceil($bytes / 4) > $this->length + $this->budget
                || $bytes < $this->length - $this->budget;

            if (!$tooFar) {
                $distance = $this->distance(Utf8::codepoints($candidate));

                if ($distance !== null) {
                    return 1.0 - $distance / $this->length;
                }
            }
        }

        // Below three characters a prefix says almost nothing — `de` would
        // reach a fifth of a French vocabulary — and the words that short are
        // the ones carrying no IDF anyway.
        if ($this->length < self::MIN_PREFIX || !str_starts_with($candidate, $this->typed)) {
            return null;
        }

        // Half the unexplained share of the candidate, deducted.
        //
        // The rule to satisfy: typing four of the seven letters of `cuisine` in
        // the right order is *better* evidence than typing four letters one of
        // which is wrong. It was not, at first — the weight was the plain
        // length ratio, 4/7 = 0.57 against 0.75 for a single substitution, so
        // `cuis` returned `cups` and `cuir` ahead of every kitchen knife in the
        // catalogue. Appending is weaker evidence of *error* than mistyping,
        // which is what the halving says.
        //
        //     cuis → cuisine      1 - (3/7)/2 = 0.79   beats a substitution
        //     leath → leather     1 - (2/7)/2 = 0.86
        //     cui → cuisinière    1 - (7/10)/2 = 0.65  loses to one, as it should
        //
        // So a long completion of a short stem still gives way to a plausible
        // correction, and a nearly-finished word does not.
        $missing = max(0, Utf8::length($candidate) - $this->length);

        return 1.0 - ($missing / max(1, Utf8::length($candidate))) / 2;
    }

    /**
     * Levenshtein distance over code points, or null once the budget is spent.
     *
     * One row at a time, and abandoned as soon as every cell in a row is over
     * budget: any path to the far corner crosses that row somewhere, and edit
     * costs are never negative, so nothing downstream can come back under it.
     *
     * @param int[] $other
     */
    private function distance(array $other): ?int
    {
        $rows    = $this->length;
        $columns = count($other);

        if (abs($rows - $columns) > $this->budget) {
            return null;
        }

        $previous = range(0, $columns);

        for ($i = 1; $i <= $rows; $i++) {
            $current = [$i];
            $least   = $i;
            $left    = $i;
            $glyph   = $this->codepoints[$i - 1];

            for ($j = 1; $j <= $columns; $j++) {
                $value = min(
                    $previous[$j] + 1,
                    $left + 1,
                    $previous[$j - 1] + ($glyph === $other[$j - 1] ? 0 : 1),
                );

                $current[$j] = $value;
                $left        = $value;

                if ($value < $least) {
                    $least = $value;
                }
            }

            if ($least > $this->budget) {
                return null;
            }

            $previous = $current;
        }

        return $previous[$columns] <= $this->budget ? $previous[$columns] : null;
    }
}
