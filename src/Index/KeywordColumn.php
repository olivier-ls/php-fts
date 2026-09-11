<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\Varint;

/**
 * A column of exact string values, dictionary-encoded.
 *
 *     $brand = KeywordColumn::open($segment, 'dv.brand');
 *
 *     $adidas = $brand->equals('Adidas');            // a Bitset
 *     $either = $brand->in(['Adidas', 'Puma']);
 *     $counts = $brand->facet($matches, size: 20);   // ['Nike' => 42, …]
 *
 * ── Why facets are cheap here and expensive in 1.x ──────────────────────────
 *
 * Counting how many matching documents carry each brand is, in this layout,
 * incrementing an integer per document and turning the twenty largest counters
 * back into strings at the end. No document is read, no JSON is decoded, no
 * string is compared.
 *
 * 1.x cannot do that at all: it has no columns, so the demo shipped with it
 * runs six separate searches with `limit: 2000` and tallies the decoded
 * documents in PHP — twelve thousand deserialisations to draw one page of
 * facets. The `total` it reports is wrong as well, capped at the page size,
 * because nothing in that design can count matches without materialising them.
 */
final class KeywordColumn
{
    public const MAGIC   = 'KCOL';
    public const VERSION = 1;

    /** magic 4 | version 1 | ordinalWidth 1 | reserved 2 | documentCount 4 | valueCount 4 */
    private const HEADER_SIZE = 16;

    /** Documents whose ordinals are decoded per pass. Must stay a multiple of 8. */
    private const CHUNK = 4096;

    private int $documentCount;
    private int $valueCount;
    private int $ordinalWidth;
    private string $ordinalFormat;
    private Bitset $presence;
    private int $ordinalsOffset;

    /**
     * Loaded only when a facet needs to name its values.
     *
     * @var array<int, string>|null keyed by the ordinal stored in the payload,
     *      so neither sequential nor ordered
     */
    private ?array $valuesByOrdinal = null;

    private ?BlockDictionaryReader $dictionary = null;

    private function __construct(
        private readonly SegmentReader $segment,
        private readonly string $section,
        private readonly string $valuesSection,
    ) {
        $header = $this->segment->read($this->section, 0, self::HEADER_SIZE);

        if (substr($header, 0, 4) !== self::MAGIC) {
            throw new CorruptSegmentException("Section '{$this->section}' does not hold a keyword column");
        }

        $version = ord($header[4]);

        if ($version !== self::VERSION) {
            throw new CorruptSegmentException(
                "Keyword column is format version $version, this build reads version " . self::VERSION
            );
        }

        $this->ordinalWidth  = ord($header[5]);
        $this->documentCount = unpack('V', substr($header, 8, 4))[1];
        $this->valueCount    = unpack('V', substr($header, 12, 4))[1];

        $this->ordinalFormat = match ($this->ordinalWidth) {
            1 => 'C',
            2 => 'v',
            4 => 'V',
            default => throw new CorruptSegmentException(
                "Keyword column declares an impossible ordinal width of {$this->ordinalWidth}"
            ),
        };

        $presenceLength = (int) (($this->documentCount + 7) >> 3);

        $this->presence = Bitset::fromBytes(
            $this->segment->read($this->section, self::HEADER_SIZE, $presenceLength),
            $this->documentCount
        );

        $this->ordinalsOffset = self::HEADER_SIZE + $presenceLength;
    }

    /**
     * @param string $section the ordinals section; the value dictionary is read
     *                        from the same name suffixed with `.values`
     * @throws CorruptSegmentException
     */
    public static function open(SegmentReader $segment, string $section): self
    {
        return new self($segment, $section, $section . '.values');
    }

    /**
     * Bytes per ordinal for a given number of distinct values.
     *
     * A catalogue with fifty brands spends one byte per document; one with
     * sixty thousand categories spends two. Sizing this from the data rather
     * than fixing it at four bytes is most of the space this layout saves.
     */
    public static function ordinalWidth(int $valueCount): int
    {
        return match (true) {
            $valueCount <= 0xFF   => 1,
            $valueCount <= 0xFFFF => 2,
            default               => 4,
        };
    }

    public function count(): int
    {
        return $this->documentCount;
    }

    public function cardinality(): int
    {
        return $this->valueCount;
    }

    public function exists(): Bitset
    {
        return $this->presence;
    }

    public function missing(): Bitset
    {
        return $this->presence->not();
    }

    /**
     * @throws CorruptSegmentException
     */
    public function get(int $document): ?string
    {
        if (!$this->presence->has($document)) {
            return null;
        }

        $ordinal = unpack(
            $this->ordinalFormat,
            $this->segment->read(
                $this->section,
                $this->ordinalsOffset + $document * $this->ordinalWidth,
                $this->ordinalWidth
            )
        )[1];

        return $this->valuesByOrdinal()[$ordinal] ?? null;
    }

    /**
     * Documents whose value is exactly the one given.
     *
     * The string is resolved to its ordinal once, against the value dictionary;
     * the column scan then compares small integers. A value the dictionary does
     * not know cannot match anything, and returns an empty set without reading
     * the column at all.
     *
     * @throws CorruptSegmentException
     */
    public function equals(string $value): Bitset
    {
        $ordinal = $this->ordinalOf($value);

        return $ordinal === null
            ? Bitset::empty($this->documentCount)
            : $this->selectOrdinals([$ordinal => true]);
    }

    /**
     * Documents whose value is one of those given.
     *
     * @param string[] $values
     * @throws CorruptSegmentException
     */
    public function in(array $values): Bitset
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
            : $this->selectOrdinals($wanted);
    }

    /**
     * Counts documents per value.
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

        // Ranked by name once the ordinals have become values, so that ties
        // break the same way here as they do when several segments are added
        // together. Sorting by ordinal would break them by dictionary position
        // instead, which is a different order and would flip on a merge.
        return Facet::rank($result);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, int> ordinal => count, zero counts omitted
     * @throws CorruptSegmentException
     */
    private function countByOrdinal(?Bitset $documents): array
    {
        $presence = $this->presence->bytes();
        $selected = $documents?->bytes();
        $counts   = [];

        // Same discipline as the numeric column: nothing in the inner loop is a
        // method call, because at twenty thousand documents those dominate.
        foreach ($this->chunks() as [$start, $size, $ordinals]) {
            for ($i = 0; $i < $size; $i++) {
                $document = $start + $i;
                $index    = $document >> 3;
                $mask     = 1 << ($document & 7);

                if ((ord($presence[$index]) & $mask) === 0) {
                    continue;
                }

                if ($selected !== null && (ord($selected[$index]) & $mask) === 0) {
                    continue;
                }

                $ordinal = $ordinals[$i + 1];
                $counts[$ordinal] = ($counts[$ordinal] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<int, true> $wanted ordinals to select
     * @throws CorruptSegmentException
     */
    private function selectOrdinals(array $wanted): Bitset
    {
        $presence = $this->presence->bytes();
        $bits     = '';

        foreach ($this->chunks() as [$start, $size, $ordinals]) {
            $document = $start;
            $end      = $start + $size;

            while ($document < $end) {
                $presenceByte = ord($presence[$document >> 3]);
                $accumulated  = 0;

                for ($bit = 0; $bit < 8 && $document < $end; $bit++, $document++) {
                    if ((($presenceByte >> $bit) & 1) === 0) {
                        continue;
                    }

                    if (isset($wanted[$ordinals[$document - $start + 1]])) {
                        $accumulated |= 1 << $bit;
                    }
                }

                $bits .= chr($accumulated);
            }
        }

        return Bitset::fromBytes($bits, $this->documentCount);
    }

    /**
     * Walks the ordinals a chunk at a time, bounding peak memory.
     *
     * @return \Generator<int, array{0: int, 1: int, 2: array<int, int>}>
     * @throws CorruptSegmentException
     */
    private function chunks(): \Generator
    {
        for ($start = 0; $start < $this->documentCount; $start += self::CHUNK) {
            $size = min(self::CHUNK, $this->documentCount - $start);

            $bytes = $this->segment->read(
                $this->section,
                $this->ordinalsOffset + $start * $this->ordinalWidth,
                $size * $this->ordinalWidth
            );

            yield [$start, $size, unpack($this->ordinalFormat . $size, $bytes)];
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
     * Cached because `in(['Nike','Adidas','Puma'])` resolves several values, and
     * reopening would re-read the dictionary header and block index each time.
     */
    private function dictionary(): BlockDictionaryReader
    {
        return $this->dictionary ??= new BlockDictionaryReader($this->segment, $this->valuesSection);
    }

    /**
     * Ordinal to value, built on demand.
     *
     * Only a facet that has to name its buckets needs this, and only then is
     * the dictionary walked. A filter never builds it: it goes the other way,
     * resolving one string to one ordinal.
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
            $position          = 0;
            $names[Varint::decode($payload, $position)] = $value;
        }

        return $this->valuesByOrdinal = $names;
    }
}
