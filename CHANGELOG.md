# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] — 2026-09-11

A rewrite of the index and the query planner. **Segment format version 2:
existing indexes must be rebuilt by reindexing from your own source data.**
There is no migration path and there cannot be one — the terms a 1.x index holds
are the trigrams of documents, and this build looks up words.

Opening a version-1 index raises `UnsupportedFormatException`, which is
deliberately outside the `CorruptSegmentException` hierarchy so that the
rollback machinery cannot mistake an old index for a torn commit and quietly
present it as empty.

### Changed — words are the terms

Documents were indexed as character trigrams. They are now indexed as **words**,
in every script that separates them; the scripts that do not — Han, Hiragana,
Katakana, Hangul, Thai, Lao, Khmer, Burmese — keep their n-grams, because
finding word boundaries there needs a segmentation dictionary this library does
not ship.

Trigrams did not go away. They index the **vocabulary** instead of the
documents, in a new `§ termgrams` section, and a query expands what was typed
into the words the index holds before touching a document. Tolerance therefore
costs the size of the vocabulary rather than the size of the corpus, and a
vocabulary stops growing where a catalogue does not.

Measured on a 45 000-product catalogue, warm, one segment:

| query | 1.x terms | 2.0 | matches then → now |
|---|---|---|---|
| `steel` | 210.7 ms | **24.9 ms** | 2 945 → 1 412 |
| `steel cold` | 443.5 ms | **68.5 ms** | 1 421 → 758 |
| `stel` (a typo) | 181.0 ms | **33.5 ms** | 2 831 → 1 530 |
| `leath` (a prefix) | — | **10.0 ms** | — → 249 |
| `chromé` | 252.2 ms | **28.0 ms** | 528 → 496 |
| with 4 facets | 340.0 ms | **141.1 ms** | |
| `couteau de cuisine inox` | 2 261.7 ms | **318.6 ms** | 10 877 → 1 140 |
| `couteau de cuisine inox pliant` | 2 833.6 ms | **432.0 ms** | 11 469 → 721 |

Index size 71 MB → 69.8 MB. The four-word query is the one case still above the
150 ms a results page is budgeted; four fifths of what remains is walking
posting lists, and the mandatory-slot driver already halved it.

**Results changed, deliberately and for the better.** The old threshold applied
to a flat list of a query's trigrams with no notion of which word each came
from, so a document could clear the bar on one word's trigrams plus a handful of
strays — `couteau de cuisine inox` matched 10 877 documents, many containing no
form of `cuisine` at all. Compared side by side on the catalogue, the new
ranking is better on every query tried: `inoxx pliant` returned `Meyerco Maxx-Q`
and `Sencut Braxx` in its top five and now returns `POIGNARD PLIANT MUELA INOX`.

### Added

- **Typo tolerance you can reason about.** A query word is accepted if it is
  within an edit budget of an indexed word (Elasticsearch's `fuzziness: AUTO`:
  nothing under three characters, one edit to five, two beyond). Distances are
  counted in **characters, not bytes**, so one mistyped Cyrillic or Arabic
  letter costs one edit rather than two.
- **Prefix completion.** `leath` finds `leatherman`, `inox` finds `inoxydable`.
  Three characters minimum. This is also the safety net for suffix-inflecting
  languages — Turkish, Finnish, Hungarian — that no corpus here can test.
- **Per-field term frequency** (`§ fieldfreq`, replacing `§ fieldmask`). BM25's
  `k1` had no effect for as long as terms were deduplicated trigrams; it does
  now. A search for `opinel` used to return four products scoring 10.29 each,
  indistinguishable, one of which named Opinel twice.
- **`minimum_should_match` weighed in IDF rather than counted in words.** In
  `couteau de cuisine inox`, `de` sits in 38 555 of 45 000 documents and carries
  1.8% of the query's information, so it can no longer satisfy the threshold on
  its own.
- **`PostingsCursor::advance()` is finally called.** The skip tables were
  written from the start and no query path had ever used them. A slot the
  threshold makes mandatory now drives the walk and the other lists are jumped
  through — worth roughly half the posting time on a four-word query, and exact
  by construction rather than by measurement (`tests/Index/DrivenWalkTest.php`
  asserts the driven and undriven walks agree hit for hit and score for score).

### Added — a search says which of its words it could not use

`SearchResult::$unknown` lists the typed words the index holds nothing
resembling, in the order they were typed. Empty for a query the index
understood, which is the common case, so `if ($result->unknown)` is the whole
test.

They were already being dropped, and have to be: keeping one would put its IDF
in the budget with no way to ever gather it, so a single stray keystroke — or a
brand you do not stock — would empty the result instead of narrowing it. But
dropping it *silently* is how `couteau zwilling` becomes `couteau` and hands the
shopper the whole knife aisle with no hint that half their query was ignored.
The plan knew all along; now it says so, and an application can answer the way
every search engine answers: no results for *zwilling*, showing results for
*couteau*.

### Performance — the scoring loop, measured

The worst case is **1.55× faster**. `acier lame longueur` — three words each in
about 46% of 45 000 products, which is what someone shopping for a knife types —
went from 110 ms to 67 warm, and four such words from 140 to 89. Light queries
gained too: one word 4.9 → 3.8 ms, two words 10.2 → 8.3. Relevance is
byte-for-byte identical across all twelve benchmark queries.

**What a request actually costs**, cold — a fresh interpreter, `open()`
included, which is the only shape a visitor ever meets — at 45 000 products:

| query | matches | cold total |
|---|---|---|
| `steel` | 1 412 | **23 ms** |
| `stel` (a typo) | 1 379 | **25 ms** |
| `steel cold` | 746 | **29 ms** |
| `couteau de cuisine inox` | 1 013 | **61 ms** |
| `couteau` (38% of the catalogue) | 18 750 | **70 ms** |
| `acier lame longueur` | 20 109 | **109 ms** |
| `acier lame longueur couteau` | 18 276 | **139 ms** |

All of it inside the 150 ms a results page is budgeted, including the shape
that is hardest for this design: several words that are each in half the
catalogue, on a mono-thematic one where that really happens.

#### And the same thing on real shared hosting

Those figures come from a development machine, which is not what this library is
for. Measured on an **OVH mutualisé** — `cluster105`, PHP 8.3, 45 000 products,
100 fresh interpreters, `acier lame longueur`, the worst shape there is:

| step | p50 | p95 | min | max |
|---|---|---|---|---|
| `open()` the index | 8.7 ms | 13.3 | 7.7 | 21.8 |
| one search | 118.6 ms | 156.0 | 110.8 | 179.6 |
| **both** | **127.4 ms** | **165.7** | 118.5 | 201.4 |

**The typical request is inside the budget and the tail is about 10% over it**,
on the hardest query shape this design has. A one- or two-word search — which
is what most people type — is a fraction of that.

Two things in this table are worth more than the headline. `open()` costs
**8.7 ms**, *faster* than on the development machine: an index that is only
files, with no daemon to reach and no connection to make, opens as quickly on a
shared host as anywhere. And the floor is flat — a minimum of 118.5 against a
p50 of 127.4 — so the spread above it is the platform rather than the engine;
`open()` alone swings from 7.7 ms to 21.8 with no code involved. Chasing the
last 10% of that tail would be chasing a neighbour's disk.

So the figure to quote is a pair, and both halves of it are given here rather
than the flattering one.

Nothing architectural changed. The engine already refuses to iterate in PHP what
it can do in C by the block — `Bitset` uses the string operators, `count_chars`
and `strspn` for exactly that reason — and the scoring loop was the one place
that had not followed the rule. It now does:

- **Frequencies by the block.** 328 000 `ord()` calls for 65 689 postings on a
  five-field schema cost 87.7 ms; the same bytes through one `unpack('C*')` per
  128-posting block cost 18.2. `PostingsCursor::blocks()` hands over the block
  it had already decoded rather than serving it one element at a time, which
  also removes `current()`/`next()` — two method calls a posting, 34.6 ms across
  the same list. A posting is not enough work to pay for two method calls.
- **No allocation per posting.** `frequenciesAt()` built an array for every
  posting, `fieldedFrequency()` walked it back with a `??` on four separate
  maps, and `fieldLengthsOf()` returned another. Three allocations and a dozen
  hash lookups to multiply five numbers. The arithmetic is `Scorer`'s, in
  `Scorer`'s order, so the two agree to the last bit rather than to a tolerance.
- **A candidate rejected in one `strncmp`.** `accepts()` already required the
  first two characters to be right; testing that on bytes before decoding turns
  2 289 dynamic programmes into about a hundred for `couteau`. No measurable
  effect on a warm search, and the entry says so.

**On the numbers, and on two ways they were wrong first.** Every figure above is
A/B'd in a single session against the other version of the code, with Xdebug
disabled. Both of those conditions were learned by getting them wrong.

A stored baseline is not a comparison: this development machine drifts by up to
27% between sessions, and the *unchanged* code measured 578 ms one hour and 736
the next — which made a real gain look like a regression.

And **Xdebug inflates these numbers by 6.6×, unevenly.** It charges per function
call, which is what a hot loop is made of, so it flatters every change that
removes one: the improvement above measured as 1.9× under Xdebug and is 1.55×
without it. `benchmark.php` now refuses to run while Xdebug is active rather
than printing a number nobody can trust — `--phase=cold` had guarded its own
worker all along, which is why the cold figures were right while every other
phase was quietly not.

`benchmark/cold.php` also answers over HTTP now, because on shared hosting every
request is already a cold one and `shell_exec()` is commonly disabled there —
so the one measurement that describes a real request can be taken on the kind
of machine this library is for.

### Performance — work that was being done more than once

Measured claims are marked as such; the rest are reductions in work whose
effect on wall-clock has not been measured and is not claimed.

- **A filter leaf is compiled once per search rather than once per facet.** A
  facet excluding its own clause is counted over the candidates narrowed by
  every *other* clause, so each segment was asked to narrow the same candidates
  once per excluded tag — and `narrow()` rebuilt every leaf of the tree from its
  column. That is not cheap the way set arithmetic over bits is cheap:
  `KeywordColumn::equals()`, `TagColumn::contains()` and `NumericColumn::range()`
  each scan the whole column, in chunks of 4 096 documents. The variants differ
  only in the clause they lifted out, so the remaining passes rebuilt
  bit-for-bit identical sets — around 720 000 PHP loop iterations on the
  reference catalogue, three quarters of them redundant. **Verified rather than
  assumed:** three clauses across two filter views is five compilations
  requested and three column scans performed. Safe to hold for the object's life
  because a segment file never changes and a `Bitset` is mutated only by
  `set()`, which nothing calls on a set that came back from `compile()`.
- **`TopK::offer()` no longer allocates two arrays to reject a candidate.** Both
  sides of its comparison were built with the spread operator, for every
  candidate offered — including the overwhelming majority that lose on their
  first number. That is the shape of the empty-query page, where every document
  the filter keeps is offered: on the reference catalogue the heap was asked
  forty-five thousand times to return twenty rows. A rank is compared left to
  right, so the first number settles it unless it ties, and sorting by relevance
  alone — the default — is now a float against a float.
- **Four hoists out of loops that run per document, per posting or per
  candidate.** `array_keys()` rebuilt inside `SegmentIndexWriter`'s per-document
  loop, in both the analysed and the carried path; `Utf8::codepoints()` decoding
  the same candidate twice in `TermExpansion::accepts()`; `strlen()` re-measured
  on every turn of `frequenciesAt()`'s inner loop, which is the innermost thing
  in the whole search. No result changes.

### Fixed — four silent answers at the seams between correct pieces

Found by a second read-only audit. None of these was a bug *inside* a component,
which is why every component's own tests passed over all four; each was in the
seam where two of them met, and each was silent — no exception, no warning, a
plausible-looking answer. Two of them could not be observed at the scale the
suite ran at, so the suite gained the scale rather than only the fix.

- **A document frequency could run past the document count it was weighed
  against.** `statisticsOver()` counted *live* documents while the three figures
  beside it — document frequencies, summed term lengths, summed field lengths —
  are all measured over every document a segment holds, tombstones included,
  because that is what is on disk. `df` could then exceed `N`; `Scorer::idf()`
  clamps it, which turns a common term's IDF into `ln(1 + 0.5/(N + 0.5))` —
  zero, to three decimals. An index rewritten in place is where that bit: a
  thousand documents put ten times is ten thousand written against a thousand
  live, so every IDF collapsed, the minimum-should-match threshold collapsed
  with it, and the ranking became arbitrary. `averageLength` was inflated by the
  same ratio. Counting the deleted documents on **both** sides is what Lucene
  does and for the same reason — it keeps `df <= N` true, so the approximation
  stays an approximation instead of becoming a discontinuity. A merge still
  recomputes every sum over the documents it carried.
- **A single-character CJK query kept fifty readings chosen by byte order.** One
  character of a continuous script reaches every bigram of the vocabulary
  holding it, and each is weighed `1/2` — a bigram is half about the character
  asked for, whichever bigram it is. With every weight equal, the sort's final
  settle decided alone, and that settle was `strcmp`: the fifty kept were the
  fifty whose *first* character had the lowest code point. On a Chinese
  catalogue where a common character sits in several hundred bigrams, recall
  became a function of the code chart. Document frequency now breaks the tie, so
  the readings kept are the ones that reach documents; `strcmp` remains the
  final settle, so the choice is still identical on every machine. It applies to
  the Latin case too, where two corrections at the same distance from a word of
  the same length were also being settled by byte order.
- **A field an inferred schema had never seen was stored and never indexed.** An
  index that declares nothing infers its schema from the first batch and freezes
  it like any other, so a field appearing later was never analysed, produced no
  postings and got no column — while `isStored()` kept it in the docstore.
  `$hit->document['colour']` came back and `search('rouge')` found nothing, with
  nothing anywhere saying so. It happened *inside a single import* too:
  `putMany()` freezes the inference of its first spilled segment, so a generator
  whose first five thousand rows lacked an optional field condemned that field
  for the whole import. Now refused, naming the document, the field and the
  remedy. A **declared** schema is untouched: a field it does not mention is
  stored-only on purpose.
- **A term facet could be read as a numeric one.** Merging a facet across
  segments told statistics from term counts by looking for the keys `count` and
  `sum` — which are what `NumericColumn::stats()` returns and also two
  perfectly ordinary tags. A facet holding both went down the statistics branch,
  `min` was not there, and the facet came back as an undefined-key warning over
  nonsense. The answer was never in the shape: the frozen schema knows the
  field's type, index-wide, before any segment is read.

Also: per-field statistics now cross a segment boundary keyed by **field name**
rather than by mask bit. Bits are handed out by `Schema::searchableFields()` in
name order, so the mapping is stable for one schema — and `CollectionStatistics`
adds up the contributions of *segments*, which is exactly where that stopped
being a guarantee. Nothing produces disagreeing segments today, because the
schema is frozen; the invariant held, it was simply never stated and was
load-bearing three files from where it was decided. And `matchDriven()` no
longer takes a `CollectionStatistics` it never read.

### Fixed

- **A stray `<` no longer eats the rest of the text.** `strip_tags()` treats
  every `<` as the start of a tag and drops everything to the next `>`, or to
  the end of the string when there is none — so a catalogue writing
  `lame <3 mm` or `prix <30 euros` had the rest of its description missing from
  the **index**, not only from the highlight, and the product could not be found
  by any word after the bracket. HTML's own rule is narrower: a `<` opens a tag
  only when a letter, `/`, `!` or `?` follows it. Real markup is stripped
  exactly as before.
- **An all-digit term came back as an integer.** `Analyzer::analyze()`
  deduplicates through array keys and PHP turns a numeric key into an int, so a
  model number or a year was rejected by every string signature downstream. The
  `#` padding had hidden this for the life of the trigram index, because `#21#`
  is not numeric.
- `suggest: ext-intl` claimed a normalisation capability nothing in the library
  had used for some time. Removed.

### Fixed — three ways a wrong word could outrank a right one

Found by giving the query path its first **property** test. Everything that
tested it before asked an equivalence question — does the driven walk agree with
the undriven one, does a merged segment agree with a fresh one — and a ranking
that is wrong the same way twice satisfies all of them. It was: 797 tests were
green while a search for `pliant` on the reference catalogue returned
`Ranger Point Precision` first, a product holding no form of the word.

- **A slot added its variants together.** `plan`, `plat` and `point` are each
  two edits from `pliant`, so each counted 1 − 2/6 = 0.667; added they made 2.0
  against the exact word's 1.0, and BM25's saturation turned that into a higher
  score. A slot now counts once, for the closest reading of what was typed.
  The price, stated: `couteau` twice plus `couteaux` once carries 2.0 rather
  than 2.857 — the plural stops adding on top of the singular.
- **The edit budget had no prefix.** At `prefix_length` 0, which is
  Elasticsearch's default and is not meant to be used alone, two edits traverse
  a language: `couteau` reached `nouveau`, `contenu` and `bouleau`. An edit is
  forgiven only where the word started the same way — **one anchoring character
  per edit spent, plus one**, graduated on the distance found rather than the
  budget allowed. Measured over twelve corrections that must keep working and
  sixteen different words that must not: a fixed prefix of 2 keeps 12/12 and
  excludes 12/16, a fixed 3 keeps 11/12 and excludes 16/16, graduating keeps
  12/12 and excludes 15/16.
- **The variant weight was multiplied into term frequency**, so "how sure am I
  that this is the word" and "how much does this document talk about it" were
  expressed in the same units — and term frequency is unbounded, so the doubt
  always lost. A near miss said twice beat the exact word said once, which is
  what `Ranger Point Precision` was doing. Each variant is scored whole now and
  the weight multiplies the finished number.

Every single-word query on the catalogue returns nothing in its first hundred
results that does not hold the word:

| query | first junk hit | junk in top 100 |
|---|---|---|
| `pliant` | #1 → none | 12% → 0% |
| `black` | #7 → none | 20% → 0% |
| `bois` | #53 → none | 6% → 0% |
| `steel` `cold` `inox` | #50 #4 #69 → none | → 0% |

### Added — every script named, and the tables generated

The analyzer's folding and classification tables were written by hand against
French and were incomplete everywhere else, silently. Measured over every code
point the engine claims: **916 folded differently in upper and lower case** —
Vietnamese `VIỆT` became `viỆt` and never met `việt` — and **79 were punctuation
being indexed as letters**, because a Unicode block holds its script's own
punctuation and the Arabic comma therefore rode on the word beside it.

Both are zero. `tools/generate-folding.php` derives the tables from Unicode's
own case mappings, decompositions and general categories, using ext-intl and
ext-mbstring *at generation time only*; `tests/Analysis/FoldingClosureTest.php`
asserts the result is closed over ranges it reads back out of `Script` rather
than repeating.

**Twelve scripts that were being deleted outright** now index: Bengali,
Gurmukhi, Gujarati, Oriya, Tamil, Telugu, Kannada, Malayalam, Sinhala, Armenian,
Georgian, Ethiopic.

**Twenty-six in total, and here they are**, because the number is quoted
elsewhere and a reader should be able to check whether their language is in it.
The split is not cosmetic: it decides whether a word gets typo tolerance.

*Eighteen that separate their words* — indexed as words, and expanded by the
edit budget and by prefix completion: Latin, Cyrillic, Greek, Arabic, Hebrew,
Devanagari, Bengali, Gurmukhi, Gujarati, Oriya, Tamil, Telugu, Kannada,
Malayalam, Sinhala, Armenian, Georgian, Ethiopic.

*Eight written continuously* — indexed as n-grams of a run, and never expanded:
Han, Hiragana, Katakana, Hangul, Thai, Lao, Khmer, Myanmar.

Anything outside those twenty-six is treated as a separator, which means a
language written in it finds nothing rather than finding it badly — see
**Known limits**.

Arabic harakat, Hebrew niqqud and the tatweel are dropped, so a vocalised
spelling meets the ordinary one — `كِتَاب`, `كــتاب` and `كتاب` are one term.
Digits fold across writing systems: ٢٠٢٤ and 2024 are one term, and so are ۱۲۳,
१२३ and 123.

### Added — a search, not just an analyzer, proved outside Latin

Forty-three analyzer tests across fourteen scripts proved that Russian, Greek,
Arabic, Hebrew, Thai, Korean and Chinese **produce terms**. Nothing proved a
search could **find** them: the only end-to-end search outside Latin was one
Japanese document asserting one total, and a four-document Chinese fixture. So
the analyzer was validated and the query path was not — which is the asymmetry
both audits kept naming, and the place a defect survives longest, because a
term correctly produced and never reachable looks exactly like an empty
catalogue.

`tests/MultilingualRecallTest.php` closes it. The same twenty-product
catalogue, written in **Latin, Cyrillic, Japanese and Thai**, with five
properties asserted in each: every document is reachable by its own name; a
query the catalogue has no word for returns nothing; a query reaches what it
names and leaves the rest; filters and facets work whatever the script; and
highlighting marks the text it matched, at the right byte offsets in a
multi-byte script. Twenty tests, 211 assertions.

**What it deliberately does not assert is order.** Whether the first result is
the one a Japanese shopper wanted needs a native reader and a real catalogue,
and this suite has neither. The properties rest on lexical facts a dictionary
settles instead — that `ножи` is a form of `нож`, that `折りたたみ` is kanji
followed by okurigana, that Thai writes no space between words. Ranking stays
asserted in French, in `RelevanceTest`, where the judgement was actually made.

### Added — `§ keyfwd`, and an import that fits in the host

- **`ordinal → key` is written down rather than derived.** `keyOf()` inverted
  the whole key dictionary on the first hit of every request: **60.7 ms and
  5.4 MB on a 45 000-document segment**, to serve the twenty ids of one page,
  and growing with the index rather than with the page. It was there because
  `SEGMENT-FORMAT.md` said the direction was free, which it had not been since
  `DocumentStoreWriter` existed. Now 0.13 ms and 0.17 MB, for 0.56% of a
  segment. A segment written before the section falls back, so old indexes keep
  answering.
- **`putMany()` holds a segment, not the batch.** It promised bounded memory
  and delivered ~8 KB a document, so the documented example — yielding rows out
  of a PDO cursor — died at about fifteen thousand of them on a 128 MB host. The
  writer spills to a segment when its growth crosses a share of what
  `memory_limit` leaves; nothing is published until the end, so the batch still
  lands whole or not at all. The whole 45 000-product catalogue now imports in
  one call at **64 MB peak**, flat in the size of the input.

Cold request, fresh process, one segment — the only figure a shared host ever
sees:

| query | before | after |
|---|---|---|
| `couteau` | 150.4 ms | **68.1 ms** |
| empty query, category page | 87.5 ms | **22.8 ms** |
| `couteau de cuisine inox` | 138.7 ms | **51.7 ms** |

### Upgrading from an earlier 2.0 build

**Reindex.** The segment format is unchanged and old segments open, but the
analyzer folds differently — `×` is no longer a letter, ligatures fold, Arabic
and Hebrew lose their optional marks — so a document indexed by the earlier
rules and a query analysed by these ones can disagree. Nothing enforces it,
because there is no released 2.0 to be compatible with; it is the last moment
where that is free.

### Known limits

- **Ranking is validated in French only.** Three layers, and they are not in
  the same state, so it is worth being precise about which. The *analyzer* is
  proven over every script — its tables are derived from Unicode and asserted
  closed. *Recall and precision* are now proven in four scripts, end to end,
  by `MultilingualRecallTest`: a search finds what it names and leaves the
  rest, in Latin, Cyrillic, Japanese and Thai. What is still checked in one
  language is **order** — the reference catalogue is a French production
  catalogue, and what a *good* result looks like has been judged against it and
  against nothing else. That last one needs a native reader, not another test.
- **Twenty-six scripts, and the rest are separators.** Tibetan, Mongolian,
  Cherokee and everything else outside the twenty-six enumerated above are not
  indexed at all: a language written in them finds nothing rather than finding
  it badly. That is a real limit and stated as one.
- **A language that inflects by prefix is not bridged.** Arabic and Hebrew
  attach the definite article at the front, so `مطبخ` is a *suffix* of `المطبخ`
  and neither the edit budget nor prefix completion reaches it. The mirror rule
  would fix it and would also return the *Victorinox* brand for `inox`, so it is
  not the default; it belongs behind an explicit option.
- **A word inside a longer word is not found.** `shoe` does not match
  `snowshoes`. Deliberate — see above.
- **Continuous scripts are not cheaper than before.** Their terms are n-grams,
  so they keep the longer posting lists, and they are never expanded: an n-gram
  one edit from another is a different word, not a misspelling of it.
- **Korean gets no typo tolerance, and that is a choice.** Hangul is classed as
  a continuous script, so a Korean word is cut into bigrams and never expanded
  — which means neither the edit budget nor prefix completion applies to it.
  This is what Lucene's `CJKBigramFilter` does, with its hangul flag on by
  default, and bigrams handle Korean's agglutination well: the particles attach
  (서울 + 에서), so a bigram index reaches the stem where a whole-word one would
  not. But Korean *does* put spaces between its words, unlike the other scripts
  in that group, so it is the one case where the classification is a trade
  rather than a necessity. Stated because a Korean user would otherwise find
  the tolerance this release advertises simply absent, with no way to know it
  was deliberate.

---

## [1.1.4] — 2026-09-06

Security and correctness release. No format change — existing indexes are read
and written unchanged.

> Supersedes **1.1.3**, which was withdrawn. Its git tag was moved after
> publication while commit authorship was being cleaned up, which left the
> Packagist entry pointing at a commit that no longer exists. The code is
> byte-identical; 1.1.4 is the same release under a number that resolves.
> Anyone who managed to install 1.1.3 should move to 1.1.4.

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
