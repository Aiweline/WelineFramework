<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * After successful QueryBin call: publish index.bq.br under the same ~q identity
 * as HTML Extra FPC. Identity is always server-recomputed (never client sha).
 */
final class QueryBinFpcDiskPublisher
{
    /**
     * @param array<string, mixed> $params
     */
    public static function tryPublish(
        string $provider,
        string $operation,
        array $params,
        string $wireBody,
        string $hostWithScheme,
    ): bool {
        if ($wireBody === '' || $hostWithScheme === '') {
            return false;
        }
        $uri = self::canonicalUri($provider, $operation, $params, $hostWithScheme);
        if ($uri === null) {
            return false;
        }
        $brotli = '';
        if (\function_exists('brotli_compress')) {
            $compressed = @\brotli_compress($wireBody, 5);
            if (\is_string($compressed) && $compressed !== '') {
                $brotli = $compressed;
            }
        }

        return FpcHtmlDiskStore::publishBq($uri, $brotli === '' ? $wireBody : '', $brotli);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function canonicalUri(
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
            && \class_exists(\Weline\Product\Service\StorefrontListingFpcQueryKeys::class)
        ) {
            return \Weline\Product\Service\StorefrontListingFpcQueryKeys::canonicalStorefrontUriFromBinQueryParams(
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
            && \class_exists(\Weline\Search\Service\StorefrontSearchFpcQueryKeys::class)
        ) {
            return \Weline\Search\Service\StorefrontSearchFpcQueryKeys::canonicalStorefrontUriFromBinQueryParams(
                $params,
                $hostWithScheme,
            );
        }

        return null;
    }
}
