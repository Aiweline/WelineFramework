<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeHeaderMobileAmazonContractTest extends TestCase
{
    public function testDefaultHeaderLocksAmazonMobileStructure(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('data-header-mobile="amazon"', $source);
        self::assertStringContainsString('header-mobile-menu-btn js-header-drawer-trigger', $source);
        self::assertStringContainsString('hamburger-menu-btn--fallback', $source);
        self::assertStringContainsString('header-nav-all-root', $source);
        self::assertStringContainsString('header-mobile-menu-icon', $source);
        self::assertStringContainsString('categories-sidebar-close-icon', $source);
        self::assertStringContainsString('<w:widget type="navigation" name="all-menu"', $source);
        self::assertStringContainsString('categories-sidebar-home', $source);
        self::assertStringContainsString('categories-sidebar-signin', $source);
        self::assertStringContainsString('<w:i18n:switcher />', $source);

        self::assertStringContainsString('.header-search-toggle', $source);
        self::assertStringContainsString('display: none !important;', $source);
        self::assertStringContainsString('.header-search-wrapper', $source);
        self::assertStringContainsString('display: block;', $source);
        self::assertStringContainsString('.header-nav-all', $source);
        self::assertStringContainsString('flex-wrap: nowrap', $source);
        self::assertStringContainsString('data-w-component="popover"', $source);
        self::assertStringContainsString('data-w-open-on="hover"', $source);
        self::assertStringContainsString('data-w-component="menu"', $source);
        self::assertStringContainsString('id="nav-more-wrapper"', $source);
        self::assertStringContainsString('data-w-menu-trigger', $source);
        self::assertStringContainsString('data-w-menu-panel', $source);
        self::assertStringContainsString("menuItem.className = 'w-menu__item'", $source);
        self::assertStringContainsString('window.Weline.UI.mount(navMoreWrapper)', $source);
        self::assertStringNotContainsString('function checkNavFillOverflow()', $source);
        self::assertMatchesRegularExpression(
            '/\.header-nav-links\s*\{[^}]*gap:\s*var\(--weline-space-5\)/s',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 768px\) \{[\s\S]*?\.header-nav-all \{\s*display:\s*none !important;/s',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 768px\) \{[\s\S]*?\.nav-more-wrapper \{\s*display:\s*none !important;/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 768px\) \{[\s\S]*?\.header-nav-all \{[\s\S]*?display:\s*flex !important;/s',
            $source
        );
        self::assertStringContainsString("matchMedia('(max-width: 768px)')", $source);
        self::assertStringContainsString('headerCategories.classList.remove(\'hide-narrow\')', $source);
        self::assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 768px\) \{[\s\S]*?\.nav-more-wrapper \{\s*display:\s*flex;/s',
            $source
        );
        self::assertStringContainsString('pointer-events: none', $source);
        self::assertStringContainsString('function bindHeaderCategoryDrawer()', $source);
        self::assertStringContainsString('window.__welineHeaderDrawerBound', $source);
        self::assertStringContainsString('bindHeaderCategoryDrawer();', $source);
        self::assertStringContainsString('categories-sidebar-nav.phtml', $source);
        self::assertFileExists(dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/categories-sidebar-nav.phtml');
        $sidebarNav = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/categories-sidebar-nav.phtml'
        );
        self::assertStringContainsString('data-w-placement="right-start"', $sidebarNav);
        self::assertStringContainsString('data-w-gap="0"', $sidebarNav);
        self::assertStringContainsString('mega-menu-panel.phtml', $sidebarNav);
        self::assertStringContainsString('drawer_flyout', $sidebarNav);
        self::assertStringContainsString('sidebar-category-card__media', $sidebarNav);
        self::assertStringNotContainsString('sidebar-category-children', $sidebarNav);
        self::assertStringContainsString('bindHeaderMegaMenu(categoriesSidebar)', $source);
        self::assertStringContainsString('bindDrawerFlyoutAlign', $source);
        self::assertStringContainsString('bindSidebarAccordions', $source);
        self::assertStringContainsString('is-drawer-flyout', $source);
        self::assertStringContainsString('sidebar-section-toggle', $source);
        self::assertStringContainsString('categories-sidebar-section--services', $source);
        self::assertStringContainsString('is-collapsed', $source);
        self::assertMatchesRegularExpression(
            '/categories-sidebar-scroll[\s\S]*?sidebar_categories[\s\S]*?热门入口[\s\S]*?账户与服务/u',
            $source
        );
        self::assertStringContainsString('热门入口', $source);
        self::assertStringContainsString('账户与服务', $source);
        self::assertStringContainsString('pointer-events: none', $source);
        self::assertStringContainsString('header-main-nav-inner', $source);
        self::assertMatchesRegularExpression(
            '/\.header-main-nav\s*\{[^}]*weline-chrome-bg-dark-secondary/s',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.header-main-nav\s*\{[^}]*bgDarkSecondary/s',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 768px\) \{[^}]*\.header-search-toggle \{\s*display:\s*inline-flex;/s',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 768px\) \{[^}]*\.header-search-wrapper \{\s*display:\s*none;/s',
            $source
        );
    }

    public function testHeaderContainerAndSearchWidgetsKeepMobileSearchVisible(): void
    {
        $container = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/header/default.phtml';
        $search = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/search/header-search/default.phtml';
        $account = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/account/default.phtml';
        $cart = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $full = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/full-header/default.phtml';

        $containerSource = (string)file_get_contents($container);
        self::assertStringContainsString('header-mobile-menu-btn', $containerSource);
        self::assertStringContainsString('.slot-search', $containerSource);
        self::assertStringContainsString('flex: 0 0 100%', $containerSource);

        $searchSource = (string)file_get_contents($search);
        self::assertStringContainsString('border-radius: 8px', $searchSource);
        self::assertStringContainsString('.header-search-hot-words', $searchSource);

        $accountSource = (string)file_get_contents($account);
        self::assertStringContainsString('.login-text::after', $accountSource);
        self::assertStringContainsString("@url{'customer/account/login'}", $accountSource);
        self::assertStringNotContainsString('href="/account/login"', $accountSource);

        $cartSource = (string)file_get_contents($cart);
        self::assertStringContainsString('.cart-count[hidden]', $cartSource);

        $fullSource = (string)file_get_contents($full);
        self::assertStringContainsString('mobile-menu-toggle', $fullSource);
        self::assertStringContainsString('is-drawer-open', $fullSource);
        self::assertStringNotContainsString('.header-nav {\n        display: none;', $fullSource);
    }
}
