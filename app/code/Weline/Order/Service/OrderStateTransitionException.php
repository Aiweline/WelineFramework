<?php

declare(strict_types=1);

namespace Weline\Order\Service;

final class OrderStateTransitionException extends \RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        private readonly string $transitionCode,
        string $message,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->transitionCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
