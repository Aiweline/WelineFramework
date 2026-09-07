<?php

declare(strict_types=1);

namespace Weline\Product\Api\Storefront;

use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;

/**
 * SPI: Marketing / Promotion / future modules propose unit-price adjustments.
 *
 * Register via `extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/`.
 * Do not cross-module `new` Product Assembler internals; implement this interface only.
 */
interface StorefrontPriceAdjustmentProviderInterface
{
    public function getCode(): string;

    /** Higher runs first when collecting; merge still uses exclusive-group savings. */
    public function getPriority(): int;

    /**
     * @return list<StorefrontPriceAdjustment>
     */
    public function collectAdjustments(StorefrontPriceContext $context): array;
}
