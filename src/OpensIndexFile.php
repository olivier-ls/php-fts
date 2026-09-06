<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\StorageException;

/**
 * Symlink-safe opening of an index file.
 *
 * Index files live in a directory the application controls. If that directory
 * is writable by anyone else (shared hosting, a world-writable temp path), an
 * attacker can pre-place a symbolic link at a predictable file name so that the
 * engine creates or truncates a file of their choosing.
 *
 * Two defences, because the two cases are different:
 *
 *   - Creating a file uses mode 'x+b' (O_CREAT | O_EXCL). The kernel refuses to
 *     follow a symlink under O_EXCL, dangling or not, and the check-and-create
 *     is a single atomic syscall — no TOCTOU window.
 *
 *   - Opening an existing file cannot be made atomic from PHP, so the handle is
 *     verified after the fact: the inode reached through the descriptor must be
 *     the inode the path itself refers to. A symlink fails that comparison.
 */
trait OpensIndexFile
{
    /**
     * @return array{0: resource, 1: bool} the handle, and whether it was just created
     * @throws StorageException
     */
    private function openIndexFile(string $path): array
    {
        // is_link() and lstat() below populate PHP's stat cache for this path.
        // The file is about to be written through the returned handle, so the
        // cached size would go stale and any later filesize() on the same path
        // — by this library or by the caller — would report the size as it was
        // at open() time. Cleared on every exit path.
        try {
            return $this->openIndexFileUnchecked($path);
        } finally {
            clearstatcache(true, $path);
        }
    }

    /**
     * @return array{0: resource, 1: bool}
     * @throws StorageException
     */
    private function openIndexFileUnchecked(string $path): array
    {
        if (is_link($path)) {
            throw new StorageException("Refusing to open a symbolic link: $path");
        }

        // Existing file.
        $handle = @fopen($path, 'r+b');

        if ($handle !== false) {
            $this->assertNotRedirected($handle, $path);

            return [$handle, false];
        }

        // Not there — create it exclusively. Fails on a pre-placed symlink.
        $handle = @fopen($path, 'x+b');

        if ($handle !== false) {
            return [$handle, true];
        }

        // Another process created it in between. Reopen, and re-verify.
        $handle = @fopen($path, 'r+b');

        if ($handle === false) {
            throw new StorageException("Unable to open file: $path");
        }

        $this->assertNotRedirected($handle, $path);

        return [$handle, false];
    }

    /**
     * Verifies that the open descriptor and the path designate the same inode.
     *
     * @param resource $handle
     * @throws StorageException
     */
    private function assertNotRedirected($handle, string $path): void
    {
        $fromHandle = @fstat($handle);
        $fromPath   = @lstat($path);

        if ($fromHandle === false || $fromPath === false) {
            return;
        }

        // Windows reports 0 for both device and inode — nothing to compare.
        if ($fromHandle['ino'] === 0 && $fromHandle['dev'] === 0) {
            return;
        }

        if ($fromHandle['ino'] !== $fromPath['ino'] || $fromHandle['dev'] !== $fromPath['dev']) {
            fclose($handle);

            throw new StorageException(
                "Refusing to open '$path': the path does not resolve to the file it names"
            );
        }
    }
}
