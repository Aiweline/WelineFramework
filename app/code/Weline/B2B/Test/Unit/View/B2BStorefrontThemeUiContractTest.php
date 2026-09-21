<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: B2B Theme ToC/ToB storefront templates exist with required markers.
 */
final class B2BStorefrontThemeUiContractTest extends TestCase
{
    private static function bp(string $relative): string
    {
        return dirname(__DIR__, 7) . '/' . ltrim($relative, '/');
    }

    public function testKeyTemplatesContainThemeMarkers(): void
    {
        $switcher = self::bp('app/code/Weline/B2B/view/templates/frontend/widgets/selling-mode-switcher.phtml');
        $deposit = self::bp('app/code/Weline/B2B/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml');
        $hang = self::bp('app/code/Weline/B2B/view/templates/frontend/partials/account-order-hang.phtml');
        $afterPriceHook = self::bp('app/code/Weline/B2B/view/hooks/Weline_Product/frontend/product/detail/after-price.phtml');
        $checkoutHook = self::bp('app/code/Weline/B2B/view/hooks/Weline_Checkout/frontend/layouts/checkout/summary-before.phtml');
        $productInfo = self::bp('app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml');
        $miniCart = self::bp('app/code/Weline/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml');
        $css = self::bp('app/code/Weline/B2B/view/statics/css/b2b-storefront.css');
        $js = self::bp('app/code/Weline/B2B/view/statics/js/selling-mode.js');

        foreach ([$switcher, $deposit, $hang, $afterPriceHook, $checkoutHook, $productInfo, $miniCart, $css, $js] as $path) {
            self::assertFileExists($path, $path);
        }

        $switcherContent = (string)file_get_contents($switcher);
        self::assertStringNotContainsString('createFrontendSession', $switcherContent);
        self::assertStringContainsString('$loggedIn = false', $switcherContent);
        self::assertStringContainsString("data-customer-logged-in=\"<?= \$loggedIn ? '1' : '0' ?>\"", $switcherContent);
        self::assertStringNotContainsString('SessionFactory::class)->create()', $switcherContent);
        self::assertStringContainsString('data-selling-mode', $switcherContent);
        self::assertStringContainsString('selling-mode-segment', $switcherContent);
        self::assertStringContainsString('product-native-detail__variant-axis', $switcherContent);
        self::assertStringContainsString('product-native-detail__variant-option', $switcherContent);
        self::assertStringContainsString('product-native-detail__variant-axis-label', $switcherContent);
        self::assertStringNotContainsString('data-w-width="full"', $switcherContent);
        self::assertStringNotContainsString('selling-mode-badge', $switcherContent);
        self::assertStringNotContainsString('b2b-selling-mode__chip', $switcherContent);
        self::assertStringNotContainsString('w-button-group', $switcherContent);
        self::assertStringContainsString('<lang>购买方式</lang>', $switcherContent);
        self::assertStringContainsString('<lang>零售</lang>', $switcherContent);
        self::assertStringContainsString('<lang>批发</lang>', $switcherContent);
        self::assertStringNotContainsString('data-testid="b2b-cart-type-chooser"', $switcherContent);
        self::assertStringNotContainsString('data-b2b-cart-chooser-pick', $switcherContent);
        self::assertStringNotContainsString('添加到哪个购物车？', $switcherContent);
        self::assertStringContainsString('data-b2b-apply-form', $switcherContent);
        self::assertStringContainsString('data-b2b-apply-form-wrap', $switcherContent);
        self::assertStringContainsString('data-b2b-apply-drawer', $switcherContent);
        self::assertStringContainsString('data-b2b-apply-guest-gate', $switcherContent);
        self::assertStringContainsString('data-w-component="drawer"', $switcherContent);
        self::assertStringContainsString('class="w-drawer__title"', $switcherContent);
        self::assertStringNotContainsString('<h5 id=', $switcherContent);
        self::assertStringContainsString('data-weline-mount="customer/login-panel"', $switcherContent);
        self::assertStringContainsString('data-weline-mount-load="account,customerLoginPanel"', $switcherContent);
        self::assertStringContainsString('data-weline-login-panel-heading="0"', $switcherContent);
        self::assertStringContainsString('登录后即可申请批发价与数量阶梯优惠', $switcherContent);
        self::assertStringNotContainsString('请使用下方登录面板完成登录', $switcherContent);
        self::assertStringNotContainsString('无需离开本页', $switcherContent);
        self::assertStringNotContainsString('data-i18n-title=', $switcherContent);
        self::assertStringNotContainsString('data-i18n-subtitle=', $switcherContent);
        self::assertStringContainsString('data-login-url', $switcherContent);
        self::assertStringContainsString('data-b2b-guest-login-status', $switcherContent);
        self::assertStringNotContainsString('data-b2b-guest-login"', $switcherContent);
        self::assertStringNotContainsString('data-testid="b2b-apply-guest-login"', $switcherContent);
        self::assertStringNotContainsString("<?= __('购买方式') ?>", $switcherContent);

        $jsContent = (string)file_get_contents($js);
        self::assertStringContainsString('Weline.UI.drawer.open', $jsContent);
        self::assertStringNotContainsString('loginRedirect', $jsContent);
        self::assertStringNotContainsString('global.location.href = url.toString()', $jsContent);
        self::assertStringContainsString('openApplyFlow', $jsContent);
        self::assertStringContainsString('requestFrameworkMountScan', $jsContent);
        self::assertStringContainsString('Weline.mount.scan', $jsContent);
        self::assertStringContainsString('waitFor', $jsContent);
        self::assertStringContainsString('clearGuestLoginFallback', $jsContent);
        self::assertStringContainsString("syncApplyPanels(root, 'tob')", $jsContent);
        self::assertStringNotContainsString('account.scanMounts', $jsContent);
        self::assertStringContainsString('WelineAccountModule', $jsContent);
        self::assertStringContainsString('readFrontendSessionCache', $jsContent);
        self::assertStringContainsString('ensureLogin', $jsContent);
        self::assertStringContainsString('customer/login-panel', $jsContent);
        self::assertStringContainsString('hydrateIdentityAttrs', $jsContent);
        self::assertStringContainsString('applyIdentity', $jsContent);
        self::assertStringContainsString('weline_b2b_qty_by_mode', $jsContent);
        self::assertStringContainsString('rememberQty', $jsContent);
        self::assertStringContainsString('recallQty', $jsContent);
        self::assertStringNotContainsString('confirmCartTypeForAdd', $jsContent);
        self::assertStringNotContainsString('openCartTypeChooser', $jsContent);
        self::assertStringNotContainsString('productHasDualSellingMode', $jsContent);
        self::assertStringNotContainsString('syncDualModeAddGate', $jsContent);
        self::assertStringContainsString('refresh({ forceNetwork: false })', $jsContent);
        // membership-active may forceNetwork; MutationObserver path must not storm cart.getCart
        self::assertStringContainsString('enhanceMiniCarts({ refresh: false })', $jsContent);
        self::assertStringContainsString('suppressChromeResyncUntil', $jsContent);
        self::assertStringContainsString('Do NOT forceNetwork here', $jsContent);
        self::assertStringContainsString(
            "var mode = String(detail.cart_type || detail.selling_mode || preferredMode(null)).toLowerCase();",
            $jsContent,
        );

        $accountNav = self::bp('app/code/Weline/B2B/view/hooks/account.sidebar.group.commerce.phtml');
        $accountContent = self::bp('app/code/Weline/B2B/view/hooks/account.sidebar.content.phtml');
        self::assertFileExists($accountNav);
        self::assertFileExists($accountContent);
        $nav = (string)file_get_contents($accountNav);
        $content = (string)file_get_contents($accountContent);
        self::assertStringContainsString('data-section="b2b-identity"', $nav);
        self::assertStringContainsString('<lang>批发身份</lang>', $nav);
        self::assertStringContainsString('@hook-sort-order 11', $nav);
        self::assertStringContainsString('等级、挂单跟进与订单沟通', $nav);
        self::assertStringContainsString("AccountSidebarContentGate::accepts('b2b-identity')", $content);
        self::assertStringContainsString('data-account-menu-signal', $nav);
        self::assertStringContainsString('account-identity-hub.phtml', $content);
        self::assertStringContainsString('data-b2b-order-chat-redirect', $content);
        self::assertStringContainsString('AccountSidebarProjectionProviderInterface', $content);
        self::assertStringContainsString('AccountMembershipTierPresenter', $content);

        $faq = self::bp('app/code/Weline/B2B/view/templates/frontend/faq/b2b-wholesale.phtml');
        self::assertFileExists($faq);
        $faqContent = (string)file_get_contents($faq);
        self::assertStringContainsString('WholesaleFaqVipLadderPresenter', $faqContent);
        self::assertStringContainsString('data-testid="b2b-faq-vip-ladder"', $faqContent);
        self::assertStringContainsString('data-testid="b2b-faq-vip-tier"', $faqContent);
        self::assertStringContainsString('data-testid="b2b-faq-credit-rules"', $faqContent);
        self::assertStringContainsString('data-testid="b2b-faq-deposit-rules"', $faqContent);
        self::assertStringContainsString('data-testid="b2b-faq-min-cash"', $faqContent);
        self::assertStringContainsString('min_cash_deposit_percent', $faqContent);
        self::assertStringContainsString('只抵「本期定金」', $faqContent);
        self::assertStringContainsString('最低现金占比', $faqContent);
        self::assertStringContainsString('<lang>消费门槛</lang>', $faqContent);
        self::assertStringContainsString('<lang>达标折扣额度</lang>', $faqContent);
        self::assertStringContainsString('use Weline\\Customer\\Model\\Customer', $content);
        self::assertStringContainsString("\$this->getData('user')", $content);
        self::assertStringContainsString('Lazy sidebar may set template user before facade session is mirrored.', $content);
        self::assertStringContainsString('data-b2b-account-identity="1"', $content);
        self::assertStringContainsString('data-b2b-switch-tob', $content);
        self::assertStringContainsString('b2b-account-membership-apply-form', $content);
        self::assertStringContainsString('<lang>切换到批发身份并去首页</lang>', $content);
        self::assertStringContainsString("__('提交批发身份申请')", $content);
        self::assertStringContainsString("__('修改后重新提交申请')", $content);
        self::assertStringContainsString('name="application_id"', $content);
        self::assertStringContainsString('b2b-account-identity-pending', $content);
        self::assertStringContainsString('data-testid="b2b-account-tier-name"', $content);
        self::assertStringContainsString('data-testid="b2b-account-tier-benefits"', $content);
        self::assertStringContainsString('data-testid="b2b-account-tier-next"', $content);
        self::assertStringContainsString('<lang>批发等级</lang>', $content);
        self::assertStringContainsString('<lang>当前优惠与权益</lang>', $content);
        self::assertStringContainsString('<lang>下一档</lang>', $content);
        self::assertStringContainsString('等级权益、挂单跟进、尾款与订单协商', $content);
        self::assertStringNotContainsString('<lang>客户 ID</lang>', $content);
        self::assertStringNotContainsString('<lang>网站</lang>', $content);
        self::assertStringNotContainsString('groupId', $content);
        self::assertStringNotContainsString('createFrontendSession', $content);
        self::assertStringNotContainsString('SessionFactory', $content);

        $headerLinks = self::bp('app/code/Weline/B2B/view/hooks/header-account-links.phtml');
        self::assertFileExists($headerLinks);
        $header = (string)file_get_contents($headerLinks);
        self::assertStringContainsString('#b2b-identity', $header);
        self::assertStringContainsString('data-testid="header-account-b2b-identity"', $header);
        self::assertStringContainsString('data-account-menu-auth="signed-in"', $header);
        self::assertStringContainsString("__('批发身份')", $header);
        self::assertStringContainsString('data-account-menu-signal', $header);
        self::assertStringContainsString('data-account-section="b2b-identity"', $content);
        self::assertStringContainsString('latestForCustomer', $content);
        self::assertStringContainsString('<lang>公司名称</lang>', $content);
        self::assertStringNotContainsString('<dd>', $content);
        self::assertStringNotContainsString('<dt>', $content);
        self::assertStringContainsString('b2b-account-identity__label', $content);
        self::assertStringContainsString('b2b-account-identity__value', $content);

        self::assertStringContainsString('bindAccountIdentities', $jsContent);
        self::assertStringContainsString('data-b2b-switch-tob', $jsContent);
        self::assertStringContainsString('weline:account-sidebar-content-loaded', $jsContent);
        self::assertStringContainsString('function switchToTobAndHome', $jsContent);
        self::assertStringContainsString('location.assign', $jsContent);
        self::assertStringContainsString('location.origin', $jsContent);
        self::assertStringNotContainsString('dispatchModeChanged(', $jsContent);
        self::assertStringContainsString("COOKIE_NAME + '_w'", $jsContent);
        self::assertStringContainsString('// Cookie / session win over SSR data-selling-mode', $jsContent);
        self::assertStringContainsString('product-card-add-to-cart', $jsContent);
        self::assertStringContainsString('function syncButtons', $jsContent);

        $depositContent = (string)file_get_contents($deposit);
        self::assertStringContainsString('data-b2b-checkout-credit', $depositContent);
        self::assertStringContainsString('data-mini-cart-tab-label', $depositContent);
        self::assertStringContainsString('checkout-summary-credit', $depositContent);
        self::assertStringContainsString('data-b2b-credit-panel', $depositContent);
        self::assertStringContainsString('data-b2b-credit-toggle', $depositContent);
        self::assertStringContainsString('data-b2b-credit-input', $depositContent);
        self::assertStringContainsString('data-w-component="tooltip"', $depositContent);
        self::assertStringContainsString('/faq/b2b-wholesale', $depositContent);
        self::assertStringContainsString('b2b-storefront.css)&v=20260911-credit-contrast1', $depositContent);
        self::assertStringNotContainsString('<lang>批发订单</lang>', $depositContent);
        self::assertStringNotContainsString('data-b2b-deposit-note', $depositContent);

        $orderNotePath = dirname($deposit) . '/checkout-tob-order-note.phtml';
        self::assertFileExists($orderNotePath);
        $orderNoteContent = (string)file_get_contents($orderNotePath);
        self::assertStringContainsString('data-b2b-deposit-note', $orderNoteContent);
        self::assertStringContainsString('w-badge', $orderNoteContent);
        self::assertStringContainsString('data-tone="primary"', $orderNoteContent);
        self::assertStringContainsString("\$t('批发订单')", $orderNoteContent);
        self::assertStringContainsString('30%', $orderNoteContent);

        $checkoutHookContent = (string)file_get_contents($checkoutHook);
        self::assertStringContainsString('checkout-tob-order-note.phtml', $checkoutHookContent);
        self::assertStringNotContainsString('checkout-tob-deposit-note.phtml', $checkoutHookContent);

        $hangContent = (string)file_get_contents($hang);
        self::assertStringContainsString('data-b2b-account-hang', $hangContent);
        self::assertStringContainsString('w-badge', $hangContent);
        self::assertStringContainsString('purpose=deposit', $hangContent);
        self::assertStringContainsString('purpose=balance', $hangContent);
        self::assertStringContainsString('<lang>支付定金</lang>', $hangContent);
        self::assertStringContainsString('<lang>支付尾款</lang>', $hangContent);

        $afterPriceContent = (string)file_get_contents($afterPriceHook);
        self::assertStringContainsString("fetch('Weline_B2B::templates/frontend/widgets/selling-mode-switcher.phtml')", $afterPriceContent);
        self::assertStringNotContainsString('include $path', $afterPriceContent);

        $productInfoContent = (string)file_get_contents($productInfo);
        self::assertStringContainsString('product-selling-mode', $productInfoContent);
        self::assertStringContainsString('selling-mode-switcher.phtml', $productInfoContent);
        self::assertStringContainsString("fetch('Weline_B2B::templates/frontend/widgets/selling-mode-switcher.phtml')", $productInfoContent);
        self::assertStringContainsString('data-tob-moq', $productInfoContent);
        self::assertStringContainsString('data-tob-qty-step', $productInfoContent);

        $miniCartContent = (string)file_get_contents($miniCart);
        // Theme mini-cart stays B2B-agnostic: host slots only; no tob segment markup.
        self::assertStringContainsString('data-mini-cart-type-host', $miniCartContent);
        self::assertStringContainsString('data-mini-cart-type-caption', $miniCartContent);
        self::assertStringContainsString('data-mini-cart-title', $miniCartContent);
        self::assertStringContainsString('b2b-checkout-credit', $miniCartContent);
        self::assertStringNotContainsString('data-mini-cart-type-seg', $miniCartContent);
        self::assertStringNotContainsString('data-mini-cart-type-option="tob"', $miniCartContent);
        self::assertStringNotContainsString('批发车', $miniCartContent);
        self::assertStringNotContainsString('header-cart__cart-type', $miniCartContent);
        self::assertStringNotContainsString('mini-cart-drawer__cart-type', $miniCartContent);

        $miniCartJs = self::bp('app/code/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js');
        self::assertFileExists($miniCartJs);
        $miniCartJsContent = (string)file_get_contents($miniCartJs);
        self::assertStringContainsString('preferredCartType', $miniCartJsContent);
        self::assertStringContainsString('weline:cart-type-changed', $miniCartJsContent);
        self::assertStringContainsString('WelineCart.requestCartType', $miniCartJsContent);
        self::assertStringContainsString('weline:selling-mode-changed', $miniCartJsContent);
        self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $miniCartJsContent);
        self::assertStringContainsString('MiniCart.refresh', $miniCartJsContent);
        self::assertStringNotContainsString('switchMiniCartType', $miniCartJsContent);
        self::assertStringNotContainsString('批发车', $miniCartJsContent);
        self::assertStringContainsString('cartQueryParams({ item_id: itemId })', $miniCartJsContent);

        $b2bMiniCartBoot = self::bp('app/code/Weline/B2B/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
        self::assertFileExists($b2bMiniCartBoot);
        $b2bBoot = (string)file_get_contents($b2bMiniCartBoot);
        self::assertStringContainsString('data-b2b-mini-cart-type="1"', $b2bBoot);
        self::assertStringContainsString('b2bSellingMode', $b2bBoot);
        self::assertStringContainsString('data-has-membership', $b2bBoot);
        self::assertStringNotContainsString('createFrontendSession', $b2bBoot);
        self::assertStringContainsString('data-customer-logged-in="0"', $b2bBoot);
        self::assertStringContainsString('data-i18n-coupon-tob-unavailable', $b2bBoot);
        self::assertStringContainsString('批发不可用', $b2bBoot);

        $jsContent = (string)file_get_contents($js);
        self::assertStringContainsString('enhanceMiniCarts', $jsContent);
        self::assertStringContainsString('didInitialMiniCartTypedRefresh', $jsContent);
        self::assertStringContainsString("enhanceMiniCarts({ refresh: false })", $jsContent);
        self::assertStringNotContainsString("enhanceMiniCarts({ refresh: true })", $jsContent);
        self::assertStringNotContainsString("visibilitychange", $jsContent);
        self::assertStringContainsString('ensureLogin', $jsContent);
        self::assertStringContainsString('readFrontendSessionCache', $jsContent);
        self::assertStringContainsString('data-b2b-mini-cart-type-option', $jsContent);
        self::assertStringContainsString('data-cart-type-option', $jsContent);
        self::assertStringContainsString('weline:cart-type-changed', $jsContent);
        self::assertStringContainsString('b2b-mini-cart-type-seg', $jsContent);
        self::assertStringContainsString('syncMiniCartCouponAvailability', $jsContent);
        self::assertStringContainsString('syncCheckoutChrome', $jsContent);
        self::assertStringContainsString('syncCouponAvailability', $jsContent);
        self::assertStringContainsString('ensureMiniCartExtrasVisible', $jsContent);
        self::assertStringContainsString('.weline-checkout__extras', $jsContent);
        self::assertStringContainsString('[data-cart-summary-extras="1"]', $jsContent);
        self::assertStringContainsString('data-active-tab', $jsContent);
        self::assertStringContainsString('w-order-notice', $jsContent);
        self::assertStringContainsString('is-tob-extras-stack', $jsContent);
        self::assertStringContainsString('classList.remove(\'is-tob-extras-stack\')', $jsContent);
        self::assertStringContainsString('is-tob-unavailable', $jsContent);
        self::assertStringContainsString('data-i18n-coupon-tob-unavailable', $jsContent);
        self::assertStringContainsString('data-marketing-coupon-tag', $jsContent);
        self::assertStringContainsString('ensureCartPageTypeSeg', $jsContent);
        self::assertStringContainsString('syncCartPageChrome', $jsContent);
        self::assertStringContainsString('data-cart-page-type-host', $jsContent);
        self::assertStringContainsString('cart-page-type-segment', $jsContent);

        $cssContent = (string)file_get_contents($css);
        self::assertStringContainsString('.b2b-mini-cart-type-seg', $cssContent);
        self::assertStringContainsString('--amz-drawer-cta-bg', $cssContent);
        self::assertStringContainsString('.b2b-hang-payment', $cssContent);
        self::assertStringContainsString('b2b-hang-payment__columns', $cssContent);
        self::assertStringContainsString('minmax(0, 1.4fr)', $cssContent);
        self::assertStringNotContainsString('b2b-hang-layout-switcher', $cssContent);
        self::assertStringNotContainsString('is-hang-layout-center', $cssContent);
        self::assertStringContainsString('禁止走 tertiary muted', $cssContent);
        self::assertStringContainsString('.w-b2b-checkout-credit__fx', $cssContent);
        self::assertStringContainsString('--weline-theme-text', $cssContent);
        self::assertStringContainsString('.w-dialog.w-product-purchase-panel', $cssContent);
        self::assertStringContainsString('min-height: 0', $cssContent);
        self::assertStringContainsString('product-native-detail--quick-add', $cssContent);
        // Affiliate 需求：列表「快捷规格加购」弹窗须展示分销分享；禁止 CSS 整块隐藏。
        self::assertStringNotContainsString(
            '.w-product-purchase-panel .affiliate-share-panel',
            $cssContent
        );
        self::assertStringNotContainsString(
            '.product-native-detail--quick-add [data-affiliate-share-root]',
            $cssContent
        );
        self::assertStringContainsString('is-tob-unavailable', $cssContent);
        self::assertStringContainsString('display: flex !important', $cssContent);
        self::assertStringContainsString('opacity: 0.55', $cssContent);
        self::assertStringContainsString('.weline-checkout[data-cart-type="tob"] .w-marketing-checkout-coupon.is-tob-unavailable', $cssContent);
        self::assertStringContainsString('.weline-checkout[data-cart-type="tob"] .weline-checkout__coupon-slot', $cssContent);
        self::assertStringContainsString('display: block !important', $cssContent);
        // 禁止旧「券槽+优惠行一并 display:none」串接写法
        self::assertStringNotContainsString(
            '.weline-checkout[data-cart-type="tob"] .weline-checkout__coupon-slot,' . "\n"
            . '.weline-checkout[data-cart-type="tob"] [data-checkout-discount-row]',
            $cssContent
        );
        // 禁止 tob 藏页签/堆叠双面板（应与零售同构）
        self::assertStringNotContainsString('.mini-cart-drawer__extras-tablist', $cssContent);
        // 禁止旧版「整块 display:none extras」逗号串接写法
        self::assertStringNotContainsString('.header-cart[data-cart-type="tob"] .mini-cart-drawer__extras,', $cssContent);
        self::assertStringNotContainsString('.header-cart.is-cart-type-tob .mini-cart-drawer__extras,', $cssContent);

        $qtyTiers = self::bp('app/code/Weline/B2B/view/templates/frontend/partials/qty-tiers.phtml');
        self::assertFileExists($qtyTiers);
        $qtyTiersContent = (string)file_get_contents($qtyTiers);
        self::assertStringNotContainsString('createFrontendSession', $qtyTiersContent);
        self::assertStringNotContainsString('SessionFactory::class)->create()', $qtyTiersContent);
        self::assertStringContainsString('$tiersHidden = true', $qtyTiersContent);
        self::assertStringContainsString('data-b2b-qty-ladder', $qtyTiersContent);
        self::assertStringContainsString('b2b-qty-tiers__save', $qtyTiersContent);
        self::assertStringContainsString('b2b-qty-tiers__delta', $qtyTiersContent);
        self::assertStringContainsString('data-total-save-minor', $qtyTiersContent);
        self::assertStringContainsString('省 %{1} %{2}', $qtyTiersContent);
        self::assertStringContainsString('每件低 %{1} %{2}', $qtyTiersContent);
        self::assertStringContainsString('--weline-theme-success', $cssContent);
        self::assertStringContainsString("'CNY', 'RMB' => '¥'", $qtyTiersContent);
        self::assertStringContainsString('data-unit-save-minor', $qtyTiersContent);
        self::assertStringContainsString('起订 %{1} · 步进 %{2}', $qtyTiersContent);

        $qtyTiersCss = (string)file_get_contents(self::bp('app/code/Weline/B2B/view/statics/css/b2b-storefront.css'));
        self::assertStringContainsString('.b2b-qty-tiers__list.is-ladder', $qtyTiersCss);
        self::assertStringContainsString('.b2b-qty-tiers__save', $qtyTiersCss);
        self::assertStringContainsString('.b2b-qty-tiers__delta', $qtyTiersCss);
        self::assertStringContainsString('.b2b-hang-payment.is-hang-completed', $qtyTiersCss);
        self::assertStringContainsString('.b2b-hang-payment__status[data-tone="success"]', $qtyTiersCss);

        $checkoutTobJs = self::bp('app/code/Weline/B2B/view/statics/js/checkout-tob.js');
        self::assertFileExists($checkoutTobJs);
        $checkoutTob = (string)file_get_contents($checkoutTobJs);
        self::assertStringContainsString('hangPurposeFromLocation', $checkoutTob);
        self::assertStringContainsString("hang.startPayment", $checkoutTob);
        self::assertStringContainsString("hang.paymentContext", $checkoutTob);
        self::assertStringContainsString('renderHangPaymentMethods', $checkoutTob);
        self::assertStringContainsString('renderHangOrderSummary', $checkoutTob);
        self::assertStringContainsString('data-b2b-hang-methods', $checkoutTob);
        self::assertStringContainsString('data-b2b-hang-order', $checkoutTob);
        self::assertStringContainsString('b2b-hang-order-lines', $checkoutTob);
        self::assertStringContainsString('b2b-hang-order-thumb', $checkoutTob);
        self::assertStringContainsString('image_url', $checkoutTob);
        self::assertStringContainsString('can_pay', $checkoutTob);
        self::assertStringContainsString('ctx.can_pay !== false', $checkoutTob);
        self::assertStringContainsString('applyHangNonPayableUi', $checkoutTob);
        self::assertStringContainsString('view_state', $checkoutTob);
        self::assertStringContainsString('is-hang-completed', $checkoutTob);
        self::assertStringContainsString('已付尾款', $checkoutTob);
        self::assertStringContainsString('本单尾款已结清，无需再支付', $checkoutTob);
        self::assertStringContainsString('b2b-hang-payment__columns', $checkoutTob);
        self::assertStringNotContainsString('ensureHangLayoutSwitcher', $checkoutTob);
        self::assertStringNotContainsString('hangLayoutFromLocation', $checkoutTob);
        self::assertStringNotContainsString('b2b-hang-layout-switcher', $checkoutTob);
        self::assertStringContainsString('order_summary', $checkoutTob);
        self::assertStringContainsString('返回批发身份', $checkoutTob);
        self::assertStringContainsString("hang_' + hang.purpose + '_' + hang.orderUuid", $checkoutTob);
        self::assertStringContainsString('hangRedirectUrl', $checkoutTob);
        self::assertStringContainsString('请选择支付方式', $checkoutTob);
        self::assertStringContainsString('暂无可用支付方式', $checkoutTob);
        self::assertStringNotContainsString(": 'fake_card'", $checkoutTob);
        self::assertStringNotContainsString('Date.now()', $checkoutTob);
        self::assertStringContainsString('redirect_url=', $checkoutTob);
        self::assertStringContainsString('hangLoginUrl', $checkoutTob);
        self::assertStringContainsString('suppressRetailEmptyChrome', $checkoutTob);
        self::assertStringContainsString('请先登录后再继续支付，登录后将自动返回此页', $checkoutTob);
        self::assertStringContainsString('syncCheckoutCouponAvailability', $checkoutTob);
        self::assertStringContainsString('is-tob-unavailable', $checkoutTob);
        self::assertStringContainsString('批发不可用', $checkoutTob);
        // 批发信用页签：仅 tob 展示；零售完全隐藏（禁止常显灰化）。
        self::assertStringContainsString('仅批发结账显示', $checkoutTob);
        self::assertStringContainsString('syncCreditExtrasTabVisibility', $checkoutTob);
        self::assertStringContainsString('creditSlot.hidden = type !== \'tob\'', $checkoutTob);
        self::assertStringContainsString('applyCartTypeAll', $checkoutTob);
        self::assertStringContainsString('weshop:mini-cart:open', $checkoutTob);
        self::assertStringContainsString('weline-cart-shell__credit-slot', $checkoutTob);
        self::assertStringNotContainsString('常显；零售灰化', $checkoutTob);
        self::assertStringContainsString('MutationObserver', $checkoutTob);
        // Observer must only sync coupon unavailability — never re-enter applyCartType/ensureCreditQuote.
        self::assertStringContainsString('syncCheckoutCouponAvailability(root, \'tob\')', $checkoutTob);
        self::assertStringContainsString('opts.ensureQuote === true', $checkoutTob);
        self::assertStringContainsString('Keep any settled quote (including quote_failed)', $checkoutTob);
        self::assertStringContainsString('lastRequestedDepositMinor', $checkoutTob);
        self::assertStringContainsString('resolveCheckoutCurrency', $checkoutTob);
        self::assertStringContainsString('currencyChanged', $checkoutTob);
        self::assertStringContainsString('data-b2b-credit-currency', $checkoutTob);
        self::assertStringContainsString('buildCreditFxSummary', $checkoutTob);
        self::assertStringContainsString('data-b2b-credit-fx', $checkoutTob);
        self::assertStringContainsString('formatInput', $checkoutTob);
        self::assertStringContainsString('勿再整段回写 hint_short', $checkoutTob);
        self::assertStringContainsString('Do not fetch from syncCreditUi', $checkoutTob);
        self::assertStringNotContainsString('couponSlot.hidden = true', $checkoutTob);
        self::assertStringContainsString('syncFromFrozen', $checkoutTob);
        self::assertStringContainsString('refreshCreditQuote', $checkoutTob);
        self::assertStringContainsString('ensureCreditQuote', $checkoutTob);
        self::assertStringContainsString("credit.quote", $checkoutTob);
        // 购物车/迷你车：按商品小计估算本期应付（=挂单定金基数）；优先 data-cart-goods-subtotal-major，避免隐藏过期小计。
        self::assertStringContainsString('data-cart-goods-subtotal', $checkoutTob);
        self::assertStringContainsString('data-cart-goods-subtotal-major', $checkoutTob);
        self::assertStringContainsString('readGoodsSubtotalMajor', $checkoutTob);
        self::assertStringContainsString('pickLargestMoneyMajor', $checkoutTob);
        self::assertStringContainsString('readGoodsMajorFromAttrHosts', $checkoutTob);
        self::assertStringContainsString('[data-weline-cart]', $checkoutTob);
        self::assertStringContainsString('暂无法估算本期定金', $checkoutTob);
        self::assertStringNotContainsString('当前订单无定金，无法用批发信用抵扣', $checkoutTob);
        self::assertStringContainsString('本期定金', $checkoutTob);
        self::assertStringContainsString('商品小计', $checkoutTob);
        self::assertStringContainsString('× 30%', $checkoutTob);

        $checkoutPage = self::bp('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
        self::assertFileExists($checkoutPage);
        $checkoutPageContent = (string)file_get_contents($checkoutPage);
        self::assertStringContainsString('refreshCreditQuote', $checkoutPageContent);
        self::assertStringContainsString('ensureCreditQuote', $checkoutPageContent);
        self::assertStringContainsString('estimateDepositMinor', $checkoutPageContent);
        self::assertStringContainsString('data-checkout-credit-row', $checkoutPageContent);
        self::assertStringContainsString('data-checkout-deposit-row', $checkoutPageContent);
        self::assertStringContainsString('weline:b2b-credit-changed', $checkoutPageContent);
        self::assertStringContainsString('本次应付定金', $checkoutPageContent);
        self::assertStringContainsString('keepChecked', $checkoutTob);
        self::assertStringContainsString('[data-weline-checkout], [data-checkout], .weline-checkout', $checkoutTob);
        self::assertStringNotContainsString("[data-checkout], .weline-checkout, form", $checkoutTob);
        self::assertStringContainsString('额度不够', $checkoutTob);
        self::assertStringNotContainsString('当前不可用批发信用', $checkoutTob);
        self::assertStringContainsString('readApplyMinor', $checkoutTob);
        self::assertStringContainsString('cashDepositMinor', $checkoutTob);
        self::assertStringContainsString('weline:b2b-credit-changed', $checkoutTob);
        self::assertStringContainsString('notifyCreditChanged', $checkoutTob);
        self::assertStringContainsString('creditState._notifySig', $checkoutTob);
        self::assertStringContainsString('data-b2b-credit', $checkoutTob);

        $b2bQuery = self::bp('app/code/Weline/B2B/extends/module/Weline_Framework/Query/B2BQueryProvider.php');
        self::assertFileExists($b2bQuery);
        $b2bQueryContent = (string)file_get_contents($b2bQuery);
        self::assertStringContainsString("'hang.startPayment'", $b2bQueryContent);
        self::assertStringContainsString("'hang.paymentContext'", $b2bQueryContent);
        self::assertStringContainsString("'credit.quote'", $b2bQueryContent);
        self::assertStringContainsString('function creditQuote', $b2bQueryContent);
        self::assertStringContainsString('B2BCheckoutCreditQuote', $b2bQueryContent);
        self::assertStringContainsString('Weline\\Customer\\Api\\Auth\\CustomerAccountFacadeInterface', $b2bQueryContent);
        self::assertStringContainsString('Weline\\Framework\\Runtime\\RuntimeProviderResolver', $b2bQueryContent);
        self::assertStringContainsString('createFrontendSession', $b2bQueryContent);
        self::assertStringNotContainsString('Weline\\Customer\\Api\\CustomerAccountFacadeInterface;', $b2bQueryContent);
        self::assertStringNotContainsString('Weline\\Framework\\Service\\RuntimeProviderResolver', $b2bQueryContent);

        $cartQuery = self::bp('app/code/Weline/Cart/extends/module/Weline_Framework/Query/CartQueryProvider.php');
        self::assertFileExists($cartQuery);
        $cartQueryContent = (string)file_get_contents($cartQuery);
        self::assertStringContainsString('cartServicePreference($params)', $cartQueryContent);
        self::assertStringContainsString('removeItem($scope, $itemId, $guestToken, $customerId, $preference)', $cartQueryContent);

        $hangAdmin = self::bp('app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml');
        self::assertFileExists($hangAdmin);
        $hangAdminContent = (string)file_get_contents($hangAdmin);
        self::assertStringContainsString('data-testid="b2b-copy-balance-pay-link"', $hangAdminContent);
        self::assertStringContainsString('data-testid="b2b-copy-deposit-pay-link"', $hangAdminContent);
        self::assertStringContainsString("purpose=' . rawurlencode(\$purpose)", $hangAdminContent);
        self::assertStringContainsString('storefront_base_url', $hangAdminContent);
        self::assertStringContainsString('data-copy-url', $hangAdminContent);

        $hangController = self::bp('app/code/Weline/B2B/Controller/Backend/ControlCenter.php');
        self::assertFileExists($hangController);
        $hangControllerContent = (string)file_get_contents($hangController);
        self::assertStringContainsString('CurrentWebsiteStorefrontUrlProviderInterface', $hangControllerContent);
        self::assertStringContainsString('storefront_base_url', $hangControllerContent);

        $adminService = self::bp('app/code/Weline/B2B/Service/B2BAdminService.php');
        self::assertFileExists($adminService);
        $adminServiceContent = (string)file_get_contents($adminService);
        self::assertStringContainsString('function assignGroupMember', $adminServiceContent);
        self::assertStringContainsString('grantCreditAfterAssign', $adminServiceContent);
        self::assertStringContainsString('grantToTarget', $adminServiceContent);

        $checkoutDescriptor = self::bp('app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
        self::assertFileExists($checkoutDescriptor);
        $checkoutDescriptorContent = (string)file_get_contents($checkoutDescriptor);
        self::assertStringContainsString("'cart_type' => ['type' => 'string'", $checkoutDescriptorContent);
        self::assertStringContainsString("'selling_mode' => ['type' => 'string'", $checkoutDescriptorContent);

        $menuXml = self::bp('app/code/Weline/B2B/etc/backend/menu.xml');
        self::assertFileExists($menuXml);
        $menuXmlContent = (string)file_get_contents($menuXml);
        self::assertStringContainsString('name="b2b_hang_orders"', $menuXmlContent);
        self::assertStringContainsString('source="Weline_B2B::commerce:partner:hang-orders"', $menuXmlContent);
        self::assertStringContainsString('action="b2b/backend/controlcenter/hangorders"', $menuXmlContent);
        self::assertStringContainsString('title="定金挂单"', $menuXmlContent);
    }
}
