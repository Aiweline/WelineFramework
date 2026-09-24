<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Storefront;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontRenderContext;
use Weline\Framework\Runtime\StorefrontRenderContextReader;
use Weline\Theme\Service\Storefront\StorefrontRenderContextBag;

/**
 * WS1 Theme consumers: prefer storefront.render_context.v1 via Runtime Reader;
 * miss → legacy fallback (no parallel bag / process static).
 */
final class StorefrontRenderContextBagContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Context::leave();
        Context::enter(new Context());
        RequestContext::setId('theme-ws1-render-context-bag');
    }

    protected function tearDown(): void
    {
        Context::leave();
        parent::tearDown();
    }

    public function testBagKeyAndConsumersPreferReaderNotParallelAuthority(): void
    {
        $themeRoot = dirname(__DIR__, 4);
        $bagSrc = (string)file_get_contents($themeRoot . '/Service/Storefront/StorefrontRenderContextBag.php');
        self::assertStringContainsString('StorefrontRenderContextReader', $bagSrc);
        self::assertStringContainsString('mergeThemeMeta', $bagSrc);
        self::assertSame(StorefrontRenderContext::BAG_KEY, StorefrontRenderContextBag::BAG_KEY);
        self::assertSame('storefront.render_context.v1', StorefrontRenderContextBag::BAG_KEY);

        $consumers = [
            $themeRoot . '/Helper/SiteBrand.php',
            $themeRoot . '/Helper/HeaderCommerceData.php',
            $themeRoot . '/Helper/WidgetI18n.php',
            $themeRoot . '/Helper/ThemeData.php',
            $themeRoot . '/Service/SlotRendererService.php',
            $themeRoot . '/Observer/ControllerFetchFileBefore.php',
        ];
        foreach ($consumers as $path) {
            $src = (string)file_get_contents($path);
            self::assertStringContainsString(
                'StorefrontRenderContextBag',
                $src,
                basename($path) . ' must prefer StorefrontRenderContextBag',
            );
        }

        $themeData = (string)file_get_contents($themeRoot . '/Helper/ThemeData.php');
        self::assertStringContainsString('mergeThemeMetaList', $themeData);

        $header = (string)file_get_contents($themeRoot . '/Helper/HeaderCommerceData.php');
        self::assertMatchesRegularExpression(
            '/function\s+resolveWebsiteId[\s\S]*StorefrontRenderContextBag::websiteId/',
            $header,
        );
        self::assertMatchesRegularExpression(
            '/function\s+resolveWebsiteCode[\s\S]*StorefrontRenderContextBag::websiteCode/',
            $header,
        );

        $slot = (string)file_get_contents($themeRoot . '/Service/SlotRendererService.php');
        self::assertMatchesRegularExpression(
            '/function\s+capturePageRenderContext[\s\S]*StorefrontRenderContextBag::captureFields/',
            $slot,
        );
        self::assertStringContainsString('storefront_offer', $slot);

        $observer = (string)file_get_contents($themeRoot . '/Observer/ControllerFetchFileBefore.php');
        self::assertStringContainsString('websiteTableSnapshot', $observer);
    }

    public function testBagReadsRuntimeDtoAndLazyMergesThemeMeta(): void
    {
        self::assertNull(StorefrontRenderContextBag::current());
        self::assertNull(StorefrontRenderContextBag::websiteId());
        self::assertSame([], StorefrontRenderContextBag::captureFields());

        StorefrontRenderContext::install(new StorefrontRenderContext(
            websiteId: 7,
            websiteCode: 'HanFu',
            websiteUrl: 'https://hanfu.example.test',
            websiteLocal: [
                ['local_code' => 'zh_Hans_CN', 'name' => '长安汉服', 'description' => '汉服店面'],
                ['local_code' => 'en_US', 'name' => 'Chang\'an Hanfu', 'description' => 'Hanfu store'],
            ],
            locale: 'zh_Hans_CN',
            currency: 'cny',
            timezone: 'Asia/Shanghai',
            localeCatalog: ['active' => ['zh_Hans_CN', 'en_US'], 'installed' => ['zh_Hans_CN', 'en_US']],
            maintenance: ['enabled' => false],
            themeMeta: null,
            websiteTableSnapshot: ['website_id' => 7, 'code' => 'hanfu'],
            complete: true,
        ));

        self::assertInstanceOf(StorefrontRenderContext::class, StorefrontRenderContextReader::get());
        self::assertSame(7, StorefrontRenderContextBag::websiteId());
        self::assertSame('hanfu', StorefrontRenderContextBag::websiteCode());
        self::assertSame('长安汉服', StorefrontRenderContextBag::websiteLocalName());
        self::assertSame('汉服店面', StorefrontRenderContextBag::websiteLocalDescription());
        self::assertSame('zh_Hans_CN', StorefrontRenderContextBag::locale());
        self::assertSame('CNY', StorefrontRenderContextBag::currency());
        self::assertNull(StorefrontRenderContextBag::themeMetaIdentify('theme.frontend.layouts.default'));

        StorefrontRenderContextBag::mergeThemeMetaList('frontend', 'layouts', [
            [
                'meta_identify' => 'theme.frontend.layouts.default',
                'meta_data' => ['title' => 'Home'],
            ],
        ]);

        self::assertSame(
            ['meta_identify' => 'theme.frontend.layouts.default', 'meta_data' => ['title' => 'Home']],
            StorefrontRenderContextBag::themeMetaIdentify('theme.frontend.layouts.default'),
        );
        self::assertNotNull(StorefrontRenderContextReader::themeMeta());

        $capture = StorefrontRenderContextBag::captureFields();
        self::assertSame(7, $capture['website_id']);
        self::assertSame('HanFu', $capture['website_code']);
        self::assertSame('zh_Hans_CN', $capture['locale']);
        self::assertArrayHasKey('theme_meta', $capture);
        self::assertArrayNotHasKey('storefront_offer', $capture);
    }
}
