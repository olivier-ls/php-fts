<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

/**
 * Thrown when a segment is a well-formed segment of a format this build does
 * not read.
 *
 * ── Why this is not a CorruptSegmentException ──────────────────────────────
 *
 * Because the two ask for opposite things, and confusing them loses an index.
 *
 * `CorruptSegmentException` means "this particular commit cannot be trusted",
 * and the reader answers it by falling back to the commit before — which is
 * the whole rollback mechanism. A version mismatch is not that. Every
 * generation in the directory was written by the same older build, so falling
 * back tries them all, fails at each, and arrives at the initial empty
 * manifest: the index would open cleanly and report nothing in it. Silently.
 * Someone would upgrade the library and conclude their data was gone.
 *
 * So this one is deliberately outside that hierarchy. Nothing catches it on
 * the way up, the caller is told plainly which version they have and which
 * this build reads, and the answer — reindex from your own source — is theirs
 * to act on rather than the library's to guess at.
 */
final class UnsupportedFormatException extends FtsException
{
    public static function segment(string $path, int $found, int $expected): self
    {
        return new self(
            "The index at $path is in segment format version $found, and this build of php-fts"
            . " reads version $expected. An index cannot be converted in place — the terms it"
            . " holds are not the terms this build looks up — so it has to be rebuilt by"
            . " reindexing from your own source data."
        );
    }
}
