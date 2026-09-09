<?php

declare(strict_types=1);

/*
 * One cold request, measured from inside.
 *
 *     php benchmark/cold.php <index directory> <query>
 *     {"open":1.83,"search":4.21,"total":6.04,"matches":812,"peak_mb":2.5}
 *
 * And over HTTP, which is how to measure a shared host:
 *
 *     https://example.test/cold.php?q=couteau
 *
 * Upload this file, `autoload.php`, `src/` and the index directory; the
 * directory is read from `FTS_BENCH_DIR` or taken from beside this script,
 * never from the query string. Every request is its own interpreter there, so
 * twenty requests are twenty cold samples with nothing to orchestrate.
 *
 * Spawned once per sample by benchmark.php, because this is the only honest
 * way to measure what a visitor waits for. Nothing php-fts builds survives
 * between two requests — no daemon, no shared memory, no warmed cache of its
 * own — so an in-process loop measures an index that has already been opened,
 * which is a state no real request is ever in.
 *
 * What is *not* controlled here is the operating system's page cache, and
 * deliberately: a shop's index is read on every request, so its pages really
 * are warm. Dropping them would measure a machine nobody runs.
 */

require __DIR__ . '/autoload.php';

use Ols\PhpFts\SearchEngine;

// ── Over HTTP as well, because that is where the answer lives ─────────────
//
// This measures one cold request, and on shared hosting *every* request is a
// cold one: nothing of php-fts survives between two of them, and neither does
// the interpreter. So the honest way to measure a mutualisé is to upload the
// index and hit this URL — which needs no `shell_exec()`, and shared hosts
// commonly disable that, so benchmark.php's `--phase=cold` cannot run there at
// all. Ask for it twenty times and you have the distribution a visitor sees,
// on the machine they will see it on.
//
// The query comes from the caller. **The directory does not**, and that is
// deliberate: a path from a query string is a path traversal, and this file is
// meant to sit on a public host for an afternoon. It comes from the
// environment or from the default beside this script, so the worst a caller
// can do is search.
$overHttp = PHP_SAPI !== 'cli';

if ($overHttp) {
    header('Content-Type: application/json');

    $directory = getenv('FTS_BENCH_DIR') ?: __DIR__ . '/bench_search';
    $query     = (string) ($_GET['q'] ?? '');
} else {
    $directory = $argv[1] ?? null;
    $query     = $argv[2] ?? '';

    if ($directory === null) {
        fwrite(STDERR, "usage: php benchmark/cold.php <index directory> <query>\n");
        exit(1);
    }
}

if (!is_dir($directory)) {
    $message = ['error' => 'no index directory', 'looked_in' => $overHttp ? basename($directory) : $directory];

    echo json_encode($message);
    exit(1);
}

$startedOpen = hrtime(true);
$engine      = SearchEngine::open($directory);
$opened      = (hrtime(true) - $startedOpen) / 1e6;

$startedSearch = hrtime(true);
$result        = $engine->search($query, limit: 20);
$searched      = (hrtime(true) - $startedSearch) / 1e6;

echo json_encode([
    'open'    => round($opened, 2),
    'search'  => round($searched, 2),
    'total'   => round($opened + $searched, 2),
    'matches' => $result->total,
    'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
]);
