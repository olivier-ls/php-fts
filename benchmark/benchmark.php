<?php

declare(strict_types=1);

/*
 * Measures php-fts against a real catalogue.
 *
 *     php benchmark/extract.php --database=shop --port=3307   # once
 *     php benchmark/benchmark.php --phase=all
 *
 * Phases run independently, because the indexing ones take minutes and the
 * query ones take seconds:
 *
 *     --phase=index    time, throughput, memory and size, by catalogue size
 *     --phase=source   what source() actually saves, on real text
 *     --phase=search   query latency, p50 and p95, by kind of query
 *     --phase=relevance  whether the answers are *right*, which no timing says
 *     --phase=cold     open() + one search in a *fresh process*, which is the
 *                      only number that describes a real request
 *     --phase=merge    what an automatic merge costs at this scale
 *
 * ── Why the corpus is not in this repository ────────────────────────────────
 *
 * Because generated data would measure the wrong thing. Almost everything in
 * this engine scales with real vocabulary: the size of the term dictionary,
 * how well front-coding compresses it, the gaps between ordinals in a posting
 * list, the ordinal width of a keyword column, and whether the schema's
 * inference calls a field a keyword or prose. A corpus of sixteen invented
 * words makes all of that look free.
 *
 * So the numbers below come from a production catalogue — 45 000 products,
 * French, 839 characters of description each, 99% of them carrying HTML — and
 * `extract.php` is committed so the measurement can be repeated against any
 * shop. The data itself belongs to the shop it came from and stays there.
 */

require __DIR__ . '/autoload.php';

use Ols\PhpFts\Analysis\Analyzer;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\Schema;
use Ols\PhpFts\SearchEngine;
use Ols\PhpFts\Sort;

// ---------------------------------------------------------------------------
//  Options
// ---------------------------------------------------------------------------

$options = getopt('', ['phase::', 'corpus::', 'scales::', 'batch::', 'runs::', 'dir::']);

$phase  = $options['phase']  ?? 'all';
$corpus = $options['corpus'] ?? __DIR__ . '/corpus.jsonl';
$batch  = (int) ($options['batch'] ?? 2000);
$runs   = (int) ($options['runs'] ?? 30);
$root   = rtrim($options['dir'] ?? __DIR__, '/\\');

$scales = array_map('intval', explode(',', (string) ($options['scales'] ?? '1000,5000,10000,45000')));

if (!is_file($corpus)) {
    fwrite(STDERR, "No corpus at $corpus — run benchmark/extract.php first.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
//  The catalogue, as php-fts sees it
// ---------------------------------------------------------------------------

/**
 * The schema the benchmark declares.
 *
 * Declared rather than inferred, because that is what a shop would do — and
 * because the inference is measured separately, where getting it wrong is
 * interesting rather than a distortion.
 */
function schema(array|false|null $source = null): Schema
{
    $schema = Schema::make()
        ->text('name', boost: 3.0)
        ->text('description')
        ->text('model', boost: 2.0)      // people search a reference
        ->keyword('brand')
        ->tags('categories')
        ->number('price')
        ->number('weight')
        ->number('stock')
        ->boolean('active')
        ->boolean('visible')
        ->stored('image');               // returned, never indexed

    return $source === null ? $schema : $schema->source($source);
}

/**
 * Streams the first $limit products, keyed by the shop's own id.
 *
 * A generator over fgets(), so the corpus is never held in memory — 50 MB of
 * JSON would otherwise be the largest thing in the process.
 *
 * @return Generator<string, array<string, mixed>>
 */
function corpus(string $path, int $limit): Generator
{
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException("cannot read $path");
    }

    try {
        $read = 0;

        while ($read < $limit && ($line = fgets($handle)) !== false) {
            $product = json_decode($line, true);

            if (!is_array($product)) {
                continue;
            }

            $id = (string) $product['id'];
            unset($product['id']);

            $read++;

            yield $id => $product;
        }
    } finally {
        fclose($handle);
    }
}

// ---------------------------------------------------------------------------
//  Plumbing
// ---------------------------------------------------------------------------

function reset_peak(): void
{
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
}

function peak_mb(): float
{
    return memory_get_peak_usage(true) / 1048576;
}

function wipe(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($dir . '/.lock');
    @rmdir($dir);
}

/**
 * The size of the index as it *is*, not of the directory.
 *
 * A merge retires its sources instead of unlinking them — a search that
 * started a moment earlier is still reading them — so for the length of the
 * grace period the directory holds both the old segments and the new one.
 * Summing files would report a freshly merged index as twice its size. Which
 * files are live is a question only the newest commit answers.
 */
function size_mb(string $dir): float
{
    $generations = [];

    foreach (glob($dir . '/commit.*') ?: [] as $path) {
        $generations[] = (int) substr($path, strrpos($path, '.') + 1);
    }

    if ($generations === []) {
        return 0.0;
    }

    rsort($generations);

    $commit   = $dir . '/commit.' . $generations[0];
    $manifest = json_decode((string) file_get_contents($commit), true);
    $bytes    = (int) filesize($commit);

    foreach ($manifest['segments'] ?? [] as $segment) {
        $bytes += (int) @filesize($dir . '/' . $segment['name'] . '.fts');
    }

    return $bytes / 1048576;
}

/**
 * @param float[] $samples milliseconds
 * @return array{p50: float, p95: float, min: float, max: float, mean: float}
 */
function distribution(array $samples): array
{
    sort($samples);

    $count = count($samples);

    return [
        'min'  => $samples[0],
        'p50'  => $samples[(int) floor($count * 0.50)],
        'p95'  => $samples[min($count - 1, (int) floor($count * 0.95))],
        'max'  => $samples[$count - 1],
        'mean' => array_sum($samples) / $count,
    ];
}

function heading(string $title): void
{
    printf("\n%s\n%s\n", $title, str_repeat('─', mb_strlen($title)));
}

/**
 * Indexes $limit products in commits of $batch, and reports what it cost.
 *
 * @return array<string, mixed>
 */
function build(string $dir, string $corpus, int $limit, int $batch, Schema $schema): array
{
    wipe($dir);
    reset_peak();

    $engine  = SearchEngine::open($dir, $schema);
    $started = hrtime(true);
    $chars   = 0;
    $pending = [];
    $commits = 0;

    foreach (corpus($corpus, $limit) as $id => $product) {
        $chars += mb_strlen($product['name']) + mb_strlen($product['description']);

        $pending[$id] = $product;

        if (count($pending) >= $batch) {
            $engine->putMany($pending);
            $pending = [];
            $commits++;
        }
    }

    if ($pending !== []) {
        $engine->putMany($pending);
        $commits++;
    }

    $elapsed = (hrtime(true) - $started) / 1e9;
    $stats   = $engine->stats();

    return [
        'documents' => $stats['documents'],
        'seconds'   => $elapsed,
        'per_second' => $stats['documents'] / max($elapsed, 0.001),
        'text_mb'   => $chars / 1048576,
        'text_mb_s' => ($chars / 1048576) / max($elapsed, 0.001),
        'peak_mb'   => peak_mb(),
        'size_mb'   => size_mb($dir),
        'segments'  => $stats['segments'],
        'commits'   => $commits,
        'bytes_doc' => $stats['documents'] > 0 ? (size_mb($dir) * 1048576) / $stats['documents'] : 0,
    ];
}

// ---------------------------------------------------------------------------
//  Phase: indexing, by catalogue size
// ---------------------------------------------------------------------------

if ($phase === 'index' || $phase === 'all') {
    heading('Indexing — declared schema, commits of ' . number_format($batch));

    printf("%9s  %8s  %9s  %9s  %8s  %8s  %6s  %9s\n",
        'documents', 'seconds', 'docs/s', 'text MB/s', 'peak MB', 'size MB', 'segs', 'bytes/doc');

    foreach ($scales as $scale) {
        $result = build($root . '/bench_index', $corpus, $scale, $batch, schema());

        printf("%9s  %8.1f  %9.0f  %9.2f  %8.1f  %8.1f  %6d  %9.0f\n",
            number_format($result['documents']),
            $result['seconds'],
            $result['per_second'],
            $result['text_mb_s'],
            $result['peak_mb'],
            $result['size_mb'],
            $result['segments'],
            $result['bytes_doc'],
        );
    }

    wipe($root . '/bench_index');
}

// ---------------------------------------------------------------------------
//  Phase: what source() saves
// ---------------------------------------------------------------------------

if ($phase === 'source' || $phase === 'all') {
    $scale = max($scales);

    heading('Storage — ' . number_format($scale) . ' products, what source() removes');

    printf("%-34s  %8s  %9s  %8s\n", 'configuration', 'size MB', 'bytes/doc', 'vs full');

    $baseline = null;

    $configurations = [
        'everything stored (default)' => null,
        "source(['name','price'])"    => ['name', 'price'],
        'source(false) — ids only'    => false,
    ];

    foreach ($configurations as $label => $source) {
        $result   = build($root . '/bench_source', $corpus, $scale, $batch, schema($source));
        $baseline ??= $result['size_mb'];

        printf("%-34s  %8.1f  %9.0f  %7.0f%%\n",
            $label,
            $result['size_mb'],
            $result['bytes_doc'],
            100 * $result['size_mb'] / max($baseline, 0.01),
        );
    }

    wipe($root . '/bench_source');
}

// ---------------------------------------------------------------------------
//  Phase: query latency
// ---------------------------------------------------------------------------

if ($phase === 'search' || $phase === 'all') {
    $scale = max($scales);
    $dir   = $root . '/bench_search';

    heading('Search — ' . number_format($scale) . ' products, ' . $runs . ' runs each');

    if (!is_dir($dir) || size_mb($dir) === 0.0) {
        printf("building the index first…\n");
        build($dir, $corpus, $scale, $batch, schema());
    }

    // Query terms taken from the corpus itself rather than chosen by hand: the
    // most frequent words of the product names, which is what a shopper types.
    $frequencies = [];
    $brands      = [];

    // ── And, separately, the words with the longest posting lists ─────────
    //
    // Counted once per product across the whole document rather than once per
    // occurrence in its name, because that is document frequency, and document
    // frequency is what a posting list's length *is*.
    //
    // The two are not the same question and the difference is the point. The
    // frequent words of a product *name* are brands — `steel`, `cold`,
    // `maxpedition` — and a brand is discriminating, so a query made of them
    // has a rare slot to anchor on and the driver does its job: four of them
    // return 26 matches in 98 ms. The pathological shape is the opposite,
    // several words each sitting in a large share of the catalogue, where
    // slack() is a fixed fraction of a total that grows with every word and no
    // single slot ever clears it. The walk is then fully unanchored, and that
    // is the case the CHANGELOG records at 318.6 ms for three words and 432.0
    // for four — measured with hand-picked French words, which is why nothing
    // derived here reproduced it.
    $documentFrequencies = [];
    $analyzer            = new Analyzer();

    foreach (corpus($corpus, min($scale, 5000)) as $product) {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($product['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 4) {
                $frequencies[$word] = ($frequencies[$word] ?? 0) + 1;
            }
        }

        // Through the engine's own analyzer, and not a regex of my own.
        //
        // A regex over strip_tags() was tried first and measured nothing: the
        // descriptions are 99% HTML and `strip_tags()` does not decode
        // entities, so the widest "words" came back as `eacute` (70% of
        // products), `nbsp` (56%) and `agrave` (48%). Those are not terms —
        // Analyzer::plain() decodes entities before it looks at anything, so
        // `&eacute;` reaches the index as `é` and `eacute` reaches it never.
        // The queries built from them matched zero documents and timed an
        // empty walk.
        //
        // The words with the longest posting lists are by definition the words
        // the *analyzer* produces most often, so the analyzer is the only
        // honest way to ask. It costs a few seconds over the sample and
        // removes a whole class of way to be wrong.
        foreach (array_keys($analyzer->frequencies(
            $product['name'] . ' ' . (string) ($product['description'] ?? '')
        )) as $term) {
            // Four characters or more, so the edit budget is 1 rather than 0
            // and the query really does expand — a two-letter word matches
            // exactly and would measure something else.
            if (mb_strlen((string) $term) >= 4) {
                $documentFrequencies[(string) $term] = ($documentFrequencies[(string) $term] ?? 0) + 1;
            }
        }

        if (($product['brand'] ?? null) !== null) {
            $brands[$product['brand']] = ($brands[$product['brand']] ?? 0) + 1;
        }
    }

    arsort($frequencies);
    arsort($brands);
    arsort($documentFrequencies);

    $widest = array_keys($documentFrequencies);
    $sample = min($scale, 5000);

    // The commonest brand, so the disjunctive case measures a facet that has
    // something to count rather than an empty intersection.
    $brand = array_key_first($brands) ?? 'Opinel';

    $words   = array_keys($frequencies);
    $common  = $words[0] ?? 'couteau';
    $second  = $words[1] ?? 'lame';
    $third   = $words[2] ?? 'inox';
    $fourth  = $words[3] ?? 'acier';
    $rare    = $words[count($words) - 1] ?? 'zz';
    $typo    = mb_substr($common, 0, -2) . mb_substr($common, -1);   // a letter dropped

    $engine = SearchEngine::open($dir);

    $cases = [
        "one common word ($common)"   => fn() => $engine->search($common),
        "two words ($common $second)" => fn() => $engine->search($common . ' ' . $second),

        // ── Three and four words, which is where the budget is missed ─────
        //
        // This phase measured one and two words only, and the CHANGELOG's own
        // table names the three- and four-word cases as the ones above the
        // 150 ms a results page is budgeted — `couteau de cuisine inox` at
        // 318.6 ms and one word more at 432.0. So the shape that needed
        // watching was the one shape nothing here watched.
        //
        // They are also the cases the mandatory-slot driver cannot help:
        // slack() is a fixed fraction of the total, so the more words a query
        // has the smaller each one's share, and past three common words no
        // single slot clears the bar. The walk is then fully unanchored, which
        // is what makes these the reference point for anything done to it.
        "three words (+$third)"       => fn() => $engine->search("$common $second $third"),
        "four words (+$fourth)"       => fn() => $engine->search("$common $second $third $fourth"),

        // The unanchored walk, which is the worst case this engine has.
        'three widest words'          => fn() => $engine->search(implode(' ', array_slice($widest, 0, 3))),
        'four widest words'           => fn() => $engine->search(implode(' ', array_slice($widest, 0, 4))),
        'six widest words'            => fn() => $engine->search(implode(' ', array_slice($widest, 0, 6))),

        "a typo ($typo)"              => fn() => $engine->search($typo),
        "a rare word ($rare)"         => fn() => $engine->search($rare),
        'no match at all'             => fn() => $engine->search('zzzzqqqq'),
        'empty query, filters only'   => fn() => $engine->search('', filters: Filter::all(
            Filter::eq('active', true),
            Filter::gt('stock', 0),
            Filter::between('price', 20, 200),
        )),
        'with 4 facets'               => fn() => $engine->search($common, facets: [
            'brand'      => Facet::terms(size: 20),
            'categories' => Facet::terms(size: 20),
            'pricing'    => Facet::stats('price'),
            'stock'      => Facet::stats('stock'),
        ]),
        "facets, disjunctive ($brand)" => fn() => $engine->search('',
            filters: Filter::in('brand', [$brand])->tag('brand'),
            facets:  ['brand' => Facet::terms(size: 20, exclude: 'brand')],
        ),
        'sorted by price'             => fn() => $engine->search($common, sort: [Sort::asc('price')]),
        'with highlighting'           => fn() => $engine->search($common,
            highlight: Highlight::fields(['name', 'description'])->excerpt(120)),
        'page 20 (offset 380)'        => fn() => $engine->search($common, limit: 20, offset: 380),
    ];

    // Printed so the widest-word cases can be read at all: which words they
    // picked, and in what share of the sample each one sits.
    printf("widest words in the sample of %s: ", number_format($sample));

    foreach (array_slice($widest, 0, 6) as $word) {
        printf('%s (%.0f%%) ', $word, 100 * $documentFrequencies[$word] / max(1, $sample));
    }

    printf("\n\n");

    printf("%-30s  %7s  %7s  %7s  %7s  %9s\n", 'query', 'p50 ms', 'p95 ms', 'min', 'max', 'matches');

    foreach ($cases as $label => $run) {
        $samples = [];
        $total   = 0;

        for ($i = 0; $i < $runs; $i++) {
            $started = hrtime(true);
            $result  = $run();
            $samples[] = (hrtime(true) - $started) / 1e6;
            $total     = $result->total;
        }

        $d = distribution($samples);

        printf("%-30s  %7.1f  %7.1f  %7.1f  %7.1f  %9s\n",
            $label, $d['p50'], $d['p95'], $d['min'], $d['max'], number_format($total));
    }
}

// ---------------------------------------------------------------------------
//  Phase: relevance
//
//  Every other phase here answers "how fast". This one answers "how right",
//  and it exists because nothing else did. The suite's query tests are all
//  *equivalence* tests — driven walk against undriven, merged segment against
//  fresh — and a ranking that is wrong the same way twice satisfies every one
//  of them. It did: 797 tests were green while a search for `pliant` returned
//  a product holding no form of the word, first.
//
//  Two numbers per query, and the first is the one that matters:
//
//    first junk    the rank of the first hit holding none of a typed word.
//                  At rank 1 a shopper sees the engine fail; at rank 150 they
//                  never reach it. A share alone hides that difference.
//    junk@100      how much of the first page-depth is like that.
//
//  "Junk" is measured against the *typed* words, re-analysed out of the
//  returned document — so a legitimate morphological match (`pliante` for
//  `pliant`) counts against us. The number is therefore pessimistic by
//  construction, which is the right direction for a gauge: it can only
//  understate an improvement.
// ---------------------------------------------------------------------------

if ($phase === 'relevance' || $phase === 'all') {
    $scale = max($scales);
    $dir   = $root . '/bench_search';

    heading('Relevance — ' . number_format($scale) . ' products');

    if (!is_dir($dir) || size_mb($dir) === 0.0) {
        printf("building the index first…\n");
        build($dir, $corpus, $scale, $batch, schema());
    }

    $engine   = SearchEngine::open($dir);
    $analyzer = new Analyzer();

    // Corpus-derived so the list is stable across runs and not tuned to the
    // defect being chased, plus the hand-picked words that exposed it. A gauge
    // made only of known-bad cases measures the fix, not the engine.
    $frequencies = [];

    foreach (corpus($corpus, min($scale, 5000)) as $product) {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($product['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 4) {
                $frequencies[$word] = ($frequencies[$word] ?? 0) + 1;
            }
        }
    }

    arsort($frequencies);

    $queries = array_values(array_unique(array_merge(
        array_slice(array_keys($frequencies), 0, 6),
        ['pliant', 'bois', 'inox', 'cuir', 'acier', 'couteau de cuisine inox'],
    )));

    /** Every term a document produces, as a set. */
    $bagOf = static function (array $document) use ($analyzer): array {
        $bag = [];

        foreach (['name', 'description', 'model'] as $field) {
            foreach ($analyzer->analyze((string) ($document[$field] ?? '')) as $term) {
                $bag[(string) $term] = true;
            }
        }

        return $bag;
    };

    printf("%-28s  %9s  %10s  %9s   %s\n", 'query', 'total', 'first junk', 'junk@100', 'top hit');

    foreach ($queries as $query) {
        $typed = array_map('strval', $analyzer->analyze($query));

        if ($typed === []) {
            continue;
        }

        $result = $engine->search($query, limit: 100);

        $rank      = 0;
        $firstJunk = null;
        $junk      = 0;
        // ASCII, because printf pads by bytes and an em dash is three of them —
        // which silently misaligns the one column a before/after read scans.
        $top       = '-';

        foreach ($result as $hit) {
            $rank++;
            $bag = $bagOf($hit->document);

            if ($rank === 1) {
                $top = substr(preg_replace('/\s+/', ' ', (string) ($hit->document['name'] ?? '')) ?? '', 0, 38);
            }

            foreach ($typed as $word) {
                if (!isset($bag[$word])) {
                    $junk++;
                    $firstJunk ??= $rank;
                    break;
                }
            }
        }

        printf("%-28s  %9s  %10s  %9s   %s\n",
            substr($query, 0, 28),
            number_format($result->total),
            $firstJunk === null ? 'none' : '#' . $firstJunk,
            $rank === 0 ? '-' : sprintf('%d%%', (int) round(100 * $junk / $rank)),
            $top);
    }
}

// ---------------------------------------------------------------------------
//  Phase: a cold request
// ---------------------------------------------------------------------------

if ($phase === 'cold' || $phase === 'all') {
    $scale = max($scales);
    $dir   = $root . '/bench_search';

    heading('A cold request — ' . number_format($scale) . ' products, fresh PHP process each time');

    if (!is_dir($dir) || size_mb($dir) === 0.0) {
        printf("building the index first…\n");
        build($dir, $corpus, $scale, $batch, schema());
    }

    // The number that describes a real request: nothing of php-fts survives
    // between two of them, so an in-process loop measures a warm index that no
    // visitor ever meets. Each sample is its own interpreter.
    $worker  = __DIR__ . '/cold.php';
    $samples = ['open' => [], 'search' => [], 'total' => []];

    // A child process inherits the php.ini, not the `-d` flags that were given
    // to *this* one — so `php -d xdebug.mode=off benchmark.php` measured a
    // worker with Xdebug switched on, and reported 318 ms for a search that
    // takes 68. Four and a half times, on the one phase whose whole purpose is
    // to say what a visitor waits for. The flag has to be handed on explicitly.
    for ($i = 0; $i < min($runs, 20); $i++) {
        $output = shell_exec(sprintf(
            '%s -d xdebug.mode=off %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($worker),
            escapeshellarg($dir),
            escapeshellarg('couteau'),
        ));

        $measured = json_decode((string) $output, true);

        if (!is_array($measured)) {
            fwrite(STDERR, "cold worker failed: " . substr((string) $output, 0, 300) . "\n");
            break;
        }

        foreach ($samples as $key => $_) {
            $samples[$key][] = $measured[$key];
        }
    }

    if ($samples['total'] !== []) {
        printf("%-30s  %7s  %7s  %7s  %7s\n", 'step', 'p50 ms', 'p95 ms', 'min', 'max');

        foreach (['open' => 'open() the index', 'search' => 'one search', 'total' => 'both'] as $key => $label) {
            $d = distribution($samples[$key]);

            printf("%-30s  %7.1f  %7.1f  %7.1f  %7.1f\n", $label, $d['p50'], $d['p95'], $d['min'], $d['max']);
        }
    }
}

// ---------------------------------------------------------------------------
//  Phase: merging
// ---------------------------------------------------------------------------

if ($phase === 'merge' || $phase === 'all') {
    $scale = max($scales);
    $dir   = $root . '/bench_merge';

    heading('Merging — ' . number_format($scale) . ' products');

    $result = build($dir, $corpus, $scale, $batch, schema());

    printf("before   : %d segments, %.1f MB\n", $result['segments'], $result['size_mb']);

    $engine = SearchEngine::open($dir);

    reset_peak();
    $started = hrtime(true);
    $engine->optimize();
    $elapsed = (hrtime(true) - $started) / 1e9;

    $stats = $engine->stats();

    printf("optimize(): %.1f s, peak %.1f MB\n", $elapsed, peak_mb());
    printf("after    : %d segment(s), %.1f MB, %s documents\n",
        $stats['segments'], size_mb($dir), number_format($stats['documents']));

    // The one that matters on shared hosting: an automatic merge happens
    // without being asked, and its size is not the caller's choice.
    printf("\nnote: %.0f MB peak against a typical 128 MB shared-hosting limit\n", peak_mb());

    wipe($dir);
}

printf("\n");
