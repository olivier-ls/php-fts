<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\HighlightException;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Index\IndexDirectory;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Highlighting through a real index, which is where two things can go wrong
 * that a unit test cannot see:
 *
 *   1. The hits of one page can come from several segments, each with its own
 *      analyzer and its own schema.
 *   2. The index stores the document, and a highlight is built from what came
 *      back out of the docstore — not from what the caller handed in.
 */
class HighlightingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fts_hl_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/.lock');
        @rmdir($this->dir);
    }

    private function catalogue(?Schema $schema = null): IndexDirectory
    {
        $index = IndexDirectory::open($this->dir, $schema ?? Schema::make()
            ->text('title', boost: 3.0)
            ->text('description')
            ->keyword('brand'));

        // Two commits, so the page below is assembled from two segments.
        $index->putMany([
            'a' => ['title' => 'Chaussure en cuir', 'description' => 'Cuir pleine fleur, cousu main', 'brand' => 'Nike'],
            'b' => ['title' => 'Botte en cuir',     'description' => 'Doublure chaude',              'brand' => 'Nike'],
        ]);
        $index->putMany([
            'c' => ['title' => '革靴 ブラウン',      'description' => '日本製の革靴です',              'brand' => 'Adidas'],
            'd' => ['title' => 'Basket en cuir',    'description' => 'Toile de coton',               'brand' => 'Adidas'],
        ]);

        return $index;
    }

    #[Test]
    public function a_hit_carries_the_highlights_of_the_fields_that_matched(): void
    {
        $result = $this->catalogue()->search('cuir', highlight: ['title', 'description']);

        $this->assertSame(3, $result->total);

        $highlights = [];

        foreach ($result as $hit) {
            $highlights[$hit->id] = $hit->highlights;
        }

        $this->assertSame([
            'title'       => 'Chaussure en <mark>cuir</mark>',
            'description' => '<mark>Cuir</mark> pleine fleur, cousu main',
        ], $highlights['a']);

        // Only the fields that actually matched: b's description says nothing
        // about leather, and is absent rather than present and unmarked.
        $this->assertSame(['title' => 'Botte en <mark>cuir</mark>'], $highlights['b']);
    }

    #[Test]
    public function hits_from_different_segments_are_all_highlighted(): void
    {
        // 'a' and 'b' were committed together, 'd' separately: one highlighter
        // per contributing segment, each holding that segment's analyzer.
        $result = $this->catalogue()->search('cuir', highlight: ['title']);

        $found = [];

        foreach ($result as $hit) {
            $found[$hit->id] = $hit->highlights['title'] ?? null;
        }

        $this->assertSame('Chaussure en <mark>cuir</mark>', $found['a']);
        $this->assertSame('Botte en <mark>cuir</mark>', $found['b']);
        $this->assertSame('Basket en <mark>cuir</mark>', $found['d']);
    }

    #[Test]
    public function japanese_is_highlighted_through_the_index_too(): void
    {
        $result = $this->catalogue()->search('革靴', highlight: ['title', 'description']);

        $this->assertSame(1, $result->total);
        $this->assertSame([
            'title'       => '<mark>革靴</mark> ブラウン',
            'description' => '日本製の<mark>革靴</mark>です',
        ], $result->hits[0]->highlights);
    }

    #[Test]
    public function no_highlighting_is_asked_for_by_default(): void
    {
        foreach ($this->catalogue()->search('cuir') as $hit) {
            $this->assertSame([], $hit->highlights);
        }
    }

    #[Test]
    public function an_empty_query_highlights_nothing(): void
    {
        // Everything matches, and nothing in particular is why.
        $result = $this->catalogue()->search('', highlight: ['title']);

        $this->assertSame(4, $result->total);

        foreach ($result as $hit) {
            $this->assertSame([], $hit->highlights);
        }
    }

    #[Test]
    public function options_survive_the_trip_through_the_index(): void
    {
        $result = $this->catalogue()->search(
            'cuir',
            highlight: Highlight::fields(['description'])->tags('<em>', '</em>')->excerpt(12),
        );

        foreach ($result as $hit) {
            if ($hit->id === 'a') {
                $this->assertSame('<em>Cuir</em> pleine fleur,…', $hit->highlights['description']);
            }
        }
    }

    #[Test]
    public function positions_come_back_through_a_hit_too(): void
    {
        $result = $this->catalogue()->search('cuir', highlight: Highlight::fields(['title'])->positions());

        foreach ($result as $hit) {
            if ($hit->id !== 'a') {
                continue;
            }

            $this->assertSame(
                ['text' => 'Chaussure en cuir', 'spans' => [[13, 17]]],
                $hit->highlights['title']
            );
        }
    }

    #[Test]
    public function highlighting_survives_a_merge(): void
    {
        $index = $this->catalogue();
        $index->optimize();

        $result = $index->search('cuir', highlight: ['title']);
        $marked = [];

        foreach ($result as $hit) {
            $marked[$hit->id] = $hit->highlights['title'];
        }

        // One segment now, and the same three highlights as before it.
        $this->assertSame(3, $result->total);
        $this->assertSame('Chaussure en <mark>cuir</mark>', $marked['a']);
        $this->assertSame('Basket en <mark>cuir</mark>', $marked['d']);
    }

    #[Test]
    public function a_field_the_schema_does_not_store_is_refused_before_anything_is_read(): void
    {
        $index = $this->catalogue(Schema::make()
            ->text('title')
            ->text('description')
            ->keyword('brand')
            ->source(['title', 'brand']));

        $this->expectException(HighlightException::class);
        $this->expectExceptionMessage("Cannot highlight 'description'");

        $index->search('cuir', highlight: ['description']);
    }

    #[Test]
    public function a_refusal_does_not_wait_for_a_matching_document(): void
    {
        $index = $this->catalogue(Schema::make()->text('title')->source(false));

        $this->expectException(HighlightException::class);

        // Nothing matches, and the field is still refused: a highlight that
        // cannot ever work should not depend on the query to be reported.
        $index->search('sandale-qui-nexiste-pas', highlight: ['title']);
    }
}
