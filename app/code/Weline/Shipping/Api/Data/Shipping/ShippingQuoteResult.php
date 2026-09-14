<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingQuoteResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, array{amount_minor:int,label:string,currencies:list<string>,free_reason?:string,lane_specificity?:int,carrier_id?:int}> $rates
     * @param list<string> $fxSkipped
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public readonly string $status,
        public readonly array $rates = [],
        public readonly array $fxSkipped = [],
        public readonly string $message = '',
        public readonly array $diagnostics = [],
    ) {
    }

    public static function unsupported(string $message = 'unsupported'): self
    {
        return new self(self::STATUS_UNSUPPORTED, message: $message);
    }

    public static function failed(string $message, array $diagnostics = []): self
    {
        return new self(self::STATUS_FAILED, message: $message, diagnostics: $diagnostics);
    }

    /**
     * @param array<string, array{amount_minor:int,label:string,currencies:list<string>,free_reason?:string,lane_specificity?:int,carrier_id?:int}> $rates
     * @param list<string> $fxSkipped
     * @param array<string, mixed> $diagnostics
     */
    public static function ok(array $rates, array $fxSkipped = [], array $diagnostics = []): self
    {
        return new self(self::STATUS_OK, $rates, $fxSkipped, '', $diagnostics);
    }
}
