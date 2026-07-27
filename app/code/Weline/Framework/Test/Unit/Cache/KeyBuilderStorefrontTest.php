<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

class KeyBuilderStorefrontTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->enterScope('default', 'default', 'default', ScopeIdentity::MODE_NORMAL);
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testResolveWebsiteCodeUsesFrozenIdentityAndIgnoresServerPollution(): void
    {
        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_DEV);
        $_SERVER['WELINE_WEBSITE_CODE'] = 'polluted';
        self::assertSame('shop_a', KeyBuilder::resolveWebsiteCode());
        self::assertSame('retail', KeyBuilder::resolveStoreCode());
        self::assertSame('web', KeyBuilder::resolveChannelCode());
        self::assertSame(ScopeIdentity::MODE_DEV, KeyBuilder::resolveStoreMode());

        $this->enterScope('default', 'default', 'default', ScopeIdentity::MODE_NORMAL);
        $_SERVER['WELINE_WEBSITE_ID'] = '12';
        self::assertSame('default', KeyBuilder::resolveWebsiteCode());
    }

    public function testApplyDimensionFlagsFullEscapeLeavesLogicalKey(): void
    {
        self::assertSame('phrase:zh_Hans_CN', KeyBuilder::applyDimensionFlags('phrase:zh_Hans_CN'));
    }

    public function testApplyDimensionFlagsSelectiveAndDefaultStorefront(): void
    {
        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_TEST, 'en_US', 'USD');

        $langOnly = KeyBuilder::applyDimensionFlags('menu', false, true, false, false);
        self::assertStringStartsWith('menu|schema=storefront-cache-v2|lang=', $langOnly);
        self::assertStringContainsString('lang=en_US', $langOnly);
        self::assertStringNotContainsString('website=', $langOnly);
        self::assertStringNotContainsString('cache_version=', $langOnly);
        self::assertStringNotContainsString('currency=', $langOnly);
        self::assertStringNotContainsString('area=', $langOnly);

        $full = KeyBuilder::applyDimensionFlags('menu', true, true, true, true);
        self::assertStringContainsString('area=', $full);
        self::assertStringContainsString('website=shop_a', $full);
        self::assertStringContainsString('store=retail', $full);
        self::assertStringContainsString('channel=web', $full);
        self::assertStringContainsString('store_mode=test', $full);
        self::assertStringContainsString('context_version=v1', $full);
        self::assertStringContainsString('cache_version=' . str_repeat('a', 64), $full);
        self::assertStringContainsString('lang=en_US', $full);
        self::assertStringContainsString('currency=USD', $full);
    }

    public function testStorefrontDimensionsNeverUsesWebsiteId(): void
    {
        $dims = KeyBuilder::storefrontDimensions();
        self::assertSame('default', $dims['website']);
        self::assertArrayNotHasKey('website_id', $dims);
        self::assertSame('channel', $dims['scope_kind']);
        self::assertSame('frozen', $dims['scope_state']);
        self::assertSame(str_repeat('a', 64), $dims['namespace_fingerprint']);
        self::assertSame(str_repeat('a', 64), $dims['cache_key_fingerprint']);
        self::assertArrayHasKey('lang', $dims);
        self::assertArrayHasKey('currency', $dims);
        self::assertArrayHasKey('area', $dims);
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
        RequestContext::setId('key-builder-' . $website . '-' . $store . '-' . $channel);
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
            str_repeat('a', 64),
            str_repeat('a', 64),
            true,
        ));
    }
}
