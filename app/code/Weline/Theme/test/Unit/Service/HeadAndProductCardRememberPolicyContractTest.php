<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontProductCardFragmentCache;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * wave6-6a（极小步）：head 页级 Policy + product-card HTML 片段 rememberPolicy。
 * 禁平行 static；禁大改 LayoutSlotRenderer（让 6s）。
 */
final class HeadAndProductCardRememberPolicyContractTest extends TestCase
{
    public function testStorefrontHeadPolicyIsPageScopedChromePool(): void
    {
        $policy = StorefrontThemeCacheCoordinator::storefrontHeadPolicy(120, 600);
        self::assertSame('theme.storefront_head', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::STOREFRONT_CHROME_POOL, $policy->pool);
        // wave9-9s: website scope (align chrome 8c8) so policyKey resolves on website fence.
        self::assertSame('website', $policy->scope);
        self::assertContains('theme', $policy->dependencies);
        self::assertContains('global/i18n', $policy->dependencies);
        self::assertSame(120, $policy->freshTtlSeconds);
    }

    public function testStorefrontHeadAssetsPolicyIsRouteInvariant(): void
    {
        $policy = StorefrontThemeCacheCoordinator::storefrontHeadAssetsPolicy(90, 600);
        self::assertSame('theme.storefront_head_assets', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::STOREFRONT_CHROME_POOL, $policy->pool);
        self::assertSame('website', $policy->scope);
        self::assertEqualsCanonicalizing(['lang'], $policy->vary);
        self::assertContains('theme', $policy->dependencies);
        self::assertNotContains('catalog', $policy->dependencies);
        self::assertSame(90, $policy->freshTtlSeconds);
    }

    public function testProductCardHtmlPolicyUsesDedicatedPool(): void
    {
        $policy = StorefrontThemeCacheCoordinator::productCardHtmlPolicy();
        self::assertSame('theme.product_card_html', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::PRODUCT_CARD_HTML_POOL, $policy->pool);
        self::assertSame(StorefrontProductCardFragmentCache::cachePool(), $policy->pool);
        self::assertSame('channel', $policy->scope);
        self::assertContains('catalog', $policy->dependencies);
        self::assertContains('theme', $policy->dependencies);
        self::assertEqualsCanonicalizing(['lang', 'currency'], $policy->vary);
    }

    public function testProductCardLogicalKeyPartitionsByProductAndFlags(): void
    {
        $cache = (new \ReflectionClass(StorefrontProductCardFragmentCache::class))
            ->newInstanceWithoutConstructor();
        $a = $cache->logicalKey(
            ['id' => 11, 'name' => 'A', 'price' => 1.0, 'currency' => 'CNY'],
            ['density' => 'standard', 'show_price' => true]
        );
        $b = $cache->logicalKey(
            ['id' => 12, 'name' => 'B', 'price' => 2.0, 'currency' => 'CNY'],
            ['density' => 'standard', 'show_price' => true]
        );
        $c = $cache->logicalKey(
            ['id' => 11, 'name' => 'A', 'price' => 1.0, 'currency' => 'CNY'],
            ['density' => 'compact', 'show_price' => true]
        );

        self::assertStringStartsWith('theme.product_card.html.v4.', $a);
        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
    }

    public function testProductCardLogicalKeyPartitionsByRequestOrigin(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductCardFragmentCache.php'
        );
        // Absolute @url links in card HTML must not cross Worker :19655 vs public :9555.
        self::assertStringContainsString('storefrontOriginSegment', $src);
        self::assertStringContainsString('theme.product_card.html.v4.', $src);
        self::assertStringContainsString("'website_url' => true", $src);
        self::assertStringContainsString("'host' => true", $src);
        self::assertStringContainsString("'base_url' => true", $src);
    }

    public function testHeadPartialMetaAndPartialsWireHeadPolicy(): void
    {
        $head = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/default.phtml'
        );
        $partials = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Block/Partials.php'
        );

        self::assertStringContainsString('@meta.cache.mode {default="chrome"', $head);
        self::assertStringContainsString('storefrontHeadPolicy', $partials);
        self::assertStringContainsString('storefrontHeadAssetsPolicy', $partials);
        self::assertStringContainsString('renderStorefrontHeadComposedOrMonolithic', $partials);
        self::assertStringContainsString('theme.head.', $partials);
        self::assertStringContainsString('theme.head.assets.', $partials);
        self::assertStringContainsString('normalizeHeadPartialCacheData', $partials);
        self::assertStringContainsString('seo_fp', $partials);
        self::assertStringContainsString('request_path', $partials);
        self::assertFileExists(dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/assets-prefix.phtml');
        self::assertFileExists(dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/page.phtml');
        self::assertFileExists(dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/assets-suffix.phtml');
    }

    public function testCleanerPurgesHeadAndProductCardPools(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ThemeRuntimeCacheCleaner.php'
        );
        self::assertStringContainsString("purgeProcessCacheForLogicalKey('theme.head.')", $src);
        self::assertStringContainsString('purgeProductCardHtmlHotCachePool', $src);
        self::assertStringContainsString('PRODUCT_CARD_HTML_POOL', $src);
    }
}
