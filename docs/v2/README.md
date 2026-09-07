> **⚠ WORK IN PROGRESS — v2.0**
> This is the *target* README for php-fts 2.0, written first to lock the public
> API. Most of it now describes code that exists: the engine, the schema,
> filters, facets, highlighting and the segment format are implemented and
> tested on the `2.x` branch.
>
> Still unwritten, and marked as such below where it appears: `Sort` and the
> `sort:` argument, multi-valued `tags` as a filterable column, per-field
> analyzer overrides, and `UPGRADE.md`.
>
> The shipped documentation is still [`/README.md`](../../README.md) (1.x).

---

# php-fts

A self-contained full-text search engine in pure PHP.
**No extensions. No services. No Composer dependencies. Any language.**

---

## Why php-fts

Every other PHP search option asks you for something you may not have: a daemon,
a PHP extension, a service to run, or an API key.

|                          | php-fts        | TNTSearch             | Scout `database` | Meilisearch / Algolia |
|--------------------------|----------------|-----------------------|------------------|-----------------------|
| Composer dependencies    | **none**       | `predis/predis`       | Laravel          | HTTP client           |
| PHP extensions required  | **none**       | `pdo`, `mbstring`     | `pdo`            | `curl`, `json`        |
| Service to run           | **none**       | none                  | your database    | server or SaaS        |
| Works on shared hosting  | **yes**        | if `pdo_sqlite` is on | yes              | no                    |
| Typo tolerance           | **yes**        | yes                   | no               | yes                   |
| CJK / Cyrillic / Arabic  | **yes**        | limited               | depends on DB    | yes                   |
| Facets & exact totals    | **yes**        | no                    | no               | yes                   |

If you can run Meilisearch or Elasticsearch, run them — they are more powerful.
php-fts is for everyone else: shared hosting, small VPS, flat-file CMSs, and
anywhere "add another service" is not an option.

---

## Requirements

- PHP **8.1+**
- A writable directory

That is the whole list. `composer.json` requires nothing else, and never will.

---

## Install

```bash
composer require ols/php-fts
```

Or copy `src/` and register any PSR-4 autoloader for `Ols\PhpFts\`.

---

## Quick start

```php
use Ols\PhpFts\SearchEngine;

$engine = SearchEngine::open('./search_data');

$engine->put('sku-4471', [
    'title'       => 'Brown leather shoe',
    'description' => 'Elegant city shoe in soft leather',
    'brand'       => 'Adidas',
    'category'    => 'Shoes',
    'tags'        => ['summer', 'luxury'],
    'price'       => 129.90,
    'stock'       => 42,
    'active'      => true,
]);

$result = $engine->search('lether sho');   // typos are fine

echo $result->total;                       // 37 — exact, not capped
foreach ($result as $hit) {
    echo $hit->id, ' ', $hit->score, ' ', $hit->document['title'], PHP_EOL;
}
```

No `open()` / `close()` bookkeeping, no ids to keep track of, no compaction to
schedule. The engine maintains itself.

---

## Any language, out of the box

php-fts indexes **character n-grams**, which is the standard approach for
languages written without spaces — Japanese, Chinese, Thai. For those languages
n-gram indexing is not a fallback, it is the correct technique.

Nothing to configure. The analyzer detects the script per field and adapts.

```php
$engine->put('jp-1', ['title' => '革靴 ブラウン', 'brand' => 'アディダス']);
$engine->put('ru-1', ['title' => 'Коричневые кожаные туфли']);
$engine->put('ar-1', ['title' => 'حذاء جلدي بني']);

$engine->search('革靴');        // ✔
$engine->search('кожаные');     // ✔
$engine->search('جلدي');        // ✔
```

Handled automatically: full-width forms folded to ASCII, case folded across
Latin, Greek and Cyrillic, combining marks dropped, diacritics folded to their
base letter, and the n-gram size chosen per script — 2 for scripts written
without spaces, 3 for the rest, with runs broken at script changes so a bigram
never straddles the seam in 革靴ブラウン.

Katakana is deliberately *not* folded to hiragana: it marks loanwords in
Japanese, and erasing that makes distinct words collide. Half-width katakana
folding is not implemented yet.

Need control? Override per field:

```php
Schema::make()->text('title', analyzer: Analyzer::japanese())   // not implemented yet
```

---

## Indexing

### Documents have *your* id

```php
$engine->put('sku-4471', $document);   // insert or replace — idempotent
$engine->put(42, $document);           // ints are fine too
$engine->delete('sku-4471');
$engine->get('sku-4471');              // ?array
$engine->has('sku-4471');              // bool
```

Ids are stable forever. They survive merges, restarts and file copies, so you
can store them in your own database, in URLs, anywhere.

If you have no natural key, let the engine assign one:

```php
$id = $engine->insert($document);      // returns the generated id
```

### Bulk

```php
$engine->putMany([
    'sku-1' => ['title' => 'Product A', 'price' => 29.90],
    'sku-2' => ['title' => 'Product B', 'price' => 59.90],
]);

// Or stream — constant memory, any volume
$engine->putMany((function () use ($pdo) {
    foreach ($pdo->query('SELECT * FROM products') as $row) {
        yield $row['sku'] => $row;
    }
})());
```

`putMany()` is transactional: it either commits entirely or leaves the index
untouched. A crash mid-import cannot corrupt anything.

### Schema (optional)

Field types are inferred from your documents, and **the PHP type is what
decides**. That is the whole contract:

```php
$engine->put('sku-1', [
    'price'     => 129.90,     // a number: range filters, sorting, min/max facets
    'stock'     => 12,         // a number
    'reference' => '4471',     // a string: an exact value to filter and facet on
    'phone'     => '0612345678',
]);
```

`4471` is a number and `'4471'` is a reference, so a reference keeps its shape
and a phone number keeps its leading zero. Nothing sniffs at the inside of your
strings to decide it knows better.

The one thing to watch: `PDO` hands back every column as a string until you
turn on native types, so cast what is really a number before you index it —
otherwise `price` becomes an exact value and a range filter over it has no
meaning. If you do hit that, the exception says so and says what to do.

Declare a schema when you want to settle it explicitly, or want control over
what is indexed, stored, or filterable:

```php
use Ols\PhpFts\{Schema, SearchEngine};

$engine = SearchEngine::open('./search_data', Schema::make()
    ->text('title', boost: 3.0)   // full-text searchable
    ->text('description')
    ->keyword('brand')            // exact match, filterable, facetable
    ->keyword('category')
    ->tags('tags')                // array of keywords — stored and searchable
                                  // today; filterable and facetable next
    ->number('price')             // filterable, sortable, range facets
    ->number('stock')
    ->boolean('active')
    ->stored('image_url')         // returned, never indexed or filtered
);
```

A field the schema does not mention is still stored and still comes back in
`$hit->document` — it is simply neither searched nor filtered. A catalogue
export gains columns all the time, and that should not stop a product being
indexed.

Each capability can be overridden where the type's default is not what you
want:

```php
->keyword('status', indexed: false)   // filter on it; nobody types it
->text('body', stored: false)         // searchable, not returned
->text('title', b: 0.4)               // its length matters less than a description's
```

### What a declared schema enforces

Declaring a schema is saying "I know my data", so the engine takes you at your
word and refuses a value that does not match — naming the document, the field
and what it received:

```
FieldTypeException: Field 'price' of document 'sku-4471' is declared number
but received the string 'sur devis'
```

The rule is: **the declaration disambiguates.** Where inference has only the
PHP type to go on and trusts it, a declared field has your word for what it is,
so a value that merely needs converting gets converted — and one that would
need guessing at gets refused.
A `DECIMAL` column arrives from PDO as `'129.90'` and a `TINYINT(1)` as `'1'`,
so those are converted — declaring `number('price')` is exactly the cast you
would otherwise have written yourself. `'yes'` for a boolean is someone's
convention rather than a fact, so it is refused.

The same reasoning applies to the values you filter *with*: a numeric column
accepts `'200'` from `$_GET`, because by then there is nothing left to guess
at. A field given a list where one value was
declared is refused with the fix in the message — `declare the field with
tags()`.

Values are normalised **once**, before anything is written, so the term index,
the filter columns and the returned document can never disagree about what a
value was. `$hit->document['price']` comes back as `129.9` even though
`'129.90'` went in. That is part of what a schema buys you.

A missing field and an explicit `null` are always legal — not every product has
every column, and requiring one is your application's job, not the index's. A
refused document takes its whole batch with it: a batch is one commit, so
nothing is published rather than half of it.

**None of this applies when you declare nothing.** An inferred schema never
refuses anything: refusing a value against a type guessed from whichever batch
happened to arrive first would be exactly backwards. The turnkey path stays
forgiving.

### Freezing

A schema is frozen the first time the index commits, and every later batch and
every merge uses that one — otherwise inference would read a different batch
and a filter that worked yesterday would fail today. Reopening with a
different schema is refused rather than silently ignored; to change one,
reindex into a new directory.

### Keeping the index small

Your data already lives somewhere — a database, a CMS, files on disk. php-fts
does not need to keep a second full copy of it.

```php
Schema::make()
    ->text('title')
    ->text('description')
    ->number('price')
    ->source(['title', 'price']);   // only these come back in $hit->document
```

```php
->source(false);                    // store nothing; $hit->id is all you get
```

With `source(false)` a search returns ids, and you `SELECT` the rows you need
from your own database — the index holds no duplicate data at all. On a
20 000-document catalogue that is roughly a third off the total index size.

Two other levers, in order of effect:

- `stored()` for large fields you never search (HTML blobs, URLs, serialised
  JSON) — they are returned but never indexed.
- Simply not declaring a field at all.

One constraint: highlighting a field requires that field to be stored.

---

## Search

```php
$result = $engine->search(
    query:     'leather shoe',
    limit:     20,
    offset:    40,                          // real pagination
    boosts:    ['title' => 3.0],
    filters:   Filter::eq('active', true),
    facets:    ['brand', 'category'],
    sort:      [Sort::score(), Sort::asc('price')],   // not implemented yet
    highlight: ['title', 'description'],
);
```

### The result

```php
$result->total;      // int   — exact number of matches, not capped by limit
$result->hits;       // Hit[]
$result->facets;     // array
$result->took;       // float — milliseconds

foreach ($result as $hit) {
    $hit->id;          // 'sku-4471'
    $hit->score;       // 43.74
    $hit->document;    // array
    $hit->highlights;  // ['title' => 'Brown <mark>leather</mark> shoe']
}
```

### Browsing without a query

An empty query matches everything, so filters and facets work on their own —
category pages, admin tables, faceted browsing:

```php
$engine->search('', filters: Filter::eq('category', 'Shoes'));
$engine->search('', filters: Filter::eq('category', 'Shoes'), sort: [Sort::asc('price')]);   // sort: not yet
```

---

## Filters

Composable, strictly typed, arbitrarily nested:

```php
use Ols\PhpFts\Filter;

Filter::all(
    Filter::eq('active', true),
    Filter::gt('stock', 0),
    Filter::between('price', 50, 300),
    Filter::in('category', ['Shoes', 'Sport']),
    Filter::any(
        Filter::eq('brand', 'Nike'),
        Filter::eq('brand', 'Adidas'),
    ),
    Filter::not(Filter::exists('discontinued_at')),
)
```

| Family     | Operators                                  |
|------------|--------------------------------------------|
| Equality   | `eq` `neq` `in` `notIn`                    |
| Comparison | `gt` `gte` `lt` `lte` `between`            |
| Presence   | `exists` `missing`                         |
| Logic      | `all` `any` `not`                          |

`between` takes an open end: `between('price', 100, null)` is "100 and up".
An empty `all()` narrows nothing, so a filter assembled by a loop that adds no
clauses needs no special case at the call site.

### `neq` and `not` are not the same thing

```php
Filter::neq('brand', 'Nike')              // has a brand, and it is not Nike
Filter::not(Filter::eq('brand', 'Nike'))  // is not a Nike — including
                                          // products with no brand at all
```

`neq` and `notIn` follow SQL: a comparison against a missing value is not true,
so a product with no brand is not "a brand other than Nike". `not` is plain set
complement, which is what the word means. Both are useful, so both exist rather
than one being quietly chosen for you.

### Strictness

The value you filter *with* goes through the same type rules as the value you
index, so `eq('brand', true)` is refused rather than quietly compared against
`'1'` — and `lte('price', '200')` from `$_GET` is accepted. A type mismatch
raises `FilterException`, never a silent non-match.

### From an HTTP request

`Filter::fromArray()` takes the same structure as JSON and validates it as a
structure, before any of it reaches the code that reads files:

```json
{"op": "all", "filters": [
    {"op": "eq",      "field": "active", "value": true},
    {"op": "between", "field": "price",  "value": [50, 300]},
    {"op": "not", "filters": [{"op": "exists", "field": "discontinued_at"}]}
]}
```

Operators may be spelled the way a front end writes them — `=`, `!=`, `>=`,
`<=`, `not in`, `and`, `or`. Everything unexpected is **refused**, never
ignored: a misspelled operator that silently dropped its clause would widen a
search rather than narrow it, which is the failure nobody notices. Nesting is
capped, because a request body is an attack surface rather than a style
question.

`search()` also accepts a plain list of clauses, which is ANDed.

---

## Facets

Counted over **all** matches, not just the current page.

```php
$result = $engine->search('shoe', facets: [
    'brand'    => Facet::terms(size: 20),
    'category' => Facet::terms(),
    'pricing'  => Facet::stats('price'),
]);

$result->facets['brand'];     // ['Nike' => 42, 'Adidas' => 31, ...]
$result->facets['pricing'];   // ['count' => 73, 'min' => 12.5, 'max' => 890.0, ...]
```

The array key names the result and `field` says what to read, so the same
column can be counted twice under two names and a facet's name in your
template need not be a column name. A bare field name works too and picks the
kind for you — term counts for an exact value, statistics for a number:

```php
facets: ['brand', 'price']
```

Values are ordered most frequent first, with ties broken by value so that a
facet's order is the same across two identical requests and does not reshuffle
when segments merge. A `size` is applied once, at the end: the top twenty of
the index are not the top twenty of each segment added together.

### Disjunctive facets, in one query

The classic e-commerce problem. A shopper picks Nike, so the results are Nike
products, so the brand facet is counted over Nike products and shows `Nike (42)`
alone — and the shopper can no longer see that there are 31 Adidas to switch
to. The facet that made the choice possible has destroyed itself.

Tag the clause, then exclude that tag from its own facet:

```php
$result = $engine->search('shoe',
    filters: Filter::all(
        Filter::eq('active', true),
        Filter::in('brand', ['Nike'])->tag('brand'),
        Filter::in('category', ['Sneakers'])->tag('category'),
    ),
    facets: [
        'brand'    => Facet::terms(exclude: 'brand'),
        'category' => Facet::terms(exclude: 'category'),
    ],
);
```

The brand facet then counts active Sneakers of every brand; the category facet
counts active Nike products of every category. Both halves matter: the facet
sees past its *own* clause, and every other clause still narrows it.

Only the counting is widened — the results themselves are untouched, so a
shopper who picked Nike is still shown Nike products. A statistics facet can
exclude its own tag too, which is how a price slider shows the real bounds of
the catalogue rather than collapsing onto the range already chosen.

One query, correct counts on every facet. Each distinct excluded tag costs one
more pass of the filter over the query's candidates: no posting list is walked
twice and no document is read, so two facets excluding the same tag cost one
pass, not two.

---

## Highlighting

```php
$result = $engine->search('leather', highlight: ['title', 'description']);
$hit->highlights['title'];   // 'Brown <mark>leather</mark> shoe'
```

Only the fields that matched are present, so a template can fall back with
`$hit->highlights['title'] ?? $hit->document['title']`.

**Field values are HTML-escaped before the tags are inserted.** Indexed content
is frequently user-supplied; highlights are safe to render.

```php
Highlight::fields(['title'])
    ->tags('<b>', '</b>')
    ->excerpt(window: 40)  // …context around the match…
    ->raw()                // insert the tags into the text as it stands
    ->positions();         // byte offsets instead of HTML, for non-HTML output
```

`positions()` returns `['text' => …, 'spans' => [[start, end], …]]`, where the
text is the field with markup stripped and entities decoded — the string those
offsets index into, which is not the raw value you indexed.

**The window is in characters, not words.** Words are not a unit that exists in
every script this engine indexes; a Japanese sentence is one "word", and an
excerpt of eight of them is the whole field. In a script that does use spaces,
the cut is nudged outwards to the nearest one, so an excerpt still begins and
ends on a word.

**Highlighting a field requires that field to be stored** — the highlight is
built by re-reading the field's own text. It does *not* require the field to be
indexed: a `stored()` field highlights fine.

### What is marked, and why it is not a word

There are no term positions in the index. Positions would grow the postings of
every document to serve at most `limit` of them per query; instead the field is
re-analysed for the handful of documents actually being returned, by the same
analyzer that indexed it. Re-analysing cannot disagree with what was indexed,
because there is only one set of rules.

Each term the analyzer produces knows the characters it was cut from. A query's
terms are looked up, their spans are merged where they overlap, and the union is
marked. `leather` becomes seven trigrams whose spans tile the word, so the union
is `leather`; 革靴 becomes bigrams, and the same code marks it with no special
case for the script. Nothing here knows what a word is, which is exactly why it
works in scripts that have none.

Two consequences worth knowing:

- **A partial match is marked as a partial match.** Searching `shoe` marks
  `snow⟦shoe⟧s`, and `leather` marks `w⟦eather⟧`. That is genuinely why the
  document ranked, and 1.x could not say so — having only words, it wrapped the
  whole one.
- **A coincidence is not marked.** `leather` and `The` share the trigram `the`,
  and `leather` and `over` share `er#`. Neither is marked: a span survives only
  if every term the *document* produced inside it is one the query asked for,
  and only if some term of it is about a word's content rather than its edge.
  Ranking absorbs coincidences like these — one term out of seven barely scores
  — but a highlight is either drawn or not, and drawn over `The` it makes the
  engine look broken.

---

## Maintenance

There is none. The engine merges and reclaims space on its own, within a time
budget that keeps write requests fast.

For visibility, and for the rare explicit case:

```php
$engine->stats();
// ['documents' => 20000, 'deleted' => 143, 'segments' => 4,
//  'bytes' => 18442137, 'terms' => 91204]

$engine->optimize();   // merge to a single segment — nice after a big import
$engine->clear();      // wipe the index
```

`optimize()` is never required. An index that never sees it stays healthy.

---

## How it works

Writes produce **immutable segments**. A segment is a single self-contained
file, written once and never modified. A tiny `commit.N` manifest lists the live
segments, and a commit is a single atomic `rename()`.

Three properties follow, for free:

- **Reads take no lock.** Immutable files cannot be read mid-write, so searches
  never block on indexing and never see a torn state.
- **Crashes cannot corrupt.** A half-written segment is in no manifest, so it is
  invisible. There is no repair step and no recovery mode.
- **Maintenance is incremental.** Small segments merge into larger ones a little
  at a time, bounded by a time budget — never a full rebuild.

Scoring is BM25 over character n-grams, with per-field boosts and IDF computed
across the whole index.

---

## Index files

```
search_data/
  commit.7          manifest — live segments, their tombstones, the schema
  seg_a1f.fts       a segment: terms, postings, doc-values, documents
```

An idle index is typically **two files**. Copy the directory to another server
and it works — the format is byte-order independent.

Tombstones live in the manifest rather than in a file of their own. A separate
one would have to be kept in step with the commit that referred to it, which is
a second atomicity problem to solve; inside the manifest, deleting a document
is the same single atomic `rename()` as every other write.

---

## Limits and guarantees

Stated plainly, so you can decide before you invest:

| | |
|---|---|
| Designed for | 10² – 10⁵ documents |
| Concurrent readers | unlimited, lock-free |
| Concurrent writers | one at a time (serialized by an advisory lock) |
| Durability | every commit is `fsync`'d by default |
| Atomicity | a commit is all-or-nothing |
| Isolation | each search sees one consistent snapshot |
| Id stability | ids never change, for the life of the index |
| Max index size | 2^64 bytes; practically, your disk |

Not supported, and not planned: geo search, multi-tenant isolation, distributed
indexes, real-time indexing under sustained concurrent write load.

---

## Laravel

```bash
composer require ols/laravel-scout-php-fts
```

```php
// config/scout.php
'driver' => 'php-fts',
```

Everything Scout gives you — model observers, `scout:import`, queues — with no
service to run.

---

## Upgrading from 1.x

2.0 changes the index format and the API. See [UPGRADE.md](UPGRADE.md).
Short version: reindex once, and replace integer doc ids with your own keys.

1.x remains on the `1.x` branch and receives security fixes.

---

## License

MIT
