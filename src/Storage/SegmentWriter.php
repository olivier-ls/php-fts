<?php

declare(strict_types=1);

namespace Ols\PhpFts\Storage;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\OpensIndexFile;

/**
 * Writes one segment file, in a single forward pass.
 *
 * Usage is deliberately sequential, because the format is:
 *
 *     $writer = SegmentWriter::create('/path/seg_a1f.fts');
 *
 *     $writer->addSection('terms', $termBytes);       // small section, in one go
 *
 *     $writer->beginSection('docstore');              // large section, streamed
 *     foreach ($documents as $document) {
 *         $writer->write($encoded);
 *     }
 *     $writer->endSection();
 *
 *     $writer->commit();
 *
 * The file is built under a temporary name and renamed into place by commit(),
 * so a segment file that exists under its final name is always complete. That
 * is one guarantee more than the format strictly needs — the trailer's length
 * check would catch a truncated file anyway — but it makes garbage collection
 * trivial: anything still named `*.tmp` is debris from an interrupted write and
 * can be deleted without inspection.
 */
final class SegmentWriter
{
    use OpensIndexFile;

    private const TMP_SUFFIX = '.tmp';

    /** @var resource */
    private $handle;

    /** @var array<int, array{name: string, offset: int, length: int, crc: int}> */
    private array $sections = [];

    /** @var array<string, true> */
    private array $names = [];

    private ?string $openSection = null;
    private int $openSectionOffset = 0;
    private int $openSectionLength = 0;
    private ?\HashContext $openSectionCrc = null;

    private bool $committed = false;

    private function __construct(
        private readonly string $path,
        private readonly string $tmpPath,
    ) {
    }

    /**
     * @throws StorageException
     */
    public static function create(string $path): self
    {
        $writer = new self($path, $path . self::TMP_SUFFIX);

        // A leftover temp file is debris from an interrupted write. It is never
        // referenced by a manifest, so removing it cannot lose anything.
        if (is_file($writer->tmpPath)) {
            @unlink($writer->tmpPath);
        }

        [$handle, $isNew] = $writer->openIndexFile($writer->tmpPath);

        if (!$isNew) {
            fclose($handle);
            throw new StorageException("Segment already being written: {$writer->tmpPath}");
        }

        $writer->handle = $handle;
        $writer->writeHeader();

        return $writer;
    }

    /**
     * Writes a whole section at once. Convenience for sections small enough to
     * hold in memory — the term dictionary, the doc-values columns.
     *
     * @throws StorageException
     */
    public function addSection(string $name, string $bytes): void
    {
        $this->beginSection($name);
        $this->write($bytes);
        $this->endSection();
    }

    /**
     * Opens a section for streamed writing. Use this whenever the payload is
     * produced incrementally and should not be assembled in memory first —
     * the document store being the obvious case.
     *
     * @throws StorageException
     */
    public function beginSection(string $name): void
    {
        $this->assertWritable();

        if ($this->openSection !== null) {
            throw new StorageException(
                "Cannot start section '$name': section '{$this->openSection}' is still open"
            );
        }

        if ($name === '' || strlen($name) > SegmentFormat::MAX_NAME_LENGTH) {
            throw new StorageException(
                'Section name must be between 1 and ' . SegmentFormat::MAX_NAME_LENGTH . " bytes: '$name'"
            );
        }

        if (isset($this->names[$name])) {
            throw new StorageException("Duplicate section name: '$name'");
        }

        $this->openSection       = $name;
        $this->openSectionOffset = $this->tell();
        $this->openSectionLength = 0;
        $this->openSectionCrc    = SegmentFormat::checksumStart();
    }

    /**
     * @throws StorageException
     */
    public function write(string $bytes): void
    {
        if ($this->openSection === null) {
            throw new StorageException('Cannot write outside a section; call beginSection() first');
        }

        if ($bytes === '') {
            return;
        }

        $this->writeRaw($bytes);

        $this->openSectionLength += strlen($bytes);
        hash_update($this->openSectionCrc, $bytes);
    }

    /**
     * @throws StorageException
     */
    public function endSection(): void
    {
        if ($this->openSection === null) {
            throw new StorageException('No section is open');
        }

        $this->sections[] = [
            'name'   => $this->openSection,
            'offset' => $this->openSectionOffset,
            'length' => $this->openSectionLength,
            'crc'    => SegmentFormat::checksumFinish($this->openSectionCrc),
        ];

        $this->names[$this->openSection] = true;

        $this->openSection    = null;
        $this->openSectionCrc = null;
    }

    /**
     * Finishes the segment: writes the directory and the trailer, optionally
     * forces the bytes to disk, and renames the file into place.
     *
     * @param bool $fsync Whether to force the data onto the physical device
     *                    before the rename. Under `Durability::Safe` this is
     *                    what makes the segment survive a machine losing power.
     *                    Skipping it never risks corruption — an incomplete
     *                    segment is rejected by its length check and the reader
     *                    falls back to the previous commit — it only risks
     *                    losing the most recent commit.
     *
     * @throws StorageException
     */
    public function commit(bool $fsync = true): void
    {
        $this->assertWritable();

        if ($this->openSection !== null) {
            throw new StorageException("Section '{$this->openSection}' was never closed");
        }

        $directory = $this->buildDirectory();
        $dirOffset = $this->tell();

        $this->writeRaw($directory);

        // fileLength counts the trailer itself, so a reader can compare it to
        // the real file size without doing any arithmetic.
        $fileLength = $dirOffset + strlen($directory) + SegmentFormat::TRAILER_SIZE;

        $this->writeRaw($this->buildTrailer($dirOffset, SegmentFormat::checksum($directory), $fileLength));

        if (fflush($this->handle) === false) {
            throw new StorageException("Unable to flush segment: {$this->tmpPath}");
        }

        if ($fsync && !fsync($this->handle)) {
            throw new StorageException("Unable to fsync segment: {$this->tmpPath}");
        }

        fclose($this->handle);
        $this->committed = true;

        if (!@rename($this->tmpPath, $this->path)) {
            @unlink($this->tmpPath);
            throw new StorageException("Unable to move segment into place: {$this->path}");
        }
    }

    /**
     * Abandons the segment and removes the partial file.
     *
     * Safe to call at any point, including from a `finally`: an uncommitted
     * segment has never been named in a manifest, so nothing can be referring
     * to it.
     */
    public function discard(): void
    {
        if ($this->committed) {
            return;
        }

        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        @unlink($this->tmpPath);
        $this->committed = true;
    }

    // -------------------------------------------------------------------------

    private function writeHeader(): void
    {
        $this->writeRaw(
            SegmentFormat::MAGIC
            . pack('v', SegmentFormat::VERSION)
            . str_repeat("\x00", SegmentFormat::HEADER_SIZE - 6)
        );
    }

    private function buildDirectory(): string
    {
        $directory = SegmentFormat::packU32(count($this->sections));

        foreach ($this->sections as $section) {
            $directory .= chr(strlen($section['name']))
                . $section['name']
                . SegmentFormat::packU64($section['offset'])
                . SegmentFormat::packU64($section['length'])
                . SegmentFormat::packU32($section['crc']);
        }

        return $directory;
    }

    private function buildTrailer(int $dirOffset, int $dirCrc, int $fileLength): string
    {
        $trailer = SegmentFormat::TRAILER_MAGIC
            . SegmentFormat::packU64($dirOffset)
            . SegmentFormat::packU32($dirCrc)
            . SegmentFormat::packU64($fileLength)
            . pack('v', SegmentFormat::VERSION);

        return str_pad($trailer, SegmentFormat::TRAILER_SIZE, "\x00");
    }

    /**
     * @throws StorageException
     */
    private function writeRaw(string $bytes): void
    {
        $written = fwrite($this->handle, $bytes);

        // A short write is not an error PHP reports by itself — a full disk
        // returns the number of bytes it managed to take. Catching it here is
        // what stops a truncated section from being described as complete in
        // the directory.
        if ($written === false || $written !== strlen($bytes)) {
            throw new StorageException(
                "Short write on segment {$this->tmpPath}: expected " . strlen($bytes) . ", wrote " . var_export($written, true)
            );
        }
    }

    private function tell(): int
    {
        $position = ftell($this->handle);

        if ($position === false) {
            throw new StorageException("Unable to determine position in segment: {$this->tmpPath}");
        }

        return $position;
    }

    /**
     * @throws StorageException
     */
    private function assertWritable(): void
    {
        if ($this->committed) {
            throw new StorageException('Segment is already committed or discarded');
        }
    }
}
