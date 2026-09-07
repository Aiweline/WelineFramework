<?php

declare(strict_types=1);

namespace Weline\Product\Exception;

/**
 * Illegal or blocked Product quote-request status transition.
 */
final class ProductQuoteRequestStateTransitionException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
