<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests\Index;

use Ols\PhpFts\Index\SegmentIndex;
use Ols\PhpFts\Index\SegmentIndexWriter;
use Ols\PhpFts\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fast walk and the obvious walk must answer identically.
 *
 * A query whose threshold makes one slot mandatory is not walked by reading
 * every posting list: the mandatory slot is read once and the others are jumped
 * through with `advance()`, which bisects a skip table instead of decoding
 * every gap. That is a large saving and it is only sound because a document
 * lacking a mandatory slot provably cannot reach the threshold.
 *
 * "Provably" is doing a lot of work in that sentence, and nothing else in the
 * suite would notice if it stopped being true — the results would simply get
 * quietly worse. Hence this file: the same query, asked both ways, compared on
 * the match set, the exact scores and the order.
 *
 * The corpus is shaped so the optimisation actually engages: one rare word to
 * be the driver, one word in nearly every document to be the list worth
 * skipping, and documents that hold various combinations so that a wrong
 * threshold shows up as a difference rather than as an empty result.
 */
class DrivenWalkTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/fts_driven_' . uniqid();
        $this->path = $this->dir . '/seg.fts';

        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    private function index(): SegmentIndex
    {
        $schema = Schema::make()
            ->text('name', boost: 3.0)
            ->text('description');

        $writer = new SegmentIndexWriter(null, $schema);

        // `couteau` in almost everything, `cuisine` in a few, `damas` in one.
        // Enough documents to cross a posting block, so the skip table is real
        // rather than a single-block special case.
        for ($i = 0; $i < 400; $i++) {
            $name        = 'couteau ' . $i;
            $description = 'un couteau parmi beaucoup de couteaux';

            if ($i % 40 === 0) {
                $name        = 'couteau de cuisine ' . $i;
                $description = 'couteau de cuisine, cuisine professionnelle';
            }

            if ($i === 120) {
                $name        = 'couteau damas cuisine';
                $description = 'couteau de cuisine en acier damas, damas forge';
            }

            $writer->put('sku-' . $i, ['name' => $name, 'description' => $description]);
        }

        $writer->write($this->path);

        return SegmentIndex::open($this->path);
    }

    #[Test]
    public function the_driven_walk_answers_exactly_what_the_full_walk_answers(): void
    {
        $index = $this->index();

        $queries = [
            'couteau cuisine',           // one rare slot, one nearly universal
            'couteau de cuisine',        // plus a slot worth almost nothing
            'damas cuisine',             // both rare
            'couteau damas cuisine',     // three slots, one very rare
            'coutau cuisne',             // both misspelled, so both expand
            'couteau',                   // a single slot: no driver possible
            'cuisine introuvable',       // a word the index does not hold
            'couteau cuisine damas acier',
        ];

        foreach ($queries as $query) {
            $plan = $index->planFor($query);

            [$driven, $drivenScores] = $index->candidates($plan);
            [$full, $fullScores]     = $index->candidates($plan->withoutDriver());

            $this->assertSame(
                $full->toArray(),
                $driven->toArray(),
                "match set differs for \"$query\""
            );

            ksort($drivenScores);
            ksort($fullScores);

            $this->assertSame(
                array_keys($fullScores),
                array_keys($drivenScores),
                "scored documents differ for \"$query\""
            );

            foreach ($fullScores as $ordinal => $expected) {
                $this->assertEqualsWithDelta(
                    $expected,
                    $drivenScores[$ordinal],
                    1e-9,
                    "score differs for \"$query\" at document $ordinal"
                );
            }
        }
    }

    #[Test]
    public function the_driven_walk_is_actually_taken(): void
    {
        // Guards the test above from passing for the wrong reason. If the
        // optimisation stopped engaging — a threshold change, a slack
        // computed differently — every assertion there would still hold, and
        // would be comparing the slow walk against itself.
        $index = $this->index();
        $plan  = $index->planFor('couteau cuisine');

        $this->assertCount(2, $plan->slots);
        $this->assertGreaterThan(
            $plan->slack(),
            max($plan->slots[0]->idf, $plan->slots[1]->idf),
            'neither slot is mandatory, so this corpus no longer exercises the driver'
        );
    }
}
