<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * Which fields a search highlights, and how.
 *
 *     $engine->search('leather', highlight: ['title', 'description']);
 *
 *     $engine->search('leather', highlight: Highlight::fields(['description'])
 *         ->tags('<b>', '</b>')
 *         ->excerpt(window: 40));
 *
 * A plain list of field names is accepted everywhere this object is, because
 * the default — the whole field, `<mark>`, HTML-escaped — is what most callers
 * want, and asking them to build an object to get it would be noise.
 *
 * ── Escaping ────────────────────────────────────────────────────────────────
 *
 * A highlight is HTML by construction: it carries the open and close tags. The
 * field it is built from is routinely user-supplied, so its text is escaped
 * *before* the tags go in, and the result is safe to render. `raw()` turns
 * that off, for a caller who escapes downstream itself; `positions()` sidesteps
 * the question entirely by returning offsets rather than markup.
 *
 * ── Why the window is in characters ─────────────────────────────────────────
 *
 * 1.x counted words, having split the field on whitespace. That works in
 * French and does nothing at all in Japanese, where a whole sentence is one
 * "word" and an excerpt of eight of them is the entire field. Counting
 * characters is the only unit that means the same thing in every script this
 * engine indexes. In a script that does use spaces, the cut is then nudged
 * outwards to the nearest one, so an excerpt still begins and ends on a word.
 */
final class Highlight
{
    public const DEFAULT_WINDOW = 60;

    /**
     * @param string[] $fields
     */
    private function __construct(
        public readonly array $fields,
        public readonly string $open = '<mark>',
        public readonly string $close = '</mark>',
        public readonly ?int $window = null,
        public readonly bool $offsets = false,
        public readonly bool $escape = true,
    ) {
    }

    /**
     * @param string[] $fields
     */
    public static function fields(array $fields): self
    {
        return new self(array_values(array_unique(array_map('strval', $fields))));
    }

    public function tags(string $open, string $close): self
    {
        return new self($this->fields, $open, $close, $this->window, $this->offsets, $this->escape);
    }

    /**
     * A window of context around the match instead of the whole field.
     *
     * @param int $window characters kept on each side of the marked text
     */
    public function excerpt(int $window = self::DEFAULT_WINDOW): self
    {
        return new self($this->fields, $this->open, $this->close, max(1, $window), $this->offsets, $this->escape);
    }

    /** The whole field, however long it is. The default. */
    public function full(): self
    {
        return new self($this->fields, $this->open, $this->close, null, $this->offsets, $this->escape);
    }

    /**
     * Byte offsets instead of HTML, for output that is not HTML.
     *
     * A field then highlights to `['text' => …, 'spans' => [[start, end], …]]`,
     * where the text is the field with markup stripped and entities decoded —
     * the string the offsets index into, which is not the caller's raw value.
     */
    public function positions(): self
    {
        return new self($this->fields, $this->open, $this->close, $this->window, true, $this->escape);
    }

    /** Insert the tags into the field's text as it stands, escaping nothing. */
    public function raw(): self
    {
        return new self($this->fields, $this->open, $this->close, $this->window, $this->offsets, false);
    }

    /**
     * What a `highlight:` argument means, whichever of its two forms it took.
     *
     * Null when nothing was asked for, so that a caller can skip the work
     * rather than test an empty object.
     *
     * @param self|string[] $highlight
     */
    public static function normalise(self|array $highlight): ?self
    {
        if (is_array($highlight)) {
            $highlight = self::fields($highlight);
        }

        return $highlight->fields === [] ? null : $highlight;
    }
}
