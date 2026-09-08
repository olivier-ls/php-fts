<?php

declare(strict_types=1);

namespace Ols\PhpFts\Analysis;

/**
 * Which writing system a character belongs to, and what that implies.
 *
 * Two things depend on this, and only two:
 *
 * 1. **Where runs break.** Text is cut into runs of one script. A space does
 *    that job in most writing systems, but not in Japanese, where 革靴ブラウン
 *    runs Han straight into Katakana with nothing between them — and an n-gram
 *    spanning that seam belongs to neither word.
 *
 * 2. **How long an n-gram is.** Scripts that separate words with spaces get
 *    trigrams over a padded word, exactly as 1.x did. Scripts written without
 *    spaces get bigrams over the run.
 *
 * That second point deserves stating plainly, because it is the whole reason
 * n-gram indexing suits this engine's new market: for Japanese, Chinese and
 * Thai, n-grams are not a fallback for lacking a proper tokenizer — they *are*
 * the standard technique. Words in those languages run one to three characters,
 * so a bigram is close to a word, and the alternative is a morphological
 * dictionary (MeCab, Kuromoji) weighing tens of megabytes. A pure-PHP engine
 * that ships no data files is, for once, in the better position.
 */
enum Script
{
    // Written with spaces between words → one word is one term.
    case Latin;
    case Cyrillic;
    case Greek;
    case Arabic;
    case Hebrew;
    case Devanagari;
    case Bengali;
    case Gurmukhi;
    case Gujarati;
    case Oriya;
    case Tamil;
    case Telugu;
    case Kannada;
    case Malayalam;
    case Sinhala;
    case Armenian;
    case Georgian;
    case Ethiopic;

    // Written without spaces → bigrams over the run.
    case Han;
    case Hiragana;
    case Katakana;
    case Hangul;
    case Thai;
    case Lao;
    case Khmer;
    case Myanmar;

    /** Whitespace, punctuation, symbols: breaks a run and is not indexed. */
    case Separator;

    /**
     * True for writing systems that do not put spaces between words.
     */
    public function isContinuous(): bool
    {
        return match ($this) {
            self::Han, self::Hiragana, self::Katakana, self::Hangul,
            self::Thai, self::Lao, self::Khmer, self::Myanmar => true,
            default => false,
        };
    }

    /**
     * Characters per n-gram.
     *
     * Three for space-separated scripts, where a run is one word and the
     * padding markers add the prefix and suffix matches. Two for continuous
     * scripts, where a run is a whole phrase and words inside it are short.
     */
    public function nGramSize(): int
    {
        return $this->isContinuous() ? 2 : 3;
    }

    /**
     * Whether a run of this script gets `#` markers at its ends.
     *
     * Only word-separated scripts do. A run of Japanese is a phrase rather than
     * a word, so marking its edges would say something untrue about where the
     * words are.
     */
    public function usesWordBoundaries(): bool
    {
        return !$this->isContinuous() && $this !== self::Separator;
    }

    /**
     * Classifies one code point.
     *
     * Only the ranges the engine can index are named; everything else — Latin
     * punctuation, symbols, emoji, control characters, unassigned code points —
     * is a Separator, so it breaks runs and never reaches the index.
     *
     * ── Why a block is not a set of letters ────────────────────────────────
     *
     * {@see blockOf()} answers by Unicode block, and a block holds its script's
     * own punctuation alongside its letters. Left at that, the Arabic comma is
     * a letter: `كتاب، جديد` yields the term `كتاب،`, which no query for
     * `كتاب` ever produces. The Hebrew maqaf glues `בית־ספר` into one term, the
     * Devanagari danda ends every Hindi sentence and rides on its last word,
     * and `×` makes `3×4` a single unsearchable token.
     *
     * So the block answer is filtered through {@see FoldingTables::NOT_LETTERS},
     * which is generated from Unicode's own general categories — every claimed
     * code point that is punctuation or a symbol. One `isset` on an int-keyed
     * array, after the ASCII fast path that most text never leaves.
     */
    public static function of(int $codepoint): self
    {
        // ASCII first: it is the overwhelming majority of what gets indexed.
        if ($codepoint < 0x80) {
            if (($codepoint >= 0x61 && $codepoint <= 0x7A)   // a-z
                || ($codepoint >= 0x41 && $codepoint <= 0x5A) // A-Z
                || ($codepoint >= 0x30 && $codepoint <= 0x39) // 0-9
            ) {
                return self::Latin;
            }

            return self::Separator;
        }

        return isset(FoldingTables::NOT_LETTERS[$codepoint])
            ? self::Separator
            : self::blockOf($codepoint);
    }

    /**
     * Which block a code point falls in, punctuation included.
     *
     * Separated from {@see of()} so that the generator in `tools/` can ask what
     * the ranges *claim* — which is the input it needs to work out what to
     * exclude. Asking `of()` would be circular: once the exclusions exist, the
     * excluded code points stop being claimed and regenerating would produce an
     * empty list.
     *
     * @internal for tools/generate-folding.php and the closure test
     */
    public static function blockOf(int $codepoint): self
    {
        if ($codepoint < 0x80) {
            if (($codepoint >= 0x61 && $codepoint <= 0x7A)
                || ($codepoint >= 0x41 && $codepoint <= 0x5A)
                || ($codepoint >= 0x30 && $codepoint <= 0x39)
            ) {
                return self::Latin;
            }

            return self::Separator;
        }

        return match (true) {
            // Latin supplement, Extended-A, Extended-B, Extended Additional.
            // Digits are treated as Latin so that "iphone12" stays one run,
            // matching 1.x's behaviour, rather than splitting on the digit.
            $codepoint >= 0x00C0 && $codepoint <= 0x024F,
            $codepoint >= 0x1E00 && $codepoint <= 0x1EFF,

            // Extended-C, D and E. Rare — phonetic transcription, Cornish, Old
            // Irish, medieval abbreviations — and they earn their line by
            // closing the case property rather than by their traffic: the
            // capitals of ȿ and ɀ live in Extended-C, so leaving it out left
            // two Latin letters whose two cases could never meet.
            $codepoint >= 0x2C60 && $codepoint <= 0x2C7F,
            $codepoint >= 0xA720 && $codepoint <= 0xA7FF,
            $codepoint >= 0xAB30 && $codepoint <= 0xAB6F => self::Latin,

            $codepoint >= 0x0370 && $codepoint <= 0x03FF,
            $codepoint >= 0x1F00 && $codepoint <= 0x1FFF => self::Greek,

            $codepoint >= 0x0400 && $codepoint <= 0x052F => self::Cyrillic,
            $codepoint >= 0x0590 && $codepoint <= 0x05FF => self::Hebrew,

            $codepoint >= 0x0600 && $codepoint <= 0x06FF,
            $codepoint >= 0x0750 && $codepoint <= 0x077F,
            $codepoint >= 0x08A0 && $codepoint <= 0x08FF => self::Arabic,

            $codepoint >= 0x0900 && $codepoint <= 0x097F => self::Devanagari,

            // The rest of the Brahmic family, all written with spaces between
            // words exactly as Devanagari is. They were absent rather than
            // rejected, which meant `চামড়ার জুতা` analysed to nothing at all —
            // the same silent deletion that 1.x's `[^a-z0-9]+` performed on
            // Cyrillic and Japanese, and that this release exists to end.
            $codepoint >= 0x0980 && $codepoint <= 0x09FF => self::Bengali,
            $codepoint >= 0x0A00 && $codepoint <= 0x0A7F => self::Gurmukhi,
            $codepoint >= 0x0A80 && $codepoint <= 0x0AFF => self::Gujarati,
            $codepoint >= 0x0B00 && $codepoint <= 0x0B7F => self::Oriya,
            $codepoint >= 0x0B80 && $codepoint <= 0x0BFF => self::Tamil,
            $codepoint >= 0x0C00 && $codepoint <= 0x0C7F => self::Telugu,
            $codepoint >= 0x0C80 && $codepoint <= 0x0CFF => self::Kannada,
            $codepoint >= 0x0D00 && $codepoint <= 0x0D7F => self::Malayalam,
            $codepoint >= 0x0D80 && $codepoint <= 0x0DFF => self::Sinhala,

            $codepoint >= 0x0530 && $codepoint <= 0x058F => self::Armenian,

            // Three blocks, because Georgian encodes its cases apart: Mkhedruli
            // is the everyday one, Mtavruli its capitals, Nuskhuri the
            // ecclesiastical lower case.
            $codepoint >= 0x10A0 && $codepoint <= 0x10FF,
            $codepoint >= 0x1C90 && $codepoint <= 0x1CBF,
            $codepoint >= 0x2D00 && $codepoint <= 0x2D2F => self::Georgian,

            $codepoint >= 0x1200 && $codepoint <= 0x139F => self::Ethiopic,

            $codepoint >= 0x0E00 && $codepoint <= 0x0E7F => self::Thai,
            $codepoint >= 0x0E80 && $codepoint <= 0x0EFF => self::Lao,
            $codepoint >= 0x1000 && $codepoint <= 0x109F => self::Myanmar,
            $codepoint >= 0x1780 && $codepoint <= 0x17FF => self::Khmer,

            $codepoint >= 0x3041 && $codepoint <= 0x309F => self::Hiragana,

            // U+30FC, the prolonged sound mark, is shared by both kana and is
            // classified as Katakana because that is where it nearly always
            // appears — ブラウン, コーヒー.
            $codepoint >= 0x30A0 && $codepoint <= 0x30FF,
            $codepoint >= 0x31F0 && $codepoint <= 0x31FF => self::Katakana,

            $codepoint >= 0x1100 && $codepoint <= 0x11FF,
            $codepoint >= 0xAC00 && $codepoint <= 0xD7AF => self::Hangul,

            $codepoint >= 0x3400 && $codepoint <= 0x4DBF,
            $codepoint >= 0x4E00 && $codepoint <= 0x9FFF,
            $codepoint >= 0xF900 && $codepoint <= 0xFAFF,
            $codepoint >= 0x20000 && $codepoint <= 0x2FA1F => self::Han,

            default => self::Separator,
        };
    }
}
