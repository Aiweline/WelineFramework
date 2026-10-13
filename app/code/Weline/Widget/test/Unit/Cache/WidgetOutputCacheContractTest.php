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

final class WidgetOutputCacheContractTest extends TestCase
{
    protected function setUp(): void
    {
        $this->enterScope('default', 'default', 'default', ScopeIdentity::MODE_NORMAL);
    }

    protected function tearDown(): void
    {
        TemplateFragmentOutputCache::resetProcessCaches();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testNormalizeTtlRejectsLegacyAndAcceptsSeconds(): void
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

    public function testBuildKeyUsesSystemEnvironmentScopedBuilder(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Cache/WidgetOutputCache.php',
        );
        self::assertStringContainsString('KeyBuilder::buildEnvironmentScoped', $src);
        self::assertStringContainsString("'widget.output'", $src);
        self::assertStringContainsString("'area_route' => false", $src);
        self::assertStringNotContainsString("return 'widget.output.' . \\sha1(", $src);
        self::assertStringContainsString('20261013-widget-output-env-scoped-v4', $src);
    }

    public function testRememberStoresAndHits(): void
    {
        TemplateFragmentOutputCache::resetProcessCaches();
        $key = WidgetOutputCache::buildKey([
            'identity' => 'Weline_Demo::content::x',
            'template_path' => '',
            'node_uid' => 'n1',
            'ttl' => 30,
        ], ['title' => 'a']);
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

    public function testDifferentNodeUidProducesDifferentKeys(): void
    {
        $a = WidgetOutputCache::buildKey(['identity' => 'm::t::c', 'node_uid' => 'a', 'ttl' => 10], []);
        $b = WidgetOutputCache::buildKey(['identity' => 'm::t::c', 'node_uid' => 'b', 'ttl' => 10], []);
        self::assertNotSame($a, $b);
    }

    public function testLayoutNameSplitsCacheKeys(): void
    {
        $base = ['identity' => 'm::t::c', 'node_uid' => 'n1', 'ttl' => 3600];
        $product = WidgetOutputCache::buildKey($base + ['layout_name' => 'product.default'], []);
        $cart = WidgetOutputCache::buildKey($base + ['layout_name' => 'cart.default'], []);
        $fromParts = WidgetOutputCache::buildKey($base + [
            'layout_type' => 'product',
            'layout_option' => 'default',
        ], []);
        self::assertNotSame($product, $cart);
        self::assertSame($product, $fromParts);
        self::assertSame('product.default', WidgetOutputCache::resolveLayoutName([
            'layout_type' => 'product',
            'layout_option' => 'default',
        ]));
    }

    public function testShareMergesLayoutScopedKeys(): void
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

    public function testLangAndCurrencySplitCacheKeys(): void
    {
        $parts = [
            'identity' => 'm::t::c',
            'node_uid' => 'n1',
            'ttl' => 3600,
            'layout_name' => 'product.default',
        ];

        $this->enterScope('default', 'default', 'default', ScopeIdentity::MODE_NORMAL, 'zh_Hans_CN', 'CNY');
        $zhCny = WidgetOutputCache::buildKey($parts, []);

        $this->enterScope('default', 'default', 'default', ScopeIdentity::MODE_NORMAL, 'en_US', 'CNY');
        $enCny = WidgetOutputCache::buildKey($parts, []);

        $this->enterScope('default', 'default', 'default', ScopeIdentity::MODE_NORMAL, 'en_US', 'USD');
        $enUsd = WidgetOutputCache::buildKey($parts, []);

        self::assertNotSame($zhCny, $enCny, 'lang must split widget output keys');
        self::assertNotSame($enCny, $enUsd, 'currency must split widget output keys');
        self::assertNotSame($zhCny, $enUsd);
    }

    public function testProductIdSplitsCacheKeys(): void
    {
        $base = ['identity' => 'm::t::c', 'node_uid' => 'n1', 'ttl' => 3600, 'layout_name' => 'product.default'];
        $a = WidgetOutputCache::buildKey($base + ['product_id' => 101], []);
        $b = WidgetOutputCache::buildKey($base + ['product_id' => 202], []);
        self::assertNotSame($a, $b);
    }

    public function testTaglibInjectsProductDimsIntoBuildKey(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Taglib/Widget.php',
        );
        self::assertStringContainsString("'product_id' => \$productId", $src);
        self::assertStringContainsString("'offer_id' => \$offerId", $src);
        self::assertStringContainsString('StorefrontOfferResolver::currentOffer', $src);
    }

    private function enterScope(
        string $website,
        string $store,
        string $channel,
        string $storeMode,
        string $lang = 'zh_Hans_CN',
        string $currency = 'CNY',
    ): void {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('widget-output-cache-' . $website . '-' . $lang . '-' . $currency);
        Context::current()->set('input.server.WELINE_AREA', 'frontend');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            $website === 'default' ? 0 : 7,
            $website,
            $store,
            $channel,
            $storeMode,
        ));
        RequestContext::setWelineUserLang($lang);
        RequestContext::setWelineUserCurrency($currency);
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            RequestContext::scopeIdentity(),
            $lang,
            $currency,
            \str_repeat('a', 64),
            \str_repeat('a', 64),
            true,
        ));
    }
}
