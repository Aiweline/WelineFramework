<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedPublicDecision
{
    private function __construct(
        public int $statusCode,
        public string $body,
        public string $contentType,
    ) {
    }

    public static function notFound(): self
    {
        return new self(404, '', 'text/plain; charset=utf-8');
    }

    public static function ready(string $body, string $contentType): self
    {
        return new self(200, $body, $contentType);
    }
}
