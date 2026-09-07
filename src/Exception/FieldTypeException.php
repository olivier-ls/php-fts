<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

/**
 * A value does not match the type its field was declared with.
 *
 * Only ever thrown against a schema the caller declared. A schema the engine
 * inferred is a guess, and refusing data on the strength of a guess would be
 * exactly backwards — so the turnkey path never raises this.
 */
final class FieldTypeException extends FtsException
{
}
