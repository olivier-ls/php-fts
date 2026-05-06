<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * Tokenizer
 *
 * Single responsibility: transform a raw text string
 * into a list of normalized trigrams.
 *
 * Stateless — no file, no internal state.
 * All methods are deterministic.
 * Zero required extensions — native PHP only.
 *
 * Pipeline:
 *   1. HTML cleanup     : strip_tags + html_entity_decode
 *   2. Transliteration  : static table (accents, diacritics)
 *   3. Lowercase        : strtolower (safe as ASCII after step 2)
 *   4. Cleanup          : characters outside [a-z0-9] → space, trim
 *   5. Trigrams         : padded with # + sliding windows of 3 characters
 *   6. Deduplication    : each unique trigram, only once
 */
class Tokenizer
{
    private const WORD_BOUNDARY = '#';

    /**
     * Full transliteration table.
     * Handles uppercase and lowercase — strtolower is applied after.
     */
    private const CHAR_MAP = [
        // Uppercase
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
        'Æ'=>'Ae','Ç'=>'C',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
        'Ð'=>'D','Ñ'=>'N',
        'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'Ý'=>'Y','Þ'=>'B',
        'Š'=>'S','Ž'=>'Z','Œ'=>'Oe',
        // Lowercase
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
        'æ'=>'ae','ç'=>'c',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ð'=>'o','ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','þ'=>'b','ÿ'=>'y',
        'š'=>'s','ž'=>'z','œ'=>'oe',
        'ß'=>'ss',
    ];

    /**
     * Normalizes a raw text string.
     *
     * - HTML cleanup (strip_tags + html_entity_decode)
     * - Diacritic transliteration (static table, zero extensions)
     * - Lowercase
     * - Any character outside [a-z0-9] replaced by a space
     * - Multiple spaces collapsed to one, trim
     */
    public function normalize(string $text): string
    {
        // HTML cleanup — useful when data comes from a CMS or e-commerce platform
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Transliteration via static table — zero extensions
        $text = strtr($text, self::CHAR_MAP);

        // Lowercase — safe as we are in pure ASCII after strtr
        $text = strtolower($text);

        // Any character outside [a-z0-9] → space
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);

        // Multiple spaces → one, trim
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Transforms a raw text string into a list of unique normalized trigrams.
     *
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        $words    = explode(' ', $normalized);
        $trigrams = [];

        foreach ($words as $word) {
            foreach ($this->trigramsForWord($word) as $trigram) {
                $trigrams[] = $trigram;
            }
        }

        // array_unique preserves types — no int cast on numeric trigrams
        return array_values(array_unique($trigrams));
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Generates the trigrams for a single already-normalized word.
     * Pads the word with # and slices into windows of 3 characters.
     *
     * "cuir" → "#cuir#" → ['#cu', 'cui', 'uir', 'ir#']
     * "en"   → "#en#"   → ['#en', 'en#']
     * "a"    → "#a#"    → ['#a#']
     *
     * @return string[]
     */
    private function trigramsForWord(string $word): array
    {
        $padded   = self::WORD_BOUNDARY . $word . self::WORD_BOUNDARY;
        $length   = strlen($padded);
        $trigrams = [];

        for ($i = 0; $i <= $length - 3; $i++) {
            $trigrams[] = substr($padded, $i, 3);
        }

        return $trigrams;
    }
}
