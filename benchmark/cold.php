<?php

declare(strict_types=1);

/*
 * One cold request, measured from inside.
 *
 *     php benchmark/cold.php <index directory> <query>
 *     {"open":1.83,"search":4.21,"total":6.04,"matches":812,"peak_mb":2.5}
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

$directory = $argv[1] ?? null;
$query     = $argv[2] ?? '';

if ($directory === null) {
    fwrite(STDERR, "usage: php benchmark/cold.php <index directory> <query>\n");
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
