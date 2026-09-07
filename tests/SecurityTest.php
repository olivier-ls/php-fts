<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use Ols\PhpFts\Storage\Manifest;
use Ols\PhpFts\Storage\SegmentReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the three vulnerabilities fixed in 1.1.4.
 *
 * Carried over to 2.x deliberately. The engine they were reported against is
 * gone — every class named in the original reports has been deleted — but a
 * fix that is not tested against the code that replaced it is a fix that comes
 * back. Each one is re-asserted here against the new implementation, which in
 * two of the three cases is a different mechanism reaching the same guarantee.
 *
 * @see https://github.com/olivier-ls/php-fts/issues/1 stored XSS in highlights
 * @see https://github.com/olivier-ls/php-fts/issues/2 filter bypass by type juggling
 * @see https://github.com/olivier-ls/php-fts/issues/3 symlink following on open
 */
class SecurityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_sec_' . uniqid();
    }

    protected function tearDown(): void
    {
        self::removeDir($this->dir);
    }

    private function engine(?Schema $schema = null): SearchEngine
    {
        return SearchEngine::open($this->dir . '/index', $schema);
    }

    // =========================================================================
    // The exception hierarchy the fixes rely on
    // =========================================================================

    #[Test]
    public function engine_errors_are_fts_exceptions(): void
    {
        $this->engine(Schema::make()->text('title'))->put('sku-1', ['title' => 'Shoe']);

        $this->expectException(FtsException::class);

        // One catch is enough for an application that only wants to know that
        // indexing failed.
        $this->engine(Schema::make()->number('title'));
    }

    #[Test]
    public function fts_exceptions_remain_catchable_as_runtime_exception(): void
    {
        try {
            $this->engine()->search('x', filters: [['field' => 'a', 'op' => 'sounds like', 'value' => 1]]);
            $this->fail('Expected a FilterException');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(FtsException::class, $e);
        }
    }

    #[Test]
    public function storage_errors_are_storage_exceptions(): void
    {
        $this->expectException(StorageException::class);

        SegmentReader::open($this->dir . '/nothing-here.fts');
    }

    // =========================================================================
    // Issue #1 — stored XSS in highlights
    // =========================================================================

    #[Test]
    public function highlights_escape_markup_coming_from_documents(): void
    {
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'Shoe <script>alert(1)</script> leather']);

        $marked = $engine->search('leather', highlight: ['title'])->hits[0]->highlights['title'];

        // Two defences in sequence, which is why the payload survives as inert
        // text: the analyzer strips the tags, and what is left is escaped. The
        // string `alert(1)` remains, and is now a word rather than a script.
        $this->assertStringNotContainsString('<script>', $marked);
        $this->assertStringNotContainsString('</script>', $marked);
        $this->assertSame('Shoe alert(1) <mark>leather</mark>', $marked);

        // No angle bracket in the output that this library did not put there.
        $this->assertSame('Shoe alert(1) leather', strip_tags($marked));
    }

    #[Test]
    public function an_attribute_payload_cannot_escape_its_quotes(): void
    {
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'leather" onmouseover="alert(1)']);

        $marked = $engine->search('leather', highlight: ['title'])->hits[0]->highlights['title'];

        // Rendered inside an attribute, an unescaped double quote is the whole
        // exploit. `htmlspecialchars` with ENT_QUOTES is what closes it.
        $this->assertStringNotContainsString('"', $marked);
        $this->assertStringContainsString('&quot;', $marked);
    }

    #[Test]
    public function highlights_still_emit_their_own_tags(): void
    {
        // The fix must not have escaped its way out of being useful: the
        // engine's own tags are markup and stay markup.
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'Brown leather shoe']);

        $this->assertSame(
            'Brown <mark>leather</mark> shoe',
            $engine->search('leather', highlight: ['title'])->hits[0]->highlights['title']
        );
    }

    #[Test]
    public function escaping_can_only_be_dropped_explicitly(): void
    {
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'A "leather" shoe']);

        $safe = $engine->search('leather', highlight: Highlight::fields(['title']));
        $raw  = $engine->search('leather', highlight: Highlight::fields(['title'])->raw());

        $this->assertStringContainsString('&quot;', $safe->hits[0]->highlights['title']);
        $this->assertStringContainsString('"leather"', str_replace(
            ['<mark>', '</mark>'],
            '',
            $raw->hits[0]->highlights['title']
        ));
    }

    #[Test]
    public function offsets_carry_no_markup_to_escape(): void
    {
        // The third answer to the same question: a caller that renders its own
        // output gets positions and never sees a tag from this library.
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'Brown leather shoe']);

        $marked = $engine->search(
            'leather',
            highlight: Highlight::fields(['title'])->positions()
        )->hits[0]->highlights['title'];

        $this->assertIsArray($marked);
        $this->assertSame('Brown leather shoe', $marked['text']);
        $this->assertSame([[6, 13]], $marked['spans']);
    }

    // =========================================================================
    // Issue #2 — filter bypass by type juggling
    // =========================================================================

    #[Test]
    public function boolean_true_no_longer_matches_every_non_empty_string(): void
    {
        // The original bug: `'Nike' == true` is true in PHP, so filtering
        // `active => true` matched every document with a non-empty string in
        // that field. Now the comparison is refused before it is made.
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'Shoe', 'brand' => 'Nike']);

        $this->expectException(FilterException::class);

        $engine->search('', filters: Filter::eq('brand', true));
    }

    #[Test]
    public function a_boolean_field_still_filters_on_a_boolean(): void
    {
        $engine = $this->engine();
        $engine->putMany([
            'sku-1' => ['title' => 'Shoe', 'active' => true],
            'sku-2' => ['title' => 'Boot', 'active' => false],
        ]);

        $this->assertSame(1, $engine->search('', filters: Filter::eq('active', true))->total);
        $this->assertSame(1, $engine->search('', filters: Filter::eq('active', false))->total);
    }

    #[Test]
    public function a_numeric_filter_refuses_a_value_it_would_have_to_guess_at(): void
    {
        $engine = $this->engine(Schema::make()->text('title')->number('price'));
        $engine->put('sku-1', ['title' => 'Shoe', 'price' => 129.90]);

        // A numeric string from $_GET is accepted — there is nothing left to
        // guess at by then. A word is not.
        $this->assertSame(1, $engine->search('', filters: Filter::lte('price', '200'))->total);

        $this->expectException(FilterException::class);

        $engine->search('', filters: Filter::lte('price', 'cheap'));
    }

    #[Test]
    public function int_and_float_still_compare_numerically(): void
    {
        $engine = $this->engine();
        $engine->put('sku-1', ['title' => 'Shoe', 'price' => 100.0]);

        $this->assertSame(1, $engine->search('', filters: Filter::eq('price', 100))->total);
        $this->assertSame(1, $engine->search('', filters: Filter::gte('price', 99.5))->total);
        $this->assertSame(0, $engine->search('', filters: Filter::gt('price', 100))->total);
    }

    #[Test]
    public function an_unknown_operator_is_refused_rather_than_ignored(): void
    {
        // The failure nobody notices: a dropped clause widens a search instead
        // of narrowing it, and the page looks like it worked.
        $this->expectException(FilterException::class);

        $this->engine()->search('anything', filters: [
            ['field' => 'name', 'op' => 'sounds like', 'value' => 'x'],
        ]);
    }

    #[Test]
    public function a_malformed_filter_structure_is_refused(): void
    {
        $this->expectException(FilterException::class);

        Filter::fromArray(['op' => 'eq']);
    }

    // =========================================================================
    // Issue #3 — symlink following when opening index files
    // =========================================================================

    #[Test]
    public function reading_refuses_to_follow_a_symlink(): void
    {
        $victim = $this->dir . '/victim.txt';
        $link   = $this->dir . '/seg_linked.fts';

        mkdir($this->dir, 0755, true);
        file_put_contents($victim, 'DO NOT READ THROUGH ME');

        if (!@symlink($victim, $link)) {
            $this->markTestSkipped('symlink() is not available on this platform');
        }

        try {
            SegmentReader::open($link);
            $this->fail('Expected the symlink to be refused');
        } catch (StorageException $e) {
            $this->assertMatchesRegularExpression('/symbolic link|does not resolve/i', $e->getMessage());
        }
    }

    #[Test]
    public function writing_a_commit_refuses_to_follow_a_symlink(): void
    {
        // The commit file is written under a temporary name and renamed into
        // place, so the temporary name is the one an attacker would pre-place.
        // It is opened with the same guard as every other file, which is what
        // this asserts: the victim keeps its contents.
        $victim = $this->dir . '/victim.txt';
        $index  = $this->dir . '/index';

        mkdir($index, 0755, true);
        file_put_contents($victim, 'DO NOT TRUNCATE');

        if (!@symlink($victim, $index . '/commit.1.tmp')) {
            $this->markTestSkipped('symlink() is not available on this platform');
        }

        try {
            Manifest::initial()->next([])->write($index);
            $this->fail('Expected the symlink to be refused');
        } catch (StorageException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('DO NOT TRUNCATE', file_get_contents($victim));
    }

    #[Test]
    public function writing_a_commit_refuses_a_dangling_symlink(): void
    {
        $victim = $this->dir . '/not-yet-created.php';
        $index  = $this->dir . '/index';

        mkdir($index, 0755, true);

        if (!@symlink($victim, $index . '/commit.1.tmp')) {
            $this->markTestSkipped('symlink() is not available on this platform');
        }

        try {
            Manifest::initial()->next([])->write($index);
            $this->fail('Expected the dangling symlink to be refused');
        } catch (StorageException $e) {
            $this->addToAssertionCount(1);
        }

        // A dangling link is the dangerous case: following it *creates* the
        // target, so a file the attacker names appears with content the
        // process wrote.
        $this->assertFileDoesNotExist($victim, 'the symlink target must not have been created');
    }

    #[Test]
    public function a_commit_that_is_a_symlink_is_not_trusted(): void
    {
        $victim = $this->dir . '/victim.txt';
        $index  = $this->dir . '/index';

        mkdir($index, 0755, true);
        file_put_contents($victim, 'DO NOT READ THROUGH ME');

        if (!@symlink($victim, $index . '/commit.1')) {
            $this->markTestSkipped('symlink() is not available on this platform');
        }

        // Reading falls back to the previous commit when one cannot be
        // trusted, and a symlinked commit cannot: the index opens empty rather
        // than opening whatever the link pointed at.
        $engine = SearchEngine::open($index);

        $this->assertSame(0, $engine->count());
        $this->assertSame('DO NOT READ THROUGH ME', file_get_contents($victim));
    }

    // =========================================================================

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::removeDir($path);
            }
        }

        @rmdir($dir);
    }
}
