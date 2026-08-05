<?php

declare(strict_types=1);

namespace Weline\Product\Api\Capability;

/**
 * Inventory capability SPI — strategy metadata for Inventory module (P2B).
 * Does not perform reserve/commit here.
 */
interface ProductInventoryCapabilityInterface
{
    public const STRATEGY_STRICT = 'strict';
    public const STRATEGY_OVERSELL = 'oversell';
    public const STRATEGY_PREORDER = 'preorder';
    public const STRATEGY_UNLIMITED = 'unlimited';

    public function strategy(): string;

    public function supportsReservation(): bool;

    /**
     * @return list<string>
     */
    public function allowedStrategies(): array;
}
