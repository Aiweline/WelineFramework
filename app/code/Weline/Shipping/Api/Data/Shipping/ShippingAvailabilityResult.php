<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingAvailabilityResult
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $reasons = [],
    ) {
    }

    public static function yes(): self
    {
        return new self(true);
    }

    /**
     * @param list<string> $reasons
     */
    public static function no(array $reasons = []): self
    {
        return new self(false, $reasons);
    }
}
