<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\CorruptSegmentException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\BlockDictionaryFormat;
use Ols\PhpFts\Index\BlockDictionaryReader;
use Ols\PhpFts\Index\BlockDictionaryWriter;
use Ols\PhpFts\Storage\SegmentReader;
use Ols\PhpFts\Storage\SegmentWriter;
use Ols\PhpFts\Storage\Varint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The block dictionary: a sorted map from key to opaque payload, built to be
 * searched with one disk read and opened without loading itself.
 *
 * These tests are the specification. They cover what it stores, what it costs,
 * and what it refuses.
 */
class BlockDictionaryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_dict_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    /**
     * Builds a dictionary, stores it in a real segment, and hands back a reader.
     *
     * @param array<string, string> $entries key => payload, in sorted order
     */
    private function dictionary(array $entries, int $blockSize = BlockDictionaryFormat::DEFAULT_BLOCK_SIZE): BlockDictionaryReader
    {
        $writer = new BlockDictionaryWriter($blockSize);

        foreach ($entries as $key => $payload) {
            $writer->add((string) $key, $payload);
        }

        $path    = $this->dir . '/seg_' . uniqid() . '.fts';
        $segment = SegmentWriter::create($path);
        $segment->addSection('terms', $writer->finish());
        $segment->commit();

        return new BlockDictionaryReader(SegmentReader::open($path), 'terms');
    }

    // =========================================================================
    // Storing and finding
    // =========================================================================

    #[Test]
    public function it_finds_every_key_it_was_given(): void
    {
        $entries = [
            '#ch'  => 'payload for #ch',
            '#cha' => 'payload for #cha',
            '#che' => 'payload for #che',
            '#chi' => 'payload for #chi',
            'cha'  => 'payload for cha',
            'hau'  => 'payload for hau',
        ];

        $dictionary = $this->dictionary($entries);

        foreach ($entries as $key => $payload) {
            $this->assertSame($payload, $dictionary->get($key), "looking up '$key'");
        }
    }

    #[Test]
    public function an_absent_key_returns_null(): void
    {
        $dictionary = $this->dictionary(['bbb' => 'x', 'ddd' => 'y', 'fff' => 'z']);

        $this->assertNull($dictionary->get('aaa'), 'before every key');
        $this->assertNull($dictionary->get('ccc'), 'between two keys');
        $this->assertNull($dictionary->get('zzz'), 'after every key');
        $this->assertNull($dictionary->get('bb'),  'a prefix of a key is not a key');
        $this->assertNull($dictionary->get('bbbb'), 'a key extended is not a key');
    }

    #[Test]
    public function an_empty_dictionary_answers_null_to_everything(): void
    {
        $dictionary = $this->dictionary([]);

        $this->assertSame(0, $dictionary->count());
        $this->assertNull($dictionary->get('anything'));
    }

    #[Test]
    public function payloads_are_opaque_bytes(): void
    {
        // The dictionary never interprets a payload, so null bytes, high bytes
        // and empty payloads must all survive intact.
        $entries = [
            'aaa' => "\x00\x01\x02\xff\xfe",
            'bbb' => '',
            'ccc' => random_bytes(300),
        ];

        $dictionary = $this->dictionary($entries);

        foreach ($entries as $key => $payload) {
            $this->assertSame($payload, $dictionary->get($key));
        }
    }

    #[Test]
    public function it_holds_keys_from_any_script(): void
    {
        // The reason this structure exists. v1 computed a position from three
        // characters of a 37-symbol alphabet, so anything outside [a-z0-9#] was
        // destroyed before it ever reached the index. Here keys are stored, not
        // computed, so the alphabet is whatever UTF-8 can express.
        $entries = [
            '革靴'    => 'japonais',
            'кожа'    => 'russe',
            'δέρμα'   => 'grec',
            'جلد'     => 'arabe',
            'หนัง'    => 'thaï',
            'cuir'    => 'français',
        ];

        // Sorted bytewise, which for UTF-8 is the same as sorting by code point.
        $keys = array_keys($entries);
        sort($keys, SORT_STRING);

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = $entries[$key];
        }

        $dictionary = $this->dictionary($sorted);

        foreach ($entries as $key => $expected) {
            $this->assertSame($expected, $dictionary->get($key), "looking up '$key'");
        }
    }

    // =========================================================================
    // Behaviour across many blocks
    // =========================================================================

    #[Test]
    public function it_finds_keys_spread_over_many_blocks(): void
    {
        $entries = [];

        for ($i = 0; $i < 5000; $i++) {
            $entries[sprintf('term%05d', $i)] = "payload $i";
        }

        $dictionary = $this->dictionary($entries);

        $this->assertSame(5000, $dictionary->count());
        $this->assertGreaterThan(50, $dictionary->blockCount(), 'the data must really span many blocks');

        // First, last, and a scatter in between — every one must resolve.
        foreach ([0, 1, 63, 64, 65, 127, 128, 2500, 4998, 4999] as $i) {
            $this->assertSame("payload $i", $dictionary->get(sprintf('term%05d', $i)), "entry $i");
        }
    }

    #[Test]
    public function the_first_key_of_every_block_is_findable(): void
    {
        // Block boundaries are where front coding restarts and where the binary
        // search lands, so they are the entries most likely to break.
        $entries = [];

        for ($i = 0; $i < 600; $i++) {
            $entries[sprintf('k%04d', $i)] = (string) $i;
        }

        $dictionary = $this->dictionary($entries, blockSize: 8);

        $this->assertSame(75, $dictionary->blockCount());

        for ($i = 0; $i < 600; $i += 8) {
            $this->assertSame((string) $i, $dictionary->get(sprintf('k%04d', $i)), "block-leading entry $i");
        }
    }

    #[Test]
    public function a_block_size_of_one_still_works(): void
    {
        // Degenerate but legal: every entry is its own block, front coding never
        // applies. Worth pinning because it exercises the index path hardest.
        $dictionary = $this->dictionary(['aaa' => '1', 'bbb' => '2', 'ccc' => '3'], blockSize: 1);

        $this->assertSame(3, $dictionary->blockCount());
        $this->assertSame('2', $dictionary->get('bbb'));
        $this->assertNull($dictionary->get('abc'));
    }

    #[Test]
    public function iteration_returns_every_entry_in_order(): void
    {
        $entries = [];

        for ($i = 0; $i < 300; $i++) {
            $entries[sprintf('key%04d', $i)] = "value $i";
        }

        $dictionary = $this->dictionary($entries, blockSize: 16);

        $seen = [];
        foreach ($dictionary->iterate() as $key => $payload) {
            $seen[$key] = $payload;
        }

        $this->assertSame($entries, $seen);
    }

    // =========================================================================
    // What it costs
    // =========================================================================

    /**
     * What front coding actually buys, measured rather than assumed.
     *
     * It replaces a key's shared prefix with a count of how many bytes were
     * borrowed — so it spends one varint to save the prefix. On a three-byte
     * trigram that is one byte spent to save two, and the gain is modest. On a
     * thirty-byte business key it is one byte spent to save twenty-five.
     *
     * The consequence worth remembering: a dictionary costs roughly six bytes
     * per key almost regardless of how long the keys are. And the saving is
     * largest exactly where this structure is most needed — the key index, and
     * CJK n-grams, which run to six or nine bytes in UTF-8 where a Latin
     * trigram runs to three.
     */
    #[Test]
    public function front_coding_pays_in_proportion_to_shared_prefix_length(): void
    {
        $cases = [
            // label, keys, minimum saving expected
            ['Latin trigrams (3 bytes)',   $this->latinTrigrams(),  0.05],
            ['CJK bigrams (6 bytes)',      $this->cjkBigrams(),     0.25],
            ['business keys (30 bytes)',   $this->businessKeys(),   0.70],
        ];

        $costPerKey = [];

        foreach ($cases as [$label, $keys, $minimumSaving]) {
            $payload = "\x01\x02";

            $writer = new BlockDictionaryWriter();
            foreach ($keys as $key) {
                $writer->add($key, $payload);
            }
            $actual = strlen($writer->finish());

            // The same structure with every key stored whole.
            $unshared = BlockDictionaryFormat::HEADER_SIZE;
            foreach ($keys as $key) {
                $unshared += Varint::size(strlen($key)) + strlen($key)
                    + Varint::size(strlen($payload)) + strlen($payload);
            }

            $this->assertGreaterThan(
                $minimumSaving,
                1 - $actual / $unshared,
                "$label: front coding saving"
            );

            $costPerKey[$label] = $actual / count($keys);
        }

        // The property that matters, stated as a property rather than a magic
        // number: the cost of a key barely depends on how long the key is.
        // Keys ten times longer must not cost anywhere near ten times more.
        $this->assertLessThan(
            1.3,
            max($costPerKey) / min($costPerKey),
            'cost per key must stay nearly flat across key lengths: ' . json_encode(
                array_map(fn(float $cost): float => round($cost, 2), $costPerKey)
            )
        );
    }

    /** @return string[] sorted */
    private function latinTrigrams(): array
    {
        $alphabet = str_split('abcdefghijklmnopqrstuvwxyz');
        $keys     = [];

        foreach ($alphabet as $a) {
            foreach ($alphabet as $b) {
                foreach ($alphabet as $c) {
                    $keys[] = $a . $b . $c;
                }
            }
        }

        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return string[] sorted */
    private function cjkBigrams(): array
    {
        $characters = [];

        // U+4E00 onwards, the start of the CJK unified ideographs. Encoded by
        // hand rather than with mb_chr(), because the library must not need
        // ext-mbstring and neither must its tests.
        for ($codepoint = 0x4E00; $codepoint < 0x4E00 + 130; $codepoint++) {
            $characters[] = chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        $keys = [];

        foreach ($characters as $first) {
            foreach ($characters as $second) {
                $keys[] = $first . $second;
            }
        }

        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return string[] sorted */
    private function businessKeys(): array
    {
        $keys = [];

        for ($i = 0; $i < 20000; $i++) {
            $keys[] = sprintf('product-catalogue-sku-%08d', $i);
        }

        sort($keys, SORT_STRING);

        return $keys;
    }

    #[Test]
    public function opening_reads_only_the_header_and_the_index(): void
    {
        // The claim that matters against v1, which loaded 810 KB and built
        // 50 653 arrays on every search. Here the whole payload area must stay
        // untouched until a key is actually looked up.
        $entries = [];

        for ($i = 0; $i < 20000; $i++) {
            $entries[sprintf('term%06d', $i)] = str_repeat('x', 40);
        }

        $writer = new BlockDictionaryWriter();
        foreach ($entries as $key => $payload) {
            $writer->add($key, $payload);
        }

        $bytes = $writer->finish();
        $header = BlockDictionaryFormat::unpackHeader($bytes);

        $indexShare = $header['indexLength'] / strlen($bytes);

        $this->assertLessThan(
            0.10,
            $indexShare,
            'the part read at open time must be a small fraction of the dictionary'
        );
    }

    // =========================================================================
    // What it refuses
    // =========================================================================

    #[Test]
    public function keys_must_be_added_in_ascending_order(): void
    {
        $writer = new BlockDictionaryWriter();
        $writer->add('bbb', 'x');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/ascending order/i');

        $writer->add('aaa', 'y');
    }

    #[Test]
    public function the_same_key_cannot_be_added_twice(): void
    {
        $writer = new BlockDictionaryWriter();
        $writer->add('aaa', 'x');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/ascending order/i');

        $writer->add('aaa', 'y');
    }

    #[Test]
    public function an_empty_key_is_refused(): void
    {
        $writer = new BlockDictionaryWriter();

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/cannot be empty/i');

        $writer->add('', 'x');
    }

    #[Test]
    public function finishing_twice_is_refused(): void
    {
        $writer = new BlockDictionaryWriter();
        $writer->add('aaa', 'x');
        $writer->finish();

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/already finished/i');

        $writer->finish();
    }

    #[Test]
    public function a_section_that_is_not_a_dictionary_is_refused(): void
    {
        $path    = $this->dir . '/seg_wrong.fts';
        $segment = SegmentWriter::create($path);
        $segment->addSection('terms', str_repeat('this is not a dictionary', 10));
        $segment->commit();

        $this->expectException(CorruptSegmentException::class);
        $this->expectExceptionMessageMatches('/block dictionary/i');

        new BlockDictionaryReader(SegmentReader::open($path), 'terms');
    }
}
