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
        self::assertStringContainsString('hamburger-menu-btn--fallback js-header-drawer-trigger', $source);
        self::assertStringContainsString("\$headerEsc('全部')", $source);
        self::assertStringContainsString('header-nav-all-root', $source);
        self::assertStringContainsString('header-mobile-menu-icon', $source);
        self::assertStringContainsString('categories-sidebar-close-icon', $source);
        self::assertStringContainsString('<w:widget type="navigation" name="all-menu"', $source);
        self::assertStringContainsString('<w:widget type="navigation" name="category-menu"', $source);
        self::assertStringContainsString('<w:slot id="header-nav-extensions"', $source);
        self::assertStringContainsString('categories-sidebar-home', $source);
        self::assertStringContainsString('categories-sidebar-signin', $source);
        self::assertStringContainsString('<w:i18n:switcher />', $source);
        self::assertStringContainsString(
            'class="my-menu-submenu-content my-menu-submenu-content--language"',
            $source,
        );
        self::assertStringContainsString('<w:i18n:switcher navigation="path" />', $source);
        self::assertStringContainsString('<w:hook>header-currency-switcher</w:hook>', $source);
        self::assertStringContainsString('<button type="button"', $source);
        self::assertStringContainsString('id="hamburger-menu-fallback"', $source);
        self::assertStringNotContainsString('href="#"', $source);
        self::assertStringNotContainsString('class="language-option', $source);
        self::assertStringNotContainsString('class="currency-option', $source);

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
        self::assertStringContainsString('id="header-nav-fill"', $source);
        self::assertMatchesRegularExpression(
            '/\.header-nav-fill,\s*\n\.header-nav-right-slot,\s*\n\.header-nav-links-slot\s*\{[^}]*flex:\s*0\s+1\s+auto;/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/\.header-nav-fill-inner\s*\{[^}]*width:\s*auto;/s',
            $source
        );
        // 两行栈后左右按全宽算「更多」，禁止只定义 clustersOnSeparateRows 却不接入量宽
        self::assertStringContainsString('function clustersOnSeparateRows()', $source);
        self::assertStringContainsString('rowThreshold', $source);
        self::assertStringContainsString('跳过互让', $source);
        self::assertMatchesRegularExpression(
            '/function measureNavAvailableWidth\(\)\s*\{[\s\S]*?clustersOnSeparateRows\(\)/',
            $source
        );
        self::assertMatchesRegularExpression(
            '/function measureCatAvailableWidth\(\)\s*\{[\s\S]*?clustersOnSeparateRows\(\)/',
            $source
        );
        self::assertStringContainsString('已换两行：右簇独占第二行', $source);
        self::assertStringContainsString('已换两行：左簇独占第一行', $source);
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
        self::assertStringContainsString("headerCategories.classList.remove('hide-narrow')", $source);
        self::assertStringContainsString('clearHideNarrowAndReflow', $source);
        self::assertStringContainsString('禁止 hide-narrow 整块 display:none', $source);
        self::assertStringContainsString('部分吐回', $source);
        self::assertStringNotContainsString('const shouldHide = width < 200', $source);
        // 左簇整体 More：候选=分类+政策，宿主在左簇末（不夹在政策前）
        self::assertStringContainsString('function collectLeftOverflowCandidates()', $source);
        self::assertStringContainsString('header-left-cluster-more', $source);
        self::assertStringContainsString('政策已列入溢出候选，不再单独预留', $source);
        self::assertStringContainsString('左簇整体 More：挂在左簇末尾', $source);
        self::assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 768px\) \{[\s\S]*?\.nav-more-wrapper \{\s*display:\s*flex;/s',
            $source
        );
        self::assertStringContainsString('pointer-events: none', $source);
        self::assertStringContainsString('function bindHeaderCategoryDrawer()', $source);
        self::assertStringContainsString('window.__welineHeaderDrawerBound', $source);
        self::assertStringContainsString('bindHeaderCategoryDrawer();', $source);
        self::assertStringContainsString('fetchCategoriesSidebarNav', $source);
        self::assertFileExists(dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/categories-sidebar-nav.phtml');
        $sidebarNav = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/categories-sidebar-nav.phtml'
        );
        self::assertStringContainsString('data-w-placement="right-start"', $sidebarNav);
        self::assertStringContainsString('data-w-gap="0"', $sidebarNav);
        self::assertStringNotContainsString('fetchMegaMenuPanel', $sidebarNav);
        self::assertStringContainsString('data-sidebar-mega-source', $sidebarNav);
        self::assertStringContainsString('data-sidebar-mega-deferred', $sidebarNav);
        self::assertStringContainsString('data-sidebar-mega-deferred="1"', $sidebarNav);
        self::assertStringContainsString('sidebar-category-card__media', $sidebarNav);
        self::assertStringNotContainsString('sidebar-category-children', $sidebarNav);
        self::assertStringContainsString('bindHeaderMegaMenu(categoriesSidebar)', $source);
        self::assertStringContainsString('hydrateSidebarMegaPanels(categoriesSidebar)', $source);
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
        $searchCss = dirname(__DIR__, 2) . '/view/statics/css/widgets/header-search-amazon.css';
        $account = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/account/default.phtml';
        $cart = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $full = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/full-header/default.phtml';

        $containerSource = (string)file_get_contents($container);
        self::assertStringContainsString('header-mobile-menu-btn', $containerSource);
        self::assertStringContainsString('.slot-search', $containerSource);
        self::assertStringContainsString('flex: 0 0 100%', $containerSource);

        $searchSource = (string)file_get_contents($search);
        $searchCssSource = (string)file_get_contents($searchCss);
        self::assertStringContainsString('header-search-amazon.css', $searchSource);
        self::assertStringContainsString(
            '\'show_hot_words\' => $showHotWords',
            $searchSource,
        );
        self::assertStringContainsString('@media (max-width: 768px)', $searchCssSource);
        self::assertStringContainsString('.header-search-form', $searchCssSource);
        self::assertStringContainsString('.header-search-hot-words', $searchCssSource);
        self::assertStringContainsString('border-radius: var(', $searchCssSource);
        self::assertStringNotContainsString('border-radius: 8px', $searchSource . $searchCssSource);

        $accountSource = (string)file_get_contents($account);
        self::assertStringContainsString('.login-text::after', $accountSource);
        self::assertStringContainsString("@url{'customer/account/login'}", $accountSource);
        self::assertStringContainsString('data-w-header-account="1"', $accountSource);
        self::assertStringContainsString('data-weline-load="api,account"', $accountSource);
        self::assertStringContainsString('data-auth-state="guest"', $accountSource);
        self::assertStringNotContainsString('createFrontendSession', $accountSource);
        self::assertStringNotContainsString('href="/account/login"', $accountSource);

        $cartSource = (string)file_get_contents($cart);
        self::assertStringContainsString('.cart-count[hidden]', $cartSource);

        $fullSource = (string)file_get_contents($full);
        self::assertStringContainsString('mobile-menu-toggle', $fullSource);
        self::assertStringContainsString('is-drawer-open', $fullSource);
        self::assertStringNotContainsString('.header-nav {\n        display: none;', $fullSource);
    }

    public function testHeaderAccountWidgetDeclaresApiAccountModules(): void
    {
        $account = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/account/default.phtml';
        self::assertFileExists($account);
        $accountSource = (string)file_get_contents($account);
        self::assertStringContainsString('data-w-header-account="1"', $accountSource);
        self::assertStringContainsString('data-weline-load="api,account"', $accountSource);
        self::assertStringContainsString('header-account-links', $accountSource);
        self::assertStringContainsString('account-dropdown-menu', $accountSource);
        self::assertStringContainsString('data-account-logout-confirm', $accountSource);
        $hookClose = strpos($accountSource, '</w:hook>');
        $logoutAction = strpos($accountSource, 'data-account-logout-confirm');
        self::assertNotFalse($hookClose);
        self::assertNotFalse($logoutAction);
        self::assertStringContainsString('data-account-avatar', $accountSource);
        self::assertStringContainsString('data-account-avatar-fallback', $accountSource);
        self::assertStringContainsString('account-avatar__img', $accountSource);
        self::assertStringContainsString('account-avatar--menu', $accountSource);
        self::assertStringContainsString('dropdown-header__copy', $accountSource);
        self::assertSame(2, substr_count($accountSource, 'data-account-avatar-wrap'), 'Trigger + dropdown welcome both need avatar wraps');
        self::assertGreaterThan($hookClose, $logoutAction, 'Logout must render after header-account-links, not as a hook menu item');
        self::assertStringContainsString('display: block', $accountSource);
        self::assertStringContainsString('.account-dropdown .dropdown-menu', $accountSource);
        self::assertStringNotContainsString('data-weline-load="api,account"', (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml'
        ));
    }
}
