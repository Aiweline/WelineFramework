<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;

final class StorefrontScopeHotCacheProcessL1ContractTest extends TestCase
{
    public function testCachePolicyDefaultsStoreProcessL1True(): void
    {
        $policy = new CachePolicy(
            resource: 'test.resource',
            pool: 'product',
            scope: 'channel',
        );
        self::assertTrue($policy->storeProcessL1);
    }

    public function testCachePolicyCanDisableProcessL1(): void
    {
        $policy = new CachePolicy(
            resource: 'product.filter_panel.v3',
            pool: 'product',
            scope: 'channel',
            storeProcessL1: false,
        );
        self::assertFalse($policy->storeProcessL1);
        self::assertFalse($policy->toArray()['store_process_l1']);
    }

    public function testHotCachePipesStoreProcessL1IntoRememberKey(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(StorefrontScopeHotCache::class))->getFileName()
        );
        self::assertStringContainsString('$policy->storeProcessL1', $src);
        self::assertStringContainsString('maybeStoreProcessEntry', $src);
        self::assertStringContainsString('PRODUCT_POOL_MAX_BYTES', $src);
        self::assertStringContainsString('CARD_POOL_MAX_BYTES', $src);
        self::assertStringContainsString('estimateMixedBytes', $src);
        self::assertStringContainsString('oldestProductProcessKey', $src);
    }

    public function testEstimateSamplesNestedStringBytes(): void
    {
        $ref = new \ReflectionClass(StorefrontScopeHotCache::class);
        $method = $ref->getMethod('estimateProcessEntryBytes');
        $method->setAccessible(true);
        $huge = ['a' => \str_repeat('x', 300_000)];
        $bytes = (int)$method->invoke(null, ['payload' => $huge]);
        self::assertGreaterThan(262_144, $bytes);
    }
}
