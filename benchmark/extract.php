<?php

declare(strict_types=1);

/*
 * Extracts a product catalogue from a MySQL/MariaDB database into JSON Lines,
 * for the benchmark to index.
 *
 *     php benchmark/extract.php --database=coutellerie
 *     php benchmark/extract.php --database=shop --user=root --password=secret \
 *         --out=benchmark/corpus.jsonl
 *
 * The output is one JSON object per line, which the benchmark streams with
 * fgets() + json_decode(). That format is chosen over CSV for one reason that
 * is not cosmetic: **types survive it**. A price read out of a `DECIMAL` column
 * arrives as the string '24.991667', and a string makes the schema infer an
 * exact value rather than a number — at which point a range filter over prices
 * means nothing. CSV loses that distinction; JSON keeps it. Embedded newlines
 * and quotes in descriptions are also free rather than a quoting problem.
 *
 * ── Read-only, and unbuffered ───────────────────────────────────────────────
 *
 * Nothing here writes to the database. The product query is deliberately
 * *unbuffered*, so forty-five thousand rows carrying a kilobyte of description
 * each are streamed rather than materialised — the same discipline the
 * benchmark then applies when indexing them.
 *
 * ── The schema this expects ─────────────────────────────────────────────────
 *
 * osCommerce / Zen Cart, which is what the catalogue this was written against
 * runs: `products` holds the numbers and the flags, `products_description`
 * holds the text once per language, `products_to_categories` is many-to-many.
 * Adjust the query below for another shop; everything downstream only cares
 * about the shape of a line.
 */

// ---------------------------------------------------------------------------
//  Options
// ---------------------------------------------------------------------------

$options = getopt('', [
    'host::', 'port::', 'database:', 'user::', 'password::', 'language::', 'out::', 'limit::',
]);

$host = $options['host'] ?? '127.0.0.1';

// Worth passing explicitly on a WAMP-style stack, where MariaDB and MySQL are
// both installed and only one of them can have 3306.
$port     = (int) ($options['port'] ?? 3306);
$database = $options['database'] ?? null;
$user     = $options['user']     ?? 'root';
$password = $options['password'] ?? '';
$language = (int) ($options['language'] ?? 1);
$out      = $options['out']      ?? __DIR__ . '/corpus.jsonl';
$limit    = isset($options['limit']) ? (int) $options['limit'] : null;

if ($database === null) {
    fwrite(STDERR, "usage: php benchmark/extract.php --database=NAME [--host=] [--port=3306]\n"
        . "       [--user=root] [--password=] [--language=1] [--out=] [--limit=]\n");
    exit(1);
}

// ---------------------------------------------------------------------------
//  Connect
// ---------------------------------------------------------------------------

// utf8mb4 reads utf8mb3 tables without loss; the other direction is what
// mangles accents, so it is worth being explicit rather than inheriting
// whatever the server's default happens to be.
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]
);

// ---------------------------------------------------------------------------
//  Categories, gathered first
//
//  Small enough to hold — a few tens of thousands of pairs — and holding them
//  avoids a GROUP_CONCAT whose separator could turn up inside a category name.
//  Most products have one category and some have five, which is what makes
//  this the corpus's natural multi-valued field.
// ---------------------------------------------------------------------------

$categories = [];

$rows = $pdo->query(
    'SELECT ptc.products_id, cd.categories_name
       FROM products_to_categories ptc
       JOIN categories_description cd
         ON cd.categories_id = ptc.categories_id
        AND cd.language_id   = ' . $language
);

foreach ($rows as $row) {
    $name = trim((string) $row['categories_name']);

    if ($name !== '') {
        $categories[(int) $row['products_id']][$name] = true;
    }
}

fprintf(STDERR, "categories: %d products carry at least one\n", count($categories));

// ---------------------------------------------------------------------------
//  Products
// ---------------------------------------------------------------------------

$sql = '
    SELECT p.products_id,
           p.products_model,
           p.manufacturers_name,
           p.products_price,
           p.products_quantity,
           p.products_status,
           p.products_invisible,
           p.products_image,
           p.products_weight,
           d.products_name,
           d.products_description
      FROM products p
      JOIN products_description d
        ON d.products_id = p.products_id
       AND d.language_id = ' . $language . '
     ORDER BY p.products_id';

if ($limit !== null) {
    $sql .= ' LIMIT ' . $limit;
}

$handle = fopen($out, 'wb');

if ($handle === false) {
    fwrite(STDERR, "cannot write to $out\n");
    exit(1);
}

$written = 0;
$bytes   = 0;
$started = microtime(true);

foreach ($pdo->query($sql) as $row) {
    $id = (int) $row['products_id'];

    $product = [
        'id'    => (string) $id,
        'name'  => trim((string) $row['products_name']),
        'model' => trim((string) ($row['products_model'] ?? '')),

        // Kept exactly as the shop stores it, markup and entities included.
        // That is what a real integration hands the engine, and stripping it
        // here would quietly benchmark a cleaner corpus than anyone has.
        'description' => (string) ($row['products_description'] ?? ''),

        'brand'      => trim((string) ($row['manufacturers_name'] ?? '')) ?: null,
        'categories' => array_keys($categories[$id] ?? []),

        // Cast, not passed through. This is the whole reason for JSON Lines.
        'price'  => (float) $row['products_price'],
        'weight' => (float) ($row['products_weight'] ?? 0),
        'stock'  => (int) $row['products_quantity'],

        'active'  => (int) $row['products_status'] === 1,
        'visible' => (int) ($row['products_invisible'] ?? 0) === 0,

        'image' => trim((string) ($row['products_image'] ?? '')) ?: null,
    ];

    $line = json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        // A row whose text is not valid UTF-8 after all. Reported rather than
        // silently dropped, because it is exactly the kind of thing a corpus
        // should tell you about.
        fprintf(STDERR, "skipped product %d: %s\n", $id, json_last_error_msg());
        continue;
    }

    fwrite($handle, $line . "\n");

    $written++;
    $bytes += strlen($line) + 1;
}

fclose($handle);

$elapsed = microtime(true) - $started;

printf("✓ %s products written to %s in %.1f s\n", number_format($written), $out, $elapsed);
printf("  %.1f MB, %d bytes per product on average\n", $bytes / 1048576, $written > 0 ? intdiv($bytes, $written) : 0);
printf("  the benchmark reads the first N lines, so subsets are free\n");
