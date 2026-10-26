<?php

declare(strict_types=1);

namespace Weline\Points\Api;

/**
 * Storefront session boundary for the selected points redemption.
 */
interface PointsDiscountApplySessionInterface
{
    /** @return array<string, mixed>|null */
    public function get(): ?array;

    /** @param array<string, mixed> $apply */
    public function save(array $apply): void;

    public function clear(): void;
}
