<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Storage;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Storage\SegmentFormat;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\SegmentWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The segment container: one immutable file holding named byte ranges.
 *
 * These tests double as the specification. Read top to bottom they describe
 * what a segment guarantees — and, just as importantly, what it refuses.
 */
class SegmentFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_seg_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    private function path(string $name = 'seg_test.fts'): string
    {
        return $this->dir . '/' . $name;
    }

    // =========================================================================
    // Writing and reading back
    // =========================================================================

    #[Test]
    public function a_segment_round_trips_its_sections(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'the term dictionary');
        $writer->addSection('postings', 'the posting lists');
        $writer->commit();

        $reader = SegmentReader::open($this->path());

        $this->assertSame(['terms', 'postings'], $reader->sections());
        $this->assertSame('the term dictionary', $reader->read('terms'));
        $this->assertSame('the posting lists', $reader->read('postings'));

        $reader->close();
    }

    #[Test]
    public function sections_keep_the_order_they_were_written_in(): void
    {
        $writer = SegmentWriter::create($this->path());

        foreach (['docstore', 'terms', 'keys', 'postings'] as $name) {
            $writer->addSection($name, $name);
        }

        $writer->commit();

        $reader = SegmentReader::open($this->path());
        $this->assertSame(['docstore', 'terms', 'keys', 'postings'], $reader->sections());
        $reader->close();
    }

    #[Test]
    public function a_section_can_be_streamed_in_chunks(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->beginSection('docstore');

        for ($i = 0; $i < 500; $i++) {
            $writer->write(str_pad((string) $i, 8, '.', STR_PAD_LEFT));
        }

        $writer->endSection();
        $writer->commit();

        $reader = SegmentReader::open($this->path());

        $this->assertSame(4000, $reader->length('docstore'));
        $this->assertSame('.......0', $reader->read('docstore', 0, 8));
        $this->assertSame('.....499', $reader->read('docstore', 3992, 8));

        $reader->close();
    }

    #[Test]
    public function a_slice_can_be_read_without_loading_the_section(): void
    {
        $payload = str_repeat('abcdefgh', 1024); // 8 KB

        $writer = SegmentWriter::create($this->path());
        $writer->addSection('postings', $payload);
        $writer->commit();

        $reader = SegmentReader::open($this->path());

        $this->assertSame('efgh', $reader->read('postings', 4, 4));
        $this->assertSame(substr($payload, 4096, 128), $reader->read('postings', 4096, 128));

        $reader->close();
    }

    #[Test]
    public function an_empty_section_is_valid(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('deleted', '');
        $writer->commit();

        $reader = SegmentReader::open($this->path());

        $this->assertTrue($reader->has('deleted'));
        $this->assertSame(0, $reader->length('deleted'));
        $this->assertSame('', $reader->read('deleted'));

        $reader->close();
    }

    #[Test]
    public function a_segment_with_no_sections_at_all_is_valid(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->commit();

        $reader = SegmentReader::open($this->path());

        $this->assertSame([], $reader->sections());
        $this->assertSame(SegmentFormat::MIN_FILE_SIZE, filesize($this->path()));

        $reader->close();
    }

    #[Test]
    public function binary_payloads_survive_unchanged(): void
    {
        // Null bytes, high bytes and anything resembling the magic numbers must
        // pass through untouched — sections are opaque byte ranges.
        $payload = random_bytes(4096) . "\x00\x00FTSG\xff\xffGSTF\x00";

        $writer = SegmentWriter::create($this->path());
        $writer->addSection('postings', $payload);
        $writer->commit();

        $reader = SegmentReader::open($this->path());
        $this->assertSame($payload, $reader->read('postings'));
        $reader->close();
    }

    // =========================================================================
    // Integrity
    // =========================================================================

    #[Test]
    public function each_section_carries_a_checksum_that_verifies(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content that will be checksummed');
        $writer->commit();

        $reader = SegmentReader::open($this->path());
        $this->assertTrue($reader->verify('terms'));
        $reader->close();
    }

    #[Test]
    public function a_truncated_segment_is_rejected(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', str_repeat('x', 2048));
        $writer->commit();

        // Simulates a write cut short: the machine died, or the disk filled up.
        $full = filesize($this->path());
        $file = fopen($this->path(), 'r+b');
        ftruncate($file, $full - 16);
        fclose($file);

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/incomplete|trailer/i');

        SegmentReader::open($this->path());
    }

    #[Test]
    public function a_segment_with_extra_bytes_appended_is_rejected(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content');
        $writer->commit();

        file_put_contents($this->path(), 'garbage', FILE_APPEND);

        $this->expectException(CorruptSegmentException::class);

        SegmentReader::open($this->path());
    }

    #[Test]
    public function a_corrupt_directory_is_rejected(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content');
        $writer->commit();

        // Flip a byte inside the directory: it sits just before the trailer.
        $bytes = file_get_contents($this->path());
        $target = strlen($bytes) - SegmentFormat::TRAILER_SIZE - 4;
        $bytes[$target] = chr(ord($bytes[$target]) ^ 0xFF);
        file_put_contents($this->path(), $bytes);

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/directory/i');

        SegmentReader::open($this->path());
    }

    #[Test]
    public function a_corrupt_section_payload_is_reported_by_verify_but_does_not_block_opening(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', str_repeat('a', 256));
        $writer->commit();

        // Corrupt a byte inside the payload, which starts right after the header.
        $bytes = file_get_contents($this->path());
        $bytes[SegmentFormat::HEADER_SIZE + 10] = 'Z';
        file_put_contents($this->path(), $bytes);

        // Opening still works: payload checksums are deliberately not verified
        // on the read path, because doing so would make every open cost a full
        // read of the file.
        $reader = SegmentReader::open($this->path());

        $this->assertFalse($reader->verify('terms'));

        $reader->close();
    }

    #[Test]
    public function a_file_that_is_not_a_segment_is_rejected(): void
    {
        file_put_contents($this->path(), str_repeat('not a segment at all ', 100));

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/magic|trailer/i');

        SegmentReader::open($this->path());
    }

    #[Test]
    public function a_file_too_short_to_be_a_segment_is_rejected(): void
    {
        file_put_contents($this->path(), 'FTSG');

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/too short/i');

        SegmentReader::open($this->path());
    }

    // =========================================================================
    // The writer's contract
    // =========================================================================

    #[Test]
    public function a_segment_only_appears_under_its_final_name_once_committed(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content');

        $this->assertFileDoesNotExist($this->path(), 'the final name must not exist before commit');
        $this->assertFileExists($this->path() . '.tmp');

        $writer->commit();

        $this->assertFileExists($this->path());
        $this->assertFileDoesNotExist($this->path() . '.tmp');
    }

    #[Test]
    public function discarding_leaves_nothing_behind(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content');
        $writer->discard();

        $this->assertFileDoesNotExist($this->path());
        $this->assertFileDoesNotExist($this->path() . '.tmp');
    }

    #[Test]
    public function a_leftover_temporary_file_is_reclaimed(): void
    {
        // Debris from a process that died mid-write. It was never named in a
        // manifest, so it cannot be referenced by anything.
        file_put_contents($this->path() . '.tmp', 'debris from a dead process');

        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content');
        $writer->commit();

        $reader = SegmentReader::open($this->path());
        $this->assertSame('content', $reader->read('terms'));
        $reader->close();
    }

    #[Test]
    public function committing_with_a_section_left_open_is_refused(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->beginSection('terms');
        $writer->write('content');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/never closed/i');

        $writer->commit();
    }

    #[Test]
    public function nesting_sections_is_refused(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->beginSection('terms');

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessageMatches('/still open/i');

            $writer->beginSection('postings');
        } finally {
            $writer->discard();
        }
    }

    #[Test]
    public function duplicate_section_names_are_refused(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'first');

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessageMatches('/duplicate/i');

            $writer->addSection('terms', 'second');
        } finally {
            $writer->discard();
        }
    }

    #[Test]
    public function writing_outside_a_section_is_refused(): void
    {
        $writer = SegmentWriter::create($this->path());

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessageMatches('/outside a section/i');

            $writer->write('content');
        } finally {
            $writer->discard();
        }
    }

    #[Test]
    public function committing_twice_is_refused(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->commit();

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/already committed/i');

        $writer->commit();
    }

    // =========================================================================
    // The reader's contract
    // =========================================================================

    #[Test]
    public function asking_for_an_unknown_section_names_the_ones_that_exist(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content');
        $writer->commit();

        $reader = SegmentReader::open($this->path());

        $this->assertFalse($reader->has('postings'));

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessageMatches("/no section 'postings'.*terms/s");

            $reader->read('postings');
        } finally {
            $reader->close();
        }
    }

    #[Test]
    public function reading_past_the_end_of_a_section_is_refused(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', '0123456789');
        $writer->addSection('postings', 'must not leak into the previous section');
        $writer->commit();

        $reader = SegmentReader::open($this->path());

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessageMatches('/outside section/i');

            $reader->read('terms', 5, 20);
        } finally {
            $reader->close();
        }
    }

    #[Test]
    public function a_segment_can_be_read_from_a_read_only_file(): void
    {
        $writer = SegmentWriter::create($this->path());
        $writer->addSection('terms', 'content');
        $writer->commit();

        chmod($this->path(), 0444);

        $reader = SegmentReader::open($this->path());
        $this->assertSame('content', $reader->read('terms'));
        $reader->close();

        chmod($this->path(), 0644);
    }
}
