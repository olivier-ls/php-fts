<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\LockManager;
use PHPUnit\Framework\Attributes\Test;
use Ols\PhpFts\Exception\LockException;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LockManagerTest extends TestCase
{
    private string $tempDir;

    private const LOCK_DIR = '.lock';
    private const PID_FILE = '.lock/pid';

    protected function setUp(): void
    {
        // Répertoire de travail temporaire isolé par test
        $this->tempDir = sys_get_temp_dir() . '/lock_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Nettoyage complet du répertoire temporaire
        $this->removeDir($this->tempDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function lockDir(): string
    {
        return $this->tempDir . '/' . self::LOCK_DIR;
    }

    private function pidFile(): string
    {
        return $this->tempDir . '/' . self::PID_FILE;
    }

    /**
     * Simule un lock posé par un autre processus en créant manuellement
     * le répertoire de lock et en y écrivant le PID donné.
     */
    private function simulateForeignLock(int $pid): void
    {
        mkdir($this->lockDir(), 0755);
        file_put_contents($this->pidFile(), $pid);
    }

    /**
     * A lock nobody can be asked about: the directory exists and there is no
     * pid file, which is what a writer killed between `mkdir()` and the write
     * that follows it leaves behind.
     *
     * Every age-rule test below uses this rather than a foreign pid, and that
     * is not a detail. With a pid, a host that has `posix_kill()` answers from
     * liveness and never consults the clock — so a test that wrote a *live* pid
     * and expected an age-based reclaim asserted the age rule on Windows and
     * asserted its opposite on Linux. Two of them did, and passed here and
     * failed there. With no pid the clock decides on every platform, which is
     * the rule these tests are about.
     */
    private function simulateAbandonedLock(): void
    {
        mkdir($this->lockDir(), 0755);
    }

    private function removeDir(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }

        rmdir($path);
    }

    // =========================================================================
    // acquire() — cas nominaux
    // =========================================================================

    #[Test]
    public function acquire_creates_lock_directory(): void
    {
        $lm = new LockManager($this->tempDir);

        $lm->acquire();
        $lm->release();

        // Le répertoire doit exister pendant le lock — on le vérifie juste après
        // acquire en inspectant (release l'a supprimé, donc on teste le cycle)
        $this->assertTrue(true); // Si on est ici, pas d'exception
    }

    #[Test]
    public function acquire_writes_pid_file_in_lock_directory(): void
    {
        $lm = new LockManager($this->tempDir);

        $lm->acquire();

        $this->assertFileExists($this->pidFile());
        $this->assertSame(getmypid(), (int) file_get_contents($this->pidFile()));

        $lm->release();
    }

    #[Test]
    public function acquire_lock_directory_exists_while_held(): void
    {
        $lm = new LockManager($this->tempDir);

        $lm->acquire();

        $this->assertDirectoryExists($this->lockDir());

        $lm->release();
    }

    // =========================================================================
    // acquire() — timeout
    // =========================================================================

    #[Test]
    public function acquire_throws_when_lock_already_held_and_timeout_exceeded(): void
    {
        // On simule un lock posé par notre propre processus (non stale)
        $this->simulateForeignLock(getmypid());

        $lm = new LockManager($this->tempDir, timeoutSeconds: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unable to acquire lock/i');

        $lm->acquire();
    }

    #[Test]
    public function acquire_exception_message_contains_lock_path(): void
    {
        $this->simulateForeignLock(getmypid());

        $lm = new LockManager($this->tempDir, timeoutSeconds: 0);

        try {
            $lm->acquire();
            $this->fail('RuntimeException expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($this->tempDir, $e->getMessage());
        }
    }

    // =========================================================================
    // acquire() — détection de lock orphelin (Linux uniquement)
    //
    // Gated on the *function* rather than on the extension, because that is
    // what LockManager asks about. Shared hosting routinely ships ext-posix and
    // puts `posix_kill` in `disable_functions`, and on such a host these two
    // tests were neither skipped nor able to pass: the extension was there, so
    // PHPUnit ran them, and the function was not, so the clock decided and a
    // freshly abandoned lock was not reclaimed. `RequiresFunction` reads the
    // same `function_exists()` the code does.
    // =========================================================================

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    #[RequiresFunction('posix_kill')]
    public function acquire_detects_stale_lock_and_recovers(): void
    {
        // PID 999999 : très vraisemblablement inexistant sur Linux
        $this->simulateForeignLock(999999);

        $lm = new LockManager($this->tempDir, timeoutSeconds: 2);
        $lm->acquire(); // ne doit pas lever d'exception

        $this->assertDirectoryExists($this->lockDir());
        $this->assertSame(getmypid(), (int) file_get_contents($this->pidFile()));

        $lm->release();
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    #[RequiresFunction('posix_kill')]
    public function acquire_after_stale_recovery_writes_correct_pid(): void
    {
        $this->simulateForeignLock(999999);

        $lm = new LockManager($this->tempDir, timeoutSeconds: 2);
        $lm->acquire();

        $this->assertSame(getmypid(), (int) file_get_contents($this->pidFile()));

        $lm->release();
    }

    /**
     * The age-based path is the only recovery available on Windows and on any
     * host without ext-posix, and it is the only one available anywhere for a
     * lock whose holder never got as far as naming itself.
     */
    #[Test]
    public function acquire_reclaims_a_lock_older_than_the_maximum_age(): void
    {
        $this->simulateAbandonedLock();

        // The directory's timestamp, because there is no pid file to carry one.
        // A holder that is alive keeps the *pid file* moving — see heartbeat()
        // — and one that never wrote it has nothing to keep moving, which is
        // the case this covers.
        touch($this->lockDir(), time() - 600);
        clearstatcache();

        $lm = new LockManager($this->tempDir, timeoutSeconds: 2, maxAgeSeconds: 300);
        $lm->acquire();

        $this->assertSame(getmypid(), (int) file_get_contents($this->pidFile()));

        $lm->release();
    }

    #[Test]
    public function a_lock_kept_warm_is_not_reclaimed_however_old_it_is(): void
    {
        // The other half, and the reason the age rule was dangerous on its own:
        // `optimize()` on a large index legitimately holds the write lock for
        // minutes, and a shared host is not a fast machine. Declaring that
        // writer dead put two processes in the critical section together, both
        // reading the same generation and both publishing `commit.N+1` — the
        // second rename overwriting the first, one commit gone in silence.
        $holder = new LockManager($this->tempDir, timeoutSeconds: 2, maxAgeSeconds: 300);
        $holder->acquire();

        touch($this->pidFile(), time() - 600);
        touch($this->lockDir(), time() - 600);
        clearstatcache();

        $holder->heartbeat();
        clearstatcache();

        $waiting = new LockManager($this->tempDir, timeoutSeconds: 1, maxAgeSeconds: 300);

        $this->expectException(LockException::class);

        try {
            $waiting->acquire();
        } finally {
            $holder->release();
        }
    }

    #[Test]
    public function acquire_does_not_reclaim_a_lock_within_the_maximum_age(): void
    {
        $this->simulateAbandonedLock();

        $lm = new LockManager($this->tempDir, timeoutSeconds: 1, maxAgeSeconds: 300);

        $this->expectException(LockException::class);
        $lm->acquire();
    }

    #[Test]
    public function maximum_age_can_be_disabled(): void
    {
        $this->simulateAbandonedLock();
        touch($this->lockDir(), time() - 100_000);
        clearstatcache(true, $this->lockDir());

        $lm = new LockManager($this->tempDir, timeoutSeconds: 1, maxAgeSeconds: 0);

        $this->expectException(LockException::class);
        $lm->acquire();
    }

    /**
     * A pid of 0 is not a process, and this used to read it as a licence to
     * steal — `isStale()` returned true on the spot.
     *
     * It is not a licence, and the reason is a race no test here can see. A
     * perfectly healthy writer is, for the few microseconds between its
     * `mkdir()` and the write that names it, exactly this lock: held, and with
     * no readable pid. Stealing from it puts two writers in the critical
     * section together, which is the failure the whole class exists to prevent.
     * So an unaskable holder is left to the clock, like any other.
     */
    #[Test]
    public function a_lock_naming_an_impossible_pid_is_not_stolen_while_fresh(): void
    {
        $this->simulateForeignLock(0);

        $lm = new LockManager($this->tempDir, timeoutSeconds: 1, maxAgeSeconds: 300);

        $this->expectException(LockException::class);
        $lm->acquire();
    }

    /** And the other half: the clock does eventually answer. */
    #[Test]
    public function a_lock_naming_an_impossible_pid_is_reclaimed_once_it_is_old(): void
    {
        $this->simulateForeignLock(0);

        touch($this->pidFile(), time() - 600);
        touch($this->lockDir(), time() - 600);
        clearstatcache();

        $lm = new LockManager($this->tempDir, timeoutSeconds: 2, maxAgeSeconds: 300);
        $lm->acquire();

        $this->assertSame(getmypid(), (int) file_get_contents($this->pidFile()));

        $lm->release();
    }

    // =========================================================================
    // release()
    // =========================================================================

    #[Test]
    public function release_removes_lock_directory(): void
    {
        $lm = new LockManager($this->tempDir);
        $lm->acquire();

        $lm->release();

        $this->assertDirectoryDoesNotExist($this->lockDir());
    }

    #[Test]
    public function release_removes_pid_file(): void
    {
        $lm = new LockManager($this->tempDir);
        $lm->acquire();

        $lm->release();

        $this->assertFileDoesNotExist($this->pidFile());
    }

    #[Test]
    public function release_is_idempotent_when_not_held(): void
    {
        $lm = new LockManager($this->tempDir);

        // Jamais acquis — ne doit pas exploser
        $lm->release();
        $lm->release();

        $this->assertTrue(true);
    }

    #[Test]
    public function release_is_idempotent_after_normal_release(): void
    {
        $lm = new LockManager($this->tempDir);
        $lm->acquire();
        $lm->release();

        // Second appel — ne doit pas exploser
        $lm->release();

        $this->assertTrue(true);
    }

    #[Test]
    public function release_allows_another_acquire_after(): void
    {
        $lm = new LockManager($this->tempDir);

        $lm->acquire();
        $lm->release();
        $lm->acquire(); // doit réussir sans exception

        $this->assertDirectoryExists($this->lockDir());

        $lm->release();
    }

    // =========================================================================
    // withLock()
    // =========================================================================

    #[Test]
    public function with_lock_executes_the_callable(): void
    {
        $lm      = new LockManager($this->tempDir);
        $called  = false;

        $lm->withLock(function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    #[Test]
    public function with_lock_returns_callable_return_value(): void
    {
        $lm = new LockManager($this->tempDir);

        $result = $lm->withLock(fn() => 42);

        $this->assertSame(42, $result);
    }

    #[Test]
    public function with_lock_returns_null_when_callable_returns_null(): void
    {
        $lm = new LockManager($this->tempDir);

        $result = $lm->withLock(fn() => null);

        $this->assertNull($result);
    }

    #[Test]
    public function with_lock_holds_lock_during_callable_execution(): void
    {
        $lm              = new LockManager($this->tempDir);
        $lockExistsDuring = false;

        $lm->withLock(function () use (&$lockExistsDuring) {
            $lockExistsDuring = is_dir($this->lockDir());
        });

        $this->assertTrue($lockExistsDuring);
    }

    #[Test]
    public function with_lock_releases_lock_after_callable(): void
    {
        $lm = new LockManager($this->tempDir);

        $lm->withLock(fn() => null);

        $this->assertDirectoryDoesNotExist($this->lockDir());
    }

    #[Test]
    public function with_lock_releases_lock_even_when_callable_throws(): void
    {
        $lm = new LockManager($this->tempDir);

        try {
            $lm->withLock(function () {
                throw new \LogicException('Boom');
            });
        } catch (\LogicException) {
            // attendu
        }

        $this->assertDirectoryDoesNotExist($this->lockDir());
    }

    #[Test]
    public function with_lock_propagates_exception_from_callable(): void
    {
        $lm = new LockManager($this->tempDir);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Boom');

        $lm->withLock(function () {
            throw new \LogicException('Boom');
        });
    }

    #[Test]
    public function with_lock_can_be_called_sequentially(): void
    {
        $lm      = new LockManager($this->tempDir);
        $counter = 0;

        $lm->withLock(function () use (&$counter) { $counter++; });
        $lm->withLock(function () use (&$counter) { $counter++; });

        $this->assertSame(2, $counter);
    }

    #[Test]
    public function with_lock_throws_if_lock_cannot_be_acquired(): void
    {
        // Lock déjà posé manuellement par notre process (non stale)
        $this->simulateForeignLock(getmypid());

        $lm = new LockManager($this->tempDir, timeoutSeconds: 0);

        $this->expectException(RuntimeException::class);

        $lm->withLock(fn() => null);
    }
}
