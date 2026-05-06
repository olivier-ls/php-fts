<?php

set_time_limit(0);

require __DIR__ . '/autoload.php';
require __DIR__ . '/DataGenerator.php';

use Ols\PhpFts\SearchEngine;

// ============================================================
//  CONFIG
// ============================================================

const BENCH_DIR       = './search_data_bench';
const DOC_COUNTS      = [1_000, 5_000, 10_000];  // volumes testés
const BULK_CHUNK_SIZE = 500;
const SEARCH_ROUNDS   = 200;  // nb d'itérations pour les stats de recherche

const SEARCH_QUERIES = [
    'chaussure',
    'cuir marron',
    'sneaker confortable',
    'chausure',           // faute volontaire — teste la robustesse trigramme
    'Nike',
    'taille 42',
    'botine cuir noir',   // double faute
    'artisanal premium luxe',
    'vélo',               // hors corpus — doit renvoyer 0 résultat
    'élégant',
];

// ============================================================
//  HELPERS
// ============================================================

function hrtime_ms(): float
{
    return hrtime(true) / 1_000_000;
}

function format_ms(float $ms): string
{
    return $ms < 1000
        ? round($ms, 1) . ' ms'
        : round($ms / 1000, 2) . ' s';
}

function dir_size(string $dir): int
{
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isFile()) $size += $file->getSize();
    }
    return $size;
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 ** 2)  return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1024 ** 2, 2) . ' MB';
}

function percentile(array $sorted, float $p): float
{
    $idx = (int) ceil(count($sorted) * $p) - 1;
    return $sorted[max(0, $idx)];
}

function section(string $title): void
{
    $line = str_repeat('─', 60);
    echo "\n$line\n  $title\n$line\n";
}

function row(string $label, string $value): void
{
    echo '  ' . str_pad($label, 30, '.') . ' ' . $value . "\n";
}


// ============================================================
//  PHASE 1 — INSERTION
// ============================================================

section('PHASE 1 — Insertion');

foreach (DOC_COUNTS as $docCount) {

    $documents = DataGenerator::generate($docCount);

    // — Insert unitaire —
    $engine = new SearchEngine();
    $engine->open(BENCH_DIR);
    $engine->reset();

    $memBefore = memory_get_usage(true);
    $t0        = hrtime_ms();

    foreach ($documents as $doc) {
        $engine->insert($doc);
    }

    $timeUnit = hrtime_ms() - $t0;
    $memUnit  = memory_get_usage(true) - $memBefore;
    $engine->close();

    // — insertBulk —
    $engine = new SearchEngine();
    $engine->open(BENCH_DIR);
    $engine->reset();

    $memBefore = memory_get_usage(true);
    $t0        = hrtime_ms();

    foreach (array_chunk($documents, BULK_CHUNK_SIZE) as $chunk) {
        $engine->insertBulk($chunk);
    }

    $timeBulk = hrtime_ms() - $t0;
    $memBulk  = memory_get_usage(true) - $memBefore;

    $indexSize = dir_size(BENCH_DIR);
    $engine->close();

    echo "\n  [ $docCount documents ]\n";
    row('insert() unitaire',     format_ms($timeUnit) . '  (mémoire : ' . format_bytes($memUnit) . ')');
    row('insertBulk() chunks',   format_ms($timeBulk) . '  (mémoire : ' . format_bytes($memBulk) . ')');
    row('Gain bulk vs unitaire', round($timeUnit / max($timeBulk, 0.001), 1) . 'x plus rapide');
    row('Taille index',          format_bytes($indexSize));
}

// ============================================================
//  PHASE 2 — RECHERCHE  (sur le dernier volume indexé)
// ============================================================

$docCounts = DOC_COUNTS;
section('PHASE 2 — Recherche  (corpus : ' . number_format(end($docCounts)) . ' docs)');

$engine = new SearchEngine();
$engine->open(BENCH_DIR);

$queryPool = SEARCH_QUERIES;
$times     = [];
$hits      = [];

for ($i = 0; $i < SEARCH_ROUNDS; $i++) {
    $query = $queryPool[$i % count($queryPool)];

    $t0      = hrtime_ms();
    $results = $engine->search($query, limit: 20, boosts: ['titre' => 3.0, 'description' => 1.0]);
    $times[] = hrtime_ms() - $t0;
    $hits[]  = count($results);
}

$engine->close();

sort($times);
$avg    = array_sum($times) / count($times);
$median = percentile($times, 0.50);
$p95    = percentile($times, 0.95);
$p99    = percentile($times, 0.99);

$rounds = SEARCH_ROUNDS;
echo "\n  [ $rounds requêtes, " . count($queryPool) . " queries distinctes en rotation ]\n";
row('Moyenne',         round($avg, 2)    . ' ms');
row('Médiane (P50)',   round($median, 2) . ' ms');
row('P95',            round($p95, 2)    . ' ms');
row('P99',            round($p99, 2)    . ' ms');
row('Min / Max',      round(min($times), 2) . ' ms / ' . round(max($times), 2) . ' ms');
row('Résultats moy.', round(array_sum($hits) / count($hits), 1) . ' docs');


// ============================================================
//  PHASE 3 — MAINTENANCE
// ============================================================

section('PHASE 3 — Maintenance');

$engine = new SearchEngine();
$engine->open(BENCH_DIR);

// Suppression de 20 % des documents pour simuler une vraie fragmentation
$sampleDocs = DataGenerator::generate(1_000);
$insertedIds = [];
foreach ($sampleDocs as $doc) {
    $insertedIds[] = $engine->insert($doc);
}

$toDelete = array_slice($insertedIds, 0, (int)(count($insertedIds) * 0.20));
foreach ($toDelete as $id) {
    $engine->delete($id);
}

$fragRate = $engine->fragmentationRate();
row('Taux fragmentation avant compaction', $fragRate . ' %');

$t0 = hrtime_ms();
$engine->compact();
$timeCompact = hrtime_ms() - $t0;

row('Durée compaction',                   format_ms($timeCompact));
row('Taux fragmentation après compaction', $engine->fragmentationRate() . ' %');

$engine->close();

echo "\n" . str_repeat('─', 60) . "\n\n";
