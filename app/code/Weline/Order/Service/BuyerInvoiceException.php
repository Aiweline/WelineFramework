<?php

declare(strict_types=1);

namespace Weline\Order\Service;

final class BuyerInvoiceException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
