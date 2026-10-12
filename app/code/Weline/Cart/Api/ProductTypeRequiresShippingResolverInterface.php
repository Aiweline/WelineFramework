<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

/**
 * Resolves default requires_shipping from product_type when Offer/snapshot flag is absent.
 *
 * Prefer ProductTypeDefinition via Product provider preference; Cart ships a legacy
 * whitelist fallback when Product is not bound. Never special-case site types here.
 */
interface ProductTypeRequiresShippingResolverInterface
{
    /**
     * @return bool|null null = unknown type (caller should default to physical true)
     */
    public function requiresShippingForProductType(string $productType): ?bool;
}
