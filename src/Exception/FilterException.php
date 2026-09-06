<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

/**
 * Thrown when a search filter is malformed: unknown operator,
 * missing key, or a value whose type the operator cannot handle.
 */
class FilterException extends FtsException
{
}
