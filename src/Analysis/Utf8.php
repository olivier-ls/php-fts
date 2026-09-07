<?php

declare(strict_types=1);

namespace Ols\PhpFts\Analysis;

/**
 * UTF-8 decoding and encoding, in pure PHP.
 *
 * Everything the analyzer does — deciding which script a character belongs to,
 * folding case, choosing an n-gram size — needs code points, not bytes. PHP
 * offers `mb_str_split()` and `mb_ord()` for that, but ext-mbstring is an
 * extension, and this library promises to need none. PCRE with the `/u` flag
 * would also work, since UTF-8 support is compiled into PCRE, but it is slower
 * per call and would still leave every character as a string to decode.
 *
 * So the decoding is done here, once, and it is about a hundred lines.
 *
 * ── The encoding ────────────────────────────────────────────────────────────
 *
 *   0xxxxxxx                                 1 byte    U+0000 – U+007F
 *   110xxxxx 10xxxxxx                        2 bytes   U+0080 – U+07FF
 *   1110xxxx 10xxxxxx 10xxxxxx               3 bytes   U+0800 – U+FFFF
 *   11110xxx 10xxxxxx 10xxxxxx 10xxxxxx      4 bytes   U+10000 – U+10FFFF
 *
 * The leading byte announces the total length; every continuation byte starts
 * with `10`. Note how close that is to the varint used for the postings: both
 * store something of variable size and use high bits to say how far it runs.
 * The difference is who carries the information — the first byte here, every
 * byte there.
 *
 * ── Being strict on the way in ──────────────────────────────────────────────
 *
 * Indexed text comes from databases, CMS exports and file uploads, so it will
 * sooner or later contain bytes that are not valid UTF-8. Rather than trusting
 * it, the decoder rejects:
 *
 *   - overlong forms, where a character is padded into more bytes than it
 *     needs (the classic way to smuggle a `/` or a `.` past a naive filter);
 *   - surrogate halves, U+D800–U+DFFF, which are a UTF-16 mechanism and are
 *     not characters;
 *   - anything above U+10FFFF, which Unicode does not define;
 *   - truncated or misaligned sequences.
 *
 * Each of those yields U+FFFD, the replacement character, and decoding resumes
 * at the next byte. Substituting rather than throwing is deliberate: one bad
 * byte in a product description should cost that character, not the document.
 */
final class Utf8
{
    public const REPLACEMENT = 0xFFFD;

    private function __construct()
    {
    }

    /**
     * Decodes a UTF-8 string into code points.
     *
     * @param int[]|null $offsets pass an array to also receive, for each code
     *        point, its byte offset in $text — followed by one sentinel equal
     *        to the string length, so that code point $i occupies the bytes
     *        $offsets[$i] up to $offsets[$i + 1].
     *
     *        These cannot be recomputed from the code points afterwards: an
     *        invalid byte yields U+FFFD while consuming a single byte, not the
     *        three that U+FFFD encodes to, so a re-encode drifts from the
     *        original exactly where the text is malformed. Highlighting needs
     *        to point back into the caller's own bytes and therefore needs
     *        them; indexing does not ask, and pays one null check per string.
     *
     * @return int[]
     */
    public static function codepoints(string $text, ?array &$offsets = null): array
    {
        $codepoints = [];
        $length     = strlen($text);
        $position   = 0;
        $wanted     = $offsets !== null;

        while ($position < $length) {
            if ($wanted) {
                $offsets[] = $position;
            }

            $byte = ord($text[$position]);

            if ($byte < 0x80) {
                // Plain ASCII, by far the most common case in practice.
                $codepoints[] = $byte;
                $position++;
                continue;
            }

            if ($byte >= 0xC2 && $byte <= 0xDF) {
                $width   = 2;
                $value   = $byte & 0x1F;
                $minimum = 0x80;
            } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                $width   = 3;
                $value   = $byte & 0x0F;
                $minimum = 0x800;
            } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                $width   = 4;
                $value   = $byte & 0x07;
                $minimum = 0x10000;
            } else {
                // 0x80–0xC1 and 0xF5–0xFF cannot begin a sequence: a stray
                // continuation byte, or a leading byte for a range that only
                // overlong forms could reach.
                $codepoints[] = self::REPLACEMENT;
                $position++;
                continue;
            }

            if ($position + $width > $length) {
                $codepoints[] = self::REPLACEMENT;
                $position++;
                continue;
            }

            $valid = true;

            for ($i = 1; $i < $width; $i++) {
                $continuation = ord($text[$position + $i]);

                if (($continuation & 0xC0) !== 0x80) {
                    $valid = false;
                    break;
                }

                $value = ($value << 6) | ($continuation & 0x3F);
            }

            if (!$valid || $value < $minimum || $value > 0x10FFFF || ($value >= 0xD800 && $value <= 0xDFFF)) {
                $codepoints[] = self::REPLACEMENT;
                $position++;
                continue;
            }

            $codepoints[] = $value;
            $position    += $width;
        }

        if ($wanted) {
            $offsets[] = $length;
        }

        return $codepoints;
    }

    /**
     * Encodes one code point as UTF-8.
     *
     * Anything Unicode does not define becomes U+FFFD rather than an exception,
     * so that a round trip through the analyzer can never fail on data.
     */
    public static function chr(int $codepoint): string
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            $codepoint = self::REPLACEMENT;
        }

        if ($codepoint < 0x80) {
            return chr($codepoint);
        }

        if ($codepoint < 0x800) {
            return chr(0xC0 | ($codepoint >> 6))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint < 0x10000) {
            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }

    /**
     * Encodes a run of code points.
     *
     * @param int[] $codepoints
     */
    public static function implode(array $codepoints): string
    {
        $text = '';

        foreach ($codepoints as $codepoint) {
            $text .= self::chr($codepoint);
        }

        return $text;
    }

    /**
     * Number of characters, as opposed to strlen()'s number of bytes.
     */
    public static function length(string $text): int
    {
        return count(self::codepoints($text));
    }

    /**
     * True when every byte of the string forms a valid sequence.
     */
    public static function isValid(string $text): bool
    {
        foreach (self::codepoints($text) as $codepoint) {
            if ($codepoint === self::REPLACEMENT) {
                // A genuine U+FFFD in the input is indistinguishable from a
                // substituted one, which is fine: text containing the
                // replacement character was already damaged upstream.
                return false;
            }
        }

        return true;
    }
}
