<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

/**
 * A set of document numbers, held as one bit per document in a PHP string.
 *
 * PHP applies `&`, `|`, `^` and `~` to strings byte by byte, in C. That single
 * fact is what makes filters, exact totals and facets affordable in a pure-PHP
 * engine:
 *
 *     $matches = $textMatches->and($active)->and($inStock)->andNot($deleted);
 *
 * Twenty thousand documents fit in 2 500 bytes, and combining two sets is one
 * C-level operation over those bytes — no PHP loop, no extension, no array of
 * twenty thousand integers.
 *
 * Compare with what 1.x had to do. Filtering there meant reading each candidate
 * document, running `json_decode` on it and inspecting a field; counting a
 * facet meant doing that for every match. Its demo runs six searches with
 * `limit: 2000` to fake facet counts, which is twelve thousand deserialisations
 * per page. Here the same work is a handful of string operations.
 *
 * ── Two traps in PHP's string operators ─────────────────────────────────────
 *
 * They behave differently when the operands differ in length: `&` truncates to
 * the shorter string, while `|` and `^` pad to the longer. Relying on either
 * would make a set's meaning depend on how many bytes happened to be allocated
 * for it, so every set here carries an explicit capacity and every operation
 * works on equal-length buffers.
 *
 * `~` has no capacity to respect at all, so complementing sets the unused bits
 * at the end of the last byte. Those would then be counted and iterated as
 * documents that do not exist, which is why the tail is masked after every
 * complement.
 */
final class Bitset
{
    /**
     * Number of set bits for every possible byte value.
     *
     * Built once, then used to turn a population count into 256 additions
     * rather than one per document — see count().
     *
     * @var int[]|null
     */
    private static ?array $popcount = null;

    private function __construct(
        private string $bytes,
        private readonly int $capacity,
    ) {
    }

    public static function empty(int $capacity): self
    {
        return new self(str_repeat("\x00", self::byteLength($capacity)), $capacity);
    }

    /**
     * Every document in range.
     *
     * Used as the starting point of a filter chain, and as the answer to a
     * search with no query text — where the result is "everything, then
     * filtered".
     */
    public static function full(int $capacity): self
    {
        $set = new self(str_repeat("\xff", self::byteLength($capacity)), $capacity);
        $set->maskTail();

        return $set;
    }

    /**
     * @param int[] $documents
     */
    public static function of(array $documents, int $capacity): self
    {
        $set = self::empty($capacity);

        foreach ($documents as $document) {
            $set->set($document);
        }

        return $set;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Adds a document. Mutates, because this is how a set gets built.
     *
     * Out-of-range numbers are ignored rather than rejected: a set is often
     * built from a posting list belonging to a different segment generation,
     * and dropping the stragglers is more useful than refusing the batch.
     */
    public function set(int $document): void
    {
        if ($document < 0 || $document >= $this->capacity) {
            return;
        }

        $index = $document >> 3;
        $this->bytes[$index] = chr(ord($this->bytes[$index]) | (1 << ($document & 7)));
    }

    public function has(int $document): bool
    {
        if ($document < 0 || $document >= $this->capacity) {
            return false;
        }

        return (ord($this->bytes[$document >> 3]) & (1 << ($document & 7))) !== 0;
    }

    /**
     * Documents in both sets.
     */
    public function and(self $other): self
    {
        return new self($this->bytes & $this->align($other), $this->capacity);
    }

    /**
     * Documents in either set.
     */
    public function or(self $other): self
    {
        return new self($this->bytes | $this->align($other), $this->capacity);
    }

    /**
     * Documents in this set but not the other.
     *
     * The operation deletions use: live documents are `$matches->andNot($deleted)`.
     */
    public function andNot(self $other): self
    {
        return new self($this->bytes & ~$this->align($other), $this->capacity);
    }

    /**
     * Documents not in this set.
     */
    public function not(): self
    {
        $set = new self(~$this->bytes, $this->capacity);
        $set->maskTail();

        return $set;
    }

    /**
     * How many documents are in the set.
     *
     * count_chars() tallies byte values in C, so the population count becomes a
     * loop over at most 256 distinct values instead of one over every document.
     * On a set of twenty thousand that is 256 additions rather than 2 500 byte
     * reads in PHP.
     */
    public function count(): int
    {
        $table = self::popcountTable();
        $total = 0;

        foreach (count_chars($this->bytes, 1) as $byte => $occurrences) {
            $total += $table[$byte] * $occurrences;
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        // strspn counts leading NUL bytes in C; if that covers the whole
        // buffer the set holds nothing, and no counting is needed.
        return strspn($this->bytes, "\x00") === strlen($this->bytes);
    }

    /**
     * Walks the set in ascending document order.
     *
     * Runs of empty bytes are skipped with strspn rather than examined one by
     * one, which matters because a filter that keeps a hundred documents out of
     * twenty thousand leaves long stretches of zeroes.
     *
     * @return \Generator<int, int>
     */
    public function iterate(): \Generator
    {
        $length = strlen($this->bytes);
        $index  = 0;

        while ($index < $length) {
            $skipped = strspn($this->bytes, "\x00", $index);

            if ($skipped > 0) {
                $index += $skipped;
                continue;
            }

            $byte = ord($this->bytes[$index]);

            for ($bit = 0; $bit < 8; $bit++) {
                if (($byte & (1 << $bit)) !== 0) {
                    yield ($index << 3) | $bit;
                }
            }

            $index++;
        }
    }

    /**
     * @return int[]
     */
    public function toArray(): array
    {
        return iterator_to_array($this->iterate(), false);
    }

    /**
     * The raw bytes, for storing a set on disk — deletion bitmaps do this.
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    public static function fromBytes(string $bytes, int $capacity): self
    {
        $expected = self::byteLength($capacity);
        $bytes    = substr(str_pad($bytes, $expected, "\x00"), 0, $expected);

        $set = new self($bytes, $capacity);
        $set->maskTail();

        return $set;
    }

    // -------------------------------------------------------------------------

    private static function byteLength(int $capacity): int
    {
        return $capacity <= 0 ? 0 : (int) (($capacity + 7) >> 3);
    }

    /**
     * The other set's bytes, cut or padded to this set's length.
     *
     * Without this, `&` would silently shorten the result to the smaller
     * operand and `|` would silently lengthen it — so a set's meaning would
     * depend on the capacity of whatever it was last combined with.
     */
    private function align(self $other): string
    {
        $length = strlen($this->bytes);

        if (strlen($other->bytes) === $length) {
            return $other->bytes;
        }

        return substr(str_pad($other->bytes, $length, "\x00"), 0, $length);
    }

    /**
     * Clears the bits past the capacity in the final byte.
     *
     * A capacity of 20 occupies three bytes, so bits 20 to 23 exist physically
     * but mean nothing. Complementing sets them; leaving them set would make
     * count() and iterate() report documents that do not exist.
     */
    private function maskTail(): void
    {
        $used = $this->capacity & 7;

        if ($used === 0 || $this->bytes === '') {
            return;
        }

        $last = strlen($this->bytes) - 1;

        $this->bytes[$last] = chr(ord($this->bytes[$last]) & ((1 << $used) - 1));
    }

    /**
     * @return int[]
     */
    private static function popcountTable(): array
    {
        if (self::$popcount !== null) {
            return self::$popcount;
        }

        $table = [];

        for ($byte = 0; $byte < 256; $byte++) {
            $bits = 0;

            for ($bit = 0; $bit < 8; $bit++) {
                $bits += ($byte >> $bit) & 1;
            }

            $table[$byte] = $bits;
        }

        return self::$popcount = $table;
    }
}
