<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CachePolicy;
use Weline\Websites\Service\SalesChannelCatalog;
use Weline\Websites\Service\StoreCatalog;
use Weline\Websites\Service\StorefrontScopeCatalogCacheCoordinator;

final class StorefrontScopeCatalogCacheContractTest extends TestCase
{
    public function testCatalogPoliciesUseTheCentralScopeCacheBoundary(): void
    {
        $storePolicy = StorefrontScopeCatalogCacheCoordinator::storePolicy();
        $channelPolicy = StorefrontScopeCatalogCacheCoordinator::channelPolicy();

        self::assertInstanceOf(CachePolicy::class, $storePolicy);
        self::assertSame('website', $storePolicy->scope);
        self::assertSame('website', $storePolicy->pool);
        self::assertSame(['catalog'], $storePolicy->dependencies);

        self::assertInstanceOf(CachePolicy::class, $channelPolicy);
        self::assertSame('store', $channelPolicy->scope);
        self::assertSame('website', $channelPolicy->pool);
        self::assertSame(['catalog'], $channelPolicy->dependencies);
    }

    public function testCatalogReadsDelegateToRequestMemoAndPolicyCache(): void
    {
        $storeSource = (string)file_get_contents((string)(new \ReflectionClass(StoreCatalog::class))->getFileName());
        $channelSource = (string)file_get_contents((string)(new \ReflectionClass(SalesChannelCatalog::class))->getFileName());

        self::assertStringContainsString('rememberForRequest', $storeSource);
        self::assertStringContainsString('StorefrontScopeCatalogCacheCoordinator::storePolicy()', $storeSource);
        self::assertStringContainsString('rememberForRequest', $channelSource);
        self::assertStringContainsString('StorefrontScopeCatalogCacheCoordinator::channelPolicy()', $channelSource);
    }
}
