<?php

declare(strict_types=1);

namespace Ols\PhpFts;

/**
 * One search result.
 */
final class Hit
{
    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public readonly string $id,
        public readonly float $score,
        public readonly array $document,
    ) {
    }
}
