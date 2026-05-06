<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * TombstoneStorage
 *
 * Single responsibility: keep track of which doc_ids are deleted.
 *
 * File format:
 *   [0-15]  header : magic "TOMB" + version 1 + 11 reserved bytes
 *   [16...] doc_id list : uint32 LE, 4 bytes each, append-only
 */
class TombstoneStorage
{
    private const MAGIC       = 'TOMB';
    private const VERSION     = 1;
    private const HEADER_SIZE = 16;
    private const ID_SIZE     = 4; // uint32

    /** @var resource|null */
    private $handle = null;

    /** @var array<int, true> — O(1) lookup */
    private array $deleted = [];

    /**
     * Opens the file. Creates it with the header if it does not exist.
     * Loads all existing doc_ids into memory.
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
            $this->loadAll();
        }
    }

    /**
     * Marks a doc_id as deleted.
     * Writes it to disk and updates the in-memory array.
     *
     * @throws RuntimeException
     */
    public function add(int $docId): void
    {
        $this->assertOpen();

        if (isset($this->deleted[$docId])) {
            return; // already marked, nothing to do
        }

        fseek($this->handle, 0, SEEK_END);
        fwrite($this->handle, pack('V', $docId));

        $this->deleted[$docId] = true;
    }

    /**
     * Returns true if the doc_id is deleted.
     * In-memory lookup only — no I/O.
     *
     * @throws RuntimeException
     */
    public function isDeleted(int $docId): bool
    {
        $this->assertOpen();

        return isset($this->deleted[$docId]);
    }

    /**
     * Returns all deleted doc_ids.
     * Useful for compaction.
     *
     * @return int[]
     * @throws RuntimeException
     */
    public function getAll(): array
    {
        $this->assertOpen();

        return array_keys($this->deleted);
    }

    /**
     * Returns the number of deleted doc_ids.
     *
     * @throws RuntimeException
     */
    public function count(): int
    {
        $this->assertOpen();

        return count($this->deleted);
    }

    /**
     * Properly closes the file.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
            $this->deleted = [];
        }
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

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
            throw new RuntimeException("Invalid magic number: expected 'TOMB', got '$magic'");
        }

        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported version: $version");
        }
    }

    /**
     * Loads all doc_ids from the file into memory.
     */
    private function loadAll(): void
    {
        fseek($this->handle, self::HEADER_SIZE);

        while (!feof($this->handle)) {
            $data = fread($this->handle, self::ID_SIZE);

            if ($data === false || strlen($data) < self::ID_SIZE) {
                break;
            }

            ['id' => $docId] = unpack('Vid', $data);
            $this->deleted[$docId] = true;
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
