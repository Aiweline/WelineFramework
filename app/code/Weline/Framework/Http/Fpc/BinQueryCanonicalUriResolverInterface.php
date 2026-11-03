<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * Optional canonical-URI resolver for storefront QueryBin FPC keys, supplied by
 * the owning business modules (catalog/search).
 */
interface BinQueryCanonicalUriResolverInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function canonicalStorefrontUriFromBinQueryParams(
        string $provider,
        string $operation,
        array $params,
        string $hostWithScheme,
    ): ?string;
}
