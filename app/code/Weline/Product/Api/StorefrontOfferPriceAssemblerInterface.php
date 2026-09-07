<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\StorefrontOfferPriceView;
use Weline\Product\Api\Data\StorefrontPriceContext;

/**
 * Single Product-owned read entry for storefront unit prices.
 *
 * Cards / PDP / cart snapshots must call this — not template-local deal math.
 */
interface StorefrontOfferPriceAssemblerInterface
{
    public function assemble(StorefrontPriceContext $context): StorefrontOfferPriceView;
}
