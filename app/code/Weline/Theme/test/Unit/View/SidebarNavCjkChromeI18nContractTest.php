<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Sidebar/Mega must translate CJK chrome labels (e.g. 全部商品) for hi_IN path locale,
 * not only en_US.
 */
final class SidebarNavCjkChromeI18nContractTest extends TestCase
{
    public function testSidebarResolvesPathLocaleAndTranslatesCjkForNonZh(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/categories-sidebar-nav.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WidgetI18n::localeFromRequestUri', $src);
        self::assertStringContainsString("str_starts_with(strtolower(str_replace('-', '_', \$locale)), 'zh')", $src);
        self::assertStringContainsString('WidgetI18n::label($name)', $src);
        self::assertStringContainsString("WidgetI18n::label('浏览 %{1} 相关商品与配件'", $src);
        self::assertStringNotContainsString("\$locale === 'en_US' && preg_match('/\\p{Han}/u', \$name)", $src);
        self::assertStringNotContainsString("preg_match('#/(en_US|zh_Hans_CN|zh_CN|zh_Hant_TW)", $src);
    }

    public function testMegaMenuResolvesPathLocaleAndTranslatesCjkChrome(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/mega-menu-panel.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WidgetI18n::localeFromRequestUri', $src);
        self::assertStringContainsString('WidgetI18n::label($name)', $src);
        self::assertStringNotContainsString("preg_match('#/(en_US|zh_Hans_CN|zh_CN|zh_Hant_TW)", $src);
    }

    public function testHeaderNavFragmentCacheBumpedForCjkChromeFix(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/StorefrontHeaderNavFragmentCache.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('mega_panel.v6.', $src);
        self::assertStringContainsString('sidebar_nav.v6.', $src);
        self::assertStringNotContainsString('mega_panel.v5.', $src);
        self::assertStringNotContainsString('sidebar_nav.v5.', $src);
    }
}
