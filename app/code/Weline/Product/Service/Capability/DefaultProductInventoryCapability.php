<?php

declare(strict_types=1);

namespace Weline\Product\Service\Capability;

use Weline\Product\Api\Capability\ProductInventoryCapabilityInterface;

final class DefaultProductInventoryCapability implements ProductInventoryCapabilityInterface
{
    public function __construct(
        private readonly string $strategy = self::STRATEGY_STRICT,
        private readonly bool $supportsReservation = true,
    ) {
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function supportsReservation(): bool
    {
        return $this->supportsReservation;
    }

    public function allowedStrategies(): array
    {
        return [
            self::STRATEGY_STRICT,
            self::STRATEGY_OVERSELL,
            self::STRATEGY_PREORDER,
            self::STRATEGY_UNLIMITED,
        ];
    }
}
