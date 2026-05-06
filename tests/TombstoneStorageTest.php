<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\TombstoneStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TombstoneStorageTest extends TestCase
{
    /** @var string[] — chemins des fichiers temporaires à supprimer après chaque test */
    private array $tempFiles = [];

    private TombstoneStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new TombstoneStorage();
    }

    protected function tearDown(): void
    {
        // Fermeture propre si le storage est encore ouvert
        try {
            $this->storage->close();
        } catch (\Throwable) {
            // Déjà fermé ou jamais ouvert — on ignore
        }

        // Nettoyage des fichiers temporaires
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Crée un chemin de fichier temporaire vierge (non existant).
     * Le fichier sera supprimé dans tearDown().
     */
    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tomb_test_');
        unlink($path); // on veut un chemin libre, pas un fichier existant
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Crée un fichier tombstone valide pré-rempli avec les doc_ids fournis.
     * Simule ce que ferait open() + add() + close() en boîte noire.
     */
    private function createValidFile(array $docIds = []): string
    {
        $path = $this->tempPath();

        $s = new TombstoneStorage();
        $s->open($path);
        foreach ($docIds as $id) {
            $s->add($id);
        }
        $s->close();

        return $path;
    }

    /**
     * Écrit un fichier binaire brut avec un header personnalisé.
     * Utile pour les tests de validation du header.
     */
    private function createRawFile(string $magic, int $version, array $docIds = []): string
    {
        $path = $this->tempPath();

        $handle = fopen($path, 'w+b');
        fwrite($handle, $magic);                    // 4 bytes magic
        fwrite($handle, pack('C', $version));        // 1 byte version
        fwrite($handle, str_repeat("\x00", 11));    // 11 reserved bytes

        foreach ($docIds as $id) {
            fwrite($handle, pack('V', $id));
        }

        fclose($handle);

        return $path;
    }

    // =========================================================================
    // open() — création d'un nouveau fichier
    // =========================================================================

    #[Test]
    public function open_creates_file_if_it_does_not_exist(): void
    {
        $path = $this->tempPath();

        $this->storage->open($path);

        $this->assertFileExists($path);
    }

    #[Test]
    public function open_new_file_has_correct_header_size(): void
    {
        $path = $this->tempPath();

        $this->storage->open($path);
        $this->storage->close();

        // Header seul = 16 bytes (4 magic + 1 version + 11 réservés)
        $this->assertSame(16, filesize($path));
    }

    #[Test]
    public function open_new_file_writes_correct_magic_and_version(): void
    {
        $path = $this->tempPath();

        $this->storage->open($path);
        $this->storage->close();

        $handle = fopen($path, 'rb');
        $header = fread($handle, 16);
        fclose($handle);

        $this->assertSame('TOMB', substr($header, 0, 4));
        $this->assertSame(1, ord($header[4]));
    }

    #[Test]
    public function open_new_file_starts_with_zero_deleted_entries(): void
    {
        $path = $this->tempPath();

        $this->storage->open($path);

        $this->assertSame(0, $this->storage->count());
    }

    // =========================================================================
    // open() — ouverture d'un fichier existant
    // =========================================================================

    #[Test]
    public function open_existing_file_loads_previously_saved_doc_ids(): void
    {
        $path = $this->createValidFile([1, 2, 3]);

        $this->storage->open($path);

        $this->assertTrue($this->storage->isDeleted(1));
        $this->assertTrue($this->storage->isDeleted(2));
        $this->assertTrue($this->storage->isDeleted(3));
    }

    #[Test]
    public function open_existing_file_reports_correct_count(): void
    {
        $path = $this->createValidFile([10, 20, 30]);

        $this->storage->open($path);

        $this->assertSame(3, $this->storage->count());
    }

    #[Test]
    public function open_existing_empty_file_loads_no_doc_ids(): void
    {
        $path = $this->createValidFile([]);

        $this->storage->open($path);

        $this->assertSame(0, $this->storage->count());
    }

    // =========================================================================
    // open() — validation du header
    // =========================================================================

    #[Test]
    public function open_throws_on_invalid_magic(): void
    {
        $path = $this->createRawFile('NOPE', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/magic/i");

        $this->storage->open($path);
    }

    #[Test]
    public function open_throws_on_unsupported_version(): void
    {
        $path = $this->createRawFile('TOMB', 99);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/version/i");

        $this->storage->open($path);
    }

    #[Test]
    public function open_throws_when_file_is_too_short_for_header(): void
    {
        $path = $this->tempPath();

        // Fichier trop court (seulement 4 bytes)
        file_put_contents($path, 'TOMB');

        $this->expectException(RuntimeException::class);

        $this->storage->open($path);
    }

    #[Test]
    public function open_throws_when_path_is_not_writable(): void
    {
        // Répertoire inexistant → fopen échouera
        $path = sys_get_temp_dir() . '/nonexistent_dir_' . uniqid() . '/tomb.bin';

        $this->expectException(RuntimeException::class);

        $this->storage->open($path);
    }

    // =========================================================================
    // add()
    // =========================================================================

    #[Test]
    public function add_marks_doc_id_as_deleted(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->add(42);

        $this->assertTrue($this->storage->isDeleted(42));
    }

    #[Test]
    public function add_increments_count(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->add(1);
        $this->storage->add(2);

        $this->assertSame(2, $this->storage->count());
    }

    #[Test]
    public function add_is_idempotent_for_same_doc_id(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->add(7);
        $this->storage->add(7);
        $this->storage->add(7);

        $this->assertSame(1, $this->storage->count());
    }

    #[Test]
    public function add_persists_data_after_close_and_reopen(): void
    {
        $path = $this->tempPath();

        $this->storage->open($path);
        $this->storage->add(99);
        $this->storage->add(100);
        $this->storage->close();

        $s2 = new TombstoneStorage();
        $s2->open($path);

        $this->assertTrue($s2->isDeleted(99));
        $this->assertTrue($s2->isDeleted(100));
        $this->assertSame(2, $s2->count());

        $s2->close();
    }

    #[Test]
    public function add_writes_doc_id_at_correct_file_offset(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->storage->add(305419896); // 0x12345678 en little-endian = \x78\x56\x34\x12
        $this->storage->close();

        $raw = file_get_contents($path);

        // Le doc_id commence au byte 16 (après le header)
        $this->assertSame("\x78\x56\x34\x12", substr($raw, 16, 4));
    }

    #[Test]
    public function add_throws_when_storage_is_not_open(): void
    {
        $this->expectException(RuntimeException::class);

        $this->storage->add(1);
    }

    // =========================================================================
    // isDeleted()
    // =========================================================================

    #[Test]
    public function is_deleted_returns_false_for_unknown_doc_id(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->assertFalse($this->storage->isDeleted(999));
    }

    #[Test]
    public function is_deleted_returns_true_after_add(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->add(5);

        $this->assertTrue($this->storage->isDeleted(5));
    }

    #[Test]
    public function is_deleted_throws_when_storage_is_not_open(): void
    {
        $this->expectException(RuntimeException::class);

        $this->storage->isDeleted(1);
    }

    // =========================================================================
    // getAll()
    // =========================================================================

    #[Test]
    public function get_all_returns_empty_array_when_no_deletions(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->assertSame([], $this->storage->getAll());
    }

    #[Test]
    public function get_all_returns_all_added_doc_ids(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->add(1);
        $this->storage->add(2);
        $this->storage->add(3);

        $result = $this->storage->getAll();
        sort($result);

        $this->assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function get_all_does_not_contain_duplicates(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->add(42);
        $this->storage->add(42);

        $this->assertSame([42], $this->storage->getAll());
    }

    #[Test]
    public function get_all_throws_when_storage_is_not_open(): void
    {
        $this->expectException(RuntimeException::class);

        $this->storage->getAll();
    }

    // =========================================================================
    // count()
    // =========================================================================

    #[Test]
    public function count_returns_zero_on_new_file(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->assertSame(0, $this->storage->count());
    }

    #[Test]
    public function count_returns_number_of_unique_deleted_doc_ids(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->add(10);
        $this->storage->add(20);
        $this->storage->add(10); // doublon

        $this->assertSame(2, $this->storage->count());
    }

    #[Test]
    public function count_throws_when_storage_is_not_open(): void
    {
        $this->expectException(RuntimeException::class);

        $this->storage->count();
    }

    // =========================================================================
    // close()
    // =========================================================================

    #[Test]
    public function close_resets_state_so_methods_throw_afterwards(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->storage->close();

        $this->expectException(RuntimeException::class);

        $this->storage->isDeleted(1);
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->close();
        $this->storage->close(); // second appel — ne doit pas exploser

        $this->assertTrue(true); // on vérifie juste qu'aucune exception n'a été levée
    }

    #[Test]
    public function close_on_never_opened_storage_does_not_throw(): void
    {
        $fresh = new TombstoneStorage();
        $fresh->close(); // jamais ouvert

        $this->assertTrue(true);
    }

    // =========================================================================
    // Intégration — cycle complet
    // =========================================================================

    #[Test]
    public function full_cycle_add_close_reopen_add_more(): void
    {
        $path = $this->tempPath();

        // Session 1 : on supprime 1 et 2
        $this->storage->open($path);
        $this->storage->add(1);
        $this->storage->add(2);
        $this->storage->close();

        // Session 2 : on ajoute 3 et vérifie que 1, 2 sont toujours là
        $s2 = new TombstoneStorage();
        $s2->open($path);
        $s2->add(3);

        $this->assertTrue($s2->isDeleted(1));
        $this->assertTrue($s2->isDeleted(2));
        $this->assertTrue($s2->isDeleted(3));
        $this->assertFalse($s2->isDeleted(4));
        $this->assertSame(3, $s2->count());

        $s2->close();

        // Vérifie la taille du fichier : 16 (header) + 3 × 4 (doc_ids) = 28 bytes
        $this->assertSame(28, filesize($path));
    }
}
