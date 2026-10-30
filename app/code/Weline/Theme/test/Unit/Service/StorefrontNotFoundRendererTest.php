<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontNotFoundRenderer;

/**
 * Storefront 404 theme renderer (render pipeline + dictionary stripping).
 */
final class StorefrontNotFoundRendererTest extends TestCase
{
    public function testStripEmptiesWidgetTranslationsObject(): void
    {
        $html = <<<'HTML'
        <script>
        var customerServiceConfig = {
            storefrontLocale: "el_GR",
            widgetTranslations: {"zh_Hans_CN":{"客服服务":"客服服务"},"en_US":{"客服服务":"Customer Service"},"el_GR":{"客服服务":"Customer Service"}},
            other: true
        };
        </script>
        HTML;
        $stripped = StorefrontNotFoundRenderer::stripClientTranslationDictionaries($html);
        self::assertStringContainsString('widgetTranslations: {}', $stripped);
        self::assertStringNotContainsString('"en_US"', $stripped);
        self::assertStringContainsString('storefrontLocale: "el_GR"', $stripped);
        self::assertStringContainsString('other: true', $stripped);
    }

    public function testStripHandlesEscapedQuotesInsideDictionary(): void
    {
        $html = 'widgetTranslations: {"fr_FR":{"显示模式":"Mode d\u0027affichage","x":"a\"b"}}, done';
        $stripped = StorefrontNotFoundRenderer::stripClientTranslationDictionaries($html);
        self::assertSame('widgetTranslations: {}, done', $stripped);
    }

    public function testStripIsNoopWithoutWidgetTranslations(): void
    {
        $html = '<html><body>404</body></html>';
        self::assertSame($html, StorefrontNotFoundRenderer::stripClientTranslationDictionaries($html));
    }

    public function testRendererSourceKeepsThemeAwareRenderPipeline(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP not defined');
        }
        $path = (string)\BP . '/app/code/Weline/Theme/Service/StorefrontNotFoundRenderer.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        // Design-theme inheritance must go through theme-aware fetchHtml, never module force.
        self::assertStringContainsString('fetchHtml(', $src);
        self::assertStringNotContainsString('fetchModuleThemeHtml(', $src);
        self::assertStringContainsString('ThemeApplicationContextProducer', $src);
        self::assertStringContainsString('installWebsiteThemeApplication', $src);
        // Skip the special FPC while rendering the fallback shell (no recursion).
        self::assertStringContainsString("'skip_static' => true", $src);
        // Same finalizer + publish-time markers as the retired static generator.
        self::assertStringContainsString('StorefrontFloatLayerHost::ensureInHtml', $src);
        self::assertStringContainsString('StaticErrorPagePublisher::CTX_PUBLISHING', $src);
    }
}
