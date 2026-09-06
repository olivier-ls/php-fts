<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Storage\SegmentReader;

/**
 * A column of numbers, read out of a segment section.
 *
 *     $price = NumericColumn::open($segment, 'dv.price');
 *
 *     $affordable = $price->range(null, 300.0);        // price <= 300
 *     $inStock    = $stock->range(0.0, null, minInclusive: false);
 *     $stats      = $price->stats($matches);           // min, max, avg
 *
 * Filtering returns a Bitset, which is the point: a filter clause becomes a set
 * of documents, and combining clauses is then a string operation rather than
 * another pass over the data.
 *
 * ── What this replaces ──────────────────────────────────────────────────────
 *
 * 1.x evaluates filters against the decoded document: for every candidate it
 * reads the record, runs `json_decode` on it and inspects a field. Filtering
 * twenty thousand documents on three fields costs twenty thousand
 * deserialisations. Here the same question is a linear pass over 160 KB of
 * doubles with no allocation per document, and the answer is reusable.
 *
 * ── Scanning in chunks ──────────────────────────────────────────────────────
 *
 * `unpack('e*', …)` would decode the whole column in one C call, which is fast
 * but materialises one PHP float per document — around 1.5 MB of array for a
 * 160 KB column. Since shared hosting is the target and `memory_limit` is often
 * 128 MB, the scan works through fixed-size chunks instead: still one C call
 * per chunk, but the peak stays bounded whatever the segment holds.
 */
final class NumericColumn
{
    public const MAGIC   = 'NCOL';
    public const VERSION = 1;

    private const HEADER_SIZE = 12;

    /** Values decoded per pass. 4 096 doubles is 32 KB read, 4 096 floats held. */
    private const CHUNK = 4096;

    private int $documentCount;
    private Bitset $presence;

    /** Byte offset of the values area within the section. */
    private int $valuesOffset;

    private function __construct(
        private readonly SegmentReader $segment,
        private readonly string $section,
    ) {
        $header = $this->segment->read($this->section, 0, self::HEADER_SIZE);

        if (substr($header, 0, 4) !== self::MAGIC) {
            throw new CorruptSegmentException("Section '{$this->section}' does not hold a numeric column");
        }

        $version = ord($header[4]);

        if ($version !== self::VERSION) {
            throw new CorruptSegmentException(
                "Numeric column is format version $version, this build reads version " . self::VERSION
            );
        }

        $this->documentCount = unpack('V', substr($header, 8, 4))[1];

        $presenceLength = (int) (($this->documentCount + 7) >> 3);

        $this->presence = Bitset::fromBytes(
            $this->segment->read($this->section, self::HEADER_SIZE, $presenceLength),
            $this->documentCount
        );

        $this->valuesOffset = self::HEADER_SIZE + $presenceLength;
    }

    /**
     * @throws CorruptSegmentException
     */
    public static function open(SegmentReader $segment, string $section): self
    {
        return new self($segment, $section);
    }

    public function count(): int
    {
        return $this->documentCount;
    }

    /**
     * Documents that actually carry a value.
     *
     * The distinction filters live on: a document whose stock is 0 has a value;
     * one with no stock field at all does not, and must not be swept up by
     * `stock >= 0`.
     */
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
    public function get(int $document): ?float
    {
        if (!$this->presence->has($document)) {
            return null;
        }

        return unpack('e', $this->segment->read($this->section, $this->valuesOffset + $document * 8, 8))[1];
    }

    /**
     * Documents whose value equals the given one.
     *
     * Expressed as a range with both bounds equal, which for any value other
     * than NaN is the same test — and keeps a single scanning loop instead of
     * two that could drift apart.
     *
     * Exact comparison on doubles, with everything that implies: a value stored
     * as 0.1 + 0.2 will not equal 0.3. Callers filtering on money should hold it
     * in whole cents, as they should anyway.
     *
     * @throws CorruptSegmentException
     */
    public function equals(float $value): Bitset
    {
        return $this->range($value, $value);
    }

    /**
     * Documents whose value falls in a range.
     *
     * Either bound may be null for an open end, so one method covers `>`, `>=`,
     * `<`, `<=` and `between`.
     *
     * ── Why this loop is written the way it is ──────────────────────────────
     *
     * The first version of this method was pleasant to read — a generic scan
     * taking a closure, calling `$presence->has()` and `$result->set()` per
     * document — and it was only 1.5× faster than decoding every document's
     * JSON, which would not have justified the format at all. Four PHP calls
     * per document is 80 000 calls over a 20 000-document column, and that cost
     * swamped everything the layout had bought.
     *
     * So the inner loop has no calls in it. Bounds are local variables rather
     * than a closure; the presence byte is read once per eight documents rather
     * than once per document; and the result is accumulated a byte at a time
     * and appended, rather than set bit by bit through a method. The chunk size
     * is a multiple of eight so those byte groups never straddle a chunk.
     *
     * @throws CorruptSegmentException
     */
    public function range(
        ?float $min,
        ?float $max,
        bool $minInclusive = true,
        bool $maxInclusive = true,
    ): Bitset {
        $presence = $this->presence->bytes();
        $bits     = '';

        for ($start = 0; $start < $this->documentCount; $start += self::CHUNK) {
            $size   = min(self::CHUNK, $this->documentCount - $start);
            $values = unpack(
                'e' . $size,
                $this->segment->read($this->section, $this->valuesOffset + $start * 8, $size * 8)
            );

            $document = $start;
            $end      = $start + $size;

            while ($document < $end) {
                $presenceByte = ord($presence[$document >> 3]);
                $accumulated  = 0;

                for ($bit = 0; $bit < 8 && $document < $end; $bit++, $document++) {
                    if ((($presenceByte >> $bit) & 1) === 0) {
                        continue;
                    }

                    $value = $values[$document - $start + 1];

                    if ($min !== null && ($minInclusive ? $value < $min : $value <= $min)) {
                        continue;
                    }

                    if ($max !== null && ($maxInclusive ? $value > $max : $value >= $max)) {
                        continue;
                    }

                    $accumulated |= 1 << $bit;
                }

                $bits .= chr($accumulated);
            }
        }

        return Bitset::fromBytes($bits, $this->documentCount);
    }

    /**
     * Minimum, maximum, sum and mean over a set of documents.
     *
     * What a stats facet needs — the price slider under a product listing —
     * computed in one pass over the documents that matched, skipping the rest.
     *
     * @return array{count: int, min: float|null, max: float|null, sum: float, avg: float|null}
     * @throws CorruptSegmentException
     */
    public function stats(?Bitset $documents = null): array
    {
        $presence = $this->presence->bytes();
        $selected = $documents?->bytes();

        $count = 0;
        $min   = null;
        $max   = null;
        $sum   = 0.0;

        for ($start = 0; $start < $this->documentCount; $start += self::CHUNK) {
            $size   = min(self::CHUNK, $this->documentCount - $start);
            $values = unpack(
                'e' . $size,
                $this->segment->read($this->section, $this->valuesOffset + $start * 8, $size * 8)
            );

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

                $value = $values[$i + 1];

                $count++;
                $sum += $value;

                if ($min === null || $value < $min) {
                    $min = $value;
                }

                if ($max === null || $value > $max) {
                    $max = $value;
                }
            }
        }

        return [
            'count' => $count,
            'min'   => $min,
            'max'   => $max,
            'sum'   => $sum,
            'avg'   => $count > 0 ? $sum / $count : null,
        ];
    }
}
