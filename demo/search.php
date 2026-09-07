<?php

/*
 * The demo's search endpoint: one query, and everything the page needs.
 *
 * ── What this file used to be ───────────────────────────────────────────────
 *
 * Six searches with `limit: 2000`, and the facets tallied in PHP by decoding
 * every document that came back — twelve thousand deserialisations to draw one
 * sidebar. The `total` it reported was `count($results)`, which is the page
 * size, because nothing in 1.x could count matches without materialising them.
 *
 * All of that is now one call. The counting happens over bitsets and columns,
 * so no document is read to count a facet, and `total` is the real number of
 * matches across the whole index.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/autoload.php';

use Ols\PhpFts\Exception\FtsException;
use Ols\PhpFts\Facet;
use Ols\PhpFts\Filter;
use Ols\PhpFts\Highlight;
use Ols\PhpFts\SearchEngine;
use Ols\PhpFts\Sort;

const PAGE_SIZE = 48;

// ---------------------------------------------------------------------------
//  What the page asked for
// ---------------------------------------------------------------------------

$input = json_decode(file_get_contents('php://input') ?: '', true) ?? [];

$query    = trim((string) ($input['q'] ?? ''));
$category = array_values(array_filter((array) ($input['category'] ?? []), 'is_string'));
$brand    = array_values(array_filter((array) ($input['brand'] ?? []), 'is_string'));
$tags     = array_values(array_filter((array) ($input['tags'] ?? []), 'is_string'));
$gender   = isset($input['gender']) ? (string) $input['gender'] : null;
$onSale   = !empty($input['promo']);
$priceMin = isset($input['price_min']) ? (float) $input['price_min'] : null;
$priceMax = isset($input['price_max']) ? (float) $input['price_max'] : null;

// ---------------------------------------------------------------------------
//  One filter tree
//
//  Every clause the shopper controls carries a tag naming the facet it feeds.
//  That tag is what lets the facet below see past its own clause — so picking
//  Nike does not make the brand facet forget that Adidas exists.
// ---------------------------------------------------------------------------

$clauses = [
    Filter::eq('active', true),
    Filter::gt('stock', 0),
];

if ($category !== []) {
    $clauses[] = Filter::in('category', $category)->tag('category');
}

if ($brand !== []) {
    $clauses[] = Filter::in('brand', $brand)->tag('brand');
}

if ($tags !== []) {
    // A multi-valued field: this asks for products whose tag list holds any of
    // the tags picked.
    $clauses[] = Filter::in('tags', $tags)->tag('tags');
}

if ($gender !== null && $gender !== '') {
    $clauses[] = Filter::eq('gender', $gender)->tag('gender');
}

if ($onSale) {
    // `promo` is a discount or nothing at all, so "on sale" is presence.
    $clauses[] = Filter::exists('promo')->tag('promo');
}

if ($priceMin !== null || $priceMax !== null) {
    // An open end is allowed, so "under $200" needs no special case.
    $clauses[] = Filter::between('price', $priceMin, $priceMax)->tag('price');
}

$sort = match ((string) ($input['sort'] ?? 'relevance')) {
    'price_asc'  => [Sort::asc('price')],
    'price_desc' => [Sort::desc('price')],
    'stock_desc' => [Sort::desc('stock')],
    default      => [],   // relevance
};

// ---------------------------------------------------------------------------
//  The query
// ---------------------------------------------------------------------------

try {
    // No open/close, nothing to release: a request opens the directory, reads
    // what it needs and ends.
    $engine = SearchEngine::open(__DIR__ . '/search_data');

    $result = $engine->search(
        query:   $query,        // empty means everything, so this doubles as a
        limit:   PAGE_SIZE,     // category page with no search box
        filters: Filter::all(...$clauses),
        sort:    $sort,
        facets:  [
            // Each facet excludes its own clause and keeps every other one, so
            // the sidebar stays usable after the shopper has used it.
            'category' => Facet::terms(size: 24, exclude: 'category'),
            'brand'    => Facet::terms(size: 24, exclude: 'brand'),
            'gender'   => Facet::terms(exclude: 'gender'),
            'tags'     => Facet::terms(size: 14, exclude: 'tags'),

            // Statistics rather than counts: a price slider needs the real
            // bounds of the catalogue, not of the range already chosen — which
            // is why this one excludes 'price' too.
            'promo'    => Facet::stats('promo', exclude: 'promo'),
            'price'    => Facet::stats('price', exclude: 'price'),
        ],
        highlight: Highlight::fields(['name', 'description']),
    );
} catch (FtsException $e) {
    http_response_code(400);

    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ---------------------------------------------------------------------------
//  The response
// ---------------------------------------------------------------------------

$results = [];

foreach ($result as $hit) {
    $product = $hit->document;

    $results[] = [
        'id'       => $hit->id,
        'score'    => round($hit->score, 3),
        'name'     => $product['name'],
        'category' => $product['category'],
        'brand'    => $product['brand'],
        'gender'   => $product['gender'],
        'color'    => $product['color'] ?? null,
        'price'    => $product['price'],
        'promo'    => $product['promo'] ?? null,
        'stock'    => $product['stock'],
        'tags'     => $product['tags'] ?? [],
        'image'    => $product['image'],

        // Already HTML-escaped, with the marks inserted afterwards, so the
        // page can render them as they are. Absent when the query did not
        // match that field.
        'marked_name'        => $hit->highlights['name'] ?? null,
        'marked_description' => $hit->highlights['description'] ?? null,
    ];
}

$facets = $result->facets;

echo json_encode([
    // The exact number of matches across the index, not the size of this page.
    'total'   => $result->total,
    'took'    => round($result->took, 2),
    'queries' => 1,
    'results' => $results,
    'facets'  => [
        'category' => $facets['category'] ?? [],
        'brand'    => $facets['brand'] ?? [],
        'gender'   => $facets['gender'] ?? [],
        'tags'     => $facets['tags'] ?? [],

        // A statistics facet counts the documents that *have* a value, which
        // for a discount is exactly how many products are on sale.
        'promo'    => ['on_sale' => $facets['promo']['count'] ?? 0],
        'price'    => [
            'min' => round((float) ($facets['price']['min'] ?? 0), 2),
            'max' => round((float) ($facets['price']['max'] ?? 0), 2),
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
