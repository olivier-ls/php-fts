<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\DocumentStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentStorageTest extends TestCase
{
    private DocumentStorage $storage;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->storage = new DocumentStorage();
    }

    protected function tearDown(): void
    {
        try { $this->storage->close(); } catch (\Throwable) {}

        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) unlink($path);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docs_test_');
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
        fwrite($handle, pack('VV', 0, 0));
        fwrite($handle, str_repeat("\x00", 3));
        fclose($handle);
        return $path;
    }

    // =========================================================================
    // open()
    // =========================================================================

    #[Test]
    public function open_creates_file_with_correct_header(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->storage->close();

        $raw = file_get_contents($path);
        $this->assertSame('DOCS', substr($raw, 0, 4));
        $this->assertSame(4, ord($raw[4]));
        $this->assertSame(16, strlen($raw)); // header seul
    }

    #[Test]
    public function open_existing_file_loads_count_and_trigram_sum(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->storage->write(['name' => 'foo'], 5);
        $this->storage->write(['name' => 'bar'], 3);
        $this->storage->close();

        $this->storage->open($path);

        $this->assertSame(2, $this->storage->count());
        $this->assertSame(4.0, $this->storage->avgTrigramCount()); // (5+3)/2
    }

    #[Test]
    public function open_throws_on_invalid_magic(): void
    {
        $path = $this->createRawFile('NOPE', 4);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/magic/i');
        $this->storage->open($path);
    }

    #[Test]
    public function open_throws_on_unsupported_version(): void
    {
        $path = $this->createRawFile('DOCS', 99);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/version/i');
        $this->storage->open($path);
    }

    #[Test]
    public function open_throws_when_path_is_not_writable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage->open('/nonexistent_dir_' . uniqid() . '/docs.bin');
    }

    // =========================================================================
    // write()
    // =========================================================================

    #[Test]
    public function write_returns_offset_greater_than_or_equal_to_header_size(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $offset = $this->storage->write(['x' => 1]);

        $this->assertGreaterThanOrEqual(16, $offset);
    }

    #[Test]
    public function write_increments_count(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->write(['a' => 1]);
        $this->storage->write(['b' => 2]);

        $this->assertSame(2, $this->storage->count());
    }

    #[Test]
    public function write_accumulates_trigram_sum(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->write(['a' => 1], 10);
        $this->storage->write(['b' => 2], 6);

        $this->assertSame(8.0, $this->storage->avgTrigramCount()); // 16/2
    }

    #[Test]
    public function write_returns_sequential_increasing_offsets(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $offset1 = $this->storage->write(['a' => 1]);
        $offset2 = $this->storage->write(['b' => 2]);

        $this->assertLessThan($offset2, $offset1);
    }

    #[Test]
    public function write_throws_when_not_open(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage->write(['x' => 1]);
    }

    // =========================================================================
    // read()
    // =========================================================================

    #[Test]
    public function read_returns_written_document(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $doc    = ['id' => 42, 'name' => 'produit'];
        $offset = $this->storage->write($doc, 7);
        $result = $this->storage->read($offset);

        $this->assertSame($doc, $result['document']);
        $this->assertSame(7, $result['trigramCount']);
    }

    #[Test]
    public function read_handles_multiple_documents_at_correct_offsets(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $offset1 = $this->storage->write(['n' => 'alpha'], 3);
        $offset2 = $this->storage->write(['n' => 'beta'],  5);

        $this->assertSame('alpha', $this->storage->read($offset1)['document']['n']);
        $this->assertSame('beta',  $this->storage->read($offset2)['document']['n']);
    }

    #[Test]
    public function read_preserves_unicode_content(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $doc    = ['name' => 'Château Île-de-Ré'];
        $offset = $this->storage->write($doc);

        $this->assertSame($doc, $this->storage->read($offset)['document']);
    }

    #[Test]
    public function read_throws_on_invalid_offset(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid offset/i');

        $this->storage->read(4); // < HEADER_SIZE (16)
    }

    #[Test]
    public function read_throws_when_not_open(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage->read(16);
    }

    // =========================================================================
    // iterate()
    // =========================================================================

    #[Test]
    public function iterate_visits_all_documents_in_order(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->write(['n' => 'a'], 1);
        $this->storage->write(['n' => 'b'], 2);
        $this->storage->write(['n' => 'c'], 3);

        $visited = [];
        $this->storage->iterate(function (int $offset, array $doc, int $tc) use (&$visited) {
            $visited[] = $doc['n'];
        });

        $this->assertSame(['a', 'b', 'c'], $visited);
    }

    #[Test]
    public function iterate_passes_correct_offsets_and_trigram_counts(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $offset1 = $this->storage->write(['n' => 'x'], 9);
        $offset2 = $this->storage->write(['n' => 'y'], 4);

        $collected = [];
        $this->storage->iterate(function (int $offset, array $doc, int $tc) use (&$collected) {
            $collected[] = ['offset' => $offset, 'tc' => $tc];
        });

        $this->assertSame($offset1, $collected[0]['offset']);
        $this->assertSame(9,        $collected[0]['tc']);
        $this->assertSame($offset2, $collected[1]['offset']);
        $this->assertSame(4,        $collected[1]['tc']);
    }

    #[Test]
    public function iterate_does_nothing_on_empty_file(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $count = 0;
        $this->storage->iterate(function () use (&$count) { $count++; });

        $this->assertSame(0, $count);
    }

    #[Test]
    public function iterate_throws_when_not_open(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage->iterate(fn() => null);
    }

    // =========================================================================
    // count() / avgTrigramCount()
    // =========================================================================

    #[Test]
    public function count_returns_zero_on_new_file(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->assertSame(0, $this->storage->count());
    }

    #[Test]
    public function avg_trigram_count_returns_zero_when_no_documents(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->assertSame(0.0, $this->storage->avgTrigramCount());
    }

    #[Test]
    public function avg_trigram_count_is_correct(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);

        $this->storage->write(['x' => 1], 10);
        $this->storage->write(['x' => 2], 20);
        $this->storage->write(['x' => 3], 30);

        $this->assertSame(20.0, $this->storage->avgTrigramCount()); // 60/3
    }

    // =========================================================================
    // close()
    // =========================================================================

    #[Test]
    public function close_makes_subsequent_calls_throw(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->storage->close();

        $this->expectException(RuntimeException::class);
        $this->storage->count();
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->storage->close();
        $this->storage->close(); // ne doit pas exploser

        $this->assertTrue(true);
    }

    // =========================================================================
    // Persistance
    // =========================================================================

    #[Test]
    public function stats_persist_across_open_close_cycles(): void
    {
        $path = $this->tempPath();
        $this->storage->open($path);
        $this->storage->write(['x' => 1], 12);
        $this->storage->write(['x' => 2], 8);
        $this->storage->close();

        $this->storage->open($path);

        $this->assertSame(2,    $this->storage->count());
        $this->assertSame(10.0, $this->storage->avgTrigramCount()); // 20/2
    }
}
