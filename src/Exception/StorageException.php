<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

/**
 * Thrown when an index file cannot be opened, read, written,
 * or when its content is not what the format expects.
 */
class StorageException extends FtsException
{
}
