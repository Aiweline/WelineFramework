<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Header 横向分类部件化 + 导航扩展槽契约。
 */
final class HeaderCategoryMenuSlotContractTest extends TestCase
{
    public function testHeaderCategorySlotUsesWidgetFallbackWithoutInlineList(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="category-menu"', $source);
        self::assertStringContainsString('<w:widget type="navigation" name="category-menu"', $source);
        self::assertStringContainsString('<w:slot id="header-nav-extensions"', $source);
        self::assertStringContainsString('multiple="true"', $source);
        self::assertStringContainsString('accept="header-blog-link,header-deals-link,header-contact-service-link,header-nav-link,layout-header-nav-extensions"', $source);
        self::assertStringContainsString('class="header-nav-left-cluster"', $source);
        self::assertStringContainsString('class="header-nav-right-cluster"', $source);
        self::assertMatchesRegularExpression(
            '/header-nav-right-cluster[\s\S]*header-nav-extensions[\s\S]*header-nav-right-slot/s',
            $source,
            '博客扩展槽须在右侧大簇内、快捷导航之前'
        );
        self::assertStringNotContainsString('id="categories-list"', $source);
        self::assertStringNotContainsString('id="categories-overflow-wrapper"', $source);
        self::assertStringNotContainsString('<nav class="categories-nav"', $source);
    }

    public function testCategoryMenuWidgetIsLayoutOwnedWithoutDefaultInjection(): void
    {
        $widgetPath = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/navigation/category-menu/default.phtml';
        $partialPath = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/categories-horizontal-nav.phtml';
        self::assertFileExists($widgetPath);
        self::assertFileExists($partialPath);

        $widget = (string)file_get_contents($widgetPath);
        $partial = (string)file_get_contents($partialPath);

        self::assertStringContainsString('@widget.slot {category-menu}', $widget);
        self::assertStringNotContainsString('@widget.default_injections', $widget);
        self::assertStringContainsString('fetchCategoriesHorizontalNav', $widget);
        self::assertStringContainsString('HeaderNavFragment', $widget);
        self::assertStringContainsString('id="categories-list"', $partial);
        self::assertStringContainsString('categories-overflow-wrapper', $partial);
        self::assertStringContainsString('fetchMegaMenuPanel', $partial);
        self::assertStringContainsString('allocateMegaPanelId', $partial);
        self::assertStringContainsString('data-testid="header-category-menu"', $partial);
    }

    public function testHeaderNavExtensionsCssPresent(): void
    {
        $cssPath = dirname(__DIR__, 2) . '/view/statics/css/partials/header-default.css';
        $headerPath = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        self::assertFileExists($cssPath);
        self::assertFileExists($headerPath);
        $css = (string)file_get_contents($cssPath);
        $header = (string)file_get_contents($headerPath);

        self::assertStringContainsString('.header-nav-left-cluster {', $css);
        self::assertStringContainsString('.header-nav-right-cluster {', $css);
        self::assertStringContainsString('.header-nav-extensions {', $css);
        self::assertStringContainsString('.header-nav-extensions:empty {', $css);
        self::assertStringContainsString(
            '.header-nav-right-cluster > .header-nav-right-slot:has(#nav-links-list:empty)',
            $css
        );
        self::assertStringContainsString('id="nav-more-wrapper"', $header);
        self::assertStringContainsString(
            '.header-nav-right-cluster > .nav-more-wrapper[style*="display: none"]',
            $css
        );
        self::assertStringContainsString('header-default.css', $header);
    }
}
