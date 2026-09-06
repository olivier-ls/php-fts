<?php

declare(strict_types=1);

namespace Ols\PhpFts\Index;

/**
 * One term's posting list: the sorted document numbers that contain it.
 *
 * ── Layout ──────────────────────────────────────────────────────────────────
 *
 *   count        varint          how many documents
 *
 *   SKIP TABLE   present only when the list spans more than one block
 *     per block: firstOrdinal u32 | blockOffset u32
 *
 *   BLOCKS       128 documents each
 *     first document of the block, absolute varint
 *     then the gap to each following document, varint
 *
 * ── Why gaps ────────────────────────────────────────────────────────────────
 *
 * Document numbers within a segment are ascending, so a list looks like
 * 4, 9, 11, 12, 40. Stored as gaps that becomes 4, 5, 2, 1, 28 — and a term
 * that appears in one document out of three produces gaps that are nearly
 * always small enough for a single varint byte. Measured on realistic lists,
 * this is four times smaller than fixed-width uint32 for common terms.
 *
 * ── Why blocks, and why the skip table is fixed-width ───────────────────────
 *
 * Gaps can only be read forward: to know the hundredth document you must add up
 * the ninety-nine gaps before it. That is fine when a query wants the whole
 * list, and ruinous when it wants to know whether document 8 000 is in it.
 *
 * So the list is cut into blocks of 128, each starting from an absolute
 * document number and therefore decodable on its own, and a skip table records
 * where each block begins. Finding a document becomes: binary-search the skip
 * table, decode one block, scan 128 gaps at most.
 *
 * The skip table is fixed-width — two `u32` per block — precisely so it *can*
 * be binary-searched, with `unpack` and no decoding at all. That is the same
 * rule the term dictionary follows, and it is worth stating as a rule of the
 * format: **variable-length where you scan, fixed-width where you jump.**
 *
 * ── What this replaces ──────────────────────────────────────────────────────
 *
 * v1 had no skip structure, so it could not jump. Its answer was to cap each
 * list at `maxCandidates` (5 000 by default) and — worse — to read that cap
 * from the *end* of the list. Any term appearing in more than 5 000 documents
 * silently restricted results to the most recently indexed ones, and a document
 * could be dropped from an intersection purely because it sat outside another
 * term's window. The failure never raised anything; recall simply degraded as
 * the corpus grew. Blocks and a skip table remove the reason that cap existed,
 * so `maxCandidates` disappears from the public API entirely.
 */
final class PostingsFormat
{
    /**
     * Documents per block.
     *
     * The trade-off: smaller blocks mean a finer-grained skip table and less
     * wasted decoding on a jump, but more absolute values and a larger table.
     * 128 is the conventional starting point, to be settled by measurement
     * rather than argument.
     */
    public const BLOCK_SIZE = 128;

    /** firstOrdinal u32 + blockOffset u32 */
    public const SKIP_ENTRY_SIZE = 8;

    /** Returned when a cursor has moved past the last document. */
    public const END = PHP_INT_MAX;

    private function __construct()
    {
    }

    public static function blockCount(int $documentCount): int
    {
        return (int) ceil($documentCount / self::BLOCK_SIZE);
    }

    /**
     * A skip table is only written when there is more than one block: for a
     * short list it would cost more than the scan it saves.
     */
    public static function hasSkipTable(int $documentCount): bool
    {
        return self::blockCount($documentCount) > 1;
    }
}
