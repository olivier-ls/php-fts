> **v2.0 — on-disk format specification.** Implemented on the `2.x` branch,
> except where a section says otherwise. Written before the code, and kept as
> the record of what was decided and why.

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
│ § postings       posting lists + skip lists     │
│ § terms          term dictionary                │
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

**The order of the sections is nothing to a reader**, for the same reason: the
directory says where everything is, and no section is found by following
another. It matters only to the writer, which is why § postings comes before
§ terms — a dictionary entry points into the posting lists, so the dictionary is
not complete until the last list is written. Writing the postings first is what
lets them be streamed to disk one term at a time instead of concatenated in
memory; see §11.

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
| `tags`    | the same value dictionary + a run of ordinals and a fixed-width offset per document |
| presence  | a bitmap per column, for missing values — except `tags`, where an empty range is absence |

Dictionary encoding is the key move. Counting a `brand` facet becomes:
*allocate an int array of size cardinality, walk the matching ordinals,
increment.* No string comparison, no `json_decode`, no document read.

In v1, faceting requires decoding the JSON of every matching document — which is
why the demo runs six `limit: 2000` queries to fake it.

### `tags`: a range instead of a slot

A `keyword` column holds one ordinal per document at a fixed width, so document
*d* is at byte `d × width` and nothing has to be looked up to find it. That is
what makes it fast, and it is exactly what a list cannot have. So the values are
laid end to end and each document gets an **offset** into that run:

```
offsets    [ 0, 2, 3, 3 ]              documentCount + 1 entries, fixed width
ordinals   [ luxury, summer, winter ]  doc 0 owns [0,2), doc 1 owns [2,3)
```

Three properties, and they are why the layout is this one:

- **Random access survives.** Offsets are fixed width, so a document's range is
  still two reads at computable positions — no scan from the start of the column.
- **Presence needs no bitmap.** No tags is a range of length zero, so `exists`
  is `offsets[d+1] > offsets[d]`, and this is the one column with no presence
  bitset.
- **A facet costs one pass over the values.** Counting is proportional to the
  number of values, not to values × documents. An inverted bitset per tag would
  answer a *filter* faster and cost a popcount over the whole vocabulary to
  answer a *facet* — the wrong trade in the direction facets are used.

A document's ordinals are stored ascending and deduplicated. The sorting lets a
scan stop early; the dedup is not cosmetic — counted twice, a facet would report
more documents for a tag than there are documents.

Offsets are sized from the column's total number of values, ordinals from its
cardinality, both 1/2/4 bytes. Twenty thousand products with three tags each
spend two bytes per offset, not four.

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

```
"DSTO" | version u8 | reserved 3 | count u32
offsets   u32 × (count + 1)     ← where each document starts, and the end
payloads  the documents, back to back
```

One offset more than there are documents, so the last one's length is read the
same way as every other's; a document whose stored projection is empty has
`end == start`. The table is fixed-width and comes first, so any document is one
seek away — variable-length where you scan, fixed-width where you jump.

A payload is the JSON and nothing else. The key is **not** repeated here: it
lives in § keys, in a dictionary that maps it to the ordinal, which is the
direction a lookup actually goes. Storing it twice would cost a second copy of
every id to answer a question nobody asks of this section.

Only read for the documents actually returned — at most `limit` per query.

Written a document at a time, never assembled: see §11.

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

**Decided: no term positions, in 2.0 or later.** Highlighting is the only thing
that would read them, and it reads them for at most `limit` documents per query
— while the postings section would carry them for every document of every
segment, forever. The field is re-analysed instead, at highlight time, by the
same analyzer that indexed it: same rules, so the same terms, and each term
knows the characters it came from. The index pays nothing, and a highlight can
never disagree with what was indexed. See `Query\Highlighter`.

---

## 8. § meta

Schema (field names, types, flags), document count, per-field length sums for
BM25F normalisation, `k1` / `b`, creation timestamp, segment id.

---

## 9. Deletes

One **bitmap per segment**, one bit per local ordinal, carried in the manifest
next to the segment it belongs to.

**Built as `deleted.bin`, moved into the manifest.** A separate file has to be
kept in step with the commit that refers to it: publish the new manifest first
and the tombstones are stale, publish the tombstones first and a reader on the
old manifest sees documents disappear. Both orders need a second atomicity
mechanism for a few hundred bytes. In the manifest, a delete is the same single
atomic `rename()` as every other write, and a reader's snapshot includes its
tombstones by construction.

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
| `maxAutoMergeDocuments` | 50 000 | the ceiling, whatever the memory says |
| `memoryFraction` | 0.5 | share of the free memory a merge may plan to use |
| `memoryBudgetBytes` | derived | set it to make the decision reproducible |

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

### What a merge is allowed to cost

An automatic merge runs inside somebody's `put()`, in an HTTP request that on
shared hosting has 128 MB and an application already living in it. Its size is
the one thing the caller never chose. So it holds nothing it can stream:

- **Postings.** Every source dictionary is sorted and readable in order, so the
  sources are walked in lockstep — a k-way merge that takes the smallest term at
  the heads, unions the postings of the sources holding it, writes it, and lets
  it go. What is live is one term's posting list, bounded by the document count
  rather than by the vocabulary.
- **Documents.** A carried document is projected through `source()` and encoded
  the moment it arrives; only its four-byte offset is kept, which the docstore's
  offset table needs anyway. The payloads go to a `php://temp` and are copied
  through in blocks when the section is written.

What is still held is per document (keys, field lengths, column values) or per
term (the block dictionary, which is assembled before it is written) — both
bounded by things the caller can see. Measured on a 45 000-product catalogue:

| | accumulating | streaming |
|---|---|---|
| `optimize()`, 45 000 documents | 806 MB | 88 MB |
| full import, peak | 322 MB | 66 MB |
| import time | 38.2 s | 39.8 s |

The property is locked by `tests/Index/MergeMemoryTest.php`, which holds the
documents and the vocabulary fixed and varies only how many postings there are.

### How large a merge is allowed to be

Cheap is not the same as bounded, so the cap was measured rather than picked.
What a merge costs turned out to be almost exactly **linear in documents** —
and, once the postings and the docstore were streamed, in nothing else that a
policy could see:

| varied | range | cost |
|---|---|---|
| documents | 5 k → 80 k | 942 → 1 065 B/doc |
| searchable fields | 1 → 6 | 871 → 895 B/doc — nothing |
| key length | 4 B → 40 B | 923 → 972 B/doc — nothing |
| **filterable fields** | 0 → 16 | **896 → 2 731 B/doc**, ~115 B each |

A column is one array slot per document carried across, which is why it is the
only shape that moves the number, and why a cap cannot be a constant: the same
50 000 documents cost 45 MB with no columns and 137 MB with sixteen.

**Bytes on disk are not the predictor**, which is the counter-intuitive part. A
19 MB index of 5 000 documents with a large stored payload merges in 4 MB; a
1.3 MB index of 20 000 short ones takes 18 MB. The correlation runs backwards,
because the docstore is most of a segment file and is streamed.

So `MergePolicy` converts a share of the memory the process actually has left
into a number of documents, using `1 000 + 128 × filterableFields` bytes each.
Predicted 73.2 MB for the 45 000-product catalogue against 73.0 measured, on
coefficients fitted on synthetic data. A merge that will not fit does not
happen: the segments stay, search gets a little slower, and `optimize()` from a
cron fixes it — a slow index beats a failed request.

Measured end to end, importing the 45 000-product catalogue at 2 000 a commit:

| `memory_limit` | fixed cap of 50 000 | derived from memory |
|---|---|---|
| 128M | 9 segments, 66 MB | 9 segments, 66 MB |
| 96M | 9 segments | 6 segments |
| 64M | **out of memory** | 11 segments, 54 MB |
| 48M | **out of memory** | 23 segments, 48 MB |
| 40M | out of memory | out of memory* |

\* not the merge: at 40 MB it is `putMany()`'s own batch of 2 000 documents that
does not fit, which is the caller's to choose and does fit at 500 a commit
(13 segments, 22 MB peak).

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

- **Range facets** — `Facet::ranges([0, 50, 100, 200, null])`, counting a
  numeric column into buckets. The pieces exist: `NumericColumn::range()` gives
  the bitmap for one bucket and a population count gives its size. It is a
  bucketing feature rather than part of disjunction, so it is left until the
  API it belongs to is being used in anger and the bucket semantics — open
  ends, empty buckets, whether bounds are inclusive at the top — can be settled
  against something real.

- **Multi-valued columns, and the filters that need them** — `contains`,
  `containsAny`, `containsAll` over a `tags` field. A tags field is analysed
  today, so it is searchable; filtering on one needs a column holding several
  ordinals per document, which the fixed-width layout does not do. Left out of
  `Filter` entirely rather than added as factories that throw: an operator you
  can write and cannot run is worse than one that is not there.

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
