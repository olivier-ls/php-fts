<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * PostingsStorage
 *
 * Single responsibility: store and read doc_id lists
 * associated with each trigram.
 *
 * This component does not know about trigrams — it only works
 * with offsets, capacities and counts provided by the caller
 * (which comes from trigrams_index.bin).
 *
 * File format:
 *   [0-15]  header : magic "POST" + version 1 + 11 reserved bytes
 *   [16...] doc_id lists : uint32 LE, 4 bytes each, packed
 *
 * Reallocation:
 *   When count == capacity, the list is moved to the end of the file
 *   with a capacity × 1.5 (rounded up).
 *   The old space becomes a hole — cleaned up on compaction.
 */
class PostingsStorage
{
    private const MAGIC            = 'POST';
    private const VERSION          = 1;
    private const HEADER_SIZE      = 16;
    private const ID_SIZE          = 4;    // uint32
    private const GROWTH_FACTOR    = 1.5;
    private const INITIAL_CAPACITY = 4;    // initial capacity for a new trigram

    /** @var resource|null */
    private $handle = null;

    /**
     * Opens the file. Creates it with the header if it does not exist.
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
        } else {
            $this->validateHeader();
        }
    }

    /**
     * Reads and returns the doc_ids of a list.
     *
     * @return int[]
     * @throws RuntimeException
     */
    public function read(int $offset, int $count): array
    {
        $this->assertOpen();
        $this->assertOffset($offset);

        if ($count === 0) {
            return [];
        }

        fseek($this->handle, $offset);
        $data = fread($this->handle, $count * self::ID_SIZE);

        if ($data === false || strlen($data) < $count * self::ID_SIZE) {
            throw new RuntimeException("Corrupted data at offset $offset");
        }

        return array_values(unpack('V*', $data));
    }

    /**
     * Appends a doc_id to a trigram's list.
     *
     * If count < capacity → in-place write, offset unchanged.
     * If count == capacity → reallocation at the end, new offset returned.
     * If offset === 0 → first write for this trigram, initial allocation.
     *
     * Always returns the up-to-date state: ['offset', 'capacity', 'count']
     * The caller must update trigrams_index.bin if the offset has changed.
     *
     * @return array{offset: int, capacity: int, count: int}
     * @throws RuntimeException
     */
    public function append(int $docId, int $offset, int $capacity, int $count): array
    {
        $this->assertOpen();

        // First append for this trigram — no list allocated yet
        if ($offset === 0) {
            return $this->allocate([$docId], self::INITIAL_CAPACITY);
        }

        $this->assertOffset($offset);

        // Slot available — in-place write
        if ($count < $capacity) {
            fseek($this->handle, $offset + $count * self::ID_SIZE);
            fwrite($this->handle, pack('V', $docId));

            return [
                'offset'   => $offset,
                'capacity' => $capacity,
                'count'    => $count + 1,
            ];
        }

        // No space left — read the existing list and reallocate
        $existing = $this->read($offset, $count);
        $existing[] = $docId;

        return $this->allocate($existing, (int) ceil($capacity * self::GROWTH_FACTOR));
    }

    /**
     * Properly closes the file.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Allocates a new space at the end of the file.
     * Writes the provided doc_ids, leaves the remaining capacity empty (zeros).
     *
     * @param int[] $docIds
     * @return array{offset: int, capacity: int, count: int}
     */
    private function allocate(array $docIds, int $capacity): array
    {
        fseek($this->handle, 0, SEEK_END);
        $offset = ftell($this->handle);
        $count  = count($docIds);

        // Write doc_ids
        foreach ($docIds as $id) {
            fwrite($this->handle, pack('V', $id));
        }

        // Padding for remaining capacity
        $padding = $capacity - $count;
        if ($padding > 0) {
            fwrite($this->handle, str_repeat("\x00", $padding * self::ID_SIZE));
        }

        return [
            'offset'   => $offset,
            'capacity' => $capacity,
            'count'    => $count,
        ];
    }

    private function writeHeader(): void
    {
        fseek($this->handle, 0);
        fwrite($this->handle, self::MAGIC);
        fwrite($this->handle, pack('C', self::VERSION));
        fwrite($this->handle, str_repeat("\x00", 11));
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
            throw new RuntimeException("Invalid magic number: expected 'POST', got '$magic'");
        }

        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported version: $version");
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertOffset(int $offset): void
    {
        if ($offset < self::HEADER_SIZE) {
            throw new RuntimeException("Invalid offset: $offset");
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
