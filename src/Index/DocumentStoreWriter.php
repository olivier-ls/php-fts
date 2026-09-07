<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Storage\SegmentWriter;

/**
 * Builds the document store one document at a time, without holding them.
 *
 *     $store = new DocumentStoreWriter();
 *     $store->add(['title' => 'Brown leather shoe'], 'sku-4471');
 *     $store->add(null, 'sku-4472');            // stored: nothing
 *     $store->writeTo($segment, 'docs');
 *
 * ── Why it spills instead of concatenating ──────────────────────────────────
 *
 * The store used to be encoded in one call, from the array of documents the
 * writer had been keeping since the first put(). That is three copies of the
 * catalogue in memory at the same moment: the documents as PHP arrays, the
 * projected copies `source()` produces, and the JSON. On a real catalogue the
 * arrays alone were 3.4 KB per document — a merge of 45 000 of them held
 * 150 MB before it had written a byte, on an operation nobody asked for.
 *
 * A document is encoded the moment it arrives instead, appended to a scratch
 * stream, and forgotten. Only its offset is kept: four bytes, which is what
 * the section's offset table needs anyway. The scratch stream is a
 * `php://temp`, so a small batch never touches the disk and a large one stops
 * being the process's problem.
 *
 * The offsets cannot simply be streamed along with the payloads, because the
 * format puts the whole table *before* them — that is what makes document N
 * one seek away instead of a scan. Hence the two-part write: the table, held
 * in memory at four bytes a document, then the payloads copied through in
 * blocks.
 */
final class DocumentStoreWriter
{
    /** How much of the scratch stream is kept in memory before it hits disk. */
    private const SPILL_THRESHOLD = 1 << 20;

    /** Copied through in blocks of this size, so neither end is ever whole. */
    private const COPY_BLOCK = 1 << 19;

    /** @var resource */
    private $payloads;

    /** Offsets, packed as they are produced: u32 per document. */
    private string $offsets = '';

    private int $count = 0;

    private int $written = 0;

    private bool $finished = false;

    /**
     * @throws StorageException
     */
    public function __construct()
    {
        $handle = fopen('php://temp/maxmemory:' . self::SPILL_THRESHOLD, 'w+b');

        if ($handle === false) {
            throw new StorageException('Could not open a scratch stream for the document store');
        }

        $this->payloads = $handle;
    }

    /**
     * One document, encoded and let go.
     *
     * @param array<string, mixed>|null $document what the schema decided to
     *        store, already projected. Null and the empty array are the same
     *        thing here — a document with nothing stored — and read back as
     *        null.
     * @param string $label the caller's id, used only to name a document that
     *        fails to encode
     *
     * @throws StorageException
     */
    public function add(?array $document, string $label = ''): void
    {
        if ($this->finished) {
            throw new StorageException('Document store is already finished');
        }

        $this->offsets .= pack('V', $this->written);
        $this->count++;

        if ($document === null) {
            return;
        }

        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            // Named, because the alternative is learning that one document out
            // of five thousand holds a byte that is not valid UTF-8.
            $named = $label === '' ? 'at position ' . ($this->count - 1) : "'$label'";

            throw new StorageException(
                "Document $named could not be encoded: " . json_last_error_msg()
            );
        }

        if (fwrite($this->payloads, $json) === false) {
            throw new StorageException('Could not buffer a document for the store');
        }

        $this->written += strlen($json);
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Writes the whole section: header, offset table, then the payloads.
     *
     * @throws StorageException
     */
    public function writeTo(SegmentWriter $segment, string $name): void
    {
        if ($this->finished) {
            throw new StorageException('Document store is already finished');
        }

        $this->finished = true;

        $segment->beginSection($name);

        $segment->write(
            DocumentStore::MAGIC
            . pack('C', DocumentStore::VERSION)
            . "\x00\x00\x00"
            . pack('V', $this->count)
            . $this->offsets
            // One offset more than there are documents, so the last one's
            // length is read the same way as every other's.
            . pack('V', $this->written)
        );

        $this->offsets = '';

        rewind($this->payloads);

        while (!feof($this->payloads)) {
            $block = fread($this->payloads, self::COPY_BLOCK);

            if ($block === false) {
                throw new StorageException('Could not read back the buffered documents');
            }

            $segment->write($block);
        }

        $segment->endSection();

        fclose($this->payloads);
    }
}
