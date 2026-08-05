<?php

declare(strict_types=1);

namespace Weline\Order\Api;

/**
 * Stable cross-module Order Facade failure contract.
 */
class OrderFacadeConflictException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
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
