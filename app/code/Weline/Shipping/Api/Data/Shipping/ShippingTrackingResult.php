<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingTrackingResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $status,
        public readonly string $trackingStatus = '',
        public readonly string $currentLocation = '',
        public readonly string $trackingUrl = '',
        public readonly array $nodes = [],
        public readonly string $message = '',
        public readonly array $payload = [],
    ) {
    }

    public static function unsupported(string $message = 'unsupported'): self
    {
        return new self(self::STATUS_UNSUPPORTED, message: $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::STATUS_FAILED, message: $message);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed> $payload
     */
    public static function ok(
        string $trackingStatus,
        string $currentLocation = '',
        string $trackingUrl = '',
        array $nodes = [],
        array $payload = [],
    ): self {
        return new self(self::STATUS_OK, $trackingStatus, $currentLocation, $trackingUrl, $nodes, '', $payload);
    }
}
