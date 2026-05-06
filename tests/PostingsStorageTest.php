<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\PostingsStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PostingsStorageTest extends TestCase
{
    private PostingsStorage $storage;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->storage = new PostingsStorage();
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
        $path = tempnam(sys_get_temp_dir(), 'post_test_');
        unlink($path);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function openNew(): void
    {
        $this->storage->open($this->tempPath());
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
        $this->assertSame('POST', substr($raw, 0, 4));
        $this->assertSame(1, ord($raw[4]));
        $this->assertSame(16, strlen($raw));
    }

    #[Test]
    public function open_throws_on_invalid_magic(): void
    {
        $path = $this->createRawFile('NOPE', 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/magic/i');
        $this->storage->open($path);
    }

    #[Test]
    public function open_throws_on_unsupported_version(): void
    {
        $path = $this->createRawFile('POST', 99);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/version/i');
        $this->storage->open($path);
    }

    // =========================================================================
    // read()
    // =========================================================================

    #[Test]
    public function read_returns_empty_array_when_count_is_zero(): void
    {
        $this->openNew();

        $this->assertSame([], $this->storage->read(16, 0));
    }

    #[Test]
    public function read_returns_correct_doc_ids(): void
    {
        $this->openNew();

        $result = $this->storage->append(10, 0, 0, 0);
        $this->storage->append(20, $result['offset'], $result['capacity'], $result['count']);

        $state = $this->storage->append(30, $result['offset'], $result['capacity'], $result['count'] + 1);

        $ids = $this->storage->read($result['offset'], $state['count']);

        $this->assertContains(10, $ids);
        $this->assertContains(20, $ids);
    }

    #[Test]
    public function read_throws_on_invalid_offset(): void
    {
        $this->openNew();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid offset/i');

        $this->storage->read(4, 1); // < HEADER_SIZE
    }

    #[Test]
    public function read_throws_when_not_open(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage->read(16, 1);
    }

    // =========================================================================
    // append() — branche 1 : première allocation (offset = 0)
    // =========================================================================

    #[Test]
    public function append_first_call_allocates_at_end_of_header(): void
    {
        $this->openNew();

        $result = $this->storage->append(42, 0, 0, 0);

        // Premier offset = juste après le header
        $this->assertSame(16, $result['offset']);
    }

    #[Test]
    public function append_first_call_sets_initial_capacity_to_4(): void
    {
        $this->openNew();

        $result = $this->storage->append(42, 0, 0, 0);

        $this->assertSame(4, $result['capacity']);
    }

    #[Test]
    public function append_first_call_sets_count_to_1(): void
    {
        $this->openNew();

        $result = $this->storage->append(42, 0, 0, 0);

        $this->assertSame(1, $result['count']);
    }

    #[Test]
    public function append_first_call_doc_id_is_readable(): void
    {
        $this->openNew();

        $result = $this->storage->append(99, 0, 0, 0);
        $ids    = $this->storage->read($result['offset'], $result['count']);

        $this->assertSame([99], $ids);
    }

    // =========================================================================
    // append() — branche 2 : écriture en place (count < capacity)
    // =========================================================================

    #[Test]
    public function append_in_place_keeps_same_offset(): void
    {
        $this->openNew();

        $state1 = $this->storage->append(1, 0, 0, 0); // capacity=4, count=1
        $state2 = $this->storage->append(2, $state1['offset'], $state1['capacity'], $state1['count']);

        $this->assertSame($state1['offset'], $state2['offset']);
    }

    #[Test]
    public function append_in_place_increments_count(): void
    {
        $this->openNew();

        $state1 = $this->storage->append(1, 0, 0, 0);
        $state2 = $this->storage->append(2, $state1['offset'], $state1['capacity'], $state1['count']);

        $this->assertSame(2, $state2['count']);
    }

    #[Test]
    public function append_in_place_keeps_same_capacity(): void
    {
        $this->openNew();

        $state1 = $this->storage->append(1, 0, 0, 0); // capacity=4
        $state2 = $this->storage->append(2, $state1['offset'], $state1['capacity'], $state1['count']);

        $this->assertSame($state1['capacity'], $state2['capacity']);
    }

    #[Test]
    public function append_in_place_all_ids_are_readable(): void
    {
        $this->openNew();

        $s = $this->storage->append(1, 0, 0, 0);
        $s = $this->storage->append(2, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(3, $s['offset'], $s['capacity'], $s['count']);

        $ids = $this->storage->read($s['offset'], $s['count']);

        $this->assertSame([1, 2, 3], $ids);
    }

    // =========================================================================
    // append() — branche 3 : réallocation (count == capacity)
    // =========================================================================

    #[Test]
    public function append_reallocates_when_capacity_is_full(): void
    {
        $this->openNew();

        // Remplit la capacité initiale (4 slots)
        $s = $this->storage->append(1, 0, 0, 0);
        $s = $this->storage->append(2, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(3, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(4, $s['offset'], $s['capacity'], $s['count']);

        $offsetBefore = $s['offset'];

        // 5e append → count(4) == capacity(4) → réallocation
        $s = $this->storage->append(5, $s['offset'], $s['capacity'], $s['count']);

        $this->assertGreaterThan($offsetBefore, $s['offset']);
    }

    #[Test]
    public function append_reallocation_applies_growth_factor(): void
    {
        $this->openNew();

        $s = $this->storage->append(1, 0, 0, 0);
        $s = $this->storage->append(2, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(3, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(4, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(5, $s['offset'], $s['capacity'], $s['count']);

        // ceil(4 × 1.5) = 6
        $this->assertSame(6, $s['capacity']);
    }

    #[Test]
    public function append_reallocation_preserves_all_existing_ids(): void
    {
        $this->openNew();

        $s = $this->storage->append(1, 0, 0, 0);
        $s = $this->storage->append(2, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(3, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(4, $s['offset'], $s['capacity'], $s['count']);
        $s = $this->storage->append(5, $s['offset'], $s['capacity'], $s['count']);

        $ids = $this->storage->read($s['offset'], $s['count']);

        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    // =========================================================================
    // close()
    // =========================================================================

    #[Test]
    public function close_makes_subsequent_calls_throw(): void
    {
        $this->openNew();
        $this->storage->close();

        $this->expectException(RuntimeException::class);
        $this->storage->read(16, 1);
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $this->openNew();
        $this->storage->close();
        $this->storage->close();

        $this->assertTrue(true);
    }
}
