<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;

/**
 * P6：policy 页级预取 API — 把多次 shared_read 收成一次 shared_read_batch。
 */
final class StorefrontScopeHotCachePrefetchPolicyContractTest extends TestCase
{
    public function testPrefetchPolicyApiExistsAndIsDocumentedForWidgetAssets(): void
    {
        self::assertTrue(method_exists(StorefrontScopeHotCache::class, 'prefetchPolicy'));
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cache/Service/StorefrontScopeHotCache.php'
        );
        self::assertStringContainsString('storefront.cache.shared_read_batch', $src);
        self::assertStringContainsString('readSharedMultiple', $src);
        self::assertStringContainsString('getMultipleCustom', $src);
    }

    public function testCachePoolExposesGetMultipleCustomMatchingGetCustom(): void
    {
        $poolSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cache/Pool/CachePool.php'
        );
        self::assertStringContainsString('function getMultipleCustom', $poolSrc);
        self::assertStringContainsString('buildCustomKey', $poolSrc);
        $nsSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cache/Pool/NamespaceScopedCachePool.php'
        );
        self::assertStringContainsString('function getMultipleCustom', $nsSrc);
    }
}
