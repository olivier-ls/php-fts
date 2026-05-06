<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/autoload.php';

use Ols\PhpFts\SearchEngine;

// ---------------------------------------------------------------------------
//  Parse input
// ---------------------------------------------------------------------------

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$q         = trim($input['q']         ?? '');
$fCat      = !empty($input['category'])  ? (array)$input['category']  : null;
$fBrand    = !empty($input['brand'])     ? (array)$input['brand']      : null;
$fGender   = !empty($input['gender'])   ? (string)$input['gender']     : null;
$fPromo    = isset($input['promo'])     ? (bool)$input['promo']        : null;
$fPriceMin = isset($input['price_min']) ? (float)$input['price_min']   : null;
$fPriceMax = isset($input['price_max']) ? (float)$input['price_max']   : null;

if ($q === '') {
    echo json_encode(['total' => 0, 'results' => [], 'facets' => new stdClass()]);
    exit;
}

// ---------------------------------------------------------------------------
//  Filter builder
//
//  Each parameter can be null to exclude it.
//  For adaptive facets, pass null on the facet to be computed.
// ---------------------------------------------------------------------------

const PROMO_VALUES = [-10, -20, -30, -40, -50];
const FACET_LIMIT  = 2000;

function buildFilters(
    ?array  $cat,
    ?array  $brand,
    ?string $gender,
    ?bool   $promo,
    ?float  $priceMin,
    ?float  $priceMax
): array {
    $and = [
        ['field' => 'active', 'op' => '=', 'value' => true],
        ['field' => 'stock',  'op' => '>',  'value' => 0],
    ];

    if ($cat !== null) {
        $and[] = ['field' => 'category', 'op' => 'in', 'value' => $cat];
    }
    if ($brand !== null) {
        $and[] = ['field' => 'brand', 'op' => 'in', 'value' => $brand];
    }
    if ($gender !== null) {
        $and[] = ['field' => 'gender', 'op' => '=', 'value' => $gender];
    }
    if ($promo === true) {
        $and[] = ['field' => 'promo', 'op' => 'in', 'value' => PROMO_VALUES];
    }
    if ($priceMin !== null) {
        $and[] = ['field' => 'price', 'op' => '>=', 'value' => $priceMin];
    }
    if ($priceMax !== null) {
        $and[] = ['field' => 'price', 'op' => '<=', 'value' => $priceMax];
    }

    return ['and' => $and];
}

// ---------------------------------------------------------------------------
//  Searches
//
//  Adaptive facets principle:
//  Each facet is computed against all active filters EXCEPT its own.
//  This way, removing/changing a filter does not block the other options.
//
//  Optimisation:
//  If two facets share the same parameters (because their respective filters
//  were not active), they produce an identical query.
//  Deduplicated by serialized signature → only one query is executed.
//  Gain: 2 queries minimum (main + pool), up to 7 if everything is filtered.
// ---------------------------------------------------------------------------

$engine = new SearchEngine();
$engine->open(__DIR__ . '/search_data');

$boosts = [
    'name'        => 3.0,
    'brand'       => 2.0,
    'category'    => 1.5,
    'tags'        => 1.5,
    'description' => 1.0,
];

// 1. Main query — all active filters
$mainResults = $engine->search(
    query:   $q,
    limit:   48,
    boosts:  $boosts,
    filters: buildFilters($fCat, $fBrand, $fGender, $fPromo, $fPriceMin, $fPriceMax)
);

// 2. Parameter definition for each facet query
//    Each facet drops its own filter (null at its position)
$facetDefs = [
    'category' => [null,   $fBrand, $fGender, $fPromo, $fPriceMin, $fPriceMax],
    'brand'    => [$fCat,  null,    $fGender, $fPromo, $fPriceMin, $fPriceMax],
    'gender'   => [$fCat,  $fBrand, null,     $fPromo, $fPriceMin, $fPriceMax],
    'promo'    => [$fCat,  $fBrand, $fGender, null,    $fPriceMin, $fPriceMax],
    'price'    => [$fCat,  $fBrand, $fGender, $fPromo, null,       null      ],
];

// 3. Deduplication by signature — identical queries only run once
$queryCache   = [];
$facetResults = [];

foreach ($facetDefs as $facetName => $params) {
    $signature = serialize($params);

    if (!isset($queryCache[$signature])) {
        $queryCache[$signature] = $engine->search(
            query:   $q,
            limit:   FACET_LIMIT,
            boosts:  $boosts,
            filters: buildFilters(...$params)
        );
    }

    $facetResults[$facetName] = $queryCache[$signature];
}

$engine->close();

// ---------------------------------------------------------------------------
//  Facet computation
// ---------------------------------------------------------------------------

// Categories
$catCounts = [];
foreach ($facetResults['category'] as $r) {
    $v = $r['document']['category'] ?? null;
    if ($v !== null) $catCounts[$v] = ($catCounts[$v] ?? 0) + 1;
}
arsort($catCounts);

// Brands
$brandCounts = [];
foreach ($facetResults['brand'] as $r) {
    $v = $r['document']['brand'] ?? null;
    if ($v !== null) $brandCounts[$v] = ($brandCounts[$v] ?? 0) + 1;
}
arsort($brandCounts);

// Gender
$genderCounts = [];
foreach ($facetResults['gender'] as $r) {
    $v = $r['document']['gender'] ?? null;
    if ($v !== null) $genderCounts[$v] = ($genderCounts[$v] ?? 0) + 1;
}

// Promo
$promoCount    = 0;
$nonPromoCount = 0;
foreach ($facetResults['promo'] as $r) {
    if (in_array($r['document']['promo'] ?? null, PROMO_VALUES, true)) {
        $promoCount++;
    } else {
        $nonPromoCount++;
    }
}

// Actual price range (without price filter)
$prices   = array_map(fn($r) => (float)($r['document']['price'] ?? 0), $facetResults['price']);
$priceMin = $prices ? round((float)min($prices), 2) : 0.0;
$priceMax = $prices ? round((float)max($prices), 2) : 0.0;

// ---------------------------------------------------------------------------
//  Response formatting
// ---------------------------------------------------------------------------

$results = array_map(fn($r) => [
    'docId'    => $r['docId'],
    'score'    => $r['score'],
    'name'     => $r['document']['name'],
    'category' => $r['document']['category'],
    'brand'    => $r['document']['brand'],
    'price'    => $r['document']['price'],
    'promo'    => $r['document']['promo'],
    'stock'    => $r['document']['stock'],
    'gender'   => $r['document']['gender'],
    'color'    => $r['document']['color'],
    'image'    => $r['document']['image'],
], $mainResults);

echo json_encode([
    'total'   => count($mainResults),
    'results' => $results,
    'facets'  => [
        'category' => $catCounts,
        'brand'    => $brandCounts,
        'gender'   => $genderCounts,
        'promo'    => [
            'on_sale'    => $promoCount,
            'full_price' => $nonPromoCount,
        ],
        'price' => [
            'min' => $priceMin,
            'max' => $priceMax,
        ],
    ],
], JSON_PRETTY_PRINT);
