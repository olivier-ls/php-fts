<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

/**
 * Thrown when a segment file is not a complete, well-formed segment.
 *
 * This exception is deliberately distinct from its parent: it means
 * "this particular segment cannot be trusted", not "the disk is broken".
 *
 * That distinction is what makes rollback possible. A reader that hits this
 * while opening a segment listed in commit N can discard that commit and fall
 * back to commit N-1, which is the mechanism that lets `Durability::Fast`
 * skip fsync without ever risking a corrupt index — the worst case becomes
 * losing the last commit, never reading garbage as if it were data.
 */
class CorruptSegmentException extends StorageException
{
}
