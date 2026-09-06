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
    // Written with spaces between words → padded trigrams.
    case Latin;
    case Cyrillic;
    case Greek;
    case Arabic;
    case Hebrew;
    case Devanagari;

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

        return match (true) {
            // Latin supplement, Extended-A, Extended-B, Extended Additional.
            // Digits are treated as Latin so that "iphone12" stays one run,
            // matching 1.x's behaviour, rather than splitting on the digit.
            $codepoint >= 0x00C0 && $codepoint <= 0x024F,
            $codepoint >= 0x1E00 && $codepoint <= 0x1EFF => self::Latin,

            $codepoint >= 0x0370 && $codepoint <= 0x03FF,
            $codepoint >= 0x1F00 && $codepoint <= 0x1FFF => self::Greek,

            $codepoint >= 0x0400 && $codepoint <= 0x052F => self::Cyrillic,
            $codepoint >= 0x0590 && $codepoint <= 0x05FF => self::Hebrew,

            $codepoint >= 0x0600 && $codepoint <= 0x06FF,
            $codepoint >= 0x0750 && $codepoint <= 0x077F,
            $codepoint >= 0x08A0 && $codepoint <= 0x08FF => self::Arabic,

            $codepoint >= 0x0900 && $codepoint <= 0x097F => self::Devanagari,

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
