<?php

declare(strict_types=1);

namespace Weline\Product\Service\Ai;

/**
 * Business failure of the Product AI optimization pipeline.
 *
 * Controllers map {@see httpStatus()} and {@see errorCode()} straight to the
 * JSON error envelope; no provider message is ever shown verbatim.
 */
final class ProductAiException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 422,
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
