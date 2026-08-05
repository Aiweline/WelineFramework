<?php

declare(strict_types=1);

namespace Weline\Order\Service;

final class OrderTradeAdminCommandException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
