<?php

require __DIR__ . '/autoload.php';

use Ols\PhpFts\SearchEngine;

// ---------------------------------------------------------------------------
//  Data pools
// ---------------------------------------------------------------------------

$brands = [
    'Sneakers' => ['Nike', 'Adidas', 'Converse', 'Vans', 'New Balance', 'Puma', 'Reebok'],
    'Boots'    => ['Timberland', 'Dr. Martens', 'UGG', 'Sorel', 'Red Wing', 'Steve Madden'],
    'Sandals'  => ['Birkenstock', 'Havaianas', 'Clarks', 'Teva', 'Reef'],
    'Loafers'  => ['Gucci', 'Clarks', 'Tod\'s', 'Cole Haan', 'Sebago'],
    'Heels'    => ['Jimmy Choo', 'Steve Madden', 'Sam Edelman', 'Stuart Weitzman', 'Aldo'],
    'Athletic' => ['Nike', 'Adidas', 'New Balance', 'ASICS', 'Saucony', 'On Running', 'Hoka'],
];

// [name fragment, description, base tags]
$models = [
    'Sneakers' => [
        ['Air Max 90',    'Classic leather sneaker with visible air unit in the heel for all-day comfort.',  ['casual', 'classic', 'cushioned']],
        ['Chuck Taylor',  'Iconic canvas high-top sneaker, a streetwear staple since the 1920s.',            ['retro', 'iconic', 'canvas']],
        ['Stan Smith',    'Minimalist leather tennis sneaker with perforated 3-Stripes detail.',             ['minimal', 'tennis', 'leather']],
        ['Old Skool',     'Low-top skate shoe in durable suede and canvas with the iconic side stripe.',     ['skate', 'casual', 'retro']],
        ['990v5',         'Made in USA premium everyday sneaker with superior cushioning and stability.',    ['premium', 'comfortable', 'made-in-usa']],
        ['RS-X3',         'Chunky retro-inspired sneaker with bold colour-blocking and thick sole.',         ['chunky', 'retro', 'bold']],
        ['Forum Low',     'Heritage basketball sneaker with clean lines and ankle strap detail.',            ['heritage', 'basketball', 'clean']],
        ['Sk8-Hi',        'High-top skate shoe with padded collar and waffle outsole.',                     ['skate', 'high-top', 'durable']],
    ],
    'Boots' => [
        ['6-Inch Premium Boot', 'Waterproof full-grain nubuck leather boot built for all-season wear.',    ['waterproof', 'outdoor', 'durable']],
        ['1460 Mono',           'The original 8-eye boot in smooth leather with signature yellow welt.',   ['iconic', 'leather', 'punk']],
        ['Classic Mini',        'Cozy sheepskin ankle boot with UGGplush wool lining for cold days.',      ['cozy', 'warm', 'winter']],
        ['Chelsea Boot',        'Sleek elastic-sided ankle boot in polished leather, easy on and off.',    ['elegant', 'sleek', 'smart']],
        ['Kinetic Impact',      'Waterproof winter boot with 200g insulation and non-slip outsole.',       ['winter', 'waterproof', 'insulated']],
        ['Iron Ranger',         'Rugged cap-toe boot in Amber Harness leather, Goodyear welted.',          ['rugged', 'workwear', 'premium']],
    ],
    'Sandals' => [
        ['Arizona',      'Two-strap leather sandal with the signature contoured cork footbed.',             ['classic', 'leather', 'comfortable']],
        ['Brasil Logo',  'Original flip-flop in soft rubber, a beachside classic since 1962.',             ['beach', 'summer', 'casual']],
        ['Desert Trek',  'Lightweight suede sandal with cushioned Ortholite footbed.',                     ['casual', 'suede', 'lightweight']],
        ['Hurricane XLT2','Trail-ready sport sandal with adjustable straps and cushioned midsole.',        ['outdoor', 'sport', 'adjustable']],
        ['Swash',        'Reef-friendly sandal with bottle-opener built into the sole.',                   ['beach', 'fun', 'casual']],
        ['Papillio',     'Platform sandal on a cork base with anatomic footbed.',                         ['platform', 'summer', 'comfortable']],
    ],
    'Loafers' => [
        ['Horsebit Loafer', 'Iconic leather loafer with gold-tone horsebit hardware, a Gucci signature.',  ['luxury', 'iconic', 'leather']],
        ['Wallabee',        'Moccasin-construction shoe with natural crepe sole and beeswax leather.',     ['casual', 'iconic', 'comfortable']],
        ['Penny Driver',    'Classic penny loafer in burnished calfskin with leather sole.',               ['classic', 'preppy', 'smart']],
        ['Gommino',         'Soft driving shoe in pebble-grain leather with rubber-studded sole.',         ['driving', 'luxury', 'supple']],
        ['Grand Atlantic',  'Modern penny loafer with cushioned GrandFOAM+ insole.',                      ['comfortable', 'modern', 'smart']],
    ],
    'Heels' => [
        ['Romy 85 Pump',    'Pointed-toe pump in smooth leather with a slim 85 mm stiletto heel.',        ['formal', 'elegant', 'stiletto']],
        ['Denim Mule',      'Open-toe block-heel mule for effortless daytime style.',                     ['casual', 'trendy', 'comfortable']],
        ['Hazel Sandal',    'Strappy ankle-wrap sandal with padded footbed and 70 mm block heel.',        ['evening', 'strappy', 'comfortable']],
        ['Kitten Slide',    'Low 40 mm kitten heel slide, perfect from desk to dinner.',                  ['office', 'minimal', 'versatile']],
        ['Platform Pump',   'Pointed platform pump with 30 mm platform and 110 mm heel.',                 ['bold', 'platform', 'statement']],
    ],
    'Athletic' => [
        ['UltraBoost 22',   'High-performance running shoe with full-length Boost midsole and Primeknit upper.', ['running', 'performance', 'responsive']],
        ['React Infinity 3','Lightweight stability running shoe with soft React foam and wide landing zone.',     ['running', 'stability', 'cushioned']],
        ['Gel-Nimbus 25',   'Premium high-mileage running shoe with dual-layer GEL cushioning.',               ['running', 'premium', 'long-distance']],
        ['Fresh Foam 1080v13','Plush everyday trainer with triple-density Fresh Foam X midsole.',              ['running', 'plush', 'comfortable']],
        ['Kinvara 14',      'Lightweight tempo shoe with thin PWRRUN midsole for fast workouts.',             ['speed', 'lightweight', 'responsive']],
        ['Cloudflow 4',     'Swiss-engineered racing shoe with 45 CloudTec pods for explosive toe-off.',      ['running', 'race', 'lightweight']],
        ['Clifton 9',       'Maximum-cushion road shoe with early-stage Meta-Rocker geometry.',               ['running', 'max-cushion', 'comfortable']],
    ],
];

// Price range [min, max] in cents to avoid float issues
$priceRanges = [
    'Sneakers' => [5995,  21999],
    'Boots'    => [7999,  34999],
    'Sandals'  => [1999,  14999],
    'Loafers'  => [6999,  89999],
    'Heels'    => [4999,  49999],
    'Athletic' => [7999,  22999],
];

$colors  = ['Black', 'White', 'Brown', 'Navy', 'Grey', 'Beige', 'Cream', 'Red', 'Olive', 'Tan'];
$genders = ['Men', 'Women', 'Unisex'];
// Promos — mostly null (no promo), rest spread across realistic values
$promos  = [null, null, null, null, null, -10, -20, -30, -40, -50];

// ---------------------------------------------------------------------------
//  Product generation
// ---------------------------------------------------------------------------

$products = [];
$imageIdx = 1;

foreach ($models as $category => $items) {
    $range         = $priceRanges[$category];
    $categoryBrands = $brands[$category];

    // Generate 30–35 products per category (~190 total)
    $count = rand(30, 35);

    for ($i = 0; $i < $count; $i++) {
        [$modelName, $description, $tags] = $items[array_rand($items)];
        $brand  = $categoryBrands[array_rand($categoryBrands)];
        $color  = $colors[array_rand($colors)];
        $gender = $genders[array_rand($genders)];
        $promo  = $promos[array_rand($promos)];

        // Price: random in range, rounded to a .99 or .95 ending
        $rawCents = rand($range[0], $range[1]);
        $dollars  = intdiv($rawCents, 100);
        $price    = $dollars + (rand(0, 1) ? 0.99 : 0.95);

        // Merge contextual tags
        $allTags = array_unique(array_merge($tags, [strtolower($gender), strtolower($color), strtolower($category)]));

        $products[] = [
            'name'        => $brand . ' ' . $modelName . ' — ' . $color,
            'description' => $description . ' ' . strtolower($gender) . '\'s colourway in ' . strtolower($color) . '.',
            'category'    => $category,
            'brand'       => $brand,
            'price'       => $price,
            'promo'       => $promo,        // null or -10 / -20 / -30 / -40 / -50
            'stock'       => rand(0, 120),
            'active'      => true,
            'gender'      => $gender,
            'color'       => $color,
            'tags'        => array_values($allTags),
            'image'       => 'https://picsum.photos/seed/' . $imageIdx++ . '/400/400',
        ];
    }
}

// Shuffle so categories are interleaved in the index
shuffle($products);

// ---------------------------------------------------------------------------
//  Indexing
// ---------------------------------------------------------------------------

$engine = new SearchEngine();
$engine->open(__DIR__ . '/search_data');
$engine->reset();

$docIds = $engine->insertBulk($products);
$engine->close();

printf("✓ %d products indexed successfully.\n", count($docIds));
