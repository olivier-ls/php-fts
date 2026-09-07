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
     *
     * @param array<string, string|array{text: string, spans: array<int, array{0: int, 1: int}>}> $highlights
     *        one entry per field that matched, and only for the fields the
     *        search asked to highlight. A field the query did not touch is
     *        absent rather than present-and-unmarked, so that a template can
     *        fall back to the raw value with `?? $hit->document['title']`.
     */
    public function __construct(
        public readonly string $id,
        public readonly float $score,
        public readonly array $document,
        public readonly array $highlights = [],
    ) {
    }
}
