<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * TrigramIndex
 *
 * Single responsibility: map a trigram to its position
 * in postings.bin [offset, capacity, count].
 *
 * Fixed-size file. A trigram's index is computed mathematically,
 * not searched — guaranteed O(1) access.
 *
 * Alphabet: a-z (26) + 0-9 (10) + # (1) = 37 characters
 * Possible trigrams: 37³ = 50 653
 * File size: 16 (header) + 50 653 × 16 = ~810KB, always fixed
 *
 * File format:
 *   [0-15]   header : magic "TRIG" + version 1 + 11 reserved bytes
 *   [16...]  fixed 16-byte entries:
 *              [0-7]  offset   : uint64 LE — position in postings.bin (0 = not allocated)
 *              [8-11] capacity : uint32 LE — number of allocated doc_ids
 *              [12-15] count   : uint32 LE — number of written doc_ids
 *
 * All reads come from memory.
 * Writes go to memory AND to disk.
 */
class TrigramIndex
{
    private const MAGIC         = 'TRIG';
    private const VERSION       = 1;
    private const HEADER_SIZE   = 16;
    private const ENTRY_SIZE    = 16;   // offset(8) + capacity(4) + count(4)
    private const ALPHABET      = 'abcdefghijklmnopqrstuvwxyz0123456789#';
    private const ALPHABET_SIZE = 37;
    private const TOTAL_ENTRIES = 50653; // 37³

    /** @var resource|null */
    private $handle = null;

    /**
     * In-memory array: index (int) => [offset, capacity, count]
     * @var array<int, array{offset: int, capacity: int, count: int}>
     */
    private array $entries = [];

    /**
     * Opens the file.
     * Creates it with 50 653 zero entries if it does not exist.
     * Loads everything into memory if it already exists.
     *
     * @throws RuntimeException
     */
    public function open(string $path): void
    {
        $isNew = !file_exists($path);

        $this->handle = fopen($path, $isNew ? 'w+b' : 'r+b');

        if ($this->handle === false) {
            throw new RuntimeException("Unable to open file: $path");
        }

        if ($isNew) {
            $this->writeHeader();
            $this->initEntries();
        } else {
            $this->validateHeader();
            $this->loadAll();
        }
    }

    /**
     * Returns [offset, capacity, count] for a trigram.
     * In-memory read only — no I/O.
     *
     * @return array{offset: int, capacity: int, count: int}
     * @throws RuntimeException
     */
    public function get(string $trigram): array
    {
        $this->assertOpen();
        $index = $this->trigramToIndex($trigram);

        return $this->entries[$index];
    }

    /**
     * Updates [offset, capacity, count] for a trigram.
     * Writes to memory AND to the exact position on disk.
     *
     * @throws RuntimeException
     */
    public function set(string $trigram, int $offset, int $capacity, int $count): void
    {
        $this->assertOpen();
        $index = $this->trigramToIndex($trigram);

        // In-memory update
        $this->entries[$index] = [
            'offset'   => $offset,
            'capacity' => $capacity,
            'count'    => $count,
        ];

        // Disk write at the exact position
        $position = self::HEADER_SIZE + $index * self::ENTRY_SIZE;
        fseek($this->handle, $position);

        // uint64 in little-endian: PHP has no portable pack('Q') on 32-bit
        // Split into two uint32
        $lo = $offset & 0xFFFFFFFF;
        $hi = ($offset >> 32) & 0xFFFFFFFF;
        fwrite($this->handle, pack('VVV', $lo, $hi, $capacity) . pack('V', $count));
    }

    /**
     * Properly closes the file and frees memory.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
            $this->entries = [];
        }
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Converts a trigram to a numeric index.
     * index = c1 × 37² + c2 × 37 + c3
     *
     * @throws RuntimeException
     */
    private function trigramToIndex(string $trigram): int
    {
        if (strlen($trigram) !== 3) {
            throw new RuntimeException("A trigram must be exactly 3 characters: '$trigram'");
        }

        $index = 0;

        for ($i = 0; $i < 3; $i++) {
            $pos = strpos(self::ALPHABET, $trigram[$i]);

            if ($pos === false) {
                throw new RuntimeException(
                    "Invalid character in trigram '$trigram': '{$trigram[$i]}'"
                );
            }

            $index = $index * self::ALPHABET_SIZE + $pos;
        }

        return $index;
    }

    private function writeHeader(): void
    {
        fseek($this->handle, 0);
        fwrite($this->handle, self::MAGIC);
        fwrite($this->handle, pack('C', self::VERSION));
        fwrite($this->handle, str_repeat("\x00", 11));
    }

    /**
     * Initialises all 50 653 entries to zero on disk
     * and in memory.
     */
    private function initEntries(): void
    {
        $zero = pack('VVVV', 0, 0, 0, 0); // 16 zero bytes

        fseek($this->handle, self::HEADER_SIZE);

        for ($i = 0; $i < self::TOTAL_ENTRIES; $i++) {
            fwrite($this->handle, $zero);
            $this->entries[$i] = ['offset' => 0, 'capacity' => 0, 'count' => 0];
        }
    }

    /**
     * Loads all entries from the file into memory.
     *
     * @throws RuntimeException
     */
    private function loadAll(): void
    {
        fseek($this->handle, self::HEADER_SIZE);

        for ($i = 0; $i < self::TOTAL_ENTRIES; $i++) {
            $data = fread($this->handle, self::ENTRY_SIZE);

            if ($data === false || strlen($data) < self::ENTRY_SIZE) {
                throw new RuntimeException("File truncated at entry $i");
            }

            ['lo' => $lo, 'hi' => $hi, 'capacity' => $capacity, 'count' => $count]
                = unpack('Vlo/Vhi/Vcapacity/Vcount', $data);

            $this->entries[$i] = [
                'offset'   => ($hi << 32) | $lo,
                'capacity' => $capacity,
                'count'    => $count,
            ];
        }
    }

    /**
     * @throws RuntimeException
     */
    private function validateHeader(): void
    {
        fseek($this->handle, 0);
        $header = fread($this->handle, self::HEADER_SIZE);

        if ($header === false || strlen($header) < self::HEADER_SIZE) {
            throw new RuntimeException('Unreadable header or file too short');
        }

        $magic   = substr($header, 0, 4);
        $version = ord($header[4]);

        if ($magic !== self::MAGIC) {
            throw new RuntimeException("Invalid magic number: expected 'TRIG', got '$magic'");
        }

        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported version: $version");
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertOpen(): void
    {
        if ($this->handle === null) {
            throw new RuntimeException("File is not open. Call open() first.");
        }
    }
}
