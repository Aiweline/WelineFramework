<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: B2B Theme ToC/ToB storefront templates exist with required markers.
 */
final class B2BStorefrontThemeUiContractTest extends TestCase
{
    public function testKeyTemplatesContainThemeMarkers(): void
    {
        $switcher = BP . 'app/code/Weline/B2B/view/templates/frontend/widgets/selling-mode-switcher.phtml';
        $deposit = BP . 'app/code/Weline/B2B/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml';
        $hang = BP . 'app/code/Weline/B2B/view/templates/frontend/partials/account-order-hang.phtml';
        $afterPriceHook = BP . 'app/code/Weline/B2B/view/hooks/Weline_Product/frontend/product/detail/after-price.phtml';
        $checkoutHook = BP . 'app/code/Weline/B2B/view/hooks/Weline_Checkout/frontend/layouts/checkout/summary-before.phtml';
        $productInfo = BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml';
        $miniCart = BP . 'app/code/Weline/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $css = BP . 'app/code/Weline/B2B/view/statics/css/b2b-storefront.css';
        $js = BP . 'app/code/Weline/B2B/view/statics/js/selling-mode.js';

        foreach ([$switcher, $deposit, $hang, $afterPriceHook, $checkoutHook, $productInfo, $miniCart, $css, $js] as $path) {
            self::assertFileExists($path, $path);
        }

        $switcherContent = (string)file_get_contents($switcher);
        self::assertStringContainsString('createFrontendSession', $switcherContent);
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
        self::assertStringContainsString('data-b2b-apply-form', $switcherContent);
        self::assertStringContainsString('data-b2b-apply-form-wrap', $switcherContent);
        self::assertStringContainsString('data-b2b-apply-drawer', $switcherContent);
        self::assertStringContainsString('data-b2b-apply-guest-gate', $switcherContent);
        self::assertStringContainsString('data-w-component="drawer"', $switcherContent);
        self::assertStringContainsString('class="w-drawer__title"', $switcherContent);
        self::assertStringNotContainsString('<h5 id=', $switcherContent);
        self::assertStringContainsString('data-b2b-guest-login', $switcherContent);
        self::assertStringNotContainsString("<?= __('购买方式') ?>", $switcherContent);

        $jsContent = (string)file_get_contents($js);
        self::assertStringContainsString('Weline.UI.drawer.open', $jsContent);
        self::assertStringNotContainsString('loginRedirect', $jsContent);
        self::assertStringNotContainsString('global.location.href = url.toString()', $jsContent);
        self::assertStringContainsString('openApplyFlow', $jsContent);
        self::assertStringContainsString('hydrateIdentityAttrs', $jsContent);
        self::assertStringContainsString('applyIdentity', $jsContent);
        self::assertStringContainsString('weline_b2b_qty_by_mode', $jsContent);
        self::assertStringContainsString('rememberQty', $jsContent);
        self::assertStringContainsString('recallQty', $jsContent);
        $enCsv = BP . 'app/code/Weline/B2B/i18n/en_US.csv';
        self::assertFileExists($enCsv);
        $en = (string)file_get_contents($enCsv);
        self::assertStringContainsString('Retail cart', $en);
        self::assertStringContainsString('Wholesale cart', $en);
        self::assertStringContainsString('refresh({ forceNetwork: false })', $jsContent);
        self::assertStringNotContainsString("refresh({ forceNetwork: true })", $jsContent);

        $accountNav = BP . 'app/code/Weline/B2B/view/hooks/account.sidebar.group.commerce.phtml';
        $accountContent = BP . 'app/code/Weline/B2B/view/hooks/account.sidebar.content.phtml';
        self::assertFileExists($accountNav);
        self::assertFileExists($accountContent);
        $nav = (string)file_get_contents($accountNav);
        $content = (string)file_get_contents($accountContent);
        self::assertStringContainsString('data-section="b2b-identity"', $nav);
        self::assertStringContainsString('<lang>批发身份</lang>', $nav);
        self::assertStringContainsString('@hook-sort-order 11', $nav);
        self::assertStringContainsString('B2B 等级、申请状态与联系信息', $nav);
        self::assertStringContainsString("AccountSidebarContentGate::accepts('b2b-identity')", $content);
        self::assertStringContainsString('AccountSidebarProjectionProviderInterface', $content);
        self::assertStringContainsString('use Weline\\Customer\\Model\\Customer', $content);
        self::assertStringContainsString("\$this->getData('user')", $content);
        self::assertStringContainsString('Lazy sidebar may set template user before facade session is mirrored.', $content);
        self::assertStringContainsString('data-b2b-account-identity="1"', $content);
        self::assertStringContainsString('data-b2b-switch-tob', $content);
        self::assertStringContainsString('b2b-account-membership-apply-form', $content);
        self::assertStringContainsString('<lang>切换到批发身份并去首页</lang>', $content);
        self::assertStringContainsString('<lang>提交批发身份申请</lang>', $content);
        self::assertStringContainsString('b2b-account-identity-pending', $content);
        self::assertStringContainsString("__('标准批发')", $content);
        self::assertStringNotContainsString('<lang>客户 ID</lang>', $content);
        self::assertStringNotContainsString('<lang>网站</lang>', $content);
        self::assertStringNotContainsString('groupId', $content);
        self::assertStringNotContainsString('createFrontendSession', $content);
        self::assertStringNotContainsString('SessionFactory', $content);

        $headerLinks = BP . 'app/code/Weline/B2B/view/hooks/header-account-links.phtml';
        self::assertFileExists($headerLinks);
        $header = (string)file_get_contents($headerLinks);
        self::assertStringContainsString('#b2b-identity', $header);
        self::assertStringContainsString('data-testid="header-account-b2b-identity"', $header);
        self::assertStringContainsString('data-account-menu-auth="signed-in"', $header);
        self::assertStringContainsString("__('批发身份')", $header);
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

        $depositContent = (string)file_get_contents($deposit);
        self::assertStringContainsString('data-b2b-deposit-note', $depositContent);
        self::assertStringContainsString('w-badge', $depositContent);
        self::assertStringContainsString('data-tone="primary"', $depositContent);
        self::assertStringContainsString('<lang>批发订单</lang>', $depositContent);
        self::assertStringContainsString('30%', $depositContent);
        self::assertStringContainsString('b2b-storefront.css)?v=20260908-deposit-primary2', $depositContent);

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
        self::assertStringNotContainsString('data-mini-cart-type-seg', $miniCartContent);
        self::assertStringNotContainsString('data-mini-cart-type-option="tob"', $miniCartContent);
        self::assertStringNotContainsString('批发车', $miniCartContent);
        self::assertStringNotContainsString('header-cart__cart-type', $miniCartContent);
        self::assertStringNotContainsString('mini-cart-drawer__cart-type', $miniCartContent);

        $miniCartJs = BP . 'app/code/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js';
        self::assertFileExists($miniCartJs);
        $miniCartJsContent = (string)file_get_contents($miniCartJs);
        self::assertStringContainsString('preferredCartType', $miniCartJsContent);
        self::assertStringContainsString('weline:selling-mode-changed', $miniCartJsContent);
        self::assertStringContainsString('MiniCart.refresh', $miniCartJsContent);
        self::assertStringNotContainsString('switchMiniCartType', $miniCartJsContent);
        self::assertStringNotContainsString('批发车', $miniCartJsContent);
        self::assertStringContainsString('cartQueryParams({ item_id: itemId })', $miniCartJsContent);

        $b2bMiniCartBoot = BP . 'app/code/Weline/B2B/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        self::assertFileExists($b2bMiniCartBoot);
        $b2bBoot = (string)file_get_contents($b2bMiniCartBoot);
        self::assertStringContainsString('data-b2b-mini-cart-type="1"', $b2bBoot);
        self::assertStringContainsString('b2bSellingMode', $b2bBoot);
        self::assertStringContainsString('data-has-membership', $b2bBoot);
        self::assertStringContainsString('createFrontendSession', $b2bBoot);
        self::assertStringContainsString('data-i18n-coupon-tob-unavailable', $b2bBoot);
        self::assertStringContainsString('批发不可用', $b2bBoot);

        $jsContent = (string)file_get_contents($js);
        self::assertStringContainsString('enhanceMiniCarts', $jsContent);
        self::assertStringContainsString('didInitialMiniCartTypedRefresh', $jsContent);
        self::assertStringContainsString("enhanceMiniCarts({ refresh: false })", $jsContent);
        self::assertStringContainsString("enhanceMiniCarts({ refresh: true })", $jsContent);
        self::assertStringContainsString('data-b2b-mini-cart-type-option', $jsContent);
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
        self::assertStringContainsString('.w-dialog.w-product-purchase-panel', $cssContent);
        self::assertStringContainsString('min-height: 0', $cssContent);
        self::assertStringContainsString('product-native-detail--quick-add', $cssContent);
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

        $qtyTiers = BP . 'app/code/Weline/B2B/view/templates/frontend/partials/qty-tiers.phtml';
        self::assertFileExists($qtyTiers);
        $qtyTiersContent = (string)file_get_contents($qtyTiers);
        self::assertStringContainsString('createFrontendSession', $qtyTiersContent);
        self::assertStringNotContainsString('SessionFactory::class)->create()', $qtyTiersContent);

        $checkoutTobJs = BP . 'app/code/Weline/B2B/view/statics/js/checkout-tob.js';
        self::assertFileExists($checkoutTobJs);
        $checkoutTob = (string)file_get_contents($checkoutTobJs);
        self::assertStringContainsString('hangPurposeFromLocation', $checkoutTob);
        self::assertStringContainsString("hang.startPayment", $checkoutTob);
        self::assertStringContainsString("hang.paymentContext", $checkoutTob);
        self::assertStringContainsString('redirect_url=', $checkoutTob);
        self::assertStringContainsString('hangLoginUrl', $checkoutTob);
        self::assertStringContainsString('suppressRetailEmptyChrome', $checkoutTob);
        self::assertStringContainsString('请先登录后再继续支付，登录后将自动返回此页', $checkoutTob);
        self::assertStringContainsString('syncCheckoutCouponAvailability', $checkoutTob);
        self::assertStringContainsString('is-tob-unavailable', $checkoutTob);
        self::assertStringContainsString('批发不可用', $checkoutTob);
        self::assertStringContainsString('MutationObserver', $checkoutTob);
        self::assertStringNotContainsString('couponSlot.hidden = true', $checkoutTob);

        $b2bQuery = BP . 'app/code/Weline/B2B/extends/module/Weline_Framework/Query/B2BQueryProvider.php';
        self::assertFileExists($b2bQuery);
        $b2bQueryContent = (string)file_get_contents($b2bQuery);
        self::assertStringContainsString("'hang.startPayment'", $b2bQueryContent);
        self::assertStringContainsString("'hang.paymentContext'", $b2bQueryContent);
        self::assertStringContainsString('Weline\\Customer\\Api\\Auth\\CustomerAccountFacadeInterface', $b2bQueryContent);
        self::assertStringContainsString('Weline\\Framework\\Runtime\\RuntimeProviderResolver', $b2bQueryContent);
        self::assertStringContainsString('createFrontendSession', $b2bQueryContent);
        self::assertStringNotContainsString('Weline\\Customer\\Api\\CustomerAccountFacadeInterface;', $b2bQueryContent);
        self::assertStringNotContainsString('Weline\\Framework\\Service\\RuntimeProviderResolver', $b2bQueryContent);

        $cartQuery = BP . 'app/code/Weline/Cart/extends/module/Weline_Framework/Query/CartQueryProvider.php';
        self::assertFileExists($cartQuery);
        $cartQueryContent = (string)file_get_contents($cartQuery);
        self::assertStringContainsString('cartServicePreference($params)', $cartQueryContent);
        self::assertStringContainsString('removeItem($scope, $itemId, $guestToken, $customerId, $preference)', $cartQueryContent);

        $hangAdmin = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        self::assertFileExists($hangAdmin);
        $hangAdminContent = (string)file_get_contents($hangAdmin);
        self::assertStringContainsString('data-testid="b2b-copy-balance-pay-link"', $hangAdminContent);
        self::assertStringContainsString('data-testid="b2b-copy-deposit-pay-link"', $hangAdminContent);
        self::assertStringContainsString("purpose=' . rawurlencode(\$purpose)", $hangAdminContent);
        self::assertStringContainsString('storefront_base_url', $hangAdminContent);
        self::assertStringContainsString('data-copy-url', $hangAdminContent);

        $hangController = BP . 'app/code/Weline/B2B/Controller/Backend/ControlCenter.php';
        self::assertFileExists($hangController);
        $hangControllerContent = (string)file_get_contents($hangController);
        self::assertStringContainsString('CurrentWebsiteStorefrontUrlProviderInterface', $hangControllerContent);
        self::assertStringContainsString('storefront_base_url', $hangControllerContent);

        $checkoutDescriptor = BP . 'app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php';
        self::assertFileExists($checkoutDescriptor);
        $checkoutDescriptorContent = (string)file_get_contents($checkoutDescriptor);
        self::assertStringContainsString("'cart_type' => ['type' => 'string'", $checkoutDescriptorContent);
        self::assertStringContainsString("'selling_mode' => ['type' => 'string'", $checkoutDescriptorContent);

        $menuXml = BP . 'app/code/Weline/B2B/etc/backend/menu.xml';
        self::assertFileExists($menuXml);
        $menuXmlContent = (string)file_get_contents($menuXml);
        self::assertStringContainsString('name="b2b_hang_orders"', $menuXmlContent);
        self::assertStringContainsString('source="Weline_B2B::commerce:partner:hang-orders"', $menuXmlContent);
        self::assertStringContainsString('action="b2b/backend/controlcenter/hangorders"', $menuXmlContent);
        self::assertStringContainsString('title="定金挂单"', $menuXmlContent);
    }
}
