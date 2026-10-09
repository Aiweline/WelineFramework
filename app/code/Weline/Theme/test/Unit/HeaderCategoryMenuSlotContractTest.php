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
        self::assertDoesNotMatchRegularExpression(
            '/\.header-nav-right-cluster \{[^}]*flex:\s*1\s+1\s+0%/s',
            $css,
            '右簇禁止 flex:1 吃中间空白，否则 More 按被定空的宽计算'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.header-nav-right-cluster \{[^}]*min-width:\s*max\(240px,\s*30%\)/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.header-nav-right-cluster \{[^}]*flex-grow:\s*0;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.header-nav-left-cluster \{[^}]*flex:\s*1\s+1\s+auto;/s',
            $css
        );
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
        // 布局 partial 裸链兜底 + header-container layout_source（部件路径）双保险。
        $container = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/header/default.phtml';
        self::assertFileExists($container);
        self::assertStringContainsString(
            'Weline_Theme::css/partials/header-default.css',
            (string)file_get_contents($container)
        );
        self::assertMatchesRegularExpression(
            '/<link\b[^>]*header-default\.css/i',
            $header
        );
    }

    public function testLeftClusterMoreCapDoesNotUseFlexBoxWidthAsNaturalFloor(): void
    {
        $jsPath = dirname(__DIR__, 2) . '/view/statics/js/partials/header-default.js';
        self::assertFileExists($jsPath);
        $js = (string)file_get_contents($jsPath);

        self::assertStringContainsString('function measureLeftClusterNaturalWidth', $js);
        self::assertStringContainsString('禁止用 leftCluster.getBoundingClientRect().width 当地板', $js);
        self::assertStringContainsString('k >= candidates.length ? -1 : k', $js);
        self::assertStringContainsString('function leftClusterHasSpareRoom', $js);
        self::assertStringContainsString('左簇 flex:1 吃剩余时，中间常有大片空档', $js);
        // 旧毒化写法：flex 盒宽当地板 + capped=min(k,length-1) → 宽屏空档仍出「更多」
        self::assertStringNotContainsString(
            'clusterNatW = Math.max(clusterNatW, prefixBased, renderedNat)',
            $js
        );
        self::assertDoesNotMatchRegularExpression(
            '/const capped = Math\.min\(k,\s*candidates\.length\s*-\s*1\)/',
            $js
        );

        $cssPath = dirname(__DIR__, 2) . '/view/statics/css/partials/header-default.css';
        $css = (string)file_get_contents($cssPath);
        self::assertMatchesRegularExpression(
            '/\.header-policy-links-slot \{[^}]*flex:\s*0\s+0\s+auto;/s',
            $css,
            '政策槽禁止 flex-shrink，空档在簇尾由 More 收项'
        );
    }
}
