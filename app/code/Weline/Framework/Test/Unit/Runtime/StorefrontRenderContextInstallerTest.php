<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontRenderContext;
use Weline\Framework\Runtime\StorefrontRenderContextInstaller;
use Weline\Framework\Runtime\StorefrontRenderContextReader;
use Weline\Framework\Runtime\StorefrontRenderContextResolver;

/**
 * WS1：StorefrontRenderContext 填充侧 — 幂等 / 袋键 / 禁半装.
 */
final class StorefrontRenderContextInstallerTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testBagKeyConstantIsFrozenAuthority(): void
    {
        self::assertSame('storefront.render_context.v1', StorefrontRenderContext::BAG_KEY);
        self::assertSame('storefront.render_context.v1', StorefrontRenderContextReader::bagKey());
        self::assertSame('websites.website_local_rows.v1', StorefrontRenderContext::WEBSITE_LOCAL_ROWS_BAG_KEY);
    }

    public function testInstallOnceIsIdempotent(): void
    {
        $this->enterChannelScope();
        $installer = new StorefrontRenderContextInstaller();
        $first = $installer->installOnce();
        $second = $installer->installOnce();
        $viaResolver = (new StorefrontRenderContextResolver($installer))->freezeCurrent();

        self::assertSame($first, $second);
        self::assertSame($first, $viaResolver);
        self::assertSame($first, StorefrontRenderContext::current());
        self::assertTrue($first->complete);
        self::assertSame('', $first->failureCode);
        self::assertSame('shop_a', $first->websiteCode);
        self::assertSame(7, $first->websiteId);
        self::assertSame('en_US', $first->locale);
        self::assertSame('USD', $first->currency);
    }

    public function testInstallProjectsWebsiteLocalFromUnderlyingBag(): void
    {
        $this->enterChannelScope();
        $rows = [
            ['local_code' => 'en_US', 'name' => 'Shop A', 'description' => ''],
            ['local_code' => 'zh_Hans_CN', 'name' => '商店A', 'description' => ''],
        ];
        RequestContext::set(StorefrontRenderContext::WEBSITE_LOCAL_ROWS_BAG_KEY, [
            '7' => $rows,
        ]);

        $ctx = (new StorefrontRenderContextInstaller())->installOnce();

        self::assertSame($rows, $ctx->websiteLocal);
        self::assertSame($rows, StorefrontRenderContextReader::websiteLocal());
    }

    public function testReaderMergeUpdatesSingleBagWithoutParallelKey(): void
    {
        $this->enterChannelScope();
        (new StorefrontRenderContextInstaller())->installOnce();

        $merged = StorefrontRenderContextReader::mergeFields([
            'maintenance' => [
                'scope_key' => 'website:shop_a',
                'enabled' => false,
                'reason' => '',
                'generation' => 1,
                'since' => 0,
            ],
            'theme_meta' => ['area' => 'frontend', 'count' => 0, 'keys' => []],
        ]);

        self::assertInstanceOf(StorefrontRenderContext::class, $merged);
        self::assertFalse($merged->maintenance['enabled'] ?? true);
        self::assertSame(['area' => 'frontend', 'count' => 0, 'keys' => []], $merged->themeMeta);
        self::assertSame($merged, StorefrontRenderContext::current());
        // No parallel second authority key.
        self::assertNull(RequestContext::get('seo.website_row'));
        self::assertNull(RequestContext::get('i18n.locales_dup'));
    }

    public function testIncompleteScopeInstallsFailureMarkedBagNotHalfState(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('render-incomplete-' . bin2hex(random_bytes(4)));
        // No ScopeIdentity / website → incomplete but atomic bag.
        $ctx = (new StorefrontRenderContextInstaller())->installOnce();

        self::assertFalse($ctx->complete);
        self::assertSame('storefront_render_scope_incomplete', $ctx->failureCode);
        self::assertSame($ctx, StorefrontRenderContext::current());
        self::assertSame($ctx, (new StorefrontRenderContextInstaller())->installOnce());
    }

    public function testNoRequestContextDoesNotInstallProcessStatic(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }

        $ctx = (new StorefrontRenderContextInstaller())->installOnce();

        self::assertFalse($ctx->complete);
        self::assertSame('storefront_render_no_request_context', $ctx->failureCode);
        self::assertNull(StorefrontRenderContext::current());
    }

    public function testReentrantInstallRefusesSilentHalfBag(): void
    {
        $this->enterChannelScope();
        // Simulate pending latch (as if nested install).
        RequestContext::set('storefront.render_context.install_pending.v1', true);
        $ctx = (new StorefrontRenderContextInstaller())->installOnce();

        self::assertFalse($ctx->complete);
        self::assertSame('storefront_render_install_reentrant', $ctx->failureCode);
        // Must not have written a bag while pending (禁半装).
        self::assertNull(StorefrontRenderContext::current());
    }

    public function testAppHooksInstallerNearCacheKeyFreeze(): void
    {
        $appSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/App.php');
        self::assertStringContainsString('StorefrontRenderContextInstaller', $appSrc);
        self::assertStringContainsString('storefront_render_context', $appSrc);
        self::assertMatchesRegularExpression(
            '/storefront_cache_key_context[\s\S]{0,800}StorefrontRenderContextInstaller/',
            $appSrc,
        );
    }

    private function enterChannelScope(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('render-ctx-' . bin2hex(random_bytes(4)));
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            7,
            'shop_a',
            'retail',
            'web',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang('en_US');
        RequestContext::setWelineUserCurrency('USD');
    }
}
