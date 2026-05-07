<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * DocumentStorage
 *
 * Single responsibility: write a PHP array to a binary file
 * and read it back by its address.
 *
 * Header format (16 bytes):
 *   [0-3]   magic       : "DOCS"
 *   [4]     version     : 0x04
 *   [5-8]   count       : uint32 LE — number of written documents
 *   [9-12]  trigramSum  : uint32 LE — cumulative sum of trigramCounts (for BM25 avgdl)
 *   [13-15] reserved    : 3 bytes
 *
 * Record format:
 *   [0-3]  length        : uint32 LE — JSON size in bytes
 *   [4-7]  trigramCount  : uint32 LE — number of indexed trigrams for the document
 *   [8...] json          : UTF-8 string of `length` bytes
 */
class DocumentStorage
{
    private const MAGIC              = 'DOCS';
    private const VERSION            = 4;
    private const HEADER_SIZE        = 16;
    private const RECORD_HEADER_SIZE = 8;
    private const COUNT_OFFSET       = 5;
    private const TRIGRAM_SUM_OFFSET = 9;

    /** @var resource|null */
    private $handle = null;

    private int $count      = 0;
    private int $trigramSum = 0;

    /**
     * Opens the file. Creates it with the header if it does not exist.
     *
     * @throws RuntimeException
     */
    public function open(string $path): void
    {
        $this->handle = @fopen($path, 'r+b');
        $isNew = ($this->handle === false);

        if ($isNew) {
            $this->handle = fopen($path, 'w+b');
        }

        if ($this->handle === false) {
            throw new RuntimeException("Unable to open file: $path");
        }

        if ($isNew) {
            $this->count      = 0;
            $this->trigramSum = 0;
            $this->writeHeader();
        } else {
            $this->validateHeader();
            $this->loadHeaderStats();
        }
    }

    /**
     * Serializes the array to JSON and writes it at the end of the file.
     * Updates count and trigramSum in the header.
     * Returns the record offset (= future doc_id).
     *
     * @throws RuntimeException
     */
    public function write(array $document, int $trigramCount = 0): int
    {
        $this->assertOpen();

        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('JSON serialization failed: ' . json_last_error_msg());
        }

        $length = strlen($json);

        fseek($this->handle, 0, SEEK_END);
        $offset = ftell($this->handle);

        fwrite($this->handle, pack('VV', $length, $trigramCount));
        fwrite($this->handle, $json);

        $this->count++;
        $this->trigramSum += $trigramCount;
        $this->persistHeaderStats();

        return $offset;
    }

    /**
     * Reads and returns the document at the given offset.
     *
     * @return array{document: array, trigramCount: int}
     * @throws RuntimeException
     */
    public function read(int $offset): array
    {
        $this->assertOpen();

        if ($offset < self::HEADER_SIZE) {
            throw new RuntimeException("Invalid offset: $offset");
        }

        fseek($this->handle, $offset);

        $header = fread($this->handle, self::RECORD_HEADER_SIZE);

        if ($header === false || strlen($header) < self::RECORD_HEADER_SIZE) {
            throw new RuntimeException("Unable to read record header at offset $offset");
        }

        $unpacked     = unpack('Vlength/VtrigramCount', $header);
        $length       = $unpacked['length'];
        $trigramCount = $unpacked['trigramCount'];

        $json = fread($this->handle, $length);

        if ($json === false || strlen($json) < $length) {
            throw new RuntimeException("Corrupted data at offset $offset");
        }

        $document = json_decode($json, true);

        if ($document === null) {
            throw new RuntimeException("JSON decoding failed at offset $offset");
        }

        return [
            'document'     => $document,
            'trigramCount' => $trigramCount,
        ];
    }

    /**
     * Sequentially iterates over all records in the file.
     * Constant memory footprint — no intermediate array.
     *
     * @param callable(int, array, int): void $callback
     * @throws RuntimeException
     */
    public function iterate(callable $callback): void
    {
        $this->assertOpen();

        fseek($this->handle, self::HEADER_SIZE);

        while (true) {
            $offset = ftell($this->handle);
            $header = fread($this->handle, self::RECORD_HEADER_SIZE);

            if ($header === false || strlen($header) < self::RECORD_HEADER_SIZE) {
                break;
            }

            $unpacked     = unpack('Vlength/VtrigramCount', $header);
            $length       = $unpacked['length'];
            $trigramCount = $unpacked['trigramCount'];

            $json = fread($this->handle, $length);

            if ($json === false || strlen($json) < $length) {
                break;
            }

            $document = json_decode($json, true);

            if ($document !== null) {
                $callback($offset, $document, $trigramCount);
            }
        }
    }

    /**
     * Returns the number of written documents (including deleted ones).
     *
     * @throws RuntimeException
     */
    public function count(): int
    {
        $this->assertOpen();
        return $this->count;
    }

    /**
     * Returns the average document length in trigrams.
     * Used by BM25 for length normalization.
     *
     * @throws RuntimeException
     */
    public function avgTrigramCount(): float
    {
        $this->assertOpen();

        if ($this->count === 0) {
            return 0.0;
        }

        return $this->trigramSum / $this->count;
    }

    /**
     * Properly closes the file.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle     = null;
            $this->count      = 0;
            $this->trigramSum = 0;
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
        fwrite($this->handle, pack('VV', $this->count, $this->trigramSum));
        fwrite($this->handle, str_repeat("\x00", 3));
    }

    private function persistHeaderStats(): void
    {
        fseek($this->handle, self::COUNT_OFFSET);
        fwrite($this->handle, pack('VV', $this->count, $this->trigramSum));
    }

    private function loadHeaderStats(): void
    {
        fseek($this->handle, self::COUNT_OFFSET);
        $data = fread($this->handle, 8);

        if ($data === false || strlen($data) < 8) {
            throw new RuntimeException('Unable to read stats from header');
        }

        $unpacked         = unpack('Vcount/VtrigramSum', $data);
        $this->count      = $unpacked['count'];
        $this->trigramSum = $unpacked['trigramSum'];
    }

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
            throw new RuntimeException("Invalid magic number: expected 'DOCS', got '$magic'");
        }

        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported version: $version");
        }
    }

    private function assertOpen(): void
    {
        if ($this->handle === null) {
            throw new RuntimeException("File is not open. Call open() first.");
        }
    }
}
