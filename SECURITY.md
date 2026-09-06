# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.1.x   | ✅ security fixes |
| < 1.1   | ❌ |

## Reporting a vulnerability

Please report security issues privately through
[GitHub Security Advisories](https://github.com/olivier-ls/php-fts/security/advisories/new).

Public issues are fine for anything that is not exploitable, but please use a
private advisory when a report includes a working attack path.

What to expect:

- acknowledgement within **72 hours**
- an assessment, with the threat model I applied, within **7 days**
- a fix released before any public disclosure, and credit in the changelog
  unless you prefer otherwise

## Threat model

php-fts is a library that reads and writes files in a directory the host
application chooses. That shapes what counts as a vulnerability:

**In scope**

- Anything that lets indexed content escape as executable output — the
  highlighting API returns HTML and is the main candidate.
- Index files being read or written outside the configured directory, including
  through symbolic links.
- Filters used for access control being bypassed by type confusion.
- Malformed or hostile index files causing memory exhaustion or arbitrary reads.

**Out of scope**

- The index directory being writable by untrusted local users *and* served over
  HTTP. Keep `search_data/` outside the web root; the library cannot enforce it.
- Denial of service by indexing arbitrarily large documents.
- Anything requiring the attacker to already control the calling PHP code.

## Hardening checklist

- Put the index directory **outside the web root**. It contains your documents
  in readable form.
- Never build filters directly from unvalidated request input. Cast and
  whitelist values first — `1.1.3` makes comparisons strict, which stops type
  confusion, but it cannot know which fields a given user may filter on.
- Highlights are HTML-escaped by default. Only pass `'escape' => false` if you
  escape downstream yourself.
