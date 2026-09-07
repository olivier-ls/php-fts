<?php

declare(strict_types=1);

namespace Ols\PhpFts\Exception;

/**
 * Thrown when a search asks to highlight a field it cannot: one the schema
 * does not know, or one it knows but does not store. A highlight is built by
 * re-reading the field's text, so a field that was never kept cannot produce
 * one — and saying so is more useful than returning nothing.
 */
class HighlightException extends FtsException
{
}
