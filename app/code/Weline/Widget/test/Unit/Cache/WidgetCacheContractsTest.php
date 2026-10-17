<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\View\Cache\TemplateFragmentOutputCache;
use Weline\Widget\Cache\WidgetOutputCache;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWidgetCacheContractsPass().
 */
final class WidgetCacheContractsTest extends TestCase
{
    public function testAllWidgetCacheContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WidgetOutputCache::testNormalizeTtlRejectsLegacyAndAcceptsSeconds' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testNormalizeTtlRejectsLegacyAndAcceptsSeconds();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testBuildKeyUsesSystemEnvironmentScopedBuilder' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testBuildKeyUsesSystemEnvironmentScopedBuilder();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testRememberStoresAndHits' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testRememberStoresAndHits();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testDifferentNodeUidProducesDifferentKeys' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testDifferentNodeUidProducesDifferentKeys();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testLayoutNameSplitsCacheKeys' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testLayoutNameSplitsCacheKeys();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testShareMergesLayoutScopedKeys' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testShareMergesLayoutScopedKeys();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testLangAndCurrencySplitCacheKeys' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testLangAndCurrencySplitCacheKeys();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testProductIdSplitsCacheKeys' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testProductIdSplitsCacheKeys();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
            'WidgetOutputCache::testTaglibInjectsProductDimsIntoBuildKey' => function () {
            $this->setUp_WidgetOutputCache();
            try {
                $this->assert_WidgetOutputCache_testTaglibInjectsProductDimsIntoBuildKey();
            } finally {
                $this->tearDown_WidgetOutputCache();
            }
        },
        ];
    }

private function setUp_WidgetOutputCache(): void
{
    $this->enterScope_WidgetOutputCache('default', 'default', 'default', ScopeIdentity::MODE_NORMAL);
}

private function tearDown_WidgetOutputCache(): void
{
    TemplateFragmentOutputCache::resetProcessCaches();
    RequestContext::cleanup();
    if (Context::hasCurrent()) {
        Context::leave();
    }
    parent::tearDown();
}

private function assert_WidgetOutputCache_testNormalizeTtlRejectsLegacyAndAcceptsSeconds(): void
{
    self::assertSame(0, WidgetOutputCache::normalizeTtl(null));
    self::assertSame(0, WidgetOutputCache::normalizeTtl('off'));
    self::assertSame(0, WidgetOutputCache::normalizeTtl('static'));
    self::assertSame(0, WidgetOutputCache::normalizeTtl('on'));
    self::assertSame(0, WidgetOutputCache::normalizeTtl(0));
    self::assertSame(300, WidgetOutputCache::normalizeTtl(300));
    self::assertSame(60, WidgetOutputCache::normalizeTtl('60'));
    self::assertSame(86400, WidgetOutputCache::normalizeTtl(999999));
}

private function assert_WidgetOutputCache_testBuildKeyUsesSystemEnvironmentScopedBuilder(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Cache/WidgetOutputCache.php');
    self::assertStringContainsString('KeyBuilder::buildEnvironmentScoped', $src);
    self::assertStringContainsString("'widget.output'", $src);
    self::assertStringContainsString("'area_route' => false", $src);
    self::assertStringNotContainsString("return 'widget.output.' . \\sha1(", $src);
    self::assertStringContainsString('20261013-widget-output-env-scoped-v4', $src);
}

private function assert_WidgetOutputCache_testRememberStoresAndHits(): void
{
    TemplateFragmentOutputCache::resetProcessCaches();
    $key = WidgetOutputCache::buildKey(['identity' => 'Weline_Demo::content::x', 'template_path' => '', 'node_uid' => 'n1', 'ttl' => 30], ['title' => 'a']);
    $calls = 0;
    $html = WidgetOutputCache::remember(30, $key, static function () use (&$calls): string {
        $calls++;
        return '<div data-testid="widget-cache">ok</div>';
    });
    self::assertSame('<div data-testid="widget-cache">ok</div>', $html);
    self::assertSame(1, $calls);
    $again = WidgetOutputCache::remember(30, $key, static function () use (&$calls): string {
        $calls++;
        return '<div data-testid="widget-cache">miss</div>';
    });
    self::assertSame('<div data-testid="widget-cache">ok</div>', $again);
    self::assertSame(1, $calls);
}

private function assert_WidgetOutputCache_testDifferentNodeUidProducesDifferentKeys(): void
{
    $a = WidgetOutputCache::buildKey(['identity' => 'm::t::c', 'node_uid' => 'a', 'ttl' => 10], []);
    $b = WidgetOutputCache::buildKey(['identity' => 'm::t::c', 'node_uid' => 'b', 'ttl' => 10], []);
    self::assertNotSame($a, $b);
}

private function assert_WidgetOutputCache_testLayoutNameSplitsCacheKeys(): void
{
    $base = ['identity' => 'm::t::c', 'node_uid' => 'n1', 'ttl' => 3600];
    $product = WidgetOutputCache::buildKey($base + ['layout_name' => 'product.default'], []);
    $cart = WidgetOutputCache::buildKey($base + ['layout_name' => 'cart.default'], []);
    $fromParts = WidgetOutputCache::buildKey($base + ['layout_type' => 'product', 'layout_option' => 'default'], []);
    self::assertNotSame($product, $cart);
    self::assertSame($product, $fromParts);
    self::assertSame('product.default', WidgetOutputCache::resolveLayoutName(['layout_type' => 'product', 'layout_option' => 'default']));
}

private function assert_WidgetOutputCache_testShareMergesLayoutScopedKeys(): void
{
    self::assertFalse(WidgetOutputCache::normalizeShare(null));
    self::assertTrue(WidgetOutputCache::normalizeShare(true));
    self::assertTrue(WidgetOutputCache::normalizeShare('true'));
    self::assertFalse(WidgetOutputCache::normalizeShare('off'));
    $base = ['identity' => 'm::t::c', 'node_uid' => 'n1', 'ttl' => 3600];
    $isolatedA = WidgetOutputCache::buildKey($base + ['layout_name' => 'product.default', 'share' => false], []);
    $isolatedB = WidgetOutputCache::buildKey($base + ['layout_name' => 'cart.default', 'share' => false], []);
    $sharedA = WidgetOutputCache::buildKey($base + ['layout_name' => 'product.default', 'share' => true], []);
    $sharedB = WidgetOutputCache::buildKey($base + ['layout_name' => 'cart.default', 'share' => true], []);
    self::assertNotSame($isolatedA, $isolatedB);
    self::assertSame($sharedA, $sharedB);
    self::assertNotSame($isolatedA, $sharedA);
}

private function assert_WidgetOutputCache_testLangAndCurrencySplitCacheKeys(): void
{
    $parts = ['identity' => 'm::t::c', 'node_uid' => 'n1', 'ttl' => 3600, 'layout_name' => 'product.default'];
    $this->enterScope_WidgetOutputCache('default', 'default', 'default', ScopeIdentity::MODE_NORMAL, 'zh_Hans_CN', 'CNY');
    $zhCny = WidgetOutputCache::buildKey($parts, []);
    $this->enterScope_WidgetOutputCache('default', 'default', 'default', ScopeIdentity::MODE_NORMAL, 'en_US', 'CNY');
    $enCny = WidgetOutputCache::buildKey($parts, []);
    $this->enterScope_WidgetOutputCache('default', 'default', 'default', ScopeIdentity::MODE_NORMAL, 'en_US', 'USD');
    $enUsd = WidgetOutputCache::buildKey($parts, []);
    self::assertNotSame($zhCny, $enCny, 'lang must split widget output keys');
    self::assertNotSame($enCny, $enUsd, 'currency must split widget output keys');
    self::assertNotSame($zhCny, $enUsd);
}

private function assert_WidgetOutputCache_testProductIdSplitsCacheKeys(): void
{
    $base = ['identity' => 'm::t::c', 'node_uid' => 'n1', 'ttl' => 3600, 'layout_name' => 'product.default'];
    $a = WidgetOutputCache::buildKey($base + ['product_id' => 101], []);
    $b = WidgetOutputCache::buildKey($base + ['product_id' => 202], []);
    self::assertNotSame($a, $b);
}

private function assert_WidgetOutputCache_testTaglibInjectsProductDimsIntoBuildKey(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Taglib/Widget.php');
    self::assertStringContainsString("'product_id' => \$productId", $src);
    self::assertStringContainsString("'offer_id' => \$offerId", $src);
    self::assertStringContainsString('StorefrontOfferResolver::currentOffer', $src);
}

private function enterScope_WidgetOutputCache(string $website, string $store, string $channel, string $storeMode, string $lang = 'zh_Hans_CN', string $currency = 'CNY'): void
{
    if (Context::hasCurrent()) {
        RequestContext::cleanup();
        Context::leave();
    }
    Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
    RequestContext::setId('widget-output-cache-' . $website . '-' . $lang . '-' . $currency);
    Context::current()->set('input.server.WELINE_AREA', 'frontend');
    RequestContext::installScopeIdentity(ScopeIdentity::channel($website === 'default' ? 0 : 7, $website, $store, $channel, $storeMode));
    RequestContext::setWelineUserLang($lang);
    RequestContext::setWelineUserCurrency($currency);
    StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(RequestContext::scopeIdentity(), $lang, $currency, \str_repeat('a', 64), \str_repeat('a', 64), true));
}
}
