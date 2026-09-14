<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Category card copy must prefer EAV/Local description attributes;
 * Theme chrome may only keep one parameterized fallback — never bare 浏览相关商品与配件 as content i18n.
 */
final class HeaderCategoryDescriptionAttributeContractTest extends TestCase
{
    public function testHeaderJsUsesParameterizedChromeFallbackNotBarePhrase(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('headerBrowseCategoryPattern', $source);
        self::assertStringContainsString('formatBrowseCategoryDesc', $source);
        self::assertStringContainsString("浏览 %{1} 相关商品与配件", $source);
        self::assertStringContainsString('data-category-description', $source);
        self::assertStringNotContainsString("headerLabel('浏览相关商品与配件')", $source);
        self::assertStringNotContainsString('headerBrowseCategoryLabel', $source);
    }

    public function testHorizontalNavExposesCategoryDescriptionAttribute(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/categories-horizontal-nav.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-category-description', $source);
        self::assertStringContainsString("\$item['description']", $source);
    }

    public function testSidebarAndMegaPreferNodeDescriptionBeforeChromeFallback(): void
    {
        $sidebar = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/categories-sidebar-nav.phtml',
        );
        $mega = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/mega-menu-panel.phtml',
        );
        foreach ([$sidebar, $mega] as $source) {
            self::assertStringContainsString("\$node['i18n']['description']", $source);
            self::assertStringContainsString("浏览 %{1} 相关商品与配件", $source);
            self::assertStringContainsString('WidgetI18n::label', $source);
        }
    }
}
