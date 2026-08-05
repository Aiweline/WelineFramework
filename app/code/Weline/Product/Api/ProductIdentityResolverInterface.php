<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/**
 * Public read-only Product identity resolver.
 *
 * Cross-module consumers must use this contract instead of Product Model/Repository.
 */
interface ProductIdentityResolverInterface
{
    public function resolveBySku(string $sku): ?ProductIdentity;

    public function resolveByProductUuid(string $uuid): ?ProductIdentity;

    public function resolveByOfferUuid(string $uuid): ?ProductIdentity;
}
