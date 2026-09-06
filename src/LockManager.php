<?php

declare(strict_types=1);

namespace Ols\PhpFts;

use Ols\PhpFts\Exception\LockException;

/**
 * LockManager
 *
 * Single responsibility: manage an exclusive lock via a directory.
 *
 * Why a directory rather than flock()?
 * - mkdir() is atomic on all filesystems including NFS
 * - flock() is unreliable on NFS (shared hosting, Docker volumes)
 *
 * Behaviour:
 * - acquire() retries every 50ms until timeout
 * - A stale lock (process died without releasing) is detected via a PID file
 * - release() removes the directory
 */
class LockManager
{
    private const LOCK_DIR     = '.lock';
    private const PID_FILE     = '.lock/pid';
    private const RETRY_DELAY  = 50_000; // 50ms in microseconds

    private string $lockDir;
    private string $pidFile;
    private bool   $held = false;

    public function __construct(
        private readonly string $directory,
        private readonly int    $timeoutSeconds = 5,
        private readonly int    $maxAgeSeconds  = 300
    ) {
        $this->lockDir = $directory . '/' . self::LOCK_DIR;
        $this->pidFile = $directory . '/' . self::PID_FILE;
    }

    /**
     * Acquires the exclusive lock.
     * Retries every 50ms until timeout.
     * Detects and cleans up orphaned locks (dead process).
     *
     * @throws LockException if the lock cannot be acquired within the timeout
     */
    public function acquire(): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            // Atomic acquisition attempt via mkdir
            if (@mkdir($this->lockDir, 0755)) {
                // Store our PID for orphaned lock detection
                file_put_contents($this->pidFile, getmypid());
                $this->held = true;
                return;
            }

            // Lock exists — is it stale?
            if ($this->isStale()) {
                $this->forceRelease();
                continue; // retry immediately
            }

            // Timeout exceeded
            if (microtime(true) >= $deadline) {
                throw new LockException(
                    "Unable to acquire lock after {$this->timeoutSeconds}s. " .
                    "Another process may be stuck in: {$this->lockDir}"
                );
            }

            usleep(self::RETRY_DELAY);
        }
    }

    /**
     * Releases the lock.
     * Does not throw if the lock is not held — idempotent operation.
     */
    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        $this->forceRelease();
        $this->held = false;
    }

    /**
     * Executes a callable under lock.
     * Guarantees release even if an exception is thrown.
     *
     * @throws LockException
     */
    public function withLock(callable $fn): mixed
    {
        $this->acquire();

        try {
            return $fn();
        } finally {
            $this->release();
        }
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Detects whether the lock is stale — the process that acquired it has died.
     */
    private function isStale(): bool
    {
        // A lock older than the maximum age is considered abandoned whatever the
        // PID says. This is the only recovery path on Windows, and the safety net
        // when posix_kill() is unavailable or the PID has been recycled.
        if ($this->isExpired()) {
            return true;
        }

        if (!file_exists($this->pidFile)) {
            return false;
        }

        $pid = (int) file_get_contents($this->pidFile);

        if ($pid <= 0) {
            return true;
        }

        // posix_kill() with signal 0 does not kill anything — it only reports
        // whether the process exists. ext-posix is not always installed, and is
        // frequently listed in disable_functions on shared hosting.
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill')) {
            return false;
        }

        return !posix_kill($pid, 0);
    }

    /**
     * True when the lock directory is older than the configured maximum age.
     */
    private function isExpired(): bool
    {
        if ($this->maxAgeSeconds <= 0) {
            return false;
        }

        $createdAt = @filemtime($this->lockDir);

        if ($createdAt === false) {
            return false;
        }

        return (time() - $createdAt) > $this->maxAgeSeconds;
    }

    /**
     * Removes the lock directory without any check.
     */
    private function forceRelease(): void
    {
        if (file_exists($this->pidFile)) {
            @unlink($this->pidFile);
        }

        if (is_dir($this->lockDir)) {
            @rmdir($this->lockDir);
        }
    }
}
