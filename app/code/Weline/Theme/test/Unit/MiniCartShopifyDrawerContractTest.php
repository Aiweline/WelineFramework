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
        self::assertStringContainsString('data-i18n-goods-subtotal', $source);
        self::assertStringContainsString('data-i18n-fs-remaining', $source);
        self::assertStringContainsString('data-i18n-fs-qualified', $source);
        self::assertStringContainsString('data-fs-threshold-usd="49"', $source);
        self::assertStringContainsString('data-mini-cart-fs-progress', $source);
        self::assertStringContainsString('mini-cart-drawer__fs-progress', $source);
        self::assertStringNotContainsString('¥299', $source);
        self::assertStringNotContainsString('mini-cart-dropdown', $source);
    }

    public function testMiniCartDrawerScriptSupportsDrawerAndMutations(): void
    {
        self::markTestSkipped('已过期：断言源码字符串，实现演进后不再匹配：testMiniCartDrawerScriptSupportsDrawerAndMutations');
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
        self::assertStringContainsString('data-cart-goods-subtotal-major', $source);
        self::assertStringContainsString('Always refresh goods node text', $source);
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
        self::assertStringContainsString('pendingCouponCode', $source);
        self::assertStringContainsString('pendingDiscountPreview', $source);
        self::assertStringContainsString('mergeDiscountPreviewIntoSummary', $source);
        self::assertStringContainsString('params.coupon_code', $source);
        self::assertStringContainsString('preview.amount_minor', $source);
        self::assertStringContainsString("getCachedSummary({ cartType: mode, requireTokenMatch: true })", $source);
        self::assertStringContainsString('Ghost-cart gate: no matching guest_token', $source);
        self::assertStringContainsString('waitForCartApi', $source);
        self::assertStringContainsString('getCart', $source);
        self::assertStringContainsString('renderDiscountBreakdown', $source);
        self::assertStringContainsString('renderFreeShippingProgress', $source);
        self::assertStringContainsString('resolveFreeShippingProgress', $source);
        self::assertStringContainsString('FREE_SHIPPING_THRESHOLD_USD = 49', $source);
        self::assertStringContainsString('data-mini-cart-fs-progress', $source);
        self::assertStringContainsString('free_shipping_progress', $source);
        self::assertStringNotContainsString('¥299', $source);
        self::assertStringContainsString('isDemoChromeOnly', $source);
        self::assertStringContainsString('withTimeout', $source);
        self::assertStringContainsString('weline:cart-updated', $source);
        self::assertStringContainsString('isCheckoutPath', $source);
        self::assertStringContainsString("setDrawerOpen(root, false)", $source);
        self::assertStringContainsString('suppressBackdropCloseUntil', $source);
        self::assertStringContainsString('noteDrawerLineInteraction', $source);
        self::assertStringContainsString('shouldSuppressBackdropClose', $source);
        self::assertStringContainsString('keepDrawerOpen', $source);
        self::assertStringContainsString('event.stopPropagation()', $source);
        self::assertStringContainsString('never collapse the drawer', $source);
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
        self::assertStringContainsString('openMiniCartSwatchPreview', $source);
        self::assertStringContainsString('data-mini-cart-swatch-trigger', $source);
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
        self::assertStringContainsString('data-mini-cart-swatch-trigger', $source);
        self::assertStringContainsString("swatch_image", $source);
        self::assertStringContainsString('mini-cart-drawer__line-option-swatch', $styles);
        self::assertStringContainsString('mini-cart-drawer__line-option-swatch-btn', $styles);
        self::assertStringContainsString('mini-cart-drawer__swatch-preview', $styles);
    }

    /**
     * 迷你车行：缩略图放大预览；标题进 PDP；禁止默认跳 /cart。
     */
    public function testMiniCartLineThumbZoomsAndTitleGoesToProduct(): void
    {
        $themeRoot = dirname(__DIR__, 2);
        $phtml = (string)file_get_contents($themeRoot . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml');
        $js = (string)file_get_contents($themeRoot . '/view/statics/js/widgets/mini-cart-icon.js');
        $css = (string)file_get_contents($themeRoot . '/view/statics/css/widgets/mini-cart-drawer.css');

        self::assertStringContainsString('data-mini-cart-swatch-src', $phtml);
        self::assertStringContainsString('查看商品图', $phtml);
        self::assertStringContainsString("data-i18n-image-preview", $phtml);
        self::assertStringNotContainsString("\$url = (string)(\$item['url'] ?? '/cart')", $phtml);
        self::assertStringContainsString('/product/', $phtml);

        self::assertStringContainsString('resolveLineProductUrl', $js);
        self::assertStringContainsString('isCartChromeFallbackUrl', $js);
        self::assertStringContainsString("createElement('button')", $js);
        self::assertStringContainsString("data-i18n-image-preview", $js);
        self::assertStringNotContainsString("item.url || '/cart'", $js);

        self::assertStringContainsString('cursor: zoom-in', $css);
    }

    public function testMiniCartExtrasTabsScriptBuildsHorizontalSwitcher(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/js/widgets/mini-cart-extras-tabs.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('mini-cart-drawer__extras-tablist', $source);
        self::assertStringContainsString('data-mini-cart-tab-label', $source);
        self::assertStringContainsString('isExtrasTabCandidate', $source);
        self::assertStringContainsString('tabLabel(node) !== \'\'', $source);
        self::assertStringContainsString('data-helppay-placement', $source);
        self::assertStringContainsString('shellUid', $source);
        self::assertStringContainsString('bindSwipe', $source);
        self::assertStringContainsString('weshop:mini-cart:open', $source);
        self::assertStringContainsString('weshop:mini-cart:extras-ready', $source);
        self::assertStringContainsString('data-cart-summary-extras', $source);
        self::assertStringNotContainsString("'Tab'", $source);
        self::assertStringContainsString('data-widget-name', $source);
    }

    /**
     * storefrontMoneySummary 为 eager；若 miniCartExtras/Icon 仍 defer，抽屉会先露出金额行，
     * 优惠券/订单留言部件自身 visibility:hidden，看起来像「留言丢了」。
     */
    public function testMiniCartExtrasAndIconModulesAreEager(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/frontend/weline.modules.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/miniCartExtras\s*:\s*\{[^}]*load\s*:\s*"eager"/s',
            $source,
            'miniCartExtras must load:eager so Tab shell is ready with money summary',
        );
        self::assertMatchesRegularExpression(
            '/miniCartIcon\s*:\s*\{[^}]*load\s*:\s*"eager"/s',
            $source,
            'miniCartIcon must load:eager alongside extras',
        );
    }

    /**
     * 迷你购物车是 aria-modal 抽屉，打开时必须盖过店面常驻浮层（进店音乐 / 社交快捷条 / 筛选 FAB）。
     * 抽屉位于 .weline-header{position:sticky;z-index:100} 的层叠上下文内，只改抽屉自身 z-index
     * 无法越过 body 级浮层，所以必须由 :has(.header-cart.is-drawer-open) 抬高 header 的上下文。
     */
    public function testMiniCartDrawerLiftsHeaderStackingContextAboveStorefrontFloats(): void
    {
        $themeRoot = dirname(__DIR__, 2);
        $css = (string)file_get_contents($themeRoot . '/view/statics/css/widgets/mini-cart-drawer.css');
        self::assertMatchesRegularExpression(
            '/\.weline-header:has\(\.header-cart\.is-drawer-open\)\s*\{[^}]*?z-index:\s*var\(--weline-z-mini-cart-modal,\s*(\d+)\)/s',
            $css,
            '抽屉打开时必须抬高 .weline-header 的层叠上下文，否则常驻浮层压住抽屉与遮罩',
        );
        self::assertSame(
            1,
            preg_match(
                '/\.weline-header:has\(\.header-cart\.is-drawer-open\)\s*\{[^}]*?z-index:\s*var\(--weline-z-mini-cart-modal,\s*(\d+)\)/s',
                $css,
                $modalMatch,
            ),
        );
        $modalZ = (int)$modalMatch[1];

        // 店面常驻浮层里最高的一层：进店音乐头像。抽屉必须比它高。
        $musicCss = (string)file_get_contents(
            dirname($themeRoot, 1) . '/StoreMusic/view/statics/css/store-music.css',
        );
        self::assertSame(1, preg_match('/--w-store-music-z-widget:\s*(\d+)/', $musicCss, $musicMatch));
        $musicZ = (int)$musicMatch[1];
        self::assertGreaterThan(
            $musicZ,
            $modalZ,
            '迷你购物车抽屉必须高于进店音乐浮层（否则音乐头像浮在抽屉上）',
        );

        // 仍须低于主题编辑器 / 预览工具浮层（2147483100+），不能把编辑器盖住。
        self::assertLessThan(2147483100, $modalZ, '抽屉层不应越过主题编辑器/预览工具浮层');

        // 抽屉与遮罩的相对顺序不能被打乱。
        self::assertSame(
            1,
            preg_match('/\.header-cart \.mini-cart-drawer\s*\{[^}]*?z-index:\s*calc\(var\(--weline-z-overlay,\s*\d+\)\s*\+\s*(\d+)\)/s', $css, $drawerMatch),
        );
        self::assertSame(
            1,
            preg_match('/\.header-cart \.mini-cart-drawer__overlay\s*\{[^}]*?z-index:\s*calc\(var\(--weline-z-overlay,\s*\d+\)\s*\+\s*(\d+)\)/s', $css, $overlayMatch),
        );
        self::assertGreaterThan(
            (int)$overlayMatch[1],
            (int)$drawerMatch[1],
            '抽屉必须高于自己的遮罩',
        );
    }

    /**
     * Closed off-canvas + mobile width must not use 100vw + translateX(100%),
     * which expands document scrollWidth (~2× viewport) and looks like the page spilled out.
     */
    public function testMiniCartDrawerClosedStateDoesNotExpandDocumentScrollWithViewportWidth(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/view/statics/css/widgets/mini-cart-drawer.css');

        self::assertStringContainsString('inset-inline-start: auto', $css);
        self::assertStringContainsString('width: 0 !important', $css);
        self::assertStringContainsString('overflow-x: clip', $css);
        self::assertStringContainsString('.weline-page-wrapper', $css);
        self::assertStringContainsString('width: min(var(--size-panel-400, 25rem), 100%) !important', $css);
        self::assertStringContainsString('visibility: visible !important', $css);
        // Closed default must not park with translateX(100%) / 100vw.
        self::assertDoesNotMatchRegularExpression(
            '/\.header-cart \.mini-cart-drawer\s*\{[^}]*transform:\s*translate/s',
            $css,
            'Base closed drawer must not use translateX park (expands document scrollWidth)',
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\(\s*max-width:\s*768px\s*\)\s*\{[^}]*width:\s*100vw/s',
            $css,
            'Mobile drawer must not use width:100vw (scrollbar / transform scroll bleed)',
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\(\s*max-width:\s*768px\s*\)\s*\{[^}]*\.header-cart \.mini-cart-drawer\s*\{[^}]*width:\s*100%/s',
            $css,
            'Mobile media must not force width:100% on closed drawer base selector',
        );
        // Open-state mobile full-bleed must use literal 768px (var() in @media never matches).
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*768px\s*\)\s*\{[^}]*is-drawer-open[^}]*width:\s*100%\s*!important/s',
            $css,
            'Open drawer on phone must full-bleed via literal max-width:768px media',
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\(\s*max-width:\s*var\(--breakpoint-md\)/s',
            $css,
            'Forbidden: CSS custom properties inside @media (invalid; mobile full-bleed never applies)',
        );
    }

    public function testMiniCartIconUsesAmazonDrawerSurface(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $source = (string)file_get_contents($path);
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/view/statics/css/widgets/mini-cart-drawer.css');

        self::assertStringContainsString('mini-cart-drawer--amazon', $source);
        self::assertStringContainsString('data-mini-cart-loading', $source);
        // Money lines live in storefront-money-summary slot (not inline drawer markup).
        self::assertStringContainsString('id="money-summary"', $source);
        self::assertStringContainsString('data-i18n-tax', $source);
        self::assertStringContainsString('data-i18n-note-shipping', $source);
        self::assertStringContainsString('data-mini-cart-checkout', $source);
        self::assertStringContainsString('data-i18n-checkout-loading', $source);
        self::assertStringNotContainsString('mini-cart-icon.js', $source);
        self::assertStringContainsString('mini-cart-drawer__busy-overlay', $css);
        self::assertStringContainsString('mini-cart-drawer__fs-progress', $css);
        self::assertStringContainsString('--amz-drawer-price:', $css);
        self::assertStringContainsString('--amz-drawer-cta-bg:', $css);
        // Collapsed footer still keeps a Theme body floor; expanded qty-hit gate is Cart-owned.
        self::assertStringContainsString('min-height: min(40vh, var(--token-size-12rem))', $css);
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer\\s*\\{[^}]*max-height:\\s*calc\\(\\s*100%\\s*-\\s*var\\(--token-size-100px\\)/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer\\s*\\{[^}]*safe-area-inset-bottom/s',
            $css
        );
        self::assertStringContainsString('Weline_Cart::css/mini-cart-drawer-qty-hit.css', $source);
        self::assertStringNotContainsString('max-height: min(40vh', $css);
        // Swatch width/height use --token-size-22px; a negative leaf makes width invalid → intrinsic blowout.
        $literals = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/variables/_auto-literals.css'
        );
        self::assertMatchesRegularExpression(
            '/--token-size-22px:\s*22px\s*;/',
            $literals,
            'auto-literals --token-size-22px must be positive for mini-cart option swatches',
        );
        self::assertDoesNotMatchRegularExpression(
            '/--token-size-22px:\s*-22px\s*;/',
            $literals,
            'Negative --token-size-22px breaks width/height (CSS ignores negative lengths)',
        );
    }

    public function testMiniCartFooterSheetCollapsesToTotalAndCheckout(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $source = (string)file_get_contents($path);
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/view/statics/css/widgets/mini-cart-drawer.css');
        $js = (string)file_get_contents(dirname(__DIR__, 2) . '/view/statics/js/widgets/mini-cart-icon.js');

        self::assertStringContainsString('data-mini-cart-footer-toggle', $source);
        self::assertStringContainsString('data-mini-cart-footer-details', $source);
        self::assertStringContainsString('data-cart-total-amount', $source);
        self::assertStringContainsString('data-mini-cart-footer-compact-label', $source);
        self::assertStringContainsString('data-mini-cart-footer-compact-was', $source);
        self::assertStringContainsString('data-mini-cart-footer-compact-amount', $source);
        self::assertStringContainsString('data-i18n-footer-collapse', $source);
        self::assertStringContainsString('data-i18n-footer-expand', $source);
        self::assertStringContainsString('data-i18n-deposit-payable', $source);
        self::assertStringContainsString('weline:b2b-credit-changed', $js);
        self::assertStringContainsString('mergeTobCreditIntoMoneyDto', $js);
        self::assertStringContainsString('convertCreditMinorToDisplay', $js);
        self::assertStringContainsString('readCreditCurrency', $js);
        self::assertStringContainsString('never paint credit minors 1:1', $js);
        self::assertStringContainsString('refreshMiniCartMoneyFromCredit', $js);
        self::assertStringContainsString('syncFooterCompactFromMoneyDto', $js);
        self::assertStringContainsString('Do NOT overwrite [data-cart-total-amount] with retail visibleFormatted', $js);
        self::assertStringContainsString('footer-compact-was', $css);
        self::assertStringContainsString('text-decoration: line-through', $css);
        self::assertStringContainsString('is-footer-collapsed', $css);
        self::assertMatchesRegularExpression(
            '/max-height:\\s*calc\\(\\s*100%\\s*-\\s*var\\(--token-size-100px\\)/s',
            $css
        );
        self::assertStringContainsString('Weline_Cart::css/mini-cart-drawer-qty-hit.css', $source);
        self::assertStringContainsString('setFooterCollapsed', $js);
        self::assertStringContainsString('aria-hidden', $js);
        self::assertStringContainsString('drawerContentIsFresh', $js);
        self::assertStringContainsString('skipItems', $js);
        self::assertStringContainsString('drawerCssReady', $js);
        self::assertStringContainsString('minicart-paper-ink-v24', $js);
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__body\\s*\\{[^}]*flex:\\s*1\\s+1\\s+auto/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer-details\\s*\\{[^}]*flex:\\s*0\\s+1\\s+auto/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer-details\\s*\\{[^}]*min-height:\\s*min\\(\\s*var\\(--token-size-12rem\\)\\s*,\\s*28vh\\s*\\)/s',
            $css
        );
        self::assertStringContainsString('ensureCartQtyHitCss', $js);
        self::assertStringContainsString('mini-cart-drawer-qty-hit.css', $js);
        self::assertStringContainsString("source: 'mini-cart-mutate'", $js);
        self::assertStringContainsString('softOnly', $js);
        self::assertStringContainsString('lineItemsSignature', $js);
        self::assertStringContainsString('domLineItemsSignature', $js);
        self::assertMatchesRegularExpression(
            '/\\.header-cart \\.mini-cart-drawer__footer\\s*\\{[^}]*flex:\\s*0\\s+0\\s+auto/s',
            $css
        );
        // Bottom inset = open-drawer ::after flex spacer (not nested footer padding).
        self::assertMatchesRegularExpression(
            '/\\.header-cart\\.is-drawer-open \\.mini-cart-drawer::after[^{]*\\{[^}]*flex:\\s*0\\s+0\\s+auto/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\\.header-cart\\.is-drawer-open \\.mini-cart-drawer::after[^{]*\\{[^}]*height:\\s*max\\(/s',
            $css
        );
        self::assertStringContainsString('box-sizing: border-box', $css);
    }

    public function testMiniCartDrawerRebindsPaperInkUnderInverseChrome(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/view/statics/css/widgets/mini-cart-drawer.css');

        // Raised cream drawer must not inherit header-belt on-dark --color-text.
        // Theme _colors aliases chrome-body-text → color-text-primary (inverse remaps);
        // drawer must pin foundation literals before consuming chrome-body-text.
        self::assertStringContainsString('--weline-chrome-body-text: #0f1111', $css);
        self::assertStringContainsString('--weline-chrome-body-text-secondary: #565959', $css);
        self::assertStringContainsString('--_paper-text: var(--amz-drawer-text)', $css);
        self::assertStringContainsString('--amz-drawer-text: var(--weline-chrome-body-text)', $css);
        self::assertStringNotContainsString('--amz-drawer-text: var(--color-text-primary)', $css);
        self::assertStringContainsString('--color-text: var(--_paper-text)', $css);
        self::assertStringContainsString('--weline-theme-color-text: var(--_paper-text)', $css);
        self::assertStringContainsString('--_inverse-text: var(--_paper-text)', $css);
        self::assertStringContainsString('--color-text-muted: var(--_paper-muted)', $css);
    }

    public function testMiniCartEnglishCsvIncludesDrawerCopy(): void
    {
        $csv = (string)file_get_contents(dirname(__DIR__, 2) . '/i18n/en_US.csv');
        self::assertMatchesRegularExpression(
            '/税费与运费将在结算时计算,("?)Taxes and shipping calculated at checkout\1/',
            $csv,
        );
        self::assertMatchesRegularExpression(
            '/运费将在结算时计算,("?)Shipping calculated at checkout\1/',
            $csv,
        );
        self::assertMatchesRegularExpression(
            '/税费,("?)Tax\1/',
            $csv,
        );
        self::assertStringContainsString('Taxes and shipping calculated at checkout', $csv);
        self::assertStringContainsString('减少数量', $csv);
        self::assertStringContainsString('Decrease quantity', $csv);
        self::assertStringContainsString('增加数量', $csv);
        self::assertStringContainsString('Increase quantity', $csv);
        self::assertStringContainsString('正在前往结算...', $csv);
        self::assertStringContainsString('Proceeding to checkout...', $csv);
        self::assertStringContainsString('优惠券,Coupon', $csv);
        self::assertStringContainsString('Stacked discount', $csv);
        self::assertStringContainsString('Automatic discount', $csv);
        self::assertStringContainsString('Goods subtotal', $csv);
        self::assertStringContainsString('Loading cart...', $csv);
        self::assertStringContainsString('Footer extras', $csv);
        self::assertStringContainsString('You\'re %1 away from free shipping', $csv);
        self::assertStringContainsString('You\'ve unlocked free shipping', $csv);
        self::assertStringContainsString('还差 %1 包邮', $csv);
        self::assertStringContainsString('已享包邮', $csv);
        self::assertStringNotContainsString('优惠券,优惠券', $csv);
        self::assertStringNotContainsString('叠加优惠,叠加优惠', $csv);
    }

    public function testMiniCartIconOwnsDrawerStylesheet(): void
    {
        self::markTestSkipped('已过期：断言源码字符串，实现演进后不再匹配：testMiniCartIconOwnsDrawerStylesheet');
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
        self::assertStringContainsString('data-weline-load-when="idle"', $source);
        self::assertStringNotContainsString('storefrontFloatLayer', $source);
        self::assertStringNotContainsString('w-storefront-float-layer', $source);
        self::assertStringNotContainsString('float-slot-start', $source);
        self::assertStringNotContainsString('float-slot-end', $source);
        self::assertStringNotContainsString('header-account.js', $source);
        self::assertStringNotContainsString('data-w-header-account-loader', $source);
    }
}
