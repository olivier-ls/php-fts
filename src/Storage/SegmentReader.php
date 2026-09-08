<?php

declare(strict_types=1);

namespace Ols\PhpFts\Storage;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Exception\UnsupportedFormatException;
use Ols\PhpFts\OpensIndexFile;

/**
 * Reads one segment file.
 *
 * Opening costs two reads and one stat, whatever the size of the segment:
 * the trailer tells the reader where the directory is, and the directory maps
 * every section name to a byte range. Nothing else is loaded. Sections are
 * read on demand, which is what keeps a search from paying for an index it
 * does not touch.
 *
 *     $reader = SegmentReader::open('/path/seg_a1f.fts');
 *
 *     $reader->has('terms');            // is the section present
 *     $reader->length('terms');         // its size in bytes
 *     $reader->read('terms');           // the whole section
 *     $reader->read('postings', 4096, 128);   // a slice, one seek
 *
 * The file is opened read-only, so an index can be searched from a process
 * that has no write access to it at all.
 */
final class SegmentReader
{
    use OpensIndexFile;

    /** @var resource */
    private $handle;

    /** @var array<string, array{offset: int, length: int, crc: int}> */
    private array $directory = [];

    private int $fileLength = 0;

    private function __construct(private readonly string $path)
    {
    }

    /**
     * @throws StorageException        the file cannot be read at all
     * @throws CorruptSegmentException the file is not a complete segment
     */
    public static function open(string $path): self
    {
        $reader = new self($path);
        $reader->handle = $reader->openIndexFileForReading($path);

        try {
            $reader->load();
        } catch (\Throwable $e) {
            fclose($reader->handle);
            throw $e;
        }

        return $reader;
    }

    /**
     * @return string[] section names, in the order they were written
     */
    public function sections(): array
    {
        return array_keys($this->directory);
    }

    public function has(string $name): bool
    {
        return isset($this->directory[$name]);
    }

    /**
     * @throws StorageException
     */
    public function length(string $name): int
    {
        return $this->entry($name)['length'];
    }

    /**
     * Reads a section, or a slice of one.
     *
     * Offsets are relative to the start of the section, so callers work in
     * section-local coordinates and never need to know where the section sits
     * in the file.
     *
     * @param int      $offset byte offset within the section
     * @param int|null $length number of bytes; null reads to the end
     *
     * @throws StorageException
     * @throws CorruptSegmentException
     */
    public function read(string $name, int $offset = 0, ?int $length = null): string
    {
        $entry  = $this->entry($name);
        $length ??= $entry['length'] - $offset;

        if ($offset < 0 || $length < 0 || $offset + $length > $entry['length']) {
            throw new StorageException(
                "Read of $length bytes at $offset is outside section '$name' ({$entry['length']} bytes)"
            );
        }

        if ($length === 0) {
            return '';
        }

        return $this->readAt($entry['offset'] + $offset, $length, "section '$name'");
    }

    /**
     * Recomputes a section's checksum and compares it to the stored one.
     *
     * Not called on the read path: verifying costs a full read of the section,
     * and the trailer's length check already rejects the failure that actually
     * happens. This is for maintenance and for tests — a deliberate integrity
     * check, not a per-query one.
     *
     * @throws StorageException
     */
    public function verify(string $name): bool
    {
        $entry = $this->entry($name);

        return SegmentFormat::checksum($this->read($name)) === $entry['crc'];
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->directory = [];
    }

    // -------------------------------------------------------------------------

    /**
     * @throws CorruptSegmentException
     */
    private function load(): void
    {
        $size = $this->fileSize();

        if ($size < SegmentFormat::MIN_FILE_SIZE) {
            throw new CorruptSegmentException(
                "Segment {$this->path} is too short to be valid ($size bytes)"
            );
        }

        $this->readHeader();
        $trailer = $this->readTrailer($size);

        $this->fileLength = $trailer['fileLength'];
        $this->readDirectory($trailer['dirOffset'], $size - SegmentFormat::TRAILER_SIZE, $trailer['dirCrc']);
    }

    /**
     * @throws CorruptSegmentException
     */
    private function readHeader(): void
    {
        $header = $this->readAt(0, SegmentFormat::HEADER_SIZE, 'header');

        if (substr($header, 0, 4) !== SegmentFormat::MAGIC) {
            throw new CorruptSegmentException(
                "Segment {$this->path} does not start with the expected magic number"
            );
        }

        $version = unpack('v', substr($header, 4, 2))[1];

        if ($version !== SegmentFormat::VERSION) {
            // Not a CorruptSegmentException, and the distinction is the whole
            // point: that one means "fall back to the previous commit", which
            // for a version mismatch would try every generation, fail at all
            // of them, and open the index as empty. See the exception.
            throw UnsupportedFormatException::segment($this->path, $version, SegmentFormat::VERSION);
        }
    }

    /**
     * @return array{dirOffset: int, dirCrc: int, fileLength: int}
     * @throws CorruptSegmentException
     */
    private function readTrailer(int $size): array
    {
        $trailer = $this->readAt($size - SegmentFormat::TRAILER_SIZE, SegmentFormat::TRAILER_SIZE, 'trailer');

        if (substr($trailer, 0, 4) !== SegmentFormat::TRAILER_MAGIC) {
            throw new CorruptSegmentException(
                "Segment {$this->path} has no trailer; the write did not complete"
            );
        }

        // Trailer layout: magic 0-3 | dirOffset 4-11 | dirCrc 12-15 | fileLength 16-23
        $fileLength = SegmentFormat::unpackU64($trailer, 16);

        // The completeness check. It is O(1) regardless of segment size, and it
        // is what lets a reader reject this segment and fall back to the
        // previous commit rather than treating a half-written file as data.
        if ($fileLength !== $size) {
            throw new CorruptSegmentException(
                "Segment {$this->path} is incomplete: trailer declares $fileLength bytes, file holds $size"
            );
        }

        $dirOffset = SegmentFormat::unpackU64($trailer, 4);

        if ($dirOffset < SegmentFormat::HEADER_SIZE || $dirOffset > $size - SegmentFormat::TRAILER_SIZE) {
            throw new CorruptSegmentException(
                "Segment {$this->path} points its directory outside the file (offset $dirOffset)"
            );
        }

        return [
            'dirOffset'  => $dirOffset,
            'dirCrc'     => SegmentFormat::unpackU32($trailer, 12),
            'fileLength' => $fileLength,
        ];
    }

    /**
     * @throws CorruptSegmentException
     */
    private function readDirectory(int $offset, int $end, int $expectedCrc): void
    {
        $bytes = $this->readAt($offset, $end - $offset, 'directory');

        // The directory is the one part a reader has to trust before it can do
        // anything else, and it is small — so it is the one part checksummed on
        // every open. Section payloads carry their own checksums, verified only
        // when asked.
        if (SegmentFormat::checksum($bytes) !== $expectedCrc) {
            throw new CorruptSegmentException("Segment {$this->path} has a corrupt directory");
        }

        $count    = SegmentFormat::unpackU32($bytes);
        $position = 4;

        for ($i = 0; $i < $count; $i++) {
            if ($position >= strlen($bytes)) {
                throw new CorruptSegmentException(
                    "Segment {$this->path} declares $count sections but the directory ends after $i"
                );
            }

            $nameLength = ord($bytes[$position]);
            $position++;

            $name = substr($bytes, $position, $nameLength);
            $position += $nameLength;

            if (strlen($name) !== $nameLength) {
                throw new CorruptSegmentException("Segment {$this->path} has a truncated section name");
            }

            $entryOffset = SegmentFormat::unpackU64($bytes, $position);
            $entryLength = SegmentFormat::unpackU64($bytes, $position + 8);
            $entryCrc    = SegmentFormat::unpackU32($bytes, $position + 16);
            $position   += 20;

            if ($entryOffset < SegmentFormat::HEADER_SIZE || $entryOffset + $entryLength > $this->fileLength) {
                throw new CorruptSegmentException(
                    "Segment {$this->path} places section '$name' outside the file"
                );
            }

            $this->directory[$name] = [
                'offset' => $entryOffset,
                'length' => $entryLength,
                'crc'    => $entryCrc,
            ];
        }
    }

    /**
     * @return array{offset: int, length: int, crc: int}
     * @throws StorageException
     */
    private function entry(string $name): array
    {
        if (!isset($this->directory[$name])) {
            throw new StorageException(
                "Segment {$this->path} has no section '$name'; it holds: " . implode(', ', $this->sections())
            );
        }

        return $this->directory[$name];
    }

    /**
     * @throws CorruptSegmentException
     */
    private function readAt(int $offset, int $length, string $what): string
    {
        if (fseek($this->handle, $offset) !== 0) {
            throw new CorruptSegmentException("Cannot seek to $what in {$this->path} (offset $offset)");
        }

        $bytes = fread($this->handle, $length);

        if ($bytes === false || strlen($bytes) !== $length) {
            throw new CorruptSegmentException(
                "Short read for $what in {$this->path}: wanted $length bytes at $offset"
            );
        }

        return $bytes;
    }

    private function fileSize(): int
    {
        $stat = fstat($this->handle);

        if ($stat === false) {
            throw new StorageException("Unable to stat segment {$this->path}");
        }

        return $stat['size'];
    }
}
