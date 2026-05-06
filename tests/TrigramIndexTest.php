<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\TrigramIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TrigramIndexTest extends TestCase
{
    private TrigramIndex $index;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->index = new TrigramIndex();
    }

    protected function tearDown(): void
    {
        try { $this->index->close(); } catch (\Throwable) {}

        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) unlink($path);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'trig_test_');
        unlink($path);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function createRawFile(string $magic, int $version): string
    {
        $path = $this->tempPath();
        $handle = fopen($path, 'w+b');
        fwrite($handle, $magic);
        fwrite($handle, pack('C', $version));
        fwrite($handle, str_repeat("\x00", 11));
        fclose($handle);
        return $path;
    }

    private function zeroEntry(): array
    {
        return ['offset' => 0, 'capacity' => 0, 'count' => 0];
    }

    // =========================================================================
    // open() — nouveau fichier
    // =========================================================================

    #[Test]
    public function open_creates_file_with_correct_magic_and_version(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);
        $this->index->close();

        $raw = file_get_contents($path);
        $this->assertSame('TRIG', substr($raw, 0, 4));
        $this->assertSame(1, ord($raw[4]));
    }

    #[Test]
    public function open_new_file_has_correct_total_size(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);
        $this->index->close();

        // 16 (header) + 50 653 × 16 (entries) = 810 464 bytes
        $this->assertSame(16 + 50653 * 16, filesize($path));
    }

    #[Test]
    public function open_new_file_all_entries_are_zero(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        // On sonde quelques trigrams représentatifs
        $this->assertSame($this->zeroEntry(), $this->index->get('aaa'));
        $this->assertSame($this->zeroEntry(), $this->index->get('zzz'));
        $this->assertSame($this->zeroEntry(), $this->index->get('###'));
        $this->assertSame($this->zeroEntry(), $this->index->get('#a#'));
    }

    // =========================================================================
    // open() — fichier existant
    // =========================================================================

    #[Test]
    public function open_existing_file_restores_written_entries(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);
        $this->index->set('abc', 1024, 16, 3);
        $this->index->close();

        $this->index->open($path);

        $this->assertSame(
            ['offset' => 1024, 'capacity' => 16, 'count' => 3],
            $this->index->get('abc')
        );
    }

    #[Test]
    public function open_throws_on_invalid_magic(): void
    {
        $path = $this->createRawFile('NOPE', 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/magic/i');
        $this->index->open($path);
    }

    #[Test]
    public function open_throws_on_unsupported_version(): void
    {
        $path = $this->createRawFile('TRIG', 99);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/version/i');
        $this->index->open($path);
    }

    #[Test]
    public function open_throws_when_path_is_not_writable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->index->open('/nonexistent_' . uniqid() . '/trig.bin');
    }

    // =========================================================================
    // get() / set()
    // =========================================================================

    #[Test]
    public function set_and_get_round_trip(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        $this->index->set('en#', 512, 8, 2);

        $this->assertSame(
            ['offset' => 512, 'capacity' => 8, 'count' => 2],
            $this->index->get('en#')
        );
    }

    #[Test]
    public function set_overwrites_previous_value(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        $this->index->set('abc', 100, 4, 1);
        $this->index->set('abc', 200, 8, 5);

        $this->assertSame(
            ['offset' => 200, 'capacity' => 8, 'count' => 5],
            $this->index->get('abc')
        );
    }

    #[Test]
    public function set_does_not_affect_other_trigrams(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        $this->index->set('abc', 100, 4, 1);

        $this->assertSame($this->zeroEntry(), $this->index->get('abd'));
        $this->assertSame($this->zeroEntry(), $this->index->get('#a#'));
    }

    #[Test]
    public function set_handles_large_offset_correctly(): void
    {
        // Teste le split uint64 en deux uint32 (hi/lo)
        $path = $this->tempPath();
        $this->index->open($path);

        $largeOffset = 0x1_0000_0000; // dépasse uint32, nécessite hi > 0
        $this->index->set('xyz', $largeOffset, 1, 1);
        $this->index->close();

        $this->index->open($path);

        $this->assertSame($largeOffset, $this->index->get('xyz')['offset']);
    }

    #[Test]
    public function get_throws_when_not_open(): void
    {
        $this->expectException(RuntimeException::class);
        $this->index->get('abc');
    }

    #[Test]
    public function set_throws_when_not_open(): void
    {
        $this->expectException(RuntimeException::class);
        $this->index->set('abc', 0, 0, 0);
    }

    // =========================================================================
    // trigramToIndex() — validation (via get/set)
    // =========================================================================

    #[Test]
    public function get_throws_on_trigram_shorter_than_3_chars(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/3 characters/i');

        $this->index->get('ab');
    }

    #[Test]
    public function get_throws_on_trigram_longer_than_3_chars(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        $this->expectException(RuntimeException::class);

        $this->index->get('abcd');
    }

    #[Test]
    public function get_throws_on_invalid_character_in_trigram(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid character/i');

        $this->index->get('a!c');
    }

    #[Test]
    #[DataProvider('provideBoundaryTrigrams')]
    public function get_handles_boundary_trigrams_without_exception(string $trigram): void
    {
        $path = $this->tempPath();
        $this->index->open($path);

        // Pas d'exception = succès
        $entry = $this->index->get($trigram);

        $this->assertArrayHasKey('offset',   $entry);
        $this->assertArrayHasKey('capacity', $entry);
        $this->assertArrayHasKey('count',    $entry);
    }

    public static function provideBoundaryTrigrams(): array
    {
        return [
            'premier trigram (aaa)'  => ['aaa'],
            'dernier trigram (###)'  => ['###'],
            'trigram avec #'         => ['#a#'],
            'trigram avec chiffres'  => ['0a9'],
            'trigram tout chiffres'  => ['123'],
        ];
    }

    // =========================================================================
    // close()
    // =========================================================================

    #[Test]
    public function close_makes_subsequent_calls_throw(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);
        $this->index->close();

        $this->expectException(RuntimeException::class);
        $this->index->get('abc');
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $path = $this->tempPath();
        $this->index->open($path);
        $this->index->close();
        $this->index->close();

        $this->assertTrue(true);
    }

    #[Test]
    public function close_on_never_opened_index_does_not_throw(): void
    {
        (new TrigramIndex())->close();
        $this->assertTrue(true);
    }
}
