<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Exception\StorageException;
use Ols\PhpFts\Index\PostingsCursor;
use Ols\PhpFts\Index\PostingsFormat;
use Ols\PhpFts\Index\PostingsWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Posting lists: the sorted document numbers holding a term, stored as gaps
 * and cut into blocks so a query can jump instead of decoding everything.
 */
class PostingsTest extends TestCase
{
    /**
     * @param int[] $documents
     */
    private function cursor(array $documents): PostingsCursor
    {
        return PostingsCursor::open(PostingsWriter::encode($documents));
    }

    /**
     * @return int[]
     */
    private function drain(PostingsCursor $cursor): array
    {
        $documents = [];

        while ($cursor->current() !== PostingsFormat::END) {
            $documents[] = $cursor->current();
            $cursor->next();
        }

        return $documents;
    }

    // =========================================================================
    // Storing and reading back
    // =========================================================================

    #[Test]
    public function a_list_round_trips(): void
    {
        $documents = [4, 9, 11, 12, 40, 41, 900];

        $this->assertSame($documents, $this->drain($this->cursor($documents)));
    }

    #[Test]
    public function an_empty_list_is_immediately_exhausted(): void
    {
        $cursor = $this->cursor([]);

        $this->assertSame(0, $cursor->count());
        $this->assertSame(PostingsFormat::END, $cursor->current());
        $this->assertSame(PostingsFormat::END, $cursor->next());
    }

    #[Test]
    public function a_single_document_round_trips(): void
    {
        $cursor = $this->cursor([7]);

        $this->assertSame(1, $cursor->count());
        $this->assertSame(7, $cursor->current());
        $this->assertSame(PostingsFormat::END, $cursor->next());
    }

    #[Test]
    public function document_zero_is_a_valid_document(): void
    {
        // Local ordinals start at zero, so the first document in a segment must
        // survive being stored as an absolute value of 0.
        $this->assertSame([0, 1, 5], $this->drain($this->cursor([0, 1, 5])));
    }

    #[Test]
    public function a_list_of_exactly_one_block_has_no_skip_table(): void
    {
        $documents = range(0, PostingsFormat::BLOCK_SIZE - 1);
        $bytes     = PostingsWriter::encode($documents);

        $this->assertFalse(PostingsFormat::hasSkipTable(count($documents)));
        $this->assertSame($documents, $this->drain(PostingsCursor::open($bytes)));
    }

    #[Test]
    public function a_list_one_document_past_a_block_spans_two(): void
    {
        // The boundary most likely to be wrong.
        $documents = range(0, PostingsFormat::BLOCK_SIZE);

        $this->assertTrue(PostingsFormat::hasSkipTable(count($documents)));
        $this->assertSame(2, PostingsFormat::blockCount(count($documents)));
        $this->assertSame($documents, $this->drain($this->cursor($documents)));
    }

    #[Test]
    public function a_long_sparse_list_round_trips(): void
    {
        $documents = [];
        $current   = 0;

        for ($i = 0; $i < 5000; $i++) {
            $current    += random_int(1, 500);
            $documents[] = $current;
        }

        $this->assertSame($documents, $this->drain($this->cursor($documents)));
    }

    // =========================================================================
    // Jumping
    // =========================================================================

    #[Test]
    public function advance_lands_on_an_exact_match(): void
    {
        $cursor = $this->cursor([4, 9, 11, 12, 40]);

        $this->assertSame(11, $cursor->advance(11));
    }

    #[Test]
    public function advance_lands_on_the_next_document_after_a_gap(): void
    {
        $cursor = $this->cursor([4, 9, 11, 12, 40]);

        $this->assertSame(40, $cursor->advance(13));
    }

    #[Test]
    public function advance_past_the_end_exhausts_the_cursor(): void
    {
        $cursor = $this->cursor([4, 9, 11]);

        $this->assertSame(PostingsFormat::END, $cursor->advance(12));
        $this->assertSame(PostingsFormat::END, $cursor->current());
    }

    #[Test]
    public function advance_never_moves_backwards(): void
    {
        // Relied upon by intersection, which pushes several cursors towards
        // each other in a loop: a cursor that could retreat would let the loop
        // oscillate forever.
        $cursor = $this->cursor([4, 9, 11, 12, 40]);

        $cursor->advance(12);
        $this->assertSame(12, $cursor->advance(5));
        $this->assertSame(12, $cursor->advance(12));
    }

    #[Test]
    public function advance_crosses_many_blocks(): void
    {
        $documents = [];

        for ($i = 0; $i < 10000; $i++) {
            $documents[] = $i * 7;      // predictable, so targets can be computed
        }

        $cursor = $this->cursor($documents);

        $this->assertSame(63000, $cursor->advance(63000), 'exact hit far into the list');
        $this->assertSame(700, $this->cursor($documents)->advance(695), 'landing just after a gap');
        $this->assertSame(69993, $this->cursor($documents)->advance(69993), 'the very last document');
        $this->assertSame(
            PostingsFormat::END,
            $this->cursor($documents)->advance(69994),
            'one past the last document'
        );
    }

    #[Test]
    public function advance_reaches_every_document_one_by_one(): void
    {
        // Pushes advance() through every block boundary in both the skip path
        // and the scan path.
        $documents = [];

        for ($i = 0; $i < 1000; $i++) {
            $documents[] = $i * 3;
        }

        $cursor = $this->cursor($documents);

        foreach ($documents as $document) {
            $this->assertSame($document, $cursor->advance($document));
        }
    }

    // =========================================================================
    // Intersection — what the skip table exists for
    // =========================================================================

    /**
     * @param PostingsCursor[] $cursors
     * @return int[]
     */
    private function intersect(array $cursors): array
    {
        $hits = [];

        while (true) {
            $target = 0;

            foreach ($cursors as $cursor) {
                if ($cursor->current() === PostingsFormat::END) {
                    return $hits;
                }

                $target = max($target, $cursor->current());
            }

            $agreed = true;

            foreach ($cursors as $cursor) {
                if ($cursor->advance($target) !== $target) {
                    $agreed = false;
                    break;
                }
            }

            if ($agreed) {
                $hits[] = $target;

                foreach ($cursors as $cursor) {
                    $cursor->next();
                }
            }
        }
    }

    #[Test]
    public function intersection_finds_the_documents_common_to_every_list(): void
    {
        $hits = $this->intersect([
            $this->cursor([1, 3, 5, 7, 9, 11]),
            $this->cursor([3, 4, 5, 9, 10]),
            $this->cursor([0, 3, 5, 8, 9]),
        ]);

        $this->assertSame([3, 5, 9], $hits);
    }

    #[Test]
    public function intersection_of_disjoint_lists_is_empty(): void
    {
        $this->assertSame([], $this->intersect([
            $this->cursor([1, 3, 5]),
            $this->cursor([2, 4, 6]),
        ]));
    }

    /**
     * The regression test for v1's silent recall bug.
     *
     * v1 could not jump, so it capped each list at maxCandidates (5 000) and —
     * worse — read that cap from the *end* of the list. A term appearing in
     * more than 5 000 documents therefore only ever offered its most recently
     * indexed ones, and a genuine match sitting early in the corpus was dropped
     * from the intersection without any error being raised.
     *
     * Here the common term appears in 20 000 documents and the answer is
     * document 12, right at the start. It must be found.
     */
    #[Test]
    public function an_early_document_survives_intersection_with_a_very_common_term(): void
    {
        $common = [];

        for ($i = 0; $i < 60000; $i += 3) {
            $common[] = $i;           // 20 000 documents, far past v1's cap
        }

        // 12 and 59997 are multiples of three and therefore in the common list;
        // 41338 is not, so it must be filtered out.
        $rare = [12, 41338, 59997];

        $this->assertGreaterThan(5000, count($common), 'the common list must exceed v1s cap');

        $hits = $this->intersect([
            PostingsCursor::open(PostingsWriter::encode($common)),
            PostingsCursor::open(PostingsWriter::encode($rare)),
        ]);

        $this->assertContains(12, $hits, 'a match at the very start of the corpus must survive');
        $this->assertSame([12, 59997], $hits);
    }

    // =========================================================================
    // What it costs
    // =========================================================================

    #[Test]
    public function gaps_and_varints_beat_fixed_width(): void
    {
        $documents = [];
        $current   = 0;

        for ($i = 0; $i < 20000; $i++) {
            $current    += random_int(1, 6);   // a term in roughly one document out of three
            $documents[] = $current;
        }

        $encoded = PostingsWriter::encode($documents);

        $this->assertLessThan(
            4 * count($documents) / 3,
            strlen($encoded),
            'a dense list should come to well under a third of uint32, skip table included'
        );

        $this->assertSame($documents, $this->drain(PostingsCursor::open($encoded)));
    }

    // =========================================================================
    // What it refuses
    // =========================================================================

    #[Test]
    public function documents_must_ascend(): void
    {
        $writer = new PostingsWriter();
        $writer->add(10);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/ascend/i');

        $writer->add(9);
    }

    #[Test]
    public function the_same_document_cannot_be_added_twice(): void
    {
        $writer = new PostingsWriter();
        $writer->add(10);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/without repetition/i');

        $writer->add(10);
    }

    #[Test]
    public function negative_documents_are_refused(): void
    {
        $writer = new PostingsWriter();

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/negative/i');

        $writer->add(-1);
    }

    #[Test]
    public function finishing_twice_is_refused(): void
    {
        $writer = new PostingsWriter();
        $writer->add(1);
        $writer->finish();

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/already finished/i');

        $writer->finish();
    }
}
