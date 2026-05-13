# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

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
