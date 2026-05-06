# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — 2025-XX-XX

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
