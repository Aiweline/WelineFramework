<?php

declare(strict_types=1);

namespace Weline\Product\Integration\Framework;

use Weline\Framework\Http\Fpc\BinQueryCanonicalUriResolverInterface;
use Weline\Product\Service\StorefrontListingFpcQueryKeys;
use Weline\Search\Service\StorefrontSearchFpcQueryKeys;

/**
 * Resolves canonical storefront URIs for catalog/search QueryBin FPC keys on
 * behalf of the Framework FPC publisher.
 */
final class BinQueryCanonicalUriResolver implements BinQueryCanonicalUriResolverInterface
{
    public function canonicalStorefrontUriFromBinQueryParams(
        string $provider,
        string $operation,
        array $params,
        string $hostWithScheme,
    ): ?string {
        $provider = \strtolower(\trim($provider));
        $operation = \trim($operation);

        if (
            $operation === 'filteredListingPage'
            && (
                $provider === 'product_storefront'
                || $provider === 'product'
                || \str_contains($provider, 'product')
            )
        ) {
            return StorefrontListingFpcQueryKeys::canonicalStorefrontUriFromBinQueryParams(
                $params,
                $hostWithScheme,
            );
        }

        if (
            $operation === 'search'
            && (
                $provider === 'search'
                || \str_contains($provider, 'search')
            )
        ) {
            return StorefrontSearchFpcQueryKeys::canonicalStorefrontUriFromBinQueryParams(
                $params,
                $hostWithScheme,
            );
        }

        return null;
    }
}
