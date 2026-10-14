<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Wave widgets: CSS must use @widget.source / layout_source — no bare stylesheet <link>.
 */
final class WidgetNoBareStylesheetLinkContractTest extends TestCase
{
    /** @return list<array{0:string,1:string}> */
    public static function widgetTemplateProvider(): array
    {
        $theme = dirname(__DIR__, 3);
        $modules = dirname($theme);

        return [
            [
                $theme . '/view/theme/frontend/widgets/container/header/default.phtml',
                'header-container',
            ],
            [
                $modules . '/StoreMusic/view/templates/frontend/widgets/store-music.phtml',
                'store-music',
            ],
            [
                $modules . '/CustomerService/view/templates/frontend/widgets/customer-service-float.phtml',
                'customer-service-float',
            ],
        ];
    }

    /**
     * @dataProvider widgetTemplateProvider
     */
    public function testWidgetTemplateHasNoBareStylesheetLink(string $path, string $label): void
    {
        self::assertFileExists($path, $label);
        $src = (string)file_get_contents($path);
        self::assertMatchesRegularExpression(
            '/@widget\.(?:source|layout_source)\s*\{/',
            $src,
            $label . ' must declare @widget.source or layout_source'
        );
        self::assertDoesNotMatchRegularExpression(
            '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i',
            $src,
            $label . ' must not emit bare stylesheet <link> (use source bake)'
        );
    }

    public function testHeaderContainerLayoutSourceIncludesHeaderDefaultCss(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/container/header/default.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString(
            'Weline_Theme::css/partials/header-default.css',
            $src
        );
        self::assertStringContainsString(
            'Weline_Theme::css/widgets/header-chrome-amazon.css',
            $src
        );
    }

    public function testHanfuStorefrontShellDeclaresHeaderChromeAsLayoutAssets(): void
    {
        // Theme/test/Unit/View → dirname×6 = app/
        // 汉服店面 chrome = design storefront-shell：须走 emit helper（含 module-source）进合包。
        $path = dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/partials/header/storefront-shell.phtml';
        if (!is_file($path)) {
            self::markTestSkipped('hanfu storefront-shell not present on disk');
        }
        $src = (string)file_get_contents($path);
        self::assertStringContainsString(
            'SharedChromeService::emitPartialsHeaderShellLayoutCssHtml',
            $src,
            'hanfu shell must emit via SharedChromeService helper (module-source for css_merge)'
        );
        self::assertDoesNotMatchRegularExpression(
            '/<link\b[^>]*header-chrome-amazon\.css/i',
            $src,
            'hanfu shell must not hand-write header-chrome stylesheet links'
        );
    }

    public function testThemePartialsHeaderDeclaresHeaderChromeAsLayoutAssets(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString(
            'SharedChromeService::emitPartialsHeaderShellLayoutCssHtml',
            $src
        );
        self::assertDoesNotMatchRegularExpression(
            '/<link\b[^>]*header-chrome-amazon\.css/i',
            $src,
            'Theme Partials header must not hand-write header-chrome stylesheet links'
        );
    }

    public function testPartialsHeaderShellLayoutCssConstantMatchesHeaderContainer(): void
    {
        $peers = \Weline\Theme\Service\SharedChromeService::PARTIALS_HEADER_SHELL_LAYOUT_CSS;
        self::assertSame([
            'Weline_Theme::css/widgets/header-chrome-amazon.css',
            'Weline_Theme::css/widgets/header-search-amazon.css',
            'Weline_Theme::css/partials/header-default.css',
        ], $peers);
        $headerWidget = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/container/header/default.phtml'
        );
        foreach ($peers as $path) {
            self::assertStringContainsString($path, $headerWidget);
        }
    }

    public function testEmitPartialsHeaderShellLayoutCssHtmlIncludesModuleSource(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SharedChromeService.php'
        );
        self::assertStringContainsString('function emitPartialsHeaderShellLayoutCssHtml', $src);
        self::assertStringContainsString('data-weline-module-source=', $src);
        self::assertStringContainsString('data-weline-widget-asset="layout"', $src);
        self::assertStringContainsString('PARTIALS_HEADER_SHELL_LAYOUT_CSS', $src);
        // Publisher canMerge requires module_source — emit must bind it per path.
        self::assertMatchesRegularExpression(
            '/data-weline-module-source="\'\s*\.\s*\$safeSrc\s*\.\s*\'"/',
            $src
        );
    }
}
