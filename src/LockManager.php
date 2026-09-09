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
                // Host and PID, in that order, for orphaned lock detection.
                // See isStale() for why the host has to be there.
                file_put_contents($this->pidFile, self::host() . "\n" . getmypid());
                $this->held = true;
                return;
            }

            if ($this->isStale()) {
                $this->steal();
            }

            // Checked after the steal and not instead of it. The old shape
            // looped straight back to mkdir on `continue`, which meant a lock
            // that kept looking stale — a steal losing its race, over and over
            // — could spin here with no deadline ever consulted.
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
     * Says the holder is still alive, for the benefit of a host that cannot be
     * asked directly.
     *
     * Where `posix_kill()` is available none of this matters — a live process
     * is visibly live. Where it is not, the only evidence a waiting process has
     * is the clock, and a long operation has to keep putting something on it.
     * Called between the units of work that take a while: each segment an
     * import writes, each merge a maintenance pass performs.
     *
     * Cheap enough not to think about — one `touch()` — and silent when the
     * lock is not held, so a caller need not check.
     */
    public function heartbeat(): void
    {
        if ($this->held) {
            @touch($this->pidFile);
        }
    }

    /**
     * Executes a callable under lock.
     * Guarantees release even if an exception is thrown.
     *
     * Anything the callable throws is propagated unchanged — the lock is
     * released on the way out, nothing is swallowed. Declared as \Throwable
     * because that is what actually leaves this method; annotating only
     * LockException would tell callers their own exceptions cannot escape,
     * which is untrue and would hide it from static analysis.
     *
     * @throws LockException if the lock cannot be acquired
     * @throws \Throwable    whatever the callable throws
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
     *
     * ── Age is the fallback, not the rule ──────────────────────────────────
     *
     * It used to be the rule: a lock older than the maximum age was abandoned
     * *whatever the PID said*. That is wrong in a way that costs a commit. An
     * `optimize()` on a large index legitimately holds this for minutes, and a
     * shared host is not a fast machine — so a perfectly healthy writer would
     * be declared dead, its lock taken, and two processes would then be inside
     * the critical section together. Both would read the same generation and
     * both would publish `commit.N+1`, the second `rename()` overwriting the
     * first: one commit gone, silently, with no error anywhere.
     *
     * So a living holder is never stale, however long it has been working, and
     * the clock only decides when the system cannot be asked — Windows, or a
     * host with `posix_kill()` in `disable_functions`, which shared hosting
     * frequently has. {@see heartbeat()} is what keeps *that* case honest.
     */
    private function isStale(): bool
    {
        $pid = $this->holder();

        // posix_kill() with signal 0 does not kill anything — it only reports
        // whether the process exists.
        //
        // ── Only ever asked about a process on this machine ───────────────
        //
        // A pid is meaningful on the host that issued it and nowhere else, and
        // this lock is designed for exactly the case where that matters: the
        // directory-and-mkdir scheme was chosen over flock() because the index
        // sits on NFS, and an index on NFS is an index several machines can
        // reach. Two frontends behind a load balancer share the directory and
        // number their processes independently.
        //
        // Asking the local kernel about a remote pid answers about whichever
        // unrelated local process happens to wear that number, and it answers
        // confidently. Both ways of being wrong are bad: a live remote writer
        // whose number matches nothing local is declared dead and has its lock
        // stolen, so two processes end up inside the critical section and one
        // commit is silently overwritten — which is precisely the failure the
        // age rule was corrected to avoid. The other way round, a dead remote
        // writer whose number matches something local is believed alive
        // forever, and every writer times out.
        //
        // So the host is recorded beside the pid, and the kernel is asked only
        // when the lock is ours to ask about. A lock from another machine falls
        // through to the clock, which is what heartbeat() keeps honest, and
        // which is the same answer this gives on Windows and wherever
        // posix_kill() is disabled.
        if ($pid !== null
            && $this->holderIsLocal()
            && PHP_OS_FAMILY !== 'Windows'
            && function_exists('posix_kill')
        ) {
            return !posix_kill($pid, 0);
        }

        // No pid to ask about — a writer that died between mkdir and writing
        // one — or no way to ask, or no standing to. The clock is what is left.
        return $this->isExpired();
    }

    /**
     * The process that holds the lock, or null when there is no saying.
     *
     * The pid is the **last** line, which is the same answer for the two-line
     * file this writes and for the single-line one a previous version wrote —
     * and would stay the same answer if a third line were ever added above it.
     * Whether that pid may be asked about is a separate question, and
     * holderIsLocal() is the one that answers it.
     */
    private function holder(): ?int
    {
        $lines = $this->pidLines();
        $pid   = (int) (end($lines) ?: '');

        return $pid > 0 ? $pid : null;
    }

    /**
     * Whether the lock was taken by a process on this machine.
     *
     * False when the file names another host, and false when it names none —
     * a lock written before this existed says nothing about where it came
     * from, and guessing "here" is the guess that steals live locks.
     */
    private function holderIsLocal(): bool
    {
        $host = $this->pidLines()[0] ?? null;

        return $host !== null && $host === self::host();
    }

    /**
     * @return string[] the pid file's lines, or an empty list when unreadable
     */
    private function pidLines(): array
    {
        if (!is_file($this->pidFile)) {
            return [];
        }

        $contents = @file_get_contents($this->pidFile);

        if ($contents === false) {
            return [];
        }

        return explode("\n", trim($contents));
    }

    /**
     * This machine, as well as it can be named.
     *
     * `gethostname()` can fail, and on a container it can also be the
     * container id rather than the host — which is fine and even better here:
     * what has to match is the namespace the pid belongs to, and that is the
     * container, not the metal.
     */
    private static function host(): string
    {
        return gethostname() ?: 'unknown-host';
    }

    /**
     * True when the lock has gone untouched for longer than the maximum age.
     *
     * The pid file's timestamp rather than the directory's, because that is
     * what {@see heartbeat()} can refresh on every platform — `touch()` on a
     * directory is not portable.
     */
    private function isExpired(): bool
    {
        if ($this->maxAgeSeconds <= 0) {
            return false;
        }

        // filemtime() is cached per request, and this is asked repeatedly in a
        // retry loop about a file another process is refreshing.
        clearstatcache(true, $this->pidFile);
        clearstatcache(true, $this->lockDir);

        $touchedAt = @filemtime($this->pidFile);

        if ($touchedAt === false) {
            $touchedAt = @filemtime($this->lockDir);
        }

        if ($touchedAt === false) {
            return false;
        }

        return (time() - $touchedAt) > $this->maxAgeSeconds;
    }

    /**
     * Takes an abandoned lock, in a way two processes cannot both do.
     *
     * The directory is renamed aside before being removed. Only one rename can
     * succeed, so a second process deciding the same lock is abandoned finds it
     * already gone and goes back to competing for it with `mkdir()` like anyone
     * else — rather than both believing they cleared it and one of them then
     * deleting the *new* holder's lock.
     */
    private function steal(): void
    {
        $aside = $this->lockDir . '.' . bin2hex(random_bytes(6)) . '.stale';

        if (!@rename($this->lockDir, $aside)) {
            return;
        }

        @unlink($aside . '/pid');
        @rmdir($aside);
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
