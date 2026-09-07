<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

/**
 * Thrown when a search cannot be ordered the way it was asked to be: a field
 * with no column to read, one whose values are not numbers, or a criterion
 * that is not a Sort at all.
 */
class SortException extends FtsException
{
}
