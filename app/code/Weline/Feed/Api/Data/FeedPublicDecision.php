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

    /**
     * The body must not be empty: WLS turns an empty non-HEAD/204/304 body into a retryable 503.
     */
    public static function notFound(): self
    {
        return new self(404, 'Not Found', 'text/plain; charset=utf-8');
    }

    public static function ready(string $body, string $contentType): self
    {
        return new self(200, $body, $contentType);
    }
}
