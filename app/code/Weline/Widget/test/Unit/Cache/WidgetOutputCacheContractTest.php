<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Cache\TemplateFragmentOutputCache;
use Weline\Widget\Cache\WidgetOutputCache;

final class WidgetOutputCacheContractTest extends TestCase
{
    protected function tearDown(): void
    {
        TemplateFragmentOutputCache::resetProcessCaches();
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
}
