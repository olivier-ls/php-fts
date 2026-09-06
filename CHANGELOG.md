# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.1.3] — 2026-09-06

Security and correctness release. No format change — existing indexes are read
and written unchanged.

### Security

#### Stored XSS in highlights ([#1])

`buildHighlights()` concatenated document text with HTML tags without escaping
it. An application indexing user-supplied content and rendering `highlights` as
HTML would execute whatever markup the document contained.

Field text is now escaped with `htmlspecialchars()` before the open/close tags
are inserted. The new `'escape' => false` option restores the previous behaviour
for callers that escape downstream themselves.

**This changes output.** If you already escaped highlights yourself, either drop
that escaping or pass `'escape' => false`.

#### Filter bypass by type juggling ([#2])

Filters compared with `==` and `in_array(..., strict: false)`. PHP considers any
non-empty string equal to `true`, so a filter value of `true` matched every
document with a non-empty string in that field — enough to defeat a filter used
for ownership or tenant scoping.

Comparisons are now type-strict, with one deliberate exception: `int` and `float`
compare numerically, because JSON round trips move values between the two.

Malformed filters — a missing `field`/`op`/`value` key, a non-string operator,
`in` without an array — now raise `FilterException` instead of emitting a warning
and silently failing to match.

**This changes behaviour.** `'42'` no longer matches `42`. Cast request input to
the intended type before building filters.

#### Symlink following when opening index files ([#3])

All four storage classes opened index files in a way that followed symbolic
links, and `TrigramIndex` additionally used a `file_exists()` + `fopen()`
two-step with a TOCTOU window. An attacker able to write to the index directory
could pre-place a link and have the engine create or truncate a file elsewhere.

Opening now goes through `OpensIndexFile`, which creates files with `x+b`
(`O_CREAT|O_EXCL`, which the kernel refuses to satisfy through a symlink) and
verifies for existing files that the descriptor and the path resolve to the same
inode.

### Fixed

#### Every thrown exception was a fatal error

`src/` threw `RuntimeException` from inside `namespace Ols\PhpFts` with no
import. PHP resolves unqualified class names to the current namespace and does
not fall back to the global one, so every `throw` actually raised
`Error: Class "Ols\PhpFts\RuntimeException" not found`, and the whole error
handling path was dead — as was `catch (Throwable $e)` in `compact()`, which
resolved to a non-existent `Ols\PhpFts\Throwable` and never caught anything.

Introduced a proper hierarchy under `Ols\PhpFts\Exception`:

| Class | Thrown for |
|---|---|
| `FtsException` | base class, extends `\RuntimeException` |
| `StorageException` | file open/read/write, corrupt or unsupported index files |
| `LockException` | the write lock could not be acquired |
| `FilterException` | malformed filter or unknown operator |

`FtsException` extends `\RuntimeException`, so existing `catch (\RuntimeException)`
code keeps working.

#### `LockManager` crashed without ext-posix

`posix_kill()` was called without a `function_exists()` guard. `ext-posix` is not
a dependency and is frequently listed in `disable_functions` on shared hosting,
so stale-lock detection raised a fatal error on exactly the platform the library
targets.

#### Orphaned locks could block an index forever on Windows

Stale-lock detection relied entirely on the PID, and was disabled on Windows. A
process that died while holding the lock left it in place with no recovery path.

A lock older than `maxAgeSeconds` (default 300, new optional third constructor
argument) is now treated as abandoned on every platform.

### Added

- `src/autoload.php` — the standalone autoloader the README has always
  documented, and which did not exist.
- `phpunit.xml`, GitHub Actions CI (PHP 8.1–8.4 on Linux, plus Windows and
  macOS), and a job that runs the suite with neither `ext-intl` nor `ext-posix`
  installed.
- PHPStan level 5 on `src`, clean.
- `SECURITY.md` with a reporting process and an explicit threat model.
- `tests/SecurityTest.php` — regression tests for all three issues.

### Changed

- `phpunit/phpunit` widened to `^10.5 || ^11.0` so the suite can run on PHP 8.1,
  which the library supports but PHPUnit 11 does not.

### Documentation

- Removed the claim that BM25 term-frequency saturation applies. Trigrams are
  deduplicated per document, so `tf` is always 1 and `k1` has no effect.
- Removed the claim that scores can be used to build facet counts. There is no
  facet or aggregation API, and `search()` reports neither a total nor an offset.
- Documented the highlighting API, which was implemented but absent from the
  README.
- Added a Security section, and a warning to keep the index directory outside
  the web root.

### Notes

Two assertions in `TokenizerTest` were wrong and had never been run:
`tokenize_deduplicates_identical_trigrams` asserted that a trigram appears twice
*after* deduplication, and the HTML-entity test expected `&euro;` to normalise to
the text `euro`. Both were corrected to assert actual behaviour.

[#1]: https://github.com/olivier-ls/php-fts/issues/1
[#2]: https://github.com/olivier-ls/php-fts/issues/2
[#3]: https://github.com/olivier-ls/php-fts/issues/3

---

## [1.1.2] — 2026-05-17

### Performance

#### `insertBulk()` — 11.7x faster (5k docs: 15.35s → 1.65s)

The bulk insert now uses a three-phase batch strategy instead of processing
each document individually:

- **Phase 1** — Documents are written to `documents.bin` and doc_ids are
  accumulated per trigram in a `$pendingPostings` map. No posting is written
  to disk at this stage.
- **Phase 2** — One `appendBatch()` call per unique trigram flushes all
  accumulated doc_ids in a single write, eliminating cascading reallocations
  in `postings.bin`.
- **Phase 3** — `DocumentStorage::endBulk()` flushes the header stats in one
  write (was: once per document). `TrigramIndex::flush()` rewrites the entire
  entries block sequentially (~810 KB, was: one `fseek + fwrite` per trigram
  update).

As a side effect, `postings.bin` produced by bulk inserts has zero internal
fragmentation since each trigram is allocated exactly once with the right
final capacity.

**Files changed:** `SearchEngine.php`, `DocumentStorage.php`,
`TrigramIndex.php`, `PostingsStorage.php`

---

#### `compact()` — 9.8x faster (20k docs: 43.31s → 4.41s)

Applied the same three-phase batch strategy as `insertBulk()` to the
compaction routine. Previously, `compact()` was rebuilding the index
document by document using individual `append()` and `set()` calls,
causing the same cascading reallocation problem — but on a freshly
created `postings.bin`, making it even more wasteful.

The compacted `postings.bin` is now written without any holes or wasted
capacity.

**Files changed:** `SearchEngine.php`

---

#### `PostingsStorage::allocate()` — minor

All doc_ids are now written in a single `fwrite()` call using `pack('V*', ...$ids)`
instead of a loop of individual `fwrite()` calls.

**Files changed:** `PostingsStorage.php`

---

### New methods (internal)

#### `DocumentStorage::beginBulk()` / `endBulk()`

Defers `persistHeaderStats()` disk writes during a bulk operation.
`beginBulk()` activates the deferred mode; `endBulk()` flushes count
and trigramSum to disk in one write.

#### `TrigramIndex::beginBulk()` / `flush()`

In bulk mode, `set()` only updates the in-memory entries array without
touching the disk. `flush()` rewrites the entire entries block in one
sequential write (~810 KB).

#### `PostingsStorage::appendBatch(array $newDocIds, ...)`

Appends multiple doc_ids to a trigram's posting list in one operation.
Avoids the reallocation churn that `append()` causes when called
repeatedly for the same trigram during a bulk operation.

---

### Bug fixes

#### Numeric trigram keys cast to `int` by PHP (`SearchEngine.php`)

In `insertBulk()` and `compact()`, trigrams are accumulated as keys of a
PHP array (`$pendingPostings[$trigram][] = $docId`). PHP silently casts
numeric string keys to integers (e.g. `"123"` → `123`), causing a
`TypeError` when the key was later passed to `TrigramIndex::get(string $trigram)`.

Fixed by explicitly casting the key back to string at the start of the
Phase 2 loop:
```php
$trigram = (string) $trigram;
```

Affected trigrams: any 3-character sequence composed entirely of digits
(e.g. `"123"`, `"990"`, `"v5#"`...).

---

### Crash safety

#### Bulk sentinel file (`SearchEngine.php`)

`insertBulk()` now writes a `.bulk_in_progress` sentinel file before any
data hits the disk, and removes it only on success. If the process dies
mid-bulk (between Phase 1 and Phase 3), the next `open()` call detects
the sentinel, removes it, and runs `compact()` automatically to rebuild
a consistent index from the documents already persisted in `documents.bin`.

This closes the consistency gap introduced by the deferred flush strategy:
without the sentinel, a crashed bulk would leave `documents.bin` ahead of
`trigrams.bin`, resulting in silently incomplete search results.

## [1.1.1] — 2026-05-13

### Fixed
- Extended `CHAR_MAP` in `Tokenizer` with Turkish-specific characters:
  - `İ` (U+0130) and `ı` (U+0131) — uppercase I with dot and lowercase dotless i — both mapped to `i`
  - `Ğ` / `ğ` (U+011E / U+011F) — mapped to `g`
  - `Ş` / `ş` (U+015E / U+015F) — mapped to `s`

---

## [1.1.0] — 2026-05-07

### Added
- Highlighting support in `SearchEngine::search()` via two new optional parameters: `bool $highlight = false` and `array $highlightOptions = []`
  - No overhead when `$highlight = false` (default behavior unchanged)
  - Available options: `tags` (open/close wrapping tags), `excerpt` (extract a snippet or return full text), `window` (number of context words around each match)
  - When enabled, results include a `highlights` field containing one entry per string field of the document

---

## [1.0.1] — 2026-05-07

### Fixed
- Unicode normalization is now applied before transliteration in `Tokenizer::normalize()`
  - If `ext-intl` is available, NFC normalization is performed via `Normalizer::normalize()`, with a fallback to the original string if it fails
  - Otherwise, combining diacritical marks are stripped across all Unicode blocks (U+0300–U+036F, U+1AB0–U+1AFF, U+1DC0–U+1DFF, U+20D0–U+20FF)
  - Fixes a bug where NFD-encoded input such as `fête` was tokenized as `fe te` instead of `fete`
  - Applies to both indexing and search, as both go through `Tokenizer::normalize()`
- Corrected `Þ` / `þ` (Thorn) transliteration from `B` / `b` to `Th` / `th`
- Corrected `ð` (Eth, lowercase) transliteration from `o` to `d`
- Added common ligature mappings to `CHAR_MAP`: `ﬁ`→`fi`, `ﬀ`→`ff`, `ﬂ`→`fl`, `ﬃ`→`ffi`, `ﬄ`→`ffl`, `ﬅ`→`st`, `ﬆ`→`st`
- Fixed a TOCTOU race condition in `open()` in `DocumentStorage`, `PostingsStorage`, and `TombstoneStorage` — replaced the two-step `file_exists()` + `fopen()` pattern with a direct `r+b` open attempt, falling back to `w+b` only if the file does not exist; eliminates the window between check and open during which another process could have created the file
- Fixed an unchecked `rename()` call in `SearchEngine::compact()` — if renaming a file fails, an exception is now thrown immediately rather than letting the loop continue; prevents ending up with a partially overwritten index with no error reported

---

## [1.0.0] — 2026-05-06

### Added
- Full-text search engine with trigram indexing
- BM25 + IDF relevance scoring, normalized 0–100
- Field boosting via `boosts` parameter
- Filter system with `and` / `or` logic
  - Operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not in`, `contains`, `not contains`
- `insert()` — single document insertion
- `insertBulk()` — batch insertion under a single file lock
- `update()` — atomic soft delete + re-insert
- `delete()` — soft delete via tombstone
- `search()` — full-text search with optional filters and boosts
- `count()` — number of live documents
- `fragmentationRate()` — fragmentation percentage
- `compact()` — index rebuild, removes deleted documents
- `reset()` — wipes all index files
- Binary file storage: `documents.bin`, `trigrams.bin`, `postings.bin`, `tombstones.bin`
- Fixed-size trigram index (~810 KB, 37³ entries, O(1) access)
- Zero dependencies — pure PHP 8.1+, no extensions required
