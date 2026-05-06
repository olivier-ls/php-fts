<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests d'intégration pour SearchEngine.
 *
 * Note d'architecture : SearchEngine instancie ses dépendances en dur dans le
 * constructeur (pas d'injection). Les tests sont donc nécessairement des tests
 * d'intégration avec de vrais fichiers — notamment trigrams.bin (~810KB).
 *
 * Pour éviter de recréer ce fichier à chaque test, on partage une seule
 * instance via setUpBeforeClass() / tearDownAfterClass(). Les tests qui
 * ont besoin d'un état propre utilisent reset() plutôt qu'un nouveau open().
 */
class SearchEngineTest extends TestCase
{
    private static SearchEngine $engine;
    private static string       $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/se_test_' . uniqid();
        self::$engine = new SearchEngine();
        self::$engine->open(self::$tmpDir);
    }

    public static function tearDownAfterClass(): void
    {
        self::$engine->close();
        self::removeDir(self::$tmpDir);
    }

    protected function setUp(): void
    {
        // Repart d'un index vide avant chaque test
        self::$engine->reset();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function removeDir(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = "$path/$entry";
            is_dir($full) ? self::removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    // =========================================================================
    // open()
    // =========================================================================

    #[Test]
    public function open_creates_expected_binary_files(): void
    {
        foreach (['documents.bin', 'tombstones.bin', 'postings.bin', 'trigrams.bin'] as $file) {
            $this->assertFileExists(self::$tmpDir . '/' . $file);
        }
    }

    #[Test]
    public function open_throws_when_not_open(): void
    {
        $engine = new SearchEngine();

        $this->expectException(RuntimeException::class);
        $engine->insert(['name' => 'test']);
    }

    // =========================================================================
    // insert() + search()
    // =========================================================================

    #[Test]
    public function insert_returns_an_integer_doc_id(): void
    {
        $docId = self::$engine->insert(['name' => 'chaussure cuir noir']);

        $this->assertIsInt($docId);
    }

    #[Test]
    public function search_finds_inserted_document(): void
    {
        self::$engine->insert(['name' => 'chaussure cuir noir']);

        $results = self::$engine->search('chaussure');

        $this->assertNotEmpty($results);
        $this->assertSame('chaussure cuir noir', $results[0]['document']['name']);
    }

    #[Test]
    public function search_returns_empty_on_no_match(): void
    {
        self::$engine->insert(['name' => 'chaussure cuir']);

        $results = self::$engine->search('vélo');

        $this->assertSame([], $results);
    }

    #[Test]
    public function search_returns_empty_on_blank_query(): void
    {
        self::$engine->insert(['name' => 'chaussure']);

        $this->assertSame([], self::$engine->search(''));
        $this->assertSame([], self::$engine->search('!!!'));
    }

    #[Test]
    public function search_result_contains_doc_id_score_and_document(): void
    {
        self::$engine->insert(['name' => 'sac cuir']);

        $result = self::$engine->search('sac')[0];

        $this->assertArrayHasKey('docId',    $result);
        $this->assertArrayHasKey('score',    $result);
        $this->assertArrayHasKey('document', $result);
        $this->assertIsFloat($result['score']);
    }

    #[Test]
    public function search_score_is_between_0_and_100(): void
    {
        self::$engine->insert(['name' => 'veste cuir marron']);

        $score = self::$engine->search('cuir')[0]['score'];

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    #[Test]
    public function search_respects_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            self::$engine->insert(['name' => "produit numero $i"]);
        }

        $results = self::$engine->search('produit', limit: 3);

        $this->assertCount(3, $results);
    }

    #[Test]
    public function insert_bulk_inserts_all_documents(): void
    {
        $docIds = self::$engine->insertBulk([
            ['name' => 'alpha'],
            ['name' => 'beta'],
            ['name' => 'gamma'],
        ]);

        $this->assertCount(3, $docIds);
        $this->assertNotEmpty(self::$engine->search('alpha'));
        $this->assertNotEmpty(self::$engine->search('beta'));
    }

    // =========================================================================
    // delete()
    // =========================================================================

    #[Test]
    public function delete_removes_document_from_search_results(): void
    {
        $docId = self::$engine->insert(['name' => 'bottine cuir']);

        self::$engine->delete($docId);

        $this->assertSame([], self::$engine->search('bottine'));
    }

    #[Test]
    public function delete_does_not_affect_other_documents(): void
    {
        $docId = self::$engine->insert(['name' => 'ceinture cuir']);
        self::$engine->insert(['name' => 'portefeuille cuir']);

        self::$engine->delete($docId);

        $this->assertNotEmpty(self::$engine->search('portefeuille'));
    }

    // =========================================================================
    // update()
    // =========================================================================

    #[Test]
    public function update_makes_old_content_unsearchable(): void
    {
        $docId = self::$engine->insert(['name' => 'manteau laine']);

        self::$engine->update($docId, ['name' => 'manteau cuir']);

        $this->assertSame([], self::$engine->search('laine'));
    }

    #[Test]
    public function update_makes_new_content_searchable(): void
    {
        $docId = self::$engine->insert(['name' => 'manteau laine']);

        self::$engine->update($docId, ['name' => 'manteau cuir']);

        $this->assertNotEmpty(self::$engine->search('cuir'));
    }

    // =========================================================================
    // count() + fragmentationRate()
    // =========================================================================

    #[Test]
    public function count_reflects_inserts_and_deletes(): void
    {
        self::$engine->insert(['name' => 'a']);
        self::$engine->insert(['name' => 'b']);
        $docId = self::$engine->insert(['name' => 'c']);

        self::$engine->delete($docId);

        $this->assertSame(2, self::$engine->count());
    }

    #[Test]
    public function fragmentation_rate_is_zero_with_no_deletions(): void
    {
        self::$engine->insert(['name' => 'x']);

        $this->assertSame(0, self::$engine->fragmentationRate());
    }

    #[Test]
    public function fragmentation_rate_is_100_when_all_deleted(): void
    {
        $id1 = self::$engine->insert(['name' => 'x']);
        $id2 = self::$engine->insert(['name' => 'y']);
        self::$engine->delete($id1);
        self::$engine->delete($id2);

        $this->assertSame(100, self::$engine->fragmentationRate());
    }

    // =========================================================================
    // search() avec filtres
    // =========================================================================

    #[Test]
    public function search_filter_equals_keeps_matching_documents(): void
    {
        self::$engine->insert(['name' => 'sac', 'active' => true]);
        self::$engine->insert(['name' => 'sac', 'active' => false]);

        $results = self::$engine->search('sac', filters: [
            'and' => [['field' => 'active', 'op' => '=', 'value' => true]],
        ]);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['document']['active']);
    }

    #[Test]
    public function search_filter_range_keeps_matching_documents(): void
    {
        self::$engine->insert(['name' => 'article', 'price' => 80]);
        self::$engine->insert(['name' => 'article', 'price' => 200]);

        $results = self::$engine->search('article', filters: [
            'and' => [
                ['field' => 'price', 'op' => '>=', 'value' => 50],
                ['field' => 'price', 'op' => '<=', 'value' => 100],
            ],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(80, $results[0]['document']['price']);
    }

    #[Test]
    public function search_filter_in_keeps_matching_documents(): void
    {
        self::$engine->insert(['name' => 'article', 'category' => 'Chaussures']);
        self::$engine->insert(['name' => 'article', 'category' => 'Vêtements']);

        $results = self::$engine->search('article', filters: [
            'and' => [['field' => 'category', 'op' => 'in', 'value' => ['Chaussures', 'Sport']]],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('Chaussures', $results[0]['document']['category']);
    }

    #[Test]
    public function search_filter_rejects_document_with_missing_field(): void
    {
        self::$engine->insert(['name' => 'article']); // pas de champ 'active'

        $results = self::$engine->search('article', filters: [
            'and' => [['field' => 'active', 'op' => '=', 'value' => true]],
        ]);

        $this->assertSame([], $results);
    }

    #[Test]
    public function search_filter_or_keeps_at_least_one_match(): void
    {
        self::$engine->insert(['name' => 'article', 'brand' => 'Adidas']);
        self::$engine->insert(['name' => 'article', 'brand' => 'Nike']);
        self::$engine->insert(['name' => 'article', 'brand' => 'Puma']);

        $results = self::$engine->search('article', filters: [
            'or' => [
                ['field' => 'brand', 'op' => '=', 'value' => 'Adidas'],
                ['field' => 'brand', 'op' => '=', 'value' => 'Puma'],
            ],
        ]);

        $brands = array_column(array_column($results, 'document'), 'brand');
        sort($brands);

        $this->assertSame(['Adidas', 'Puma'], $brands);
    }

    // =========================================================================
    // compact()
    // =========================================================================

    #[Test]
    public function compact_preserves_non_deleted_documents(): void
    {
        self::$engine->insert(['name' => 'survie apres compaction']);
        $toDelete = self::$engine->insert(['name' => 'a supprimer']);
        self::$engine->delete($toDelete);

        self::$engine->compact();

        $this->assertNotEmpty(self::$engine->search('survie'));
    }

    #[Test]
    public function compact_removes_deleted_documents_from_results(): void
    {
        $toDelete = self::$engine->insert(['name' => 'disparu apres compaction']);
        self::$engine->delete($toDelete);

        self::$engine->compact();

        $this->assertSame([], self::$engine->search('disparu'));
    }

    #[Test]
    public function compact_resets_fragmentation_rate_to_zero(): void
    {
        $id = self::$engine->insert(['name' => 'test']);
        self::$engine->delete($id);

        self::$engine->compact();

        $this->assertSame(0, self::$engine->fragmentationRate());
    }
}
