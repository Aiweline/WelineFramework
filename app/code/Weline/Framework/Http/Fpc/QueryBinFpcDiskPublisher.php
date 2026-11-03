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
        try {
            return \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Http\Fpc\BinQueryCanonicalUriResolverInterface::class
            )->canonicalStorefrontUriFromBinQueryParams($provider, $operation, $params, $hostWithScheme);
        } catch (\Throwable) {
            // 无业务模块提供归一化器时（纯 CMS 站），QueryBin FPC 不产 canonical URI。
            return null;
        }
    }
}
