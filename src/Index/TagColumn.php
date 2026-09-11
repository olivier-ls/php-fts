<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\Varint;

/**
 * A column of lists of exact values, dictionary-encoded.
 *
 *     $tags = TagColumn::open($segment, 'dv.tags');
 *
 *     $summer = $tags->contains('summer');                 // a Bitset
 *     $either = $tags->containsAny(['summer', 'winter']);
 *     $counts = $tags->facet($matches, size: 20);           // ['summer' => 42, …]
 *
 * The layout is described in TagColumnWriter: fixed-width offsets into a run
 * of ordinals, so a document's tags are a range rather than a value, and an
 * empty range means no tags.
 *
 * ── What a filter on a multi-valued field means ─────────────────────────────
 *
 * `contains()` is what `eq` compiles to, and that is a deliberate reading:
 * `Filter::eq('tags', 'summer')` on a list asks for the documents whose list
 * holds `summer`. There is no useful sense in which a list of three tags
 * *equals* one tag, so equality against a list is containment or it is
 * nothing — the same choice Lucene and Elasticsearch make, for the same
 * reason.
 *
 * Conjunctions need no operator of their own. "Summer *and* luxury" is
 * `Filter::all(eq('tags','summer'), eq('tags','luxury'))`, which compiles to
 * two bitsets and one AND — the filter tree already expresses it, so a
 * `containsAll` here would be a second way to say the same thing.
 *
 * ── Counting a facet over lists ─────────────────────────────────────────────
 *
 * Each document contributes one to each *distinct* value it carries, so the
 * counts of a tag facet add up to more than the number of documents. That is
 * correct and is what a tag facet is for: fifty products, of which thirty are
 * `summer` and twenty-five `luxury`, with five being both.
 *
 * The work is proportional to the number of *values*, not to the number of
 * values times the number of documents — one pass over the ordinal run,
 * incrementing a counter per value. An inverted bitset per tag would answer a
 * filter faster, and would cost a popcount over every tag in the vocabulary to
 * answer a facet; for a catalogue with a few thousand tags that is the wrong
 * trade in the direction facets are actually used.
 */
final class TagColumn
{
    public const MAGIC   = 'TCOL';
    public const VERSION = 1;

    /** magic 4 | version 1 | ordinalWidth 1 | offsetWidth 1 | reserved 1 | documentCount 4 | valueCount 4 | totalValues 4 */
    private const HEADER_SIZE = 20;

    /** Documents whose offsets are decoded per pass. Must stay a multiple of 8. */
    private const CHUNK = 4096;

    private int $documentCount;
    private int $valueCount;
    private int $totalValues;
    private int $ordinalWidth;
    private int $offsetWidth;
    private string $ordinalFormat;
    private string $offsetFormat;
    private int $offsetsOffset;
    private int $ordinalsOffset;

    /**
     * Loaded only when a facet needs to name its values.
     *
     * @var array<int, string>|null keyed by the ordinal stored in the payload,
     *      so neither sequential nor ordered
     */
    private ?array $valuesByOrdinal = null;

    private ?BlockDictionaryReader $dictionary = null;

    /**
     * @throws CorruptSegmentException
     */
    private function __construct(
        private readonly SegmentReader $segment,
        private readonly string $section,
        private readonly string $valuesSection,
    ) {
        $header = $this->segment->read($this->section, 0, self::HEADER_SIZE);

        if (substr($header, 0, 4) !== self::MAGIC) {
            throw new CorruptSegmentException("Section '{$this->section}' does not hold a tag column");
        }

        $version = ord($header[4]);

        if ($version !== self::VERSION) {
            throw new CorruptSegmentException(
                "Tag column is format version $version, this build reads version " . self::VERSION
            );
        }

        $this->ordinalWidth  = ord($header[5]);
        $this->offsetWidth   = ord($header[6]);
        $this->documentCount = unpack('V', substr($header, 8, 4))[1];
        $this->valueCount    = unpack('V', substr($header, 12, 4))[1];
        $this->totalValues   = unpack('V', substr($header, 16, 4))[1];

        $this->ordinalFormat = self::formatFor($this->ordinalWidth);
        $this->offsetFormat  = self::formatFor($this->offsetWidth);

        $this->offsetsOffset  = self::HEADER_SIZE;
        $this->ordinalsOffset = $this->offsetsOffset + ($this->documentCount + 1) * $this->offsetWidth;
    }

    /**
     * @param string $section the offsets and ordinals section; the value
     *                        dictionary is read from the same name suffixed
     *                        with `.values`
     * @throws CorruptSegmentException
     */
    public static function open(SegmentReader $segment, string $section): self
    {
        return new self($segment, $section, $section . '.values');
    }

    /**
     * Bytes needed to hold a count — used for both ordinals and offsets.
     *
     * A catalogue with fifty tags spends one byte per value; sixty thousand
     * tags spend two. Offsets are sized the same way from the total number of
     * values in the column, which is what keeps a small index small: twenty
     * thousand products with three tags each pay two bytes per offset, not
     * four.
     */
    public static function width(int $count): int
    {
        return match (true) {
            $count <= 0xFF   => 1,
            $count <= 0xFFFF => 2,
            default          => 4,
        };
    }

    /**
     * @throws CorruptSegmentException
     */
    public static function formatFor(int $width): string
    {
        return match ($width) {
            1 => 'C',
            2 => 'v',
            4 => 'V',
            default => throw new CorruptSegmentException(
                "Tag column declares an impossible width of $width"
            ),
        };
    }

    public function count(): int
    {
        return $this->documentCount;
    }

    /** How many distinct values the column holds. */
    public function cardinality(): int
    {
        return $this->valueCount;
    }

    /** How many values in total, across every document. */
    public function totalValues(): int
    {
        return $this->totalValues;
    }

    /**
     * Documents carrying at least one value.
     *
     * @throws CorruptSegmentException
     */
    public function exists(): Bitset
    {
        $bits = '';

        foreach ($this->chunks() as [$start, $size, $offsets]) {
            $document = 0;

            while ($document < $size) {
                $accumulated = 0;

                for ($bit = 0; $bit < 8 && $document < $size; $bit++, $document++) {
                    // unpack() is 1-based, so document i's range is
                    // $offsets[i + 1] to $offsets[i + 2].
                    if ($offsets[$document + 2] > $offsets[$document + 1]) {
                        $accumulated |= 1 << $bit;
                    }
                }

                $bits .= chr($accumulated);
            }
        }

        return Bitset::fromBytes($bits, $this->documentCount);
    }

    /**
     * @throws CorruptSegmentException
     */
    public function missing(): Bitset
    {
        return $this->exists()->not();
    }

    /**
     * One document's values, in ascending order.
     *
     * @return string[]
     * @throws CorruptSegmentException
     */
    public function get(int $document): array
    {
        if ($document < 0 || $document >= $this->documentCount) {
            return [];
        }

        [$from, $to] = array_values(unpack(
            $this->offsetFormat . '2',
            $this->segment->read(
                $this->section,
                $this->offsetsOffset + $document * $this->offsetWidth,
                2 * $this->offsetWidth
            )
        ));

        if ($to <= $from) {
            return [];
        }

        $ordinals = unpack(
            $this->ordinalFormat . ($to - $from),
            $this->segment->read(
                $this->section,
                $this->ordinalsOffset + $from * $this->ordinalWidth,
                ($to - $from) * $this->ordinalWidth
            )
        );

        $names  = $this->valuesByOrdinal();
        $values = [];

        foreach ($ordinals as $ordinal) {
            $values[] = $names[$ordinal] ?? "?$ordinal";
        }

        return $values;
    }

    /**
     * Documents whose list holds the value given.
     *
     * The string is resolved to its ordinal once, against the value
     * dictionary; the column scan then compares small integers. A value the
     * dictionary does not know cannot match anything, and returns an empty set
     * without reading the column at all.
     *
     * @throws CorruptSegmentException
     */
    public function contains(string $value): Bitset
    {
        $ordinal = $this->ordinalOf($value);

        return $ordinal === null
            ? Bitset::empty($this->documentCount)
            : $this->select([$ordinal => true]);
    }

    /**
     * Documents whose list holds at least one of the values given.
     *
     * @param string[] $values
     * @throws CorruptSegmentException
     */
    public function containsAny(array $values): Bitset
    {
        $wanted = [];

        foreach ($values as $value) {
            $ordinal = $this->ordinalOf((string) $value);

            if ($ordinal !== null) {
                $wanted[$ordinal] = true;
            }
        }

        return $wanted === []
            ? Bitset::empty($this->documentCount)
            : $this->select($wanted);
    }

    /**
     * Counts documents per value, each document counting once per value it
     * carries.
     *
     * @param Bitset|null $documents the matching documents; null counts everything
     * @param int         $size      how many values to report, largest counts first
     *
     * @return array<string, int>
     * @throws CorruptSegmentException
     */
    public function facet(?Bitset $documents = null, int $size = 20): array
    {
        $counts = $this->countByOrdinal($documents);

        arsort($counts);

        if ($size > 0) {
            $counts = array_slice($counts, 0, $size, true);
        }

        // Only the values actually reported are turned back into strings.
        $names  = $this->valuesByOrdinal();
        $result = [];

        foreach ($counts as $ordinal => $count) {
            $result[$names[$ordinal] ?? "?$ordinal"] = $count;
        }

        // Ranked once the ordinals have become values, so ties break by value
        // here exactly as they do when several segments are added together.
        return Facet::rank($result);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, int> ordinal => count, zero counts omitted
     * @throws CorruptSegmentException
     */
    private function countByOrdinal(?Bitset $documents): array
    {
        $selected = $documents?->bytes();
        $counts   = [];

        // Same discipline as the other columns: nothing in the inner loop is a
        // method call, because at twenty thousand documents those dominate.
        foreach ($this->chunks() as [$start, $size, $offsets, $ordinals, $base]) {
            for ($i = 0; $i < $size; $i++) {
                $from = $offsets[$i + 1];
                $to   = $offsets[$i + 2];

                if ($to <= $from) {
                    continue;
                }

                if ($selected !== null) {
                    $document = $start + $i;

                    if ((ord($selected[$document >> 3]) & (1 << ($document & 7))) === 0) {
                        continue;
                    }
                }

                for ($at = $from; $at < $to; $at++) {
                    $ordinal          = $ordinals[$at - $base + 1];
                    $counts[$ordinal] = ($counts[$ordinal] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @param array<int, true> $wanted ordinals to select
     * @throws CorruptSegmentException
     */
    private function select(array $wanted): Bitset
    {
        $bits = '';

        foreach ($this->chunks() as [$start, $size, $offsets, $ordinals, $base]) {
            $i = 0;

            while ($i < $size) {
                $accumulated = 0;

                for ($bit = 0; $bit < 8 && $i < $size; $bit++, $i++) {
                    $from = $offsets[$i + 1];
                    $to   = $offsets[$i + 2];

                    for ($at = $from; $at < $to; $at++) {
                        if (isset($wanted[$ordinals[$at - $base + 1]])) {
                            $accumulated |= 1 << $bit;
                            break;
                        }
                    }
                }

                $bits .= chr($accumulated);
            }
        }

        return Bitset::fromBytes($bits, $this->documentCount);
    }

    /**
     * Walks the column a chunk of documents at a time, bounding peak memory.
     *
     * Each pass reads that chunk's offsets — one more than there are documents
     * in it, so the last document's range is complete — and then the single
     * slice of ordinals those offsets span. Two reads per chunk, whatever the
     * number of values in it.
     *
     * @return \Generator<int, array{0: int, 1: int, 2: array<int, int>, 3: array<int, int>, 4: int}>
     * @throws CorruptSegmentException
     */
    private function chunks(): \Generator
    {
        for ($start = 0; $start < $this->documentCount; $start += self::CHUNK) {
            $size = min(self::CHUNK, $this->documentCount - $start);

            $offsets = unpack(
                $this->offsetFormat . ($size + 1),
                $this->segment->read(
                    $this->section,
                    $this->offsetsOffset + $start * $this->offsetWidth,
                    ($size + 1) * $this->offsetWidth
                )
            );

            $base = $offsets[1];
            $end  = $offsets[$size + 1];

            $ordinals = $end > $base
                ? unpack(
                    $this->ordinalFormat . ($end - $base),
                    $this->segment->read(
                        $this->section,
                        $this->ordinalsOffset + $base * $this->ordinalWidth,
                        ($end - $base) * $this->ordinalWidth
                    )
                )
                : [];

            yield [$start, $size, $offsets, $ordinals, $base];
        }
    }

    /**
     * @throws CorruptSegmentException
     */
    private function ordinalOf(string $value): ?int
    {
        $payload = $this->dictionary()->get($value);

        if ($payload === null) {
            return null;
        }

        $position = 0;

        return Varint::decode($payload, $position);
    }

    /**
     * The value dictionary, opened once.
     *
     * Cached because `containsAny(['a','b','c'])` resolves several values, and
     * reopening would re-read the dictionary header and block index each time.
     */
    private function dictionary(): BlockDictionaryReader
    {
        return $this->dictionary ??= new BlockDictionaryReader($this->segment, $this->valuesSection);
    }

    /**
     * Ordinal to value, built on demand.
     *
     * Only a facet that has to name its buckets, or get() reading one
     * document's tags, needs this. A filter never builds it: it goes the other
     * way, resolving one string to one ordinal.
     *
     * @return string[]
     * @throws CorruptSegmentException
     */
    private function valuesByOrdinal(): array
    {
        if ($this->valuesByOrdinal !== null) {
            return $this->valuesByOrdinal;
        }

        $names = [];

        foreach ($this->dictionary()->iterate() as $value => $payload) {
            $position = 0;
            $names[Varint::decode($payload, $position)] = $value;
        }

        return $this->valuesByOrdinal = $names;
    }
}
