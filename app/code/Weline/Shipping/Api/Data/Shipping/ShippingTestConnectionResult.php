<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingTestConnectionResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $payload = [],
    ) {
    }

    public static function unsupported(string $message = 'unsupported'): self
    {
        return new self(self::STATUS_UNSUPPORTED, $message);
    }

    public static function failed(string $message, array $payload = []): self
    {
        return new self(self::STATUS_FAILED, $message, $payload);
    }

    public static function ok(string $message = 'ok', array $payload = []): self
    {
        return new self(self::STATUS_OK, $message, $payload);
    }
}
