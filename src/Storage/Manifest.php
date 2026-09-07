<?php

declare(strict_types=1);

namespace Ols\PhpFts\Storage;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\OpensIndexFile;

/**
 * The list of segments that make up an index at one point in time.
 *
 * An index directory holds immutable segment files and a numbered manifest:
 *
 *     search_data/
 *       commit.7        ← this file: which segments are live, and what is deleted
 *       commit.6        ← the previous one, kept until it is safe to remove
 *       seg_a1f.fts
 *       seg_c40.fts
 *
 * ── Why the number, and not a fixed name ────────────────────────────────────
 *
 * Publishing a new state means creating `commit.8` — a name that did not exist
 * a moment ago. Renaming onto a name nobody holds is atomic on POSIX *and* on
 * Windows, and sidesteps the one case where `rename()` misbehaves there: a
 * destination another process has open. A fixed `manifest.json` overwritten in
 * place would have needed exactly that.
 *
 * A reader takes the highest-numbered manifest it can read. So a commit becomes
 * visible the instant its file appears, and never half-visible: until the
 * rename, `commit.8` does not exist, and the segment it names is invisible no
 * matter how complete it is.
 *
 * ── Rollback ────────────────────────────────────────────────────────────────
 *
 * Readers walk generations downwards and stop at the first one whose manifest
 * parses *and* whose segments all open. A commit whose segment was cut short —
 * the machine lost power between writing the segment and fsyncing it — fails
 * its length check, so the reader falls back to the generation before it.
 *
 * That is what makes skipping fsync a safe choice rather than a gamble: the
 * worst case becomes losing the newest commit, never reading a torn segment as
 * though it were data.
 *
 * ── Deletions live in the manifest ──────────────────────────────────────────
 *
 * Segments cannot be modified, so a deleted document is recorded outside them,
 * as one bit per document. Those bitmaps are stored *inside* the manifest,
 * base64-encoded, rather than in files of their own.
 *
 * The cost is that a manifest grows with the index: 2.5 KB of bitmap for twenty
 * thousand documents, 3.4 KB once base64-encoded. The gain is that a commit is
 * exactly one file, so publishing a new state — new segment, new deletions, or
 * both — is a single atomic rename with nothing to coordinate. An index at rest
 * is three files, which was the point of the compound segment in the first
 * place.
 */
final class Manifest
{
    use OpensIndexFile;

    public const FORMAT = 1;

    private const PREFIX = 'commit.';

    /** How long a retired segment is kept before it may be deleted. */
    public const GRACE_SECONDS = 60;

    /**
     * @param array<int, array{name: string, documents: int, deleted: string}> $segments
     *        deleted holds the raw deletion bitmap, already decoded
     * @param array<int, array{name: string, at: int}> $retired segments a merge replaced
     * @param array<string, string> $fields field => type, frozen at the first commit
     */
    private function __construct(
        public readonly int $generation,
        public readonly array $segments,
        public readonly array $retired = [],
        public readonly int $createdAt = 0,
        public readonly array $fields = [],
    ) {
    }

    public static function initial(): self
    {
        return new self(0, [], [], time(), []);
    }

    /**
     * @param array<int, array{name: string, documents: int, deleted: string}> $segments
     * @param array<int, array{name: string, at: int}>                         $retired
     * @param array<string, string>|null                                        $fields null keeps the frozen schema
     */
    public function next(array $segments, array $retired = [], ?array $fields = null): self
    {
        return new self(
            $this->generation + 1,
            array_values($segments),
            array_values($retired),
            time(),
            $fields ?? $this->fields,
        );
    }

    public function documentCount(): int
    {
        $total = 0;

        foreach ($this->segments as $segment) {
            $total += $segment['documents'];
        }

        return $total;
    }

    /**
     * @return string[] segment names, in commit order
     */
    public function segmentNames(): array
    {
        return array_map(static fn(array $segment): string => $segment['name'], $this->segments);
    }

    public function path(string $directory): string
    {
        return rtrim($directory, '/\\') . '/' . self::PREFIX . $this->generation;
    }

    /**
     * Every generation present in a directory, highest first.
     *
     * @return int[]
     */
    public static function generations(string $directory): array
    {
        $found = [];

        foreach (glob(rtrim($directory, '/\\') . '/' . self::PREFIX . '*') ?: [] as $file) {
            $suffix = substr(basename($file), strlen(self::PREFIX));

            if ($suffix !== '' && ctype_digit($suffix)) {
                $found[] = (int) $suffix;
            }
        }

        rsort($found);

        return $found;
    }

    /**
     * Reads one generation's manifest.
     *
     * @throws CorruptSegmentException when the file is not a manifest this build understands
     * @throws StorageException        when it cannot be read at all
     */
    public static function read(string $directory, int $generation): self
    {
        $path = rtrim($directory, '/\\') . '/' . self::PREFIX . $generation;

        $reader = new self(0, []);
        $handle = $reader->openIndexFileForReading($path);
        $json   = stream_get_contents($handle);
        fclose($handle);

        if ($json === false || $json === '') {
            throw new CorruptSegmentException("Manifest $path is empty");
        }

        $data = json_decode($json, true);

        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new CorruptSegmentException("Manifest $path is not a format " . self::FORMAT . ' manifest');
        }

        if (($data['generation'] ?? null) !== $generation) {
            throw new CorruptSegmentException(
                "Manifest $path declares generation {$data['generation']} but is named $generation"
            );
        }

        $segments = [];

        foreach ($data['segments'] ?? [] as $segment) {
            if (!isset($segment['name'], $segment['documents'])) {
                throw new CorruptSegmentException("Manifest $path has a malformed segment entry");
            }

            $deleted = $segment['deleted'] ?? '';
            $deleted = $deleted === '' ? '' : (string) base64_decode((string) $deleted, true);

            $segments[] = [
                'name'      => (string) $segment['name'],
                'documents' => (int) $segment['documents'],
                'deleted'   => $deleted,
            ];
        }

        $retired = [];

        foreach ($data['retired'] ?? [] as $entry) {
            if (isset($entry['name'], $entry['at'])) {
                $retired[] = ['name' => (string) $entry['name'], 'at' => (int) $entry['at']];
            }
        }

        $fields = [];

        foreach ($data['fields'] ?? [] as $field => $type) {
            $fields[(string) $field] = (string) $type;
        }

        return new self($generation, $segments, $retired, (int) ($data['createdAt'] ?? 0), $fields);
    }

    /**
     * Publishes this manifest.
     *
     * Written under a temporary name and renamed into place. The destination is
     * a generation number that did not exist before, so the rename never lands
     * on a file anyone holds open — which is what makes it atomic everywhere,
     * Windows included.
     *
     * @throws StorageException
     */
    public function write(string $directory, bool $fsync = true): void
    {
        $path = $this->path($directory);

        if (file_exists($path)) {
            throw new StorageException("Refusing to overwrite an existing commit: $path");
        }

        $temporary = $path . '.tmp';

        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            throw new StorageException("Unable to write manifest: $temporary");
        }

        try {
            $json = (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (fwrite($handle, $json) !== strlen($json)) {
                throw new StorageException("Short write on manifest: $temporary");
            }

            fflush($handle);

            if ($fsync && !fsync($handle)) {
                throw new StorageException("Unable to fsync manifest: $temporary");
            }
        } finally {
            fclose($handle);
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new StorageException("Unable to publish commit: $path");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(): array
    {
        $segments = [];

        foreach ($this->segments as $segment) {
            $segments[] = [
                'name'      => $segment['name'],
                'documents' => $segment['documents'],
                'deleted'   => $segment['deleted'] === '' ? '' : base64_encode($segment['deleted']),
            ];
        }

        return [
            'format'     => self::FORMAT,
            'generation' => $this->generation,
            'createdAt'  => $this->createdAt,
            'segments'   => $segments,
            'retired'    => $this->retired,
            'fields'     => $this->fields,
        ];
    }
}
