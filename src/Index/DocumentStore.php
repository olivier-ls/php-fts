<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Storage\SegmentReader;

/**
 * The documents themselves, concatenated in one section with an offset table.
 *
 * This is the only place a document exists in readable form, and the last thing
 * a search touches — after terms have narrowed the candidates, after columns
 * have filtered them and counted the facets, for the twenty documents actually
 * being returned.
 *
 * That ordering is the whole difference with 1.x. There, filtering *is* reading
 * documents: every candidate is fetched and `json_decode`d so that a field can
 * be inspected. Here the JSON is never read to answer a question about a field
 * — the columns answer those — so its cost is paid once per returned result
 * rather than once per candidate.
 *
 *   DSTO | version | reserved 3 | count u32
 *   offsets:  u32 × (count + 1)      ← where each document starts, and the end
 *   payloads: the documents, back to back
 *
 * The offsets are fixed-width, so document N is one seek away — the same rule
 * as everywhere else: variable-length where you scan, fixed-width where you
 * jump.
 *
 * This side only reads. {@see DocumentStoreWriter} produces the section, a
 * document at a time and without holding them.
 */
final class DocumentStore
{
    public const MAGIC   = 'DSTO';
    public const VERSION = 1;

    private const HEADER_SIZE = 12;

    private int $count;
    private string $offsets;
    private int $payloadsOffset;

    private function __construct(
        private readonly SegmentReader $segment,
        private readonly string $section,
    ) {
        $header = $this->segment->read($this->section, 0, self::HEADER_SIZE);

        if (substr($header, 0, 4) !== self::MAGIC) {
            throw new CorruptSegmentException("Section '{$this->section}' does not hold a document store");
        }

        if (ord($header[4]) !== self::VERSION) {
            throw new CorruptSegmentException('Document store has an unsupported format version');
        }

        $this->count = unpack('V', substr($header, 8, 4))[1];

        // The offset table is read once and kept as a plain string, bisected
        // with unpack rather than expanded into an array of integers.
        $this->offsets        = $this->segment->read($this->section, self::HEADER_SIZE, ($this->count + 1) * 4);
        $this->payloadsOffset = self::HEADER_SIZE + ($this->count + 1) * 4;
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
        return $this->count;
    }

    /**
     * @return array<string, mixed>|null
     * @throws CorruptSegmentException
     */
    public function get(int $document): ?array
    {
        if ($document < 0 || $document >= $this->count) {
            return null;
        }

        $start = unpack('V', substr($this->offsets, $document * 4, 4))[1];
        $end   = unpack('V', substr($this->offsets, ($document + 1) * 4, 4))[1];

        if ($end <= $start) {
            return null;
        }

        $json = $this->segment->read($this->section, $this->payloadsOffset + $start, $end - $start);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new CorruptSegmentException("Document $document in '{$this->section}' is not readable JSON");
        }

        return $decoded;
    }

}
