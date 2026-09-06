> **DESIGN DRAFT — v2.0.** On-disk format specification. Nothing implemented yet.

# Segment format

## Design constraints

Everything below follows from four facts:

1. **Nothing lives between requests.** Any structure loaded at open() is paid on
   every single search. Budget: a few tens of KB, not a few hundred.
2. **No extensions.** Every technique must be reachable with core PHP.
3. **Segments are immutable.** Written once, sequentially, never modified. This
   is what makes compression, front-coding and skip lists possible at all.
4. **Storage may be a network filesystem.** Seeks cost more than bytes. Minimise
   the *number* of reads, not only their size.

---

## 1. Segment file layout

One segment = one file, `seg_<id>.fts`.

```
┌─────────────────────────────────────────────────┐
│ HEADER            32 B                          │
│   "FTSG" | formatVersion u16 | flags u16        │
│   segmentId u64  | docCount u32 | reserved      │
├─────────────────────────────────────────────────┤
│ § terms          term dictionary                │
│ § postings       posting lists + skip lists     │
│ § fieldmask      per-posting field bitmaps      │
│ § docvalues      columnar values                │
│ § keys           user key → local ordinal       │
│ § docstore       the documents themselves       │
│ § meta           schema, statistics             │
├─────────────────────────────────────────────────┤
│ DIRECTORY        one (offset u64, length u64)   │
│                  per section                    │
│ TRAILER          32 B                           │
│   dirOffset u64 | fileLength u64                │
│   crc32 u32 | "GSTF"                            │
└─────────────────────────────────────────────────┘
```

**The directory sits at the end**, because offsets are only known once the
sections are written — a segment is produced in a single forward pass, never
seeking backwards. (Same reason ZIP puts its central directory last.)

**Opening a segment is two reads:** `fseek(-32, SEEK_END)` for the trailer, then
the directory. From there every section is addressable.

**The trailer is the self-validation.** `fileLength` compared to the real file
size is an O(1) completeness check, which catches the only failure that actually
happens — a truncated write. That check is what lets a reader reject a bad
segment and fall back to `commit.N-1`, and therefore what makes
`Durability::Fast` safe.

### Local ordinals

Inside a segment, documents are numbered `0 … docCount-1` in write order. Every
internal structure — postings, doc-values, docstore — is keyed by this **local
ordinal**: dense, small, perfect for delta encoding and fixed-width columns.

The stable public identity is the **user's key** (a string). Nothing internal
ever depends on it.

---

## 2. § terms — the dictionary

Replaces v1's direct-addressed 37³ table. This section is what unlocks Unicode.

Three levels:

### Level 3 — front-coded term blocks

Terms sorted by UTF-8 byte order (which equals codepoint order). Grouped into
blocks of 64 terms. Inside a block, each term shares a prefix with the previous:

```
per term:  sharedPrefixLen  varint
           suffixLen        varint
           suffix           bytes
           docFreq          varint
           postingsDelta    varint   (offset relative to previous term's)
           postingsLen      varint
```

The first term of a block has `sharedPrefixLen = 0`, so **any block decodes on
its own** without reading the ones before it.

Front-coding is unusually effective here because terms are n-grams and therefore
heavily clustered: `cha`, `chb`, `che`, `chi` share two bytes out of three.

### Level 2 — block index

One entry per block: the block's first term in full, plus its file offset.

For 100 000 terms → 1 563 entries → **≈ 25 KB**.

Stored as two flat byte strings (a concatenation of terms + an offsets array),
read once per request and binary-searched **in place with `substr`/`unpack`**.
No PHP array of structs is ever built. This is the direct lesson from v1, where
50 653 associative arrays were allocated on every search.

### Level 1 — lookup

```
binary search the in-memory block index   →  block offset
1 × fseek + fread (~4 KB)                 →  the block
linear scan over ≤ 64 front-coded terms   →  the entry
```

**One seek per term.** A 12-n-gram query over 3 segments is ~36 seeks worst
case, but n-grams from one query cluster into the same blocks, so 5–8 distinct
reads is typical. Lookups are sorted by offset and adjacent blocks coalesced
into a single read.

---

## 3. § postings — and the fix for the recall bug

A posting list is the sorted local ordinals of the documents containing a term.

```
[ blockCount varint ]
[ skip table ]                     ← only when count > 1024
   per block: firstOrdinal varint, byteOffset varint
[ block 0 ] [ block 1 ] …          ← 128 postings each
   first ordinal absolute, then deltas, all varint
```

Sorted ordinals delta-encoded as varints average ~1.5 bytes instead of a fixed
4, and there is no capacity padding because the list is written exactly once.
Expected: **~7.5 MB instead of ~27 MB** on the 20 000-document benchmark.

### Why this kills `maxCandidates`

v1 caps each posting list at 5 000 entries and reads them **from the end** —
silently restricting results to recently-inserted documents once a term exceeds
that (§1.5 of the audit). It is the worst bug in the engine precisely because it
never raises an error.

With a skip table, intersection **skips instead of truncating**: advance to the
block whose `firstOrdinal` ≥ the target, decode only that block. Cost becomes
proportional to the size of the *result*, not the size of the list.

`maxCandidates` disappears from the public API entirely.

---

## 4. § fieldmask — BM25F without re-tokenising

One byte per posting, in the same order as the delta stream: a bitmap of which
fields contain this term in this document (8 text fields; a second byte if more).

**Stored as a separate parallel stream, not interleaved.** Candidate selection
reads only the compact delta stream; field masks are touched exclusively for the
handful of documents that reach scoring. Interleaving would have wrecked both
compression and skipping.

This is what allows BM25F to weight a title match above a description match
**without re-analysing the document at query time** — v1's single largest CPU
cost (§4.1: `extractTrigrams()` is re-run on every returned document).

Cost: +1 byte per posting, ≈ +28 % on total index size. The section is omitted
entirely when the schema declares a single text field (flag in the header).

---

## 5. § docvalues — what makes totals and facets possible

One column per field declared filterable / sortable / facetable, addressed by
local ordinal.

| Schema type | Encoding |
|---|---|
| `number`  | `double` ×N, fixed width — direct offset `ordinal × 8` |
| `boolean` | bitmap, 1 bit per document |
| `keyword` | **dictionary-encoded**: sorted distinct values + per-doc ordinal (1/2/4 B by cardinality) |
| `tags`    | value-ordinal lists + an offsets array |
| presence  | a bitmap per column, for missing values |

Dictionary encoding is the key move. Counting a `brand` facet becomes:
*allocate an int array of size cardinality, walk the matching ordinals,
increment.* No string comparison, no `json_decode`, no document read.

In v1, faceting requires decoding the JSON of every matching document — which is
why the demo runs six `limit: 2000` queries to fake it.

### Bitsets with native PHP string operators

Matching document sets are represented as **PHP strings used as bitmaps**.
PHP applies `&`, `|`, `^` and `~` byte-by-byte to strings, in C:

```php
$matches = $textMatches & $activeFilter & $priceFilter & ~$deleted;
```

For 20 000 documents a bitset is 2 500 bytes; combining them is a single C-level
operation with no PHP loop and no extension.

This is what makes the whole README affordable:

- **exact `total`** — `substr_count(…)` style population count over the bitset,
  no need to stop at `limit`;
- **facets over all matches** — walk the bitset, index the column;
- **disjunctive facets** — keep one bitset per tagged filter, then re-`AND` all
  of them *except* the excluded tag. Recomputing a facet with one filter dropped
  costs a handful of string operations, not another query.

---

## 6. § keys — the primary key index

`user key (string) → local ordinal`. Same front-coded block structure as the
term dictionary, so it reuses the same reader.

Serves `get()`, `has()`, and `put()` (detecting that a key already exists).

The reverse direction (`ordinal → key`, needed for `$hit->id`) is free: each
docstore record stores its own key.

---

## 7. § docstore

Records in ordinal order:

```
[ keyLen varint ][ key ][ jsonLen varint ][ json ]
```

preceded by an offsets array (`u32` relative to the section start) so any
document is one seek away.

Only read for the documents actually returned — at most `limit` per query.

### No compression — selective source instead

**Decided: no `ext-zlib`, in 2.0 or later.** Not for performance reasons — the
docstore is read for at most `limit` documents per query, so decompression is
noise, and fewer bytes would actually help on network storage. The reason is
positioning: `PHP extensions required: none` is the project's sharpest claim,
and a conditionally-compressed format would also break the promise that an index
directory can be copied to any server.

The schema's `source()` whitelist does better anyway, because it removes the
data instead of squeezing it:

| Configuration | Docstore | Total index (20 k docs) |
|---|---:|---:|
| everything stored (default) | 8 MB | 23 MB |
| *zlib, for comparison* | *3.2 MB* | *18 MB* |
| `source(['title','price'])` | 1.5 MB | **16.5 MB** |
| `source(false)` — ids only | 0.3 MB | **15.3 MB** |

Constraint to document: highlighting a field requires that field to be stored.

---

## 8. § meta

Schema (field names, types, flags), document count, per-field length sums for
BM25F normalisation, `k1` / `b`, creation timestamp, segment id.

---

## 9. Deletes

`deleted.bin` holds one **bitmap per segment**, one bit per local ordinal:

```
[ segmentId u64 ][ bitmapLen u32 ][ bitmap ] …
```

Loading is a plain read, and the result drops straight into the bitset algebra
of §5 as `~$deleted`. No lookup, no set membership test, no per-candidate check.

`put()` on an existing key resolves `key → (segment, ordinal)` once at write
time and sets the bit. Merges physically drop deleted documents and reset the
bitmaps.

---

## 10. Cross-segment queries

A search touches K segments. For each term:

1. look it up in all K dictionaries — this yields `docFreq` per segment;
2. **sum the `docFreq`s** to get the true global `df`, and read `docCount` per
   segment from the manifest for the global `N`.

IDF is therefore exact across the whole index, not per-segment — and it costs
nothing, since the K lookups had to happen anyway.

Each segment produces its own scored bitset; the top-K are merged. With the
merge policy below, K is usually 1.

---

## 11. Merge policy

Tiered, and deliberately boring:

| Parameter | Default | Meaning |
|---|---|---|
| `segmentsPerTier` | 8 | merge when a size tier holds this many |
| `tierFactor` | 8 | each tier is 8× the previous in document count |
| `timeBudget` | 250 ms | an automatic merge never starts new work past this |
| `maxAutoMergeDocs` | 50 000 | above this, defer to `optimize()` |

Rules:

- **Commit first, merge second.** The write is durable before any merge starts.
  A merge killed mid-way leaves an orphan segment in no manifest — garbage, not
  damage.
- **Best effort.** A merge takes the writer lock; if it is held, it gives up
  immediately. No request ever waits on a merge.
- **`putMany()` ends with a full merge** of what it produced. After a cron
  import, the index is single-segment without anyone asking.

Worked example — 10 000 documents added one `put()` at a time:

```
put ×8      → 8 tiny segments      → merge → 1 segment (8 docs)
… ×8 again  → 8 segments of 8      → merge → 1 segment (64 docs)
…
steady state: 3 to 10 segments, each merge amortised O(log n) per document
```

---

## 12. Garbage collection

A merge retires segments that a concurrent reader may still hold open.

Retired segments are recorded in the commit with a retirement timestamp and
deleted by a later commit, once older than 60 s. On Windows an open file refuses
deletion; the failure is benign and retried on the next commit.

---

## 13. Open questions

1. **§ fieldmask: +28 % index size for correct field weighting.** Recommended:
   keep it, schema-driven, omitted for single-text-field schemas.
2. **Docstore compression** when `ext-zlib` is available — portability trade-off.
3. **Ordinal width.** `u32` caps a segment at 4 G documents; that is plenty, but
   it fixes the maximum merge output. Confirm.
4. **Block size** (64 terms / 128 postings) — to be tuned against a real
   benchmark on network storage, not guessed.

---

## 14. Deferred, deliberately

Agreed and scheduled, but not now:

- **Segment inspector** — a command that opens a `.fts` and prints its
  structure: sections and sizes, block counts, cost per key, a sample of keys
  with their payloads, checksum status. Makes the format legible instead of
  opaque, and becomes the basis of the CLI the adoption plan calls for. Judged
  premature while there is only a dictionary to look at.

- **Differential test against 1.x** — same corpus, same queries, both engines,
  compare results. This is the answer to "is it *correct*", which no benchmark
  can give. Only meaningful once v2 can search.

- **Parallel-corpus search-quality tests.** The same twenty or so documents
  translated into French, English, Japanese, Chinese, Russian and Arabic, each
  with a handful of queries and an expected target document. For every language
  and query, record three numbers: was the target found, at what rank, and how
  many results came back in total.

  Two things make this worth doing carefully. The bar is *comparable*, not
  *identical* — the analyzer is deliberately different per script, so expecting
  matching output would be expecting two different algorithms to agree. And
  measuring recall alone is not enough: bigrams are noisier than trigrams by
  construction, so a language could find the right document and bury it under
  fifty wrong ones while the test still passed. A workable threshold: the
  target in the top three, and no language returning an order of magnitude more
  results than the others.

  This is the closest substitute available for native-speaker review, which
  remains the thing that would catch a linguistic misunderstanding no test
  written from the same misunderstanding ever will.

- **Benchmarks on real shared hosting.** Olivier has access to production
  shared-hosting servers, so the final comparison runs there as well as on
  Windows, against 1.x on the same machine. One methodological correction is
  required: `benchmark/benchmark.php` in 1.x opens the engine *outside* the
  timed loop, so its published medians measure a search over an already-loaded
  index. Nothing survives between HTTP requests on shared hosting, so the v2
  benchmark must time **open + search** together.
