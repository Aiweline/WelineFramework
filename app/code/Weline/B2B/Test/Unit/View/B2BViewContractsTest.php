<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 7 *ContractTest files).
 * Observe one overall pass/fail via testAllB2BViewContractsPass().
 */
final class B2BViewContractsTest extends TestCase
{
    public function testAllB2BViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'B2BCheckoutCreditWidget::testWidgetDeclaresCheckoutSummaryCreditInjection' => function () {
            $this->assert_B2BCheckoutCreditWidget_testWidgetDeclaresCheckoutSummaryCreditInjection();
        },
            'B2BCheckoutCreditWidget::testCreditTemplateGatesOnEnabledAndSupportsSurfaces' => function () {
            $this->assert_B2BCheckoutCreditWidget_testCreditTemplateGatesOnEnabledAndSupportsSurfaces();
        },
            'B2BCheckoutCreditWidget::testCreditTemplateIsTabOnlyAndOrderNoteUsesSummaryBeforeHook' => function () {
            $this->assert_B2BCheckoutCreditWidget_testCreditTemplateIsTabOnlyAndOrderNoteUsesSummaryBeforeHook();
        },
            'B2BIdentityHub::testIdentityHubPartialAndNavMarkers' => function () {
            $this->assert_B2BIdentityHub_testIdentityHubPartialAndNavMarkers();
        },
            'B2BStorefrontThemeUi::testKeyTemplatesContainThemeMarkers' => function () {
            $this->assert_B2BStorefrontThemeUi_testKeyTemplatesContainThemeMarkers();
        },
            'OrderChatAccordion::testAccordionPartialDeclaresToggleAndComposer' => function () {
            $this->assert_OrderChatAccordion_testAccordionPartialDeclaresToggleAndComposer();
        },
            'OrderChatAccordion::testHangUsesAccordionInsteadOfIdentityHashLink' => function () {
            $this->assert_OrderChatAccordion_testHangUsesAccordionInsteadOfIdentityHashLink();
        },
            'OrderChatAccordion::testBackendOrderChatUsesWidgetDefaultInjection' => function () {
            $this->assert_OrderChatAccordion_testBackendOrderChatUsesWidgetDefaultInjection();
        },
            'OrderChatAccordion::testHangAdminMountsMerchantChat' => function () {
            $this->assert_OrderChatAccordion_testHangAdminMountsMerchantChat();
        },
            'OrderChatAccordion::testQuerySupportsMerchantOpenAndModulesRegistered' => function () {
            $this->assert_OrderChatAccordion_testQuerySupportsMerchantOpenAndModulesRegistered();
        },
            'ProductWholesaleAdminHook::testProductDeclaresBasicAndOffersAfterHooks' => function () {
            $this->assert_ProductWholesaleAdminHook_testProductDeclaresBasicAndOffersAfterHooks();
        },
            'ProductWholesaleAdminHook::testEditAndCreateTemplatesHostHooks' => function () {
            $this->assert_ProductWholesaleAdminHook_testEditAndCreateTemplatesHostHooks();
        },
            'ProductWholesaleAdminHook::testB2BInjectsWholesaleSwitchHooks' => function () {
            $this->assert_ProductWholesaleAdminHook_testB2BInjectsWholesaleSwitchHooks();
        },
            'QtyTiersSsrVisibility::testQtyTiersDoesNotEarlyReturnOnNonTobCookie' => function () {
            $this->assert_QtyTiersSsrVisibility_testQtyTiersDoesNotEarlyReturnOnNonTobCookie();
        },
            'QtyTiersSsrVisibility::testSellingModeJsTogglesQtyTiersHidden' => function () {
            $this->assert_QtyTiersSsrVisibility_testSellingModeJsTogglesQtyTiersHidden();
        },
            'SellingModeSwitcherBuyBoxBag::testSellingModeReadsBuyBoxBeforeEligibilityColdPath' => function () {
            $this->assert_SellingModeSwitcherBuyBoxBag_testSellingModeReadsBuyBoxBeforeEligibilityColdPath();
        },
        ];
    }

private function assert_B2BCheckoutCreditWidget_testWidgetDeclaresCheckoutSummaryCreditInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/extends/module/Weline_Widget/Weline_B2B/widget.php';
    $tpl = 'Weline_B2B::templates/frontend/widgets/checkout-tob-deposit-note.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml');
    self::assertStringContainsString('@widget.code {b2b-checkout-credit}', $src);
    self::assertStringContainsString('@widget.slot {checkout-summary-credit}', $src);
    self::assertStringContainsString('"slot":"checkout-summary-credit"', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_B2BCheckoutCreditWidget_testCreditTemplateGatesOnEnabledAndSupportsSurfaces(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml');
    self::assertStringContainsString('B2BPaymentAssetPolicyProvider', $template);
    self::assertStringContainsString('isEnabled', $template);
    self::assertStringContainsString('data-b2b-credit-surface', $template);
    self::assertStringContainsString('footer-extras', $template);
    self::assertStringContainsString('cart-summary-credit', $template);
    self::assertStringContainsString('b2b-credit-apply-input-', $template);
}

private function assert_B2BCheckoutCreditWidget_testCreditTemplateIsTabOnlyAndOrderNoteUsesSummaryBeforeHook(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml');
    self::assertStringContainsString('data-mini-cart-tab-label', $template);
    self::assertStringContainsString('data-mini-cart-tab-label-source', $template);
    self::assertStringContainsString('@widget.default_injections', $template);
    self::assertStringContainsString('checkout-summary-credit', $template);
    self::assertStringContainsString('data-b2b-credit-panel', $template);
    self::assertStringContainsString('data-testid="b2b-checkout-credit"', $template);
    self::assertStringContainsString('data-b2b-checkout-credit', $template);
    self::assertStringContainsString('data-b2b-credit-reason', $template);
    self::assertStringContainsString('data-b2b-credit-toc-msg', $template);
    self::assertStringContainsString('w-b2b-checkout-credit__reason', $template);
    self::assertStringContainsString('class="w-text w-b2b-checkout-credit__reason"', $template);
    self::assertStringContainsString('data-tone="danger"', $template);
    self::assertStringContainsString('w-b2b-checkout-credit__amount', $template);
    self::assertStringContainsString('data-b2b-credit-currency', $template);
    self::assertStringContainsString('data-testid="b2b-credit-currency"', $template);
    self::assertStringNotContainsString('data-b2b-credit-apply', $template);
    self::assertStringNotContainsString('确认抵扣', $template);
    self::assertStringContainsString('data-b2b-credit-applied', $template);
    self::assertStringContainsString('已计入下方小计', $template);
    self::assertStringContainsString('data-b2b-credit-fx', $template);
    self::assertStringContainsString('data-testid="b2b-credit-fx"', $template);
    self::assertStringContainsString('type="text"', $template);
    self::assertStringContainsString('inputmode="decimal"', $template);
    self::assertStringNotContainsString('type="number"', $template);
    self::assertStringContainsString('data-i18n-reason-no-balance', $template);
    self::assertStringContainsString('额度不够', $template);
    self::assertStringContainsString('data-i18n-quote-missing', $template);
    self::assertStringContainsString('data-i18n-quote-loading', $template);
    self::assertStringContainsString('data-i18n-reason-login', $template);
    self::assertStringContainsString('使用批发信用抵扣本期定金', $template);
    self::assertStringContainsString('暂无法估算本期定金', $template);
    self::assertStringContainsString('data-testid="b2b-credit-blurb"', $template);
    self::assertStringContainsString('data-b2b-min-cash-percent', $template);
    self::assertStringContainsString('只抵本期定金', $template);
    self::assertStringContainsString('了解批发信用规则', $template);
    self::assertStringNotContainsString('当前订单无定金，无法用批发信用抵扣', $template);
    self::assertStringNotContainsString('使用批发信用抵扣本期应付', $template);
    self::assertStringNotContainsString('当前不可用批发信用', $template);
    self::assertStringNotContainsString('data-i18n-unavailable-generic', $template);
    self::assertStringContainsString('class="w-b2b-checkout-credit__help"', $template);
    self::assertStringContainsString('data-b2b-credit-help', $template);
    // 原因必须在勾选之后、额度之前（就地）。
    $togglePos = strpos($template, 'data-b2b-credit-toggle');
    $reasonPos = strpos($template, 'data-b2b-credit-reason');
    $amountPos = strpos($template, 'data-b2b-credit-input');
    self::assertNotFalse($togglePos);
    self::assertNotFalse($reasonPos);
    self::assertNotFalse($amountPos);
    self::assertGreaterThan($togglePos, $reasonPos);
    self::assertGreaterThan($reasonPos, $amountPos);
    self::assertDoesNotMatchRegularExpression('/<button(?=[^>]*data-b2b-credit-help)[^>]*\bclass="[^"]*\bw-button\b/', $template);
    self::assertStringNotContainsString('<lang>批发订单</lang>', $template);
    self::assertStringNotContainsString('w-b2b-checkout-credit__lead', $template);
    self::assertStringNotContainsString('data-b2b-deposit-note', $template);
    $orderNote = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/checkout-tob-order-note.phtml');
    self::assertStringContainsString('data-b2b-deposit-note', $orderNote);
    self::assertStringContainsString("WidgetI18n::label(\$source)", $orderNote);
    self::assertStringContainsString("\$t('批发订单')", $orderNote);
    self::assertStringNotContainsString("__('批发订单')", $orderNote);
    self::assertStringNotContainsString('<lang>批发订单</lang>', $orderNote);
    self::assertStringContainsString('30%', $orderNote);
    self::assertStringContainsString('现金定金须保留至少', $orderNote);
    self::assertStringContainsString('不抵尾款', $orderNote);
    self::assertStringNotContainsString('data-b2b-credit-panel', $orderNote);
    $hook = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Checkout/frontend/layouts/checkout/summary-before.phtml';
    self::assertFileExists($hook);
    $hookSrc = (string) file_get_contents($hook);
    self::assertStringContainsString('checkout-tob-order-note.phtml', $hookSrc);
    self::assertStringNotContainsString('checkout-tob-deposit-note.phtml', $hookSrc);
}

private function assert_B2BIdentityHub_testIdentityHubPartialAndNavMarkers(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $hub = $root . '/app/code/Weline/B2B/view/templates/frontend/partials/account-identity-hub.phtml';
    $content = $root . '/app/code/Weline/B2B/view/hooks/account.sidebar.content.phtml';
    $nav = $root . '/app/code/Weline/B2B/view/hooks/account.sidebar.group.commerce.phtml';
    self::assertFileExists($hub);
    $hubSrc = (string) file_get_contents($hub);
    $contentSrc = (string) file_get_contents($content);
    $navSrc = (string) file_get_contents($nav);
    self::assertStringContainsString('data-testid="b2b-identity-hub"', $hubSrc);
    self::assertStringContainsString('listForCustomer', $hubSrc);
    self::assertStringContainsString('b2b-identity-hub__hint', $hubSrc);
    self::assertStringContainsString('挂单已完成', $hubSrc);
    self::assertStringContainsString('定金已付', $hubSrc);
    self::assertStringContainsString('尾款已结', $hubSrc);
    self::assertStringContainsString('查看订单', $hubSrc);
    self::assertStringContainsString('$chatUnread', $hubSrc);
    self::assertStringNotContainsString('data-testid="b2b-identity-open-chat"', $hubSrc);
    self::assertStringNotContainsString('data-b2b-open-order-chat', $hubSrc);
    self::assertStringContainsString('account-identity-hub.phtml', $contentSrc);
    self::assertStringContainsString('等级、挂单跟进与订单沟通', $navSrc);
    self::assertStringNotContainsString('data-testid="account-nav-b2b-order-chat"', $navSrc);
    $accordion = $root . '/app/code/Weline/B2B/view/templates/frontend/partials/order-chat-accordion.phtml';
    $accSrc = (string) file_get_contents($accordion);
    self::assertStringContainsString('b2b-order-chat-unread', $accSrc);
    self::assertStringContainsString('$chatUnread', $accSrc);
}

private function bp_B2BStorefrontThemeUi(string $relative): string
{
    return '/Users/weline/Project/Official/框架' . '/' . ltrim($relative, '/');
}

private function assert_B2BStorefrontThemeUi_testKeyTemplatesContainThemeMarkers(): void
{
    $switcher = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/frontend/widgets/selling-mode-switcher.phtml');
    $deposit = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml');
    $hang = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/frontend/partials/account-order-hang.phtml');
    $afterPriceHook = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/hooks/Weline_Product/frontend/product/detail/after-price.phtml');
    $checkoutHook = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/hooks/Weline_Checkout/frontend/layouts/checkout/summary-before.phtml');
    $productInfo = self::bp_B2BStorefrontThemeUi('app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml');
    $miniCart = self::bp_B2BStorefrontThemeUi('app/code/Weline/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml');
    $css = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/statics/css/b2b-storefront.css');
    $js = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/statics/js/selling-mode.js');
    foreach ([$switcher, $deposit, $hang, $afterPriceHook, $checkoutHook, $productInfo, $miniCart, $css, $js] as $path) {
        self::assertFileExists($path, $path);
    }
    $switcherContent = (string) file_get_contents($switcher);
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
    // Architecture XOR：单注册 default_injections；禁止运行时 RequestContext 去重补丁。
    self::assertStringNotContainsString('selling_mode_switcher_rendered', $switcherContent);
    self::assertStringContainsString('@widget.default_injections', $switcherContent);
    self::assertStringContainsString('product-selling-mode', $switcherContent);
    self::assertStringContainsString('data-b2b-retail-only-hint="1"', $switcherContent);
    self::assertStringContainsString('data-testid="b2b-retail-only-tob-hint"', $switcherContent);
    self::assertStringContainsString('本商品不支持批发', $switcherContent);
    self::assertStringContainsString('切换成零售车', $switcherContent);
    self::assertStringContainsString('data-b2b-retail-only-switch-toc', $switcherContent);
    self::assertStringContainsString('data-testid="b2b-retail-only-switch-toc"', $switcherContent);
    self::assertStringNotContainsString('查看零售车', $switcherContent);
    self::assertStringNotContainsString('data-b2b-retail-only-cart-link', $switcherContent);
    self::assertStringNotContainsString('b2b-retail-only-view-toc-cart', $switcherContent);
    self::assertStringContainsString('Inline boot', $switcherContent);
    self::assertStringContainsString('applyRetailOnlyHints', $switcherContent);
    self::assertStringContainsString('data-b2b-toc-wholesale-cta', $switcherContent);
    self::assertStringContainsString('data-testid="b2b-toc-wholesale-cta"', $switcherContent);
    self::assertStringContainsString('data-testid="selling-mode-soft-tob"', $switcherContent);
    self::assertStringContainsString('<lang>支持批发</lang>', $switcherContent);
    self::assertStringContainsString('<lang>点击切换到批发模式</lang>', $switcherContent);
    self::assertStringContainsString('data-b2b-selling-mode-segment', $switcherContent);
    $jsContent = (string) file_get_contents($js);
    self::assertStringContainsString('syncRetailOnlyHints', $jsContent);
    self::assertStringContainsString('chromeModeForRetailOnlyHint', $jsContent);
    self::assertStringContainsString('retail-only-hint-mirror', $jsContent);
    self::assertStringNotContainsString('function syncRetailOnlyHintBuyboxMirrors', $jsContent);
    self::assertStringContainsString('retail-only-add-preview', $jsContent);
    self::assertStringContainsString('data-cart-type-handoff', $jsContent);
    self::assertStringContainsString('Weline.UI.drawer.open', $jsContent);
    self::assertStringContainsString('data-b2b-toc-wholesale-cta', $jsContent);
    self::assertStringContainsString('data-b2b-selling-mode-segment', $jsContent);
    self::assertStringContainsString('data-cart-type-handoff', $jsContent);
    self::assertStringContainsString('clearCartTypeHandoff', $jsContent);
    self::assertStringContainsString("handoff === 'toc' || handoff === 'tob'", $jsContent);
    $modules = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/statics/frontend/weline.modules.js');
    $modulesContent = (string) file_get_contents($modules);
    self::assertStringContainsString('selling-mode.js?v=20261014-extras-boot-preserve1', $modulesContent);
    self::assertStringContainsString('function primaryCookieName', $jsContent);
    self::assertStringContainsString('function readCookieNames', $jsContent);
    self::assertStringContainsString('One preference → one jar entry', $jsContent);
    self::assertStringNotContainsString('cookieNames(resolveWebsiteId(root)).forEach', $jsContent);
    self::assertStringNotContainsString("document.cookie = 'selling_mode=' + encodeURIComponent(mode)", $jsContent);
    // Chrome sync uses boot() (orphan rebuild inside); blind rebuild reset user tab.
    self::assertStringContainsString('WelineMiniCartExtras.boot', $jsContent);
    self::assertStringContainsString('Blind rebuild tore down tabs', $jsContent);
    self::assertStringContainsString('checkout-tob.js?v=20261010-shell-toc-wins1', $modulesContent);
    self::assertStringContainsString('data-b2b-retail-only-switch-toc', $jsContent);
    self::assertStringContainsString('data-b2b-retail-only-switch-bound', $jsContent);
    self::assertStringContainsString('function switchToRetailCart', $jsContent);
    self::assertStringContainsString('switchToRetailCart: switchToRetailCart', $jsContent);
    self::assertStringContainsString('Explicit toc preference must win', $jsContent);
    self::assertStringContainsString('data-b2b-retail-only-inline-bound', $switcherContent);
    self::assertStringContainsString('switchToRetailCart', $switcherContent);
    self::assertStringContainsString('membershipResolved', $jsContent);
    self::assertStringContainsString('NEVER invent can_apply', $jsContent);
    self::assertStringContainsString('function pageViewCartType', $jsContent);
    self::assertStringContainsString('pageViewCartType(mode === \'tob\' ? \'tob\' : \'toc\')', $jsContent);
    self::assertStringNotContainsString('loginRedirect', $jsContent);
    self::assertStringNotContainsString('global.location.href = url.toString()', $jsContent);
    self::assertStringContainsString('openApplyFlow', $jsContent);
    self::assertStringContainsString('openApplyDrawerForEligible', $jsContent);
    self::assertStringContainsString('announceAlreadyMember', $jsContent);
    self::assertStringContainsString('isMembershipActive', $jsContent);
    self::assertStringContainsString('requestFrameworkMountScan', $jsContent);
    self::assertStringContainsString('Weline.mount.scan', $jsContent);
    self::assertStringContainsString('waitFor', $jsContent);
    self::assertStringContainsString('clearGuestLoginFallback', $jsContent);
    self::assertStringContainsString("syncApplyPanels(root, 'tob')", $jsContent);
    self::assertStringContainsString('reconcileGuestSafeUiState', $jsContent);
    self::assertStringContainsString("root.setAttribute('data-membership-ui-state', 'can_apply')", $jsContent);
    self::assertStringContainsString('empty shell', $jsContent);
    self::assertStringContainsString('data-b2b-membership-active-hint', $switcherContent);
    self::assertStringContainsString('已开通批发身份', $switcherContent);
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
    self::assertStringContainsString('Do NOT forceNetwork here', $jsContent);
    self::assertStringContainsString("var mode = String(detail.cart_type || detail.selling_mode || preferredMode(null)).toLowerCase();", $jsContent);
    $accountNav = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/hooks/account.sidebar.group.commerce.phtml');
    $accountContent = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/hooks/account.sidebar.content.phtml');
    self::assertFileExists($accountNav);
    self::assertFileExists($accountContent);
    $nav = (string) file_get_contents($accountNav);
    $content = (string) file_get_contents($accountContent);
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
    $faq = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/frontend/faq/b2b-wholesale.phtml');
    self::assertFileExists($faq);
    $faqContent = (string) file_get_contents($faq);
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
    self::assertStringContainsString('use Weline\Customer\Model\Customer', $content);
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
    $headerLinks = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/hooks/header-account-links.phtml');
    self::assertFileExists($headerLinks);
    $header = (string) file_get_contents($headerLinks);
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
    $depositContent = (string) file_get_contents($deposit);
    self::assertStringContainsString('data-b2b-checkout-credit', $depositContent);
    self::assertStringContainsString('data-mini-cart-tab-label', $depositContent);
    self::assertStringContainsString('checkout-summary-credit', $depositContent);
    self::assertStringContainsString('data-b2b-credit-panel', $depositContent);
    self::assertStringContainsString('data-b2b-credit-toggle', $depositContent);
    self::assertStringContainsString('data-b2b-credit-input', $depositContent);
    self::assertStringContainsString('data-w-component="tooltip"', $depositContent);
    self::assertStringContainsString('/faq/b2b-wholesale', $depositContent);
    self::assertStringContainsString('Weline_B2B::css/b2b-storefront.css', $depositContent);
    self::assertStringNotContainsString('<lang>批发订单</lang>', $depositContent);
    self::assertStringNotContainsString('data-b2b-deposit-note', $depositContent);
    $orderNotePath = dirname($deposit) . '/checkout-tob-order-note.phtml';
    self::assertFileExists($orderNotePath);
    $orderNoteContent = (string) file_get_contents($orderNotePath);
    self::assertStringContainsString('data-b2b-deposit-note', $orderNoteContent);
    self::assertStringContainsString('w-badge', $orderNoteContent);
    self::assertStringContainsString('data-tone="primary"', $orderNoteContent);
    self::assertStringContainsString("\$t('批发订单')", $orderNoteContent);
    self::assertStringContainsString('30%', $orderNoteContent);
    $checkoutHookContent = (string) file_get_contents($checkoutHook);
    self::assertStringContainsString('checkout-tob-order-note.phtml', $checkoutHookContent);
    self::assertStringNotContainsString('checkout-tob-deposit-note.phtml', $checkoutHookContent);
    $hangContent = (string) file_get_contents($hang);
    self::assertStringContainsString('data-b2b-account-hang', $hangContent);
    self::assertStringContainsString('w-badge', $hangContent);
    self::assertStringContainsString('purpose=deposit', $hangContent);
    self::assertStringContainsString('purpose=balance', $hangContent);
    self::assertStringContainsString('<lang>支付定金</lang>', $hangContent);
    self::assertStringContainsString('<lang>支付尾款</lang>', $hangContent);
    $afterPriceContent = (string) file_get_contents($afterPriceHook);
    // XOR：after-price 空实现；切换器不得再 Hook/fetch（仅 default_injections）。
    self::assertStringNotContainsString("fetch('Weline_B2B::templates/frontend/widgets/selling-mode-switcher.phtml')", $afterPriceContent);
    self::assertStringNotContainsString('include $path', $afterPriceContent);
    self::assertStringContainsString('default_injections', $afterPriceContent);
    self::assertDoesNotMatchRegularExpression('/^\s*echo\b/m', $afterPriceContent);
    $productInfoContent = (string) file_get_contents($productInfo);
    self::assertStringContainsString('product-selling-mode', $productInfoContent);
    // XOR：槽内禁止 sibling fetch 切换器（仅 B2B default_injections）；qty-tiers 仍可旁路。
    self::assertStringNotContainsString("fetch('Weline_B2B::templates/frontend/widgets/selling-mode-switcher.phtml')", $productInfoContent);
    self::assertStringContainsString('qty-tiers.phtml', $productInfoContent);
    self::assertStringNotContainsString("getHook('Weline_Product::frontend::product::detail::after-price')", $productInfoContent);
    self::assertStringContainsString('架构级单注册', $productInfoContent);
    self::assertStringContainsString('data-tob-moq', $productInfoContent);
    self::assertStringContainsString('data-tob-qty-step', $productInfoContent);
    $variantPos = strpos($productInfoContent, 'data-testid="product-variant-axes"');
    $sellingPos = strpos($productInfoContent, 'id="product-selling-mode"');
    self::assertNotFalse($variantPos);
    self::assertNotFalse($sellingPos);
    self::assertLessThan($variantPos, $sellingPos, 'selling-mode slot must sit above variant axes (compact tip)');
    $cssContent = (string) file_get_contents($css);
    self::assertStringContainsString('.b2b-selling-mode__soft-cta', $cssContent);
    self::assertStringContainsString('.b2b-selling-mode__soft-link', $cssContent);
    self::assertStringContainsString('.b2b-retail-only-hint', $cssContent);
    $miniCartContent = (string) file_get_contents($miniCart);
    // Theme mini-cart stays B2B-agnostic: host slots only; no tob segment markup.
    self::assertStringContainsString('data-mini-cart-type-host', $miniCartContent);
    self::assertStringContainsString('data-mini-cart-type-caption', $miniCartContent);
    self::assertStringContainsString('cart-type-caption', $miniCartContent);
    self::assertStringContainsString("__('零售')", $miniCartContent);
    self::assertStringNotContainsString('data-mini-cart-type-caption hidden', $miniCartContent);
    self::assertStringContainsString('data-mini-cart-title', $miniCartContent);
    self::assertStringContainsString('b2b-checkout-credit', $miniCartContent);
    self::assertStringNotContainsString('data-mini-cart-type-seg', $miniCartContent);
    self::assertStringNotContainsString('data-mini-cart-type-option="tob"', $miniCartContent);
    // i18n 文案「批发车」可出现在 data-i18n-tob-cart；禁止的是分车控件 markup。
    self::assertStringContainsString('data-i18n-tob-cart', $miniCartContent);
    self::assertStringContainsString('data-i18n-deposit-payable', $miniCartContent);
    self::assertStringNotContainsString('header-cart__cart-type', $miniCartContent);
    self::assertStringNotContainsString('mini-cart-drawer__cart-type', $miniCartContent);
    $miniCartJs = self::bp_B2BStorefrontThemeUi('app/code/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js');
    self::assertFileExists($miniCartJs);
    $miniCartJsContent = (string) file_get_contents($miniCartJs);
    self::assertStringContainsString('preferredCartType', $miniCartJsContent);
    self::assertStringContainsString('cartTypeFromPageView', $miniCartJsContent);
    self::assertStringContainsString('data-cart-type-handoff', $miniCartJsContent);
    self::assertStringContainsString('weline:cart-type-changed', $miniCartJsContent);
    self::assertStringContainsString('WelineCart.requestCartType', $miniCartJsContent);
    self::assertStringContainsString('weline:selling-mode-changed', $miniCartJsContent);
    self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $miniCartJsContent);
    self::assertStringContainsString('MiniCart.refresh', $miniCartJsContent);
    self::assertStringNotContainsString('switchMiniCartType', $miniCartJsContent);
    // Theme JS 仅作 i18n fallback；分车控件仍由 B2B 注入。
    self::assertStringContainsString("data-i18n-tob-cart", $miniCartJsContent);
    self::assertStringContainsString('weline:b2b-credit-changed', $miniCartJsContent);
    self::assertStringContainsString('mergeTobCreditIntoMoneyDto', $miniCartJsContent);
    self::assertStringContainsString('isTobCreditSurface', $miniCartJsContent);
    self::assertStringContainsString('normalizeRetailMoneyDto', $miniCartJsContent);
    self::assertStringContainsString('shell_cart_type', $miniCartJsContent);
    self::assertStringContainsString('commerce_deposit_allowed', $miniCartJsContent);
    self::assertStringNotContainsString("normalizeCartType(preferredCartType()) === 'tob'", $miniCartJsContent);
    self::assertStringContainsString('cartQueryParams({ item_id: itemId })', $miniCartJsContent);
    $cartPage = self::bp_B2BStorefrontThemeUi('app/code/Weline/Cart/view/templates/frontend/cart/index.phtml');
    self::assertFileExists($cartPage);
    $cartPageContent = (string) file_get_contents($cartPage);
    self::assertStringContainsString('isTobCreditSurface', $cartPageContent);
    self::assertStringContainsString('mergeTobCreditIntoCartMoneyDto', $cartPageContent);
    self::assertStringContainsString('normalizeRetailMoneyDto', $cartPageContent);
    self::assertStringContainsString('shell_cart_type', $cartPageContent);
    self::assertStringContainsString('commerce_deposit_allowed', $cartPageContent);
    self::assertStringNotContainsString('readPersistedCreditApplyMinor', $cartPageContent);
    self::assertStringNotContainsString('preferredCartType() === \'tob\'', $cartPageContent);
    self::assertStringContainsString('weline:b2b-credit-changed', $cartPageContent);
    self::assertStringContainsString("api.ensure(slot, { mode: 'cart' })", $cartPageContent);
    $b2bMiniCartBoot = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/frontend/widgets/b2b-mini-cart-boot.phtml');
    self::assertFileExists($b2bMiniCartBoot);
    $b2bBoot = (string) file_get_contents($b2bMiniCartBoot);
    self::assertStringContainsString('@widget.slot {storefront-tail-chrome}', $b2bBoot);
    self::assertStringContainsString('data-b2b-mini-cart-type="1"', $b2bBoot);
    self::assertStringContainsString('b2bSellingMode', $b2bBoot);
    // hidden boot must not use default visible IO (never intersects display:none).
    self::assertStringContainsString('data-weline-load-when="idle"', $b2bBoot);
    self::assertStringContainsString('data-has-membership', $b2bBoot);
    self::assertStringNotContainsString('createFrontendSession', $b2bBoot);
    self::assertStringContainsString('data-customer-logged-in="0"', $b2bBoot);
    self::assertStringContainsString('data-i18n-coupon-tob-unavailable', $b2bBoot);
    self::assertStringContainsString('批发不可用', $b2bBoot);
    $legacyBodyEnd = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertFileExists($legacyBodyEnd);
    self::assertStringNotContainsString('data-b2b-mini-cart-type', (string) file_get_contents($legacyBodyEnd));
    $creditWidget = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml');
    self::assertFileExists($creditWidget);
    $creditWidgetSrc = (string) file_get_contents($creditWidget);
    // SSR fail-closed: credit must not enter MiniCartExtras tabs on toc before JS.
    // Avoid [^>]* patterns — short-echo closers inside attrs break that class of regex.
    self::assertStringContainsString('data-b2b-checkout-credit', $creditWidgetSrc);
    self::assertStringContainsString('data-cart-type="toc"', $creditWidgetSrc);
    self::assertMatchesRegularExpression('/data-cart-type="toc"\s+hidden\b/s', $creditWidgetSrc);
    self::assertStringNotContainsString('data-cart-type="tob"', $creditWidgetSrc);
    $jsContent = (string) file_get_contents($js);
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
    $cssContent = (string) file_get_contents($css);
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
    self::assertStringContainsString('-webkit-text-fill-color', $cssContent);
    // 禁止残留确认按钮样式；`__applied` 状态行仍保留（勿用 `__apply` 子串误伤）。
    self::assertDoesNotMatchRegularExpression('/\.w-b2b-checkout-credit__apply(?!ed)\b/', $cssContent);
    self::assertStringContainsString('.w-b2b-checkout-credit__applied', $cssContent);
    self::assertStringContainsString('.w-dialog.w-product-purchase-panel', $cssContent);
    self::assertStringContainsString('min-height: 0', $cssContent);
    self::assertStringContainsString('product-native-detail--quick-add', $cssContent);
    self::assertStringContainsString('.w-product-purchase-panel__error', $cssContent);
    self::assertStringContainsString('.w-product-purchase-panel__error-text', $cssContent);
    // Affiliate 需求：列表「快捷规格加购」弹窗须展示分销分享；禁止 CSS 整块隐藏。
    self::assertStringNotContainsString('.w-product-purchase-panel .affiliate-share-panel', $cssContent);
    self::assertStringNotContainsString('.product-native-detail--quick-add [data-affiliate-share-root]', $cssContent);
    self::assertStringContainsString('is-tob-unavailable', $cssContent);
    self::assertStringContainsString('display: flex !important', $cssContent);
    self::assertStringContainsString('opacity: 0.55', $cssContent);
    self::assertStringContainsString('.weline-checkout[data-cart-type="tob"] .w-marketing-checkout-coupon.is-tob-unavailable', $cssContent);
    self::assertStringContainsString('.weline-checkout[data-cart-type="tob"] .weline-checkout__coupon-slot', $cssContent);
    self::assertStringContainsString('display: block !important', $cssContent);
    // 禁止旧「券槽+优惠行一并 display:none」串接写法
    self::assertStringNotContainsString('.weline-checkout[data-cart-type="tob"] .weline-checkout__coupon-slot,' . "\n" . '.weline-checkout[data-cart-type="tob"] [data-checkout-discount-row]', $cssContent);
    // 禁止 tob 藏页签/堆叠双面板（应与零售同构）
    self::assertStringNotContainsString('.mini-cart-drawer__extras-tablist', $cssContent);
    // 禁止旧版「整块 display:none extras」逗号串接写法
    self::assertStringNotContainsString('.header-cart[data-cart-type="tob"] .mini-cart-drawer__extras,', $cssContent);
    self::assertStringNotContainsString('.header-cart.is-cart-type-tob .mini-cart-drawer__extras,', $cssContent);
    $qtyTiers = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/frontend/partials/qty-tiers.phtml');
    self::assertFileExists($qtyTiers);
    $qtyTiersContent = (string) file_get_contents($qtyTiers);
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
    $qtyTiersCss = (string) file_get_contents(self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/statics/css/b2b-storefront.css'));
    self::assertStringContainsString('.b2b-qty-tiers__list.is-ladder', $qtyTiersCss);
    self::assertStringContainsString('.b2b-qty-tiers__save', $qtyTiersCss);
    self::assertStringContainsString('.b2b-qty-tiers__delta', $qtyTiersCss);
    self::assertStringContainsString('.b2b-hang-payment.is-hang-completed', $qtyTiersCss);
    self::assertStringContainsString('.b2b-hang-payment__status[data-tone="success"]', $qtyTiersCss);
    $checkoutTobJs = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/statics/js/checkout-tob.js');
    self::assertFileExists($checkoutTobJs);
    $checkoutTob = (string) file_get_contents($checkoutTobJs);
    self::assertStringContainsString('hangPurposeFromLocation', $checkoutTob);
    self::assertStringContainsString("hang.startPayment", $checkoutTob);
    self::assertStringContainsString("hang.paymentContext", $checkoutTob);
    self::assertStringContainsString('renderHangPaymentMethods', $checkoutTob);
    self::assertStringContainsString('renderHangOrderSummary', $checkoutTob);
    self::assertStringContainsString('discount_lines', $checkoutTob);
    self::assertStringContainsString('b2b-hang-admin-adjustment', $checkoutTob);
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
    self::assertStringContainsString('syncPaymentIncentiveAvailability', $checkoutTob);
    self::assertStringContainsString('adjustMoneySummaryDto', $checkoutTob);
    self::assertStringContainsString('isTobCreditSurface', $checkoutTob);
    self::assertStringContainsString('normalizeRetailMoneyDto', $checkoutTob);
    self::assertStringContainsString('shell_cart_type', $checkoutTob);
    self::assertStringContainsString('order_total_minor', $checkoutTob);
    self::assertStringContainsString('本单共计', $checkoutTob);
    // Cart / mini-cart share the same policy; shipping-pending must not force DAP note.
    self::assertStringContainsString('shippingPending', $checkoutTob);
    self::assertStringContainsString('mode === \'cart\' || mode === \'mini-cart\'', $checkoutTob);
    self::assertStringContainsString('data-payment-incentive', $checkoutTob);
    self::assertStringContainsString('dapTaxNoteText', $checkoutTob);
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
    // Observer re-hides payment badges + coupon unavailability — never re-enter applyCartType/ensureCreditQuote.
    self::assertStringContainsString('syncPaymentIncentiveAvailability(root, \'tob\')', $checkoutTob);
    self::assertStringContainsString('data-b2b-incentive-text', $checkoutTob);
    self::assertStringContainsString('syncCheckoutCouponAvailability(root, \'tob\')', $checkoutTob);
    self::assertStringContainsString('opts.ensureQuote === true', $checkoutTob);
    self::assertStringContainsString('Keep any settled quote (including quote_failed)', $checkoutTob);
    self::assertStringContainsString('lastRequestedDepositMinor', $checkoutTob);
    self::assertStringContainsString('resolveCheckoutCurrency', $checkoutTob);
    self::assertStringContainsString('currencyChanged', $checkoutTob);
    self::assertStringContainsString('currentDisplayCurrency', $checkoutTob);
    self::assertStringContainsString('convertCreditMinorToDisplay', $checkoutTob);
    self::assertStringContainsString('readCreditCurrency', $checkoutTob);
    self::assertStringContainsString('fillCreditI18n', $checkoutTob);
    self::assertStringContainsString('基准货币（钱包）%{1}', $checkoutTob);
    $depositNote = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml');
    self::assertStringContainsString('data-i18n-fx-same', $depositNote);
    self::assertStringContainsString('data-i18n-dap-tax-note', $depositNote);
    self::assertStringContainsString('data-i18n-cash-goods', $depositNote);
    // Cold-chaos: batch-prefetch WidgetI18n before per-string label() (chrome inject).
    $prefetch = strpos($depositNote, 'WidgetI18n::prefetchLabels');
    $labelFn = strpos($depositNote, '$t = static fn');
    self::assertNotFalse($prefetch);
    self::assertNotFalse($labelFn);
    self::assertLessThan($labelFn, $prefetch);
    self::assertStringContainsString('禁止无汇率 1:1 直扣', $checkoutTob);
    self::assertStringContainsString('Always re-resolve live display currency', $checkoutTob);
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
    self::assertStringContainsString("credit.apply", $checkoutTob);
    self::assertStringContainsString('saved_apply', $checkoutTob);
    self::assertStringContainsString('hydrateCreditControls', $checkoutTob);
    self::assertStringContainsString('function hydrateCreditControls', $checkoutTob);
    self::assertStringContainsString('ingestServerSavedApply', $checkoutTob);
    self::assertStringContainsString('persistCreditChoiceToServer', $checkoutTob);
    self::assertStringContainsString('capReady', $checkoutTob);
    self::assertStringContainsString('wait for quote with usable cap', $checkoutTob);
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
    $checkoutPage = self::bp_B2BStorefrontThemeUi('app/code/Weline/Checkout/view/frontend/checkout/index.phtml');
    self::assertFileExists($checkoutPage);
    $checkoutPageContent = (string) file_get_contents($checkoutPage);
    self::assertStringContainsString('refreshCreditQuote', $checkoutPageContent);
    self::assertStringContainsString('ensureCreditQuote', $checkoutPageContent);
    self::assertStringContainsString('adjustMoneySummaryDto', $checkoutPageContent);
    self::assertStringContainsString('estimateDepositMinor', $checkoutTob);
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
    self::assertStringNotContainsString('data-b2b-credit-apply', $checkoutTob);
    self::assertStringContainsString('weline.b2b.credit.apply.v1', $checkoutTob);
    self::assertStringContainsString('syncCreditAppliedStatus', $checkoutTob);
    self::assertStringContainsString('notifyCreditChanged', $checkoutTob);
    self::assertStringContainsString('creditState._notifySig', $checkoutTob);
    self::assertStringContainsString('data-b2b-credit', $checkoutTob);
    self::assertStringContainsString('不依赖 !toggle.checked', $checkoutTob);
    self::assertStringContainsString('readPersistedCreditChoice: readPersistedCreditChoice', $checkoutTob);
    self::assertStringContainsString('forceClear', $checkoutTob);
    self::assertStringContainsString('persist: false, dispatch: false', $checkoutTob);
    self::assertStringContainsString('must not wipe mini-cart', $checkoutTob);
    self::assertStringContainsString('Authoritative shell from server hydrate', $checkoutPageContent);
    self::assertStringNotContainsString('Live credit / selling-mode beats SSR default', $checkoutPageContent);
    self::assertStringContainsString('Persist-only path before checkout-tob binds', $cartPageContent);
    $b2bQuery = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/extends/module/Weline_Framework/Query/B2BQueryProvider.php');
    self::assertFileExists($b2bQuery);
    $b2bQueryContent = (string) file_get_contents($b2bQuery);
    self::assertStringContainsString("'hang.startPayment'", $b2bQueryContent);
    self::assertStringContainsString("'hang.paymentContext'", $b2bQueryContent);
    self::assertStringContainsString("'credit.quote'", $b2bQueryContent);
    self::assertStringContainsString('cart_type_not_tob', $b2bQueryContent);
    self::assertStringNotContainsString("?? 'tob'", $b2bQueryContent);
    self::assertStringContainsString("'credit.apply'", $b2bQueryContent);
    self::assertStringContainsString('function creditQuote', $b2bQueryContent);
    self::assertStringContainsString('function creditApply', $b2bQueryContent);
    self::assertStringContainsString('B2BCheckoutCreditQuote', $b2bQueryContent);
    self::assertStringContainsString('B2BCheckoutCreditApplySession', $b2bQueryContent);
    self::assertStringContainsString('saved_apply', $b2bQueryContent);
    self::assertStringContainsString('Weline\Customer\Api\Auth\CustomerAccountFacadeInterface', $b2bQueryContent);
    self::assertStringContainsString('Weline\Framework\Runtime\RuntimeProviderResolver', $b2bQueryContent);
    self::assertStringContainsString('createFrontendSession', $b2bQueryContent);
    self::assertStringNotContainsString('Weline\Customer\Api\CustomerAccountFacadeInterface;', $b2bQueryContent);
    self::assertStringNotContainsString('Weline\Framework\Service\RuntimeProviderResolver', $b2bQueryContent);
    $cartQuery = self::bp_B2BStorefrontThemeUi('app/code/Weline/Cart/extends/module/Weline_Framework/Query/CartQueryProvider.php');
    self::assertFileExists($cartQuery);
    $cartQueryContent = (string) file_get_contents($cartQuery);
    self::assertStringContainsString('cartServicePreference($params)', $cartQueryContent);
    self::assertStringContainsString('removeItem($scope, $itemId, $guestToken, $customerId, $preference)', $cartQueryContent);
    $hangAdmin = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/view/templates/backend/ControlCenter/index.phtml');
    self::assertFileExists($hangAdmin);
    $hangAdminContent = (string) file_get_contents($hangAdmin);
    self::assertStringContainsString('data-testid="b2b-copy-balance-pay-link"', $hangAdminContent);
    self::assertStringContainsString('data-testid="b2b-copy-deposit-pay-link"', $hangAdminContent);
    self::assertStringContainsString("purpose=' . rawurlencode(\$purpose)", $hangAdminContent);
    self::assertStringContainsString('storefront_base_url', $hangAdminContent);
    self::assertStringContainsString('data-copy-url', $hangAdminContent);
    $hangController = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/Controller/Backend/ControlCenter.php');
    self::assertFileExists($hangController);
    $hangControllerContent = (string) file_get_contents($hangController);
    self::assertStringContainsString('CurrentWebsiteStorefrontUrlProviderInterface', $hangControllerContent);
    self::assertStringContainsString('storefront_base_url', $hangControllerContent);
    $adminService = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/Service/B2BAdminService.php');
    self::assertFileExists($adminService);
    $adminServiceContent = (string) file_get_contents($adminService);
    self::assertStringContainsString('function assignGroupMember', $adminServiceContent);
    self::assertStringContainsString('grantCreditAfterAssign', $adminServiceContent);
    self::assertStringContainsString('grantToTarget', $adminServiceContent);
    $checkoutDescriptor = self::bp_B2BStorefrontThemeUi('app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertFileExists($checkoutDescriptor);
    $checkoutDescriptorContent = (string) file_get_contents($checkoutDescriptor);
    self::assertStringContainsString("'cart_type' => ['type' => 'string'", $checkoutDescriptorContent);
    self::assertStringContainsString("'selling_mode' => ['type' => 'string'", $checkoutDescriptorContent);
    $menuXml = self::bp_B2BStorefrontThemeUi('app/code/Weline/B2B/etc/backend/menu.xml');
    self::assertFileExists($menuXml);
    $menuXmlContent = (string) file_get_contents($menuXml);
    self::assertStringContainsString('name="b2b_hang_orders"', $menuXmlContent);
    self::assertStringContainsString('source="Weline_B2B::commerce:partner:hang-orders"', $menuXmlContent);
    self::assertStringContainsString('action="b2b/backend/controlcenter/hangorders"', $menuXmlContent);
    self::assertStringContainsString('title="定金挂单"', $menuXmlContent);
}

private function assert_OrderChatAccordion_testAccordionPartialDeclaresToggleAndComposer(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/partials/order-chat-accordion.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="b2b-order-chat-accordion"', $src);
    self::assertStringContainsString('data-b2b-order-chat-toggle', $src);
    self::assertStringContainsString('b2b-order-chat__toggle-open', $src);
    self::assertStringContainsString('data-open', $src);
    self::assertStringContainsString('data-b2b-order-chat-panel', $src);
    self::assertStringContainsString('data-b2b-order-chat-form', $src);
    self::assertStringContainsString('data-chat-role', $src);
}

private function assert_OrderChatAccordion_testHangUsesAccordionInsteadOfIdentityHashLink(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/partials/account-order-hang.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('order-chat-accordion.phtml', $src);
    self::assertStringNotContainsString('#b2b-identity', $src);
    self::assertStringContainsString('data-weline-load="b2bOrderChat"', $src);
    self::assertStringContainsString('b2b-account-hang__meta', $src);
}

private function assert_OrderChatAccordion_testBackendOrderChatUsesWidgetDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/extends/module/Weline_Widget/Weline_B2B/widget.php';
    $tpl = 'Weline_B2B::templates/backend/widgets/backend-order-chat.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/backend/widgets/backend-order-chat.phtml');
    self::assertStringContainsString('@widget.code {b2b-backend-order-chat}', $src);
    self::assertStringContainsString('@widget.slot {backend-order-b2b-chat}', $src);
    self::assertStringContainsString('"layout_type":"backend-order-view"', $src);
    self::assertStringContainsString('"slot":"backend-order-b2b-chat"', $src);
    self::assertStringContainsString("chatRole = 'merchant'", $src);
    self::assertStringContainsString('order-chat-accordion.phtml', $src);
    self::assertStringContainsString('data-testid="b2b-backend-order-chat-card"', $src);
    $hook = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Order/backend/order/view/wholesale-chat.phtml';
    self::assertFileExists($hook);
    self::assertStringContainsString('backend-order-chat.phtml', (string) file_get_contents($hook));
    $after = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Order/backend/order/view/after.phtml');
    self::assertStringContainsString('default_injections', $after);
    self::assertStringNotContainsString('order-chat-accordion.phtml', $after);
}

private function assert_OrderChatAccordion_testHangAdminMountsMerchantChat(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/backend/ControlCenter/index.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="b2b-hang-admin-chat"', $src);
    self::assertStringContainsString('data-testid="b2b-hang-order-ops"', $src);
    self::assertStringContainsString('b2b-hang-waterfall', $src);
    self::assertStringContainsString('b2b-hang-card', $src);
    self::assertStringContainsString('hang-compact1', $src);
    self::assertStringContainsString('data-density="compact"', $src);
    self::assertStringContainsString('b2b-hang-card__address-text', $src);
    self::assertStringContainsString('data-layout="inline"', $src);
    self::assertStringContainsString('$expandByDefault = false', $src);
    self::assertStringContainsString('b2b-hang-waterfall-inline', $src);
    self::assertStringContainsString('data-testid="b2b-hang-tabs"', $src);
    self::assertStringContainsString('data-testid="b2b-hang-severity"', $src);
    self::assertStringContainsString('data-testid="b2b-hang-customer"', $src);
    self::assertStringContainsString('data-testid="b2b-hang-lines"', $src);
    self::assertStringContainsString('data-testid="b2b-hang-card-chat"', $src);
    self::assertStringContainsString('$chatOpen', $src);
    self::assertStringContainsString("customer_id", $src);
    self::assertStringContainsString('商品详情', $src);
    self::assertStringNotContainsString('商品与沟通详情', $src);
    self::assertStringNotContainsString('getByOrderRef', $src);
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/statics/css/b2b-storefront.css');
    self::assertStringContainsString('.b2b-hang-waterfall', $css);
    self::assertStringContainsString('data-layout="waterfall"', $src);
    self::assertStringContainsString('flex-flow: row wrap', $css);
    self::assertStringContainsString('data-testid="b2b-hang-status-badge"', $src);
    self::assertStringContainsString('data-testid="b2b-hang-deposit-major"', $src);
    self::assertStringNotContainsString('data-testid="b2b-hang-order-chat-row"', $src);
    self::assertStringNotContainsString("__('hang_id')", $src);
    self::assertStringNotContainsString("__('定金(分)')", $src);
    self::assertStringContainsString('order-chat-accordion.phtml', $src);
    self::assertStringContainsString("chatRole = 'merchant'", $src);
    self::assertStringContainsString('data-weline-load="b2bOrderChat"', $src);
    self::assertStringContainsString("empty(\$dataset['rows']) && \$code !== 'hang-orders'", $src);
}

private function assert_OrderChatAccordion_testQuerySupportsMerchantOpenAndModulesRegistered(): void
{
    $query = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/extends/module/Weline_Framework/Query/B2BQueryProvider.php');
    self::assertStringContainsString('openOrCreateForMerchant', $query);
    self::assertStringContainsString('isBackendAdmin', $query);
    self::assertStringContainsString('FrontendWorkerExecutionContext', $query);
    self::assertStringContainsString('AREA_BACKEND', $query);
    self::assertStringContainsString('ROLE_MERCHANT', $query);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'orderChat\\.open'[\\s\\S]*?'auth'\\s*=>\\s*'any'/", $query);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'orderChat\\.send'[\\s\\S]*?'auth'\\s*=>\\s*'any'/", $query);
    $fe = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/statics/frontend/weline.modules.js');
    $be = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/statics/backend/weline.modules.js');
    self::assertStringContainsString('b2bOrderChat', $fe);
    self::assertStringContainsString('order-chat-accordion.js', $fe);
    self::assertStringContainsString('b2bOrderChat', $be);
}

private function assert_ProductWholesaleAdminHook_testProductDeclaresBasicAndOffersAfterHooks(): void
{
    $hook = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/hook.php';
    self::assertFileExists($hook);
    $src = (string) file_get_contents($hook);
    self::assertStringContainsString('Weline_Product::backend::catalog::edit::basic-after', $src);
    self::assertStringContainsString('Weline_Product::backend::catalog::create::basic-after', $src);
    self::assertStringContainsString('Weline_Product::backend::catalog::edit::offers-after', $src);
    self::assertStringNotContainsString('Weline_Product::backend::catalog::create::offers-after', $src);
}

private function assert_ProductWholesaleAdminHook_testEditAndCreateTemplatesHostHooks(): void
{
    $edit = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/backend/catalog/edit.phtml';
    $create = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/backend/catalog/index.phtml';
    self::assertFileExists($edit);
    self::assertFileExists($create);
    $editSrc = (string) file_get_contents($edit);
    $createSrc = (string) file_get_contents($create);
    self::assertStringContainsString('Weline_Product::backend::catalog::edit::basic-after', $editSrc);
    self::assertStringContainsString('Weline_Product::backend::catalog::edit::offers-after', $editSrc);
    self::assertStringContainsString('Weline_Product::backend::catalog::create::basic-after', $createSrc);
}

private function assert_ProductWholesaleAdminHook_testB2BInjectsWholesaleSwitchHooks(): void
{
    $editHook = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Product/backend/catalog/edit/basic-after.phtml';
    $createHook = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Product/backend/catalog/create/basic-after.phtml';
    $offersHook = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Product/backend/catalog/edit/offers-after.phtml';
    self::assertFileExists($editHook);
    self::assertFileExists($createHook);
    self::assertFileExists($offersHook);
    $editSrc = (string) file_get_contents($editHook);
    $createSrc = (string) file_get_contents($createHook);
    $offersSrc = (string) file_get_contents($offersHook);
    self::assertStringContainsString('data-testid="b2b-product-wholesale-enabled-mirror"', $editSrc);
    self::assertStringContainsString('data-b2b-wholesale-mirror="1"', $editSrc);
    self::assertStringContainsString('selling_mode_tob', $createSrc);
    self::assertStringContainsString('data-testid="b2b-product-wholesale-enabled"', $createSrc);
    self::assertStringContainsString('data-b2b-wholesale-canonical="1"', $createSrc);
    self::assertStringContainsString('启用批发', $createSrc);
    self::assertStringContainsString('data-b2b-wholesale-canonical="1"', $offersSrc);
    self::assertStringContainsString('data-testid="b2b-product-wholesale-enabled"', $offersSrc);
    self::assertStringContainsString('data-testid="b2b-product-tier-table"', $offersSrc);
    self::assertStringContainsString('批发经营与阶梯价', $offersSrc);
    $adminJs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/statics/js/backend/product-admin.js';
    self::assertFileExists($adminJs);
    $js = (string) file_get_contents($adminJs);
    self::assertStringContainsString('mergeWholesaleSellingModeFlag', $js);
    self::assertStringContainsString('data-b2b-wholesale-canonical', $js);
    self::assertStringContainsString('saveB2bTiersIfDirty', $js);
    self::assertStringContainsString('initB2bWholesaleOffersUi', $js);
}

private function assert_QtyTiersSsrVisibility_testQtyTiersDoesNotEarlyReturnOnNonTobCookie(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/partials/qty-tiers.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-b2b-qty-tiers="1"', $src);
    self::assertStringContainsString('hidden', $src);
    // Must not blank-return solely because cookie is not tob.
    self::assertStringNotContainsString("if (\$mode !== 'tob') {\n    return;\n}", $src);
    self::assertStringContainsString("\$mode !== 'tob'", $src);
}

private function assert_QtyTiersSsrVisibility_testSellingModeJsTogglesQtyTiersHidden(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/statics/js/selling-mode.js';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("tiers.hidden = mode !== 'tob'", $src);
    self::assertStringContainsString('data-b2b-qty-tiers', $src);
}

private function assert_SellingModeSwitcherBuyBoxBag_testSellingModeReadsBuyBoxBeforeEligibilityColdPath(): void
{
    $widget = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/selling-mode-switcher.phtml');
    self::assertStringContainsString("StorefrontPdpBuyBoxBag::hasKey('selling')", $widget);
    $bagPos = strpos($widget, "StorefrontPdpBuyBoxBag::hasKey('selling')");
    $eligPos = strpos($widget, 'allowsWholesaleDisplay');
    self::assertNotFalse($bagPos);
    self::assertNotFalse($eligPos);
    self::assertLessThan($eligPos, $bagPos);
    self::assertStringContainsString('$sellingFromBag === null', $widget);
}
}
