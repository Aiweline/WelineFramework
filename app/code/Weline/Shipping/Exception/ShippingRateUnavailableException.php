<?php

declare(strict_types=1);

namespace Weline\Shipping\Exception;

final class ShippingRateUnavailableException extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reasonCode);
    }
}
