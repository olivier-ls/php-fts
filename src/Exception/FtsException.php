<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

use RuntimeException;

/**
 * Base class for every exception thrown by php-fts.
 *
 * Extends the global \RuntimeException so that existing code catching
 * \RuntimeException keeps working unchanged.
 */
class FtsException extends RuntimeException
{
}
