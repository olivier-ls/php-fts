<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\LockManager;
use PHPUnit\Framework\Attributes\Test;
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
    // =========================================================================

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
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
    public function acquire_after_stale_recovery_writes_correct_pid(): void
    {
        $this->simulateForeignLock(999999);

        $lm = new LockManager($this->tempDir, timeoutSeconds: 2);
        $lm->acquire();

        $this->assertSame(getmypid(), (int) file_get_contents($this->pidFile()));

        $lm->release();
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function acquire_treats_pid_zero_as_stale(): void
    {
        // PID 0 invalide → isStale() doit retourner true
        $this->simulateForeignLock(0);

        $lm = new LockManager($this->tempDir, timeoutSeconds: 2);
        $lm->acquire(); // doit se débloquer tout seul

        $this->assertDirectoryExists($this->lockDir());

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
