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

    public function testEnvironmentOriginUsesCurrentContextInsteadOfStaleGlobals(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        $context = Context::current();
        $context->set('input.host', 'shop.test:9555');
        $context->set('input.scheme', 'https');
        $context->set('route.website_url', 'https://shop.test:9555/');
        $_SERVER['HTTP_HOST'] = 'shop.test:19655';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['WELINE_WEBSITE_URL'] = 'http://shop.test:19655/';

        $request = (new \ReflectionClass(\Weline\Framework\Http\WlsRequest::class))->newInstanceWithoutConstructor();
        foreach (['parsedHost' => 'shop.test:9555', 'parsedHttps' => true] as $name => $value) {
            (new \ReflectionProperty($request, $name))->setValue($request, $value);
        }
        $environment = KeyBuilder::environmentContext();
        self::assertSame($request->getBaseHost(), $environment['base_url']);
        self::assertSame('https://shop.test:9555/', $environment['website_url']);
        self::assertSame('shop.test:9555', $environment['host']);

        \w_env_set('base_url', 'https://shop.test:9555/mounted/');
        self::assertSame('https://shop.test:9555/mounted/', KeyBuilder::environmentContext()['base_url']);
    }

    public function testOnlyRenderedEnvironmentVariesWithRequestTransport(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        $_SERVER['HTTP_HOST'] = 'stale.test:19655';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['WELINE_WEBSITE_URL'] = 'http://stale.test:19655/';
        $scopeKeys = [];
        $environmentKeys = [];
        foreach ([9555, 19655] as $port) {
            Context::current()->set('input.host', 'shop.test:' . $port);
            Context::current()->set('input.scheme', 'https');
            Context::current()->set('route.website_url', 'https://shop.test:' . $port . '/');
            $scopeKeys[] = KeyBuilder::applyDimensionFlags('category-data', true, true, true, true);
            $environmentKeys[] = KeyBuilder::environmentHash(['surface' => 'header']);
        }
        self::assertSame($scopeKeys[0], $scopeKeys[1], 'Fixed scope metadata remains shareable across request origins.');
        self::assertNotSame($environmentKeys[0], $environmentKeys[1], 'Rendered absolute links must follow the current request origin.');
        RequestContext::setId('different-request-id');
        Context::current()->set('input.uri', '/another-page?irrelevant=1');
        self::assertSame($environmentKeys[1], KeyBuilder::environmentHash(['surface' => 'header']));
    }

    public function testEnvironmentOriginRetainsCliGlobalsFallbackWithoutContext(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        RequestContext::cleanup();
        Context::leave();
        $_SERVER['HTTP_HOST'] = 'cli.test:9555';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://cli.test:9555/mounted/';
        $environment = KeyBuilder::environmentContext([], [
            'area' => false, 'area_route' => false, 'website' => false,
            'lang' => false, 'lang_local' => false, 'currency' => false,
        ]);
        self::assertSame('cli.test:9555', $environment['host']);
        self::assertSame('https://cli.test:9555', $environment['base_url']);
        self::assertSame('https://cli.test:9555/mounted/', $environment['website_url']);
        self::assertNull(Context::getCurrent(), 'Resolving CLI cache dimensions must not create a request context.');
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
