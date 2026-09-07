# The demo

A faceted product search over a few hundred generated products. No database,
no service, no build step — a directory of files and PHP's own web server.

```bash
php demo/seed.php                # builds ./demo/search_data
php -S localhost:8000 -t demo    # then open http://localhost:8000
```

`seed.php` prints what it wrote:

```
✓ 194 products indexed in 0.22 s
  1 segment(s), 0.1 MB on disk, 12 fields
```

## What it is meant to show

**One query per page.** The results, six facets, the exact total and the
highlights all come out of a single `search()` call — the toolbar prints how
many queries were run and how long they took, and it says 1.

The same page in 1.x ran **six** searches with `limit: 2000` and tallied the
facets in PHP by decoding every document that came back. Its `total` was the
size of the page, because nothing in that design could count matches without
materialising them. Compare `search.php` against
[its 1.x version](https://github.com/olivier-ls/php-fts/blob/1.x/demo/search.php)
— same page, a fifth of the code.

**Facets that stay usable.** Pick a brand and the brand facet still shows the
others, with their counts. That is one tagged filter clause and one
`Facet::terms(exclude: …)`, not a second query.

**Highlights you can render.** Type a brand or a colour and the matches are
marked in the product names. The field text is escaped before the tags go in,
so it renders as-is.

**An empty search box is a category page.** The page opens on the whole
catalogue with its facets counted, because an empty query matches everything.

**Multi-valued fields.** The Tags facet counts a product under each of its
tags, so its counts add up to more than the number of results.

**Sorting that means something.** "Price, low to high" orders every match, not
the 48 on this page — which is why it is the engine's job and not the
template's.

## Files

| | |
|---|---|
| `seed.php` | generates the catalogue and indexes it in one commit |
| `search.php` | the whole search endpoint, one query |
| `index.html` | the page: vanilla JS, no framework, no build |
| `autoload.php` | the library's own autoloader — Composer is not required |
