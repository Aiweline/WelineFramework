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
                $modules . '/CustomerService/view/templates/Frontend/widgets/customer-service-float.phtml',
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

    public function testHanfuStorefrontShellKeepsLayoutLevelHeaderChromeLinks(): void
    {
        // Theme/test/Unit/View → dirname×6 = app/
        // 汉服店面 chrome = design storefront-shell（非 header-container 部件），布局级 <link> 合法且必需。
        $path = dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/partials/header/storefront-shell.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertMatchesRegularExpression(
            '/<link\b[^>]*header-chrome-amazon\.css/i',
            $src
        );
        self::assertMatchesRegularExpression(
            '/<link\b[^>]*header-search-amazon\.css/i',
            $src
        );
        self::assertMatchesRegularExpression(
            '/<link\b[^>]*header-default\.css/i',
            $src
        );
    }
}
