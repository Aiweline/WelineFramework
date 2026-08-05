<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Webhook;

/** HTTP-facing receive result — 2xx only after inbox commit. */
final class WebhookReceiveResult
{
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_CONFLICT = 409;
    public const HTTP_GONE = 410;
    public const HTTP_SERVER_ERROR = 500;

    public function __construct(
        public readonly int $httpStatus,
        public readonly string $body,
        public readonly ?string $errorCode = null,
        public readonly ?string $inboxCode = null,
        public readonly bool $inboxWritten = false,
        public readonly bool $replayed = false,
        /** @var list<array<string, mixed>> */
        public readonly array $audit = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }
}
