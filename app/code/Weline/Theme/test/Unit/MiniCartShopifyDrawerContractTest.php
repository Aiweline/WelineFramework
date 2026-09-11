<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class MiniCartShopifyDrawerContractTest extends TestCase
{
    public function testMiniCartIconUsesShopifyStyleDrawer(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('id="mini-cart-drawer"', $source);
        self::assertStringContainsString('data-mini-cart-drawer', $source);
        self::assertStringContainsString('data-mini-cart-overlay', $source);
        self::assertStringContainsString('data-mini-cart-trigger', $source);
        self::assertStringContainsString('data-editor-interactive', $source);
        self::assertStringContainsString('data-demo-chrome', $source);
        self::assertStringContainsString('mini-cart-drawer__btn--primary', $source);
        self::assertStringContainsString('mini-cart-drawer__line', $source);
        self::assertStringContainsString('data-qty-control', $source);
        self::assertStringContainsString('<w:slot id="footer-extras"', $source);
        self::assertStringContainsString('layout="mini-cart"', $source);
        self::assertStringContainsString('aria-label="<?= __', $source);
        self::assertStringNotContainsString('mini-cart-dropdown', $source);
    }

    public function testMiniCartDrawerScriptSupportsDrawerAndMutations(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/js/widgets/mini-cart-icon.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('is-drawer-open', $source);
        self::assertStringContainsString('weshop:mini-cart:open', $source);
        self::assertStringContainsString('weshop:mini-cart:close', $source);
        self::assertStringContainsString('Weline.MiniCart.open', $source);
        self::assertStringContainsString('Weline.MiniCart.close', $source);
        self::assertStringContainsString('weshop:mini-cart:open-request', $source);
        self::assertStringContainsString('loadDrawer', $source);
        self::assertStringContainsString('syncCartState', $source);
        self::assertStringContainsString('scheduleCartSync', $source);
        self::assertStringContainsString('applyCachedSummary', $source);
        self::assertStringContainsString('readSummaryCache', $source);
        self::assertStringContainsString('rememberSummaryCache', $source);
        self::assertStringContainsString('forceNetwork', $source);
        self::assertStringContainsString('needsOriginRefresh', $source);
        self::assertStringContainsString('consumeNeedsOriginRefresh', $source);
        self::assertStringContainsString('weline.cart.summary_cache', $source);
        self::assertStringContainsString('summaryCacheStorageKey', $source);
        self::assertStringContainsString('localStorage.getItem(summaryCacheStorageKey', $source);
        self::assertStringContainsString('cacheMatchesStorefront', $source);
        self::assertStringContainsString('currentStorefrontCurrency', $source);
        self::assertStringContainsString('forceNetwork === true', $source);
        // Coupon widget emits { refresh: true }; must forceNetwork and not paint stale cache.
        self::assertStringContainsString('summary.refresh === true', $source);
        self::assertStringContainsString('forceRefresh', $source);
        self::assertStringContainsString('!forceRefresh && applyCachedSummaryToRoots()', $source);
        self::assertStringContainsString('preview.amount_minor', $source);
        self::assertStringContainsString("getCachedSummary({ cartType: mode, requireTokenMatch: true })", $source);
        self::assertStringContainsString('Ghost-cart gate: no matching guest_token', $source);
        self::assertStringContainsString('waitForCartApi', $source);
        self::assertStringContainsString('getCart', $source);
        self::assertStringContainsString('isDemoChromeOnly', $source);
        self::assertStringContainsString('withTimeout', $source);
        self::assertStringContainsString('weline:cart-updated', $source);
        self::assertStringContainsString('isCheckoutPath', $source);
        self::assertStringContainsString("setDrawerOpen(root, false)", $source);
        self::assertStringContainsString('miniItems', $source);
        self::assertStringContainsString('update', $source);
        self::assertStringContainsString('remove', $source);
        self::assertStringContainsString('mini-cart-drawer__line', $source);
        self::assertStringContainsString('beginDrawerBusy', $source);
        self::assertStringContainsString('runWithDrawerBusy', $source);
        self::assertStringContainsString('weshop:mini-cart:busy', $source);
        self::assertStringContainsString('mini-cart-drawer__busy-overlay', $source);
        self::assertStringContainsString('bindGlobalNavigationActions', $source);
        self::assertStringContainsString('findMiniCartCheckoutLink', $source);
        self::assertStringContainsString('isCheckoutHref', $source);
        self::assertStringContainsString('setCheckoutButtonLoading', $source);
        self::assertStringContainsString('beginCheckoutNavigation', $source);
        self::assertStringContainsString('clearAllCheckoutNavigationState', $source);
        self::assertStringContainsString('bindCheckoutNavigationLifecycle', $source);
        self::assertStringContainsString('pageshow', $source);
        self::assertStringContainsString('bootMiniCartRoots', $source);
        self::assertStringContainsString('observeMiniCartRoots', $source);
        self::assertStringContainsString('__booted', $source);
        self::assertStringContainsString('isDisplayableImageUrl', $source);
        self::assertStringContainsString('appendMiniCartOptions', $source);
        self::assertStringContainsString('mini-cart-drawer__line-options', $source);
        self::assertStringContainsString('option.swatch_image', $source);
        self::assertMatchesRegularExpression('#asset:\\\\?/\\\\?/#', $source);
    }

    public function testMiniCartIconTemplateRendersOptionSwatches(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $css = dirname(__DIR__, 2) . '/view/statics/css/widgets/mini-cart-drawer.css';
        self::assertFileExists($path);
        self::assertFileExists($css);
        $source = (string)file_get_contents($path);
        $styles = (string)file_get_contents($css);

        self::assertStringContainsString('mini-cart-drawer__line-options', $source);
        self::assertStringContainsString('mini-cart-drawer__line-option-swatch', $source);
        self::assertStringContainsString("swatch_image", $source);
        self::assertStringContainsString('mini-cart-drawer__line-option-swatch', $styles);
    }

    public function testMiniCartExtrasTabsScriptBuildsHorizontalSwitcher(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/js/widgets/mini-cart-extras-tabs.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('mini-cart-drawer__extras-tablist', $source);
        self::assertStringContainsString('data-mini-cart-tab-label', $source);
        self::assertStringContainsString('shellUid', $source);
        self::assertStringContainsString('bindSwipe', $source);
        self::assertStringContainsString('weshop:mini-cart:open', $source);
        self::assertStringContainsString('weshop:mini-cart:extras-ready', $source);
        self::assertStringContainsString('data-cart-summary-extras', $source);
        self::assertStringNotContainsString("'Tab'", $source);
        self::assertStringContainsString('data-widget-name', $source);
    }

    public function testMiniCartIconUsesAmazonDrawerSurface(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $source = (string)file_get_contents($path);
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/view/statics/css/widgets/mini-cart-drawer.css');

        self::assertStringContainsString('mini-cart-drawer--amazon', $source);
        self::assertStringContainsString('data-mini-cart-loading', $source);
        self::assertStringContainsString('data-mini-cart-discount-breakdown', $source);
        self::assertStringContainsString('data-mini-cart-checkout', $source);
        self::assertStringContainsString('data-i18n-checkout-loading', $source);
        self::assertStringNotContainsString('mini-cart-icon.js', $source);
        self::assertStringContainsString('mini-cart-drawer__busy-overlay', $css);
        self::assertStringContainsString('--amz-drawer-price: #b12704', $css);
        self::assertStringContainsString('--amz-drawer-cta-bg:', $css);
    }

    public function testMiniCartEnglishCsvIncludesDrawerCopy(): void
    {
        $csv = (string)file_get_contents(dirname(__DIR__, 2) . '/i18n/en_US.csv');
        self::assertStringContainsString(
            '税费与运费将在结算时计算,"Taxes and shipping calculated at checkout"',
            $csv,
        );
        self::assertStringContainsString('减少数量,"Decrease quantity"', $csv);
        self::assertStringContainsString('增加数量,"Increase quantity"', $csv);
        self::assertStringContainsString('正在前往结算...,"Proceeding to checkout..."', $csv);
        self::assertStringContainsString('优惠券,Coupon', $csv);
        self::assertStringContainsString('叠加优惠,"Stacked discount"', $csv);
        self::assertStringContainsString('自动优惠,"Automatic discount"', $csv);
        self::assertStringContainsString('商品小计,"Goods subtotal"', $csv);
        self::assertStringContainsString('正在加载购物车...,"Loading cart..."', $csv);
        self::assertStringContainsString('底部扩展区,"Footer extras"', $csv);
        self::assertStringNotContainsString('优惠券,优惠券', $csv);
        self::assertStringNotContainsString('叠加优惠,叠加优惠', $csv);
    }

    public function testMiniCartIconOwnsDrawerStylesheet(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('mini-cart-drawer.css', $source);
        self::assertStringContainsString('data-weline-mini-cart-drawer="1"', $source);
        self::assertStringContainsString('data-weline-load="miniCartIcon,miniCartExtras"', $source);
        self::assertStringContainsString('@static(Weline_Theme::css/widgets/mini-cart-drawer.css)', $source);
        self::assertDoesNotMatchRegularExpression(
            '/@static\(Weline_Theme::css\/widgets\/mini-cart-drawer\.css\)(?:\?|&amp;)v=/',
            $source,
        );
    }

    public function testBodyEndHookDoesNotLoadMiniCartAssetsGlobally(): void
    {
        $hook = dirname(__DIR__, 2) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        $source = (string)file_get_contents($hook);

        self::assertStringNotContainsString('@static(Weline_Theme::css/widgets/mini-cart-drawer.css)', $source);
        self::assertStringNotContainsString('miniCartIcon', $source);
        self::assertStringContainsString('storefrontImageFallback', $source);
        self::assertStringNotContainsString('header-account.js', $source);
        self::assertStringNotContainsString('data-w-header-account-loader', $source);
    }
}
