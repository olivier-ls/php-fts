<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\DocumentStorage;
use Ols\PhpFts\Exception\FilterException;
use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regression tests for the issues fixed in 1.1.4.
 *
 * @see https://github.com/olivier-ls/php-fts/issues/1 stored XSS in highlights
 * @see https://github.com/olivier-ls/php-fts/issues/2 filter bypass by type juggling
 * @see https://github.com/olivier-ls/php-fts/issues/3 symlink following on open
 */
class SecurityTest extends TestCase
{
    private SearchEngine $engine;
    private string       $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fts_sec_' . uniqid();
        $this->engine = new SearchEngine();
        $this->engine->open($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->engine->close();
        self::removeDir($this->tmpDir);
    }

    // =========================================================================
    // Exception hierarchy — every throw used to resolve to a non-existent
    // Ols\PhpFts\RuntimeException and surface as a fatal Error instead.
    // =========================================================================

    #[Test]
    public function engine_errors_are_fts_exceptions(): void
    {
        $this->expectException(FtsException::class);

        (new SearchEngine())->count();
    }

    #[Test]
    public function fts_exceptions_remain_catchable_as_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);

        (new SearchEngine())->count();
    }

    #[Test]
    public function storage_errors_are_storage_exceptions(): void
    {
        $this->expectException(StorageException::class);

        (new DocumentStorage())->read(999_999_999);
    }

    // =========================================================================
    // Issue #1 — stored XSS via highlights
    // =========================================================================

    #[Test]
    public function highlights_escape_markup_coming_from_documents(): void
    {
        $this->engine->insert(['name' => '<img src=x onerror=alert(1)> hello world']);

        $results = $this->engine->search('hello', highlight: true);
        $html    = $results[0]['highlights']['name'];

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    #[Test]
    public function highlights_still_emit_their_own_tags(): void
    {
        $this->engine->insert(['name' => 'brown leather shoe']);

        $results = $this->engine->search('leather', highlight: true);

        $this->assertStringContainsString('<mark>', $results[0]['highlights']['name']);
    }

    #[Test]
    public function highlight_escaping_can_be_disabled_explicitly(): void
    {
        $this->engine->insert(['name' => '<b>bold</b> leather']);

        $results = $this->engine->search('leather', highlight: true, highlightOptions: [
            'escape' => false,
        ]);

        $this->assertStringContainsString('<b>bold</b>', $results[0]['highlights']['name']);
    }

    // =========================================================================
    // Issue #2 — filter bypass by PHP type juggling
    // =========================================================================

    #[Test]
    public function boolean_true_no_longer_matches_every_non_empty_string(): void
    {
        $this->engine->insert(['name' => 'sneaker one', 'category' => 'Sneakers']);
        $this->engine->insert(['name' => 'sneaker two', 'category' => 'Boots']);

        $bypass = $this->engine->search('sneaker', filters: ['and' => [
            ['field' => 'category', 'op' => 'in', 'value' => [true]],
        ]]);

        $this->assertCount(0, $bypass);
    }

    #[Test]
    public function equality_filter_rejects_cross_type_comparison(): void
    {
        $this->engine->insert(['name' => 'sneaker one', 'category' => 'Sneakers']);

        $results = $this->engine->search('sneaker', filters: ['and' => [
            ['field' => 'category', 'op' => '=', 'value' => true],
        ]]);

        $this->assertCount(0, $results);
    }

    #[Test]
    public function legitimate_filters_are_unaffected(): void
    {
        $this->engine->insert(['name' => 'sneaker one', 'category' => 'Sneakers']);
        $this->engine->insert(['name' => 'sneaker two', 'category' => 'Boots']);

        $results = $this->engine->search('sneaker', filters: ['and' => [
            ['field' => 'category', 'op' => 'in', 'value' => ['Sneakers']],
        ]]);

        $this->assertCount(1, $results);
        $this->assertSame('Sneakers', $results[0]['document']['category']);
    }

    #[Test]
    public function int_and_float_still_compare_numerically(): void
    {
        $this->engine->insert(['name' => 'pricey thing', 'price' => 130]);

        $matches = fn(mixed $value): int => count($this->engine->search('pricey', filters: ['and' => [
            ['field' => 'price', 'op' => '=', 'value' => $value],
        ]]));

        $this->assertSame(1, $matches(130));
        $this->assertSame(1, $matches(130.0));
        $this->assertSame(0, $matches('130'), 'a numeric string is a different type');
    }

    #[Test]
    public function numeric_comparison_rejects_non_numeric_expected_values(): void
    {
        $this->engine->insert(['name' => 'stocked thing', 'stock' => 5]);

        $results = $this->engine->search('stocked', filters: ['and' => [
            ['field' => 'stock', 'op' => '>', 'value' => true],
        ]]);

        $this->assertCount(0, $results);
    }

    #[Test]
    public function malformed_filter_raises_a_filter_exception(): void
    {
        $this->engine->insert(['name' => 'anything']);

        $this->expectException(FilterException::class);
        $this->expectExceptionMessageMatches("/missing the 'op' key/");

        $this->engine->search('anything', filters: ['and' => [
            ['field' => 'name'],
        ]]);
    }

    #[Test]
    public function unknown_operator_raises_a_filter_exception(): void
    {
        $this->engine->insert(['name' => 'anything']);

        $this->expectException(FilterException::class);

        $this->engine->search('anything', filters: ['and' => [
            ['field' => 'name', 'op' => 'sounds like', 'value' => 'x'],
        ]]);
    }

    // =========================================================================
    // Issue #3 — symlink following when opening index files
    // =========================================================================

    #[Test]
    public function opening_refuses_to_follow_a_symlink_to_an_existing_file(): void
    {
        $victim  = $this->tmpDir . '/victim.txt';
        $linkDir = $this->tmpDir . '/linked';

        file_put_contents($victim, 'DO NOT TRUNCATE');
        mkdir($linkDir);

        if (!@symlink($victim, $linkDir . '/documents.bin')) {
            $this->markTestSkipped('symlink() is not available on this platform');
        }

        try {
            (new SearchEngine())->open($linkDir);
            $this->fail('Expected the symlink to be refused');
        } catch (StorageException $e) {
            $this->assertMatchesRegularExpression('/symbolic link|does not resolve/i', $e->getMessage());
        }

        $this->assertSame('DO NOT TRUNCATE', file_get_contents($victim));
    }

    #[Test]
    public function opening_refuses_to_follow_a_dangling_symlink(): void
    {
        $victim  = $this->tmpDir . '/not-yet-created.php';
        $linkDir = $this->tmpDir . '/linked_dangling';

        mkdir($linkDir);

        if (!@symlink($victim, $linkDir . '/documents.bin')) {
            $this->markTestSkipped('symlink() is not available on this platform');
        }

        try {
            (new SearchEngine())->open($linkDir);
            $this->fail('Expected the dangling symlink to be refused');
        } catch (StorageException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertFileDoesNotExist($victim, 'the symlink target must not have been created');
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
