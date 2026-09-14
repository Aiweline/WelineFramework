<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingShipmentResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $status,
        public readonly string $trackingNumber = '',
        public readonly string $providerReference = '',
        public readonly string $message = '',
        public readonly array $payload = [],
    ) {
    }

    public static function unsupported(string $message = 'unsupported'): self
    {
        return new self(self::STATUS_UNSUPPORTED, message: $message);
    }

    public static function failed(string $message, array $payload = []): self
    {
        return new self(self::STATUS_FAILED, message: $message, payload: $payload);
    }

    public static function ok(
        string $trackingNumber,
        string $providerReference = '',
        array $payload = [],
    ): self {
        return new self(self::STATUS_OK, $trackingNumber, $providerReference, '', $payload);
    }
}
