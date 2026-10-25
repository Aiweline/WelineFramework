<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\CartPersistencePolicy;

/**
 * Aggregated contract suite (merged from 12 *ContractTest files).
 * Observe one overall pass/fail via testAllCartViewContractsPass().
 */
final class CartViewContractsTest extends TestCase
{
    public function testAllCartViewContractsPass(): void
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
            'CartEmptySiblingUi::testEmptyActionsRowUsesSecondarySiblingButton' => function () {
            $this->assert_CartEmptySiblingUi_testEmptyActionsRowUsesSecondarySiblingButton();
        },
            'CartEmptySiblingUi::testMiniCartSiblingIsSecondaryButtonWithoutCalloutCard' => function () {
            $this->assert_CartEmptySiblingUi_testMiniCartSiblingIsSecondaryButtonWithoutCalloutCard();
        },
            'CartGuestSessionTtl::testFrontendAndQueryAlignToFifteenDayGuestTtl' => function () {
            $this->assert_CartGuestSessionTtl_testFrontendAndQueryAlignToFifteenDayGuestTtl();
        },
            'CartGuestSessionTtl::testSummaryCacheRejectsGhostCartWithoutGuestToken' => function () {
            $this->assert_CartGuestSessionTtl_testSummaryCacheRejectsGhostCartWithoutGuestToken();
        },
            'CartHelpPaySlot::testCartDeclaresHelpPaySlotWithoutHardcodedWidget' => function () {
            $this->assert_CartHelpPaySlot_testCartDeclaresHelpPaySlotWithoutHardcodedWidget();
        },
            'CartJsPendingCoupon::testCartJsRegistersPendingCouponAndGuestRenewHelpers' => function () {
            $this->assert_CartJsPendingCoupon_testCartJsRegistersPendingCouponAndGuestRenewHelpers();
        },
            'CartJsPendingCoupon::testRenewGuestSessionForcePathDoesNotSkipApiOnTtlOk' => function () {
            $this->assert_CartJsPendingCoupon_testRenewGuestSessionForcePathDoesNotSkipApiOnTtlOk();
        },
            'CartPageDealChrome::testCartPageBuildLineRendersWasAndCampaign' => function () {
            $this->assert_CartPageDealChrome_testCartPageBuildLineRendersWasAndCampaign();
        },
            'CartPageDealChrome::testCartPageLoadCartSoftFallsBackOnCommerceTypeGate' => function () {
            $this->assert_CartPageDealChrome_testCartPageLoadCartSoftFallsBackOnCommerceTypeGate();
        },
            'CartPageStateMutex::testPageShellUsesExclusiveDataCartViewCssAndInert' => function () {
            $this->assert_CartPageStateMutex_testPageShellUsesExclusiveDataCartViewCssAndInert();
        },
            'CartPageStateMutex::testCartHeaderTitleSitsRightOfTypeTabs' => function () {
            $this->assert_CartPageStateMutex_testCartHeaderTitleSitsRightOfTypeTabs();
        },
            'CartPageStateMutex::testMoneySummaryRowsKeepLabelAmountSpaceBetweenLikeDiscountLine' => function () {
            $this->assert_CartPageStateMutex_testMoneySummaryRowsKeepLabelAmountSpaceBetweenLikeDiscountLine();
        },
            'CartStorefrontQueryBin::testCartPageHydratesFromThePublishedQueryProvider' => function () {
            $this->assert_CartStorefrontQueryBin_testCartPageHydratesFromThePublishedQueryProvider();
        },
            'CartStorefrontQueryBin::testCartPageAlwaysIssuesGuestTokenAfterSharedSessionLoad' => function () {
            $this->assert_CartStorefrontQueryBin_testCartPageAlwaysIssuesGuestTokenAfterSharedSessionLoad();
        },
            'CartStorefrontQueryBin::testCartPageDoesNotSendClientOwnedIdentityOrScope' => function () {
            $this->assert_CartStorefrontQueryBin_testCartPageDoesNotSendClientOwnedIdentityOrScope();
        },
            'CartStorefrontQueryBin::testCartRowsUseDomTextInsteadOfHtmlInterpolation' => function () {
            $this->assert_CartStorefrontQueryBin_testCartRowsUseDomTextInsteadOfHtmlInterpolation();
        },
            'CartStorefrontQueryBin::testControllerAlwaysRendersTheHydrationCapableCartLayout' => function () {
            $this->assert_CartStorefrontQueryBin_testControllerAlwaysRendersTheHydrationCapableCartLayout();
        },
            'CartStorefrontQueryBin::testContinueShoppingKeepsTheLocaleAwareProductRoute' => function () {
            $this->assert_CartStorefrontQueryBin_testContinueShoppingKeepsTheLocaleAwareProductRoute();
        },
            'CartStorefrontQueryBin::testMoneyUsesLocaleAwareThousandsSeparatorsWithTwoDecimals' => function () {
            $this->assert_CartStorefrontQueryBin_testMoneyUsesLocaleAwareThousandsSeparatorsWithTwoDecimals();
        },
            'CartStorefrontQueryBin::testQuantityActionLabelsNeverWrapVertically' => function () {
            $this->assert_CartStorefrontQueryBin_testQuantityActionLabelsNeverWrapVertically();
        },
            'CartStorefrontQueryBin::testCartPageUsesCommerceSurfaceAndStylesheet' => function () {
            $this->assert_CartStorefrontQueryBin_testCartPageUsesCommerceSurfaceAndStylesheet();
        },
            'CartStorefrontQueryBin::testCartPageRendersSelectedOptionLabels' => function () {
            $this->assert_CartStorefrontQueryBin_testCartPageRendersSelectedOptionLabels();
        },
            'CartStorefrontSlotsCatalog::testCartDeclaresStorefrontSlotCatalogForInjectionDiscovery' => function () {
            $this->assert_CartStorefrontSlotsCatalog_testCartDeclaresStorefrontSlotCatalogForInjectionDiscovery();
        },
            'CartStorefrontSlotsCatalog::testCartPageSummaryDeclaresCouponAndNoteSlots' => function () {
            $this->assert_CartStorefrontSlotsCatalog_testCartPageSummaryDeclaresCouponAndNoteSlots();
        },
            'CartTypeSwitchDecouple::testCartJsOwnsCartTypeChangedEventAndRequestApi' => function () {
            $this->assert_CartTypeSwitchDecouple_testCartJsOwnsCartTypeChangedEventAndRequestApi();
        },
            'CartTypeSwitchDecouple::testStorefrontConsumersUseCartApiNotB2bSelectors' => function () {
            $this->assert_CartTypeSwitchDecouple_testStorefrontConsumersUseCartApiNotB2bSelectors();
        },
            'MiniCartQtyHitGate::testCartOwnsMiniCartQtyHitCssGate' => function () {
            $this->assert_MiniCartQtyHitGate_testCartOwnsMiniCartQtyHitCssGate();
        },
            'ProductAddToCartWidget::testWidgetRegistrationPinsPurchaseActionsSlot' => function () {
            $this->assert_ProductAddToCartWidget_testWidgetRegistrationPinsPurchaseActionsSlot();
        },
            'ProductAddToCartWidget::testWidgetTemplateUsesCartPurchaseActionsScript' => function () {
            $this->assert_ProductAddToCartWidget_testWidgetTemplateUsesCartPurchaseActionsScript();
        },
            'ProductAddToCartWidget::testPurchaseActionsScriptDispatchesCartUpdatedEvent' => function () {
            $this->assert_ProductAddToCartWidget_testPurchaseActionsScriptDispatchesCartUpdatedEvent();
        },
            'ProductAddToCartWidget::testPurchaseActionsEnsureGuestTokenAlwaysIssuesAndForceRenews' => function () {
            $this->assert_ProductAddToCartWidget_testPurchaseActionsEnsureGuestTokenAlwaysIssuesAndForceRenews();
        },
            'ProductAddToCartWidget::testPurchaseActionsSubmitSelectedEavVariantValues' => function () {
            $this->assert_ProductAddToCartWidget_testPurchaseActionsSubmitSelectedEavVariantValues();
        },
            'ProductAddToCartWidget::testPurchasePanelReopenClearsNativeDialogHidden' => function () {
            $this->assert_ProductAddToCartWidget_testPurchasePanelReopenClearsNativeDialogHidden();
        },
            'ProductAddToCartWidget::testPurchasePanelFetchFailureReplacesLoadingWithError' => function () {
            $this->assert_ProductAddToCartWidget_testPurchasePanelFetchFailureReplacesLoadingWithError();
        },
            'ProductAddToCartWidget::testPurchasePanelLoadsViaBinQueryProductProvider' => function () {
            $this->assert_ProductAddToCartWidget_testPurchasePanelLoadsViaBinQueryProductProvider();
        },
            'ProductAddToCartWidget::testPurchasePanelEnsuresProductInfoAssetsOnListingHosts' => function () {
            $this->assert_ProductAddToCartWidget_testPurchasePanelEnsuresProductInfoAssetsOnListingHosts();
        },
            'ProductAddToCartWidget::testPurchasePanelMountDropsSupersededResponses' => function () {
            $this->assert_ProductAddToCartWidget_testPurchasePanelMountDropsSupersededResponses();
        },
            'ProductAddToCartWidget::testAddOfferResolvesCartTypeFromPreferredModeBeforeSsrHtml' => function () {
            $this->assert_ProductAddToCartWidget_testAddOfferResolvesCartTypeFromPreferredModeBeforeSsrHtml();
        },
            'ProductCardAddToCartHook::testThemePartialUsesProductCardPurchaseHooks' => function () {
            $this->assert_ProductCardAddToCartHook_testThemePartialUsesProductCardPurchaseHooks();
        },
            'ProductCardAddToCartHook::testCartHookRendersCardWidgetTemplate' => function () {
            $this->assert_ProductCardAddToCartHook_testCartHookRendersCardWidgetTemplate();
        },
            'ProductCardAddToCartHook::testCardWidgetUsesCartAddAction' => function () {
            $this->assert_ProductCardAddToCartHook_testCardWidgetUsesCartAddAction();
        },
            'ProductCardAddToCartHook::testThemeHookRegistryPublishesProductCardAddToCartSlot' => function () {
            $this->assert_ProductCardAddToCartHook_testThemeHookRegistryPublishesProductCardAddToCartSlot();
        },
            'ProductCardAddToCartHook::testPurchaseActionsScriptBindsCardWidgetRoot' => function () {
            $this->assert_ProductCardAddToCartHook_testPurchaseActionsScriptBindsCardWidgetRoot();
        },
        ];
    }

private function assert_CartEmptySiblingUi_testEmptyActionsRowUsesSecondarySiblingButton(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $css = (string) \file_get_contents($root . '/view/statics/css/cart-page.css');
    $page = (string) \file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
    self::assertStringContainsString('weline-cart-shell__empty-actions', $page);
    self::assertStringContainsString('data-cart-siblings', $page);
    self::assertStringContainsString('data-cart-empty-lead', $page);
    self::assertStringContainsString('siblingBrowse', $page);
    self::assertStringContainsString('siblingBrowseLabel', $page);
    self::assertStringContainsString('facingCartLabel', $page);
    self::assertStringContainsString("'tocCart'", $page);
    self::assertStringContainsString("'tobCart'", $page);
    self::assertStringContainsString('浏览%1 %2件商品', $page);
    // toc/tob facing labels come from SSR copy — not API sibling.label (zh leak).
    self::assertStringContainsString('facingCartLabel(type)', $page);
    self::assertStringNotContainsString("row.label || (type === 'tob' ? '批发车' : '零售车')", $page);
    // Any sibling with stock gets a twin button — including gate/non-switchable rows.
    self::assertStringContainsString("btn.setAttribute('data-cart-sibling'", $page);
    self::assertStringNotContainsString('登录后可查看批发车', $page);
    self::assertStringContainsString('emptyLead.hidden = true', $page);
    self::assertStringContainsString('enrichEmptySiblings', $page);
    self::assertStringContainsString('weline-cart-shell__empty-actions', $css);
    self::assertStringContainsString('justify-content: center', $css);
    self::assertStringContainsString('.weline-cart-shell__sibling-cta', $css);
    self::assertStringContainsString('display: inline-flex', $css);
    self::assertStringNotContainsString('display: contents', $css);
    self::assertStringContainsString('function activeCartType', $page);
    self::assertStringContainsString('const mode = activeCartType()', $page);
    self::assertStringNotContainsString('const mode = preferredCartType();\n        try {\n            const cart = await getCartApi();\n            const params = Object.assign(await cartIdentity(mode), { item_id: itemId });', $page);
    // Twin secondary buttons sit side-by-side — not stretched to opposite edges.
    self::assertStringNotContainsString('.weline-cart-shell__empty-actions:has(.weline-cart-shell__siblings:not([hidden]))', $css);
    self::assertStringContainsString('var(--cart-secondary-bg)', $css);
    self::assertStringNotContainsString('--color-primary-bg-subtle', $css);
    self::assertStringNotContainsString('border-inline-start', $css);
    self::assertStringNotContainsString("label + '还有 '", $page);
}

private function assert_CartEmptySiblingUi_testMiniCartSiblingIsSecondaryButtonWithoutCalloutCard(): void
{
    $theme = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme';
    $drawer = (string) \file_get_contents($theme . '/view/statics/css/widgets/mini-cart-drawer.css');
    $js = (string) \file_get_contents($theme . '/view/statics/js/widgets/mini-cart-icon.js');
    self::assertStringContainsString("btn.className = 'mini-cart-drawer__btn mini-cart-drawer__btn--secondary'", $js);
    self::assertStringContainsString('data-i18n-sibling-browse', $js);
    self::assertStringContainsString('data-i18n-toc-cart', $js);
    self::assertStringContainsString('data-i18n-tob-cart', $js);
    self::assertStringContainsString('浏览%1 %2件商品', $js);
    self::assertStringNotContainsString('String(row.label || type)', $js);
    self::assertStringNotContainsString("label + '还有 '", $js);
    self::assertStringNotContainsString('--color-primary-bg-subtle', $drawer);
    self::assertStringNotContainsString('border-inline-start', $drawer);
    $phtml = (string) \file_get_contents($theme . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml');
    self::assertStringContainsString('data-i18n-sibling-browse', $phtml);
    self::assertStringContainsString('data-i18n-toc-cart', $phtml);
    self::assertStringContainsString('data-i18n-tob-cart', $phtml);
    self::assertStringContainsString('浏览%1 %2件商品', $phtml);
}

private function assert_CartGuestSessionTtl_testFrontendAndQueryAlignToFifteenDayGuestTtl(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $cartJs = (string) \file_get_contents($root . '/view/statics/js/cart.js');
    $purchase = (string) \file_get_contents($root . '/view/statics/js/widgets/product-purchase-actions.js');
    $provider = (string) \file_get_contents($root . '/extends/module/Weline_Framework/Query/CartQueryProvider.php');
    $store = (string) \file_get_contents($root . '/Service/CartCacheStore.php');
    $guestDaysExpr = '15 * 24 * 3600';
    self::assertStringContainsString('GUEST_SESSION_MS', $cartJs);
    self::assertStringContainsString($guestDaysExpr . ' * 1000', $cartJs);
    self::assertStringContainsString('guestSessionFrom', $cartJs);
    self::assertStringContainsString('var WEEK_MS = 7 * 24 * 3600 * 1000', $cartJs);
    self::assertStringContainsString($guestDaysExpr . ' * 1000', $purchase);
    self::assertStringContainsString('CartPersistencePolicy::guestTtlSeconds()', $provider);
    self::assertStringContainsString('CartPersistencePolicy::guestExpiresAtMs()', $provider);
    self::assertStringNotContainsString('3600 * 24 * 7', $provider);
    self::assertStringContainsString('another 15 days', $provider);
    self::assertStringContainsString('CartPersistencePolicy::ttlForCart', $store);
    self::assertStringNotContainsString('604800', $store);
    self::assertStringContainsString('1_296_000', (string) \file_get_contents($root . '/Service/CartPersistencePolicy.php'));
    self::assertSame(1296000, CartPersistencePolicy::GUEST_TTL_SECONDS);
    self::assertStringContainsString('expires_at_ms', $cartJs);
    self::assertStringContainsString('guestSessionFrom()', $cartJs);
}

private function assert_CartGuestSessionTtl_testSummaryCacheRejectsGhostCartWithoutGuestToken(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $cartJs = (string) \file_get_contents($root . '/view/statics/js/cart.js');
    $cartPage = (string) \file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
    $miniCart = (string) \file_get_contents(\dirname($root) . '/Theme/view/statics/js/widgets/mini-cart-icon.js');
    self::assertStringContainsString('function summaryHasLineItems', $cartJs);
    self::assertStringContainsString('Ghost-cart gate: non-empty local summaries require an exact guest_token match', $cartJs);
    self::assertStringContainsString('requireTokenMatch: true', $cartPage);
    self::assertStringContainsString('Ghost-cart gate: non-empty local summary without matching guest_token is a miss', $cartPage);
    self::assertStringContainsString('Ghost-cart gate: no matching guest_token', $miniCart);
    self::assertStringContainsString('requireTokenMatch: true', $miniCart);
    self::assertStringContainsString('Ghost-cart gate (legacy untyped key)', $miniCart);
    self::assertStringNotContainsString('if (!(token && cachedToken && token !== cachedToken))', $miniCart);
    self::assertStringContainsString('data-cart-siblings', $cartPage);
    self::assertStringContainsString('sibling_carts', $cartPage);
    self::assertStringContainsString('WelineCart.requestCartType', $cartPage);
    self::assertStringContainsString('weline:cart-type-changed', $cartPage);
    self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $cartPage);
    self::assertStringContainsString('data-mini-cart-sibling', $miniCart);
    self::assertStringContainsString('WelineCart.requestCartType', $miniCart);
    self::assertStringContainsString('weline:cart-type-changed', $miniCart);
    self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $miniCart);
    self::assertStringContainsString('scheduleCartSync(root)', $miniCart);
}

private function assert_CartHelpPaySlot_testCartDeclaresHelpPaySlotWithoutHardcodedWidget(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
    $catalogTpl = 'Weline_Cart::templates/frontend/widgets/cart-storefront-slots.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $catalogTpl));
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/widgets/cart-storefront-slots.phtml');
    self::assertStringContainsString('"cart-summary-help-pay"', $catalog);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/cart/index.phtml');
    self::assertStringContainsString('id="cart-summary-help-pay"', $tpl);
    self::assertStringNotContainsString('Weline_HelpPay::', $tpl);
    self::assertStringNotContainsString('<w:widget', $tpl);
    $checkoutPos = strpos($tpl, 'data-cart-checkout');
    $helpPos = strpos($tpl, 'id="cart-summary-help-pay"');
    self::assertNotFalse($checkoutPos);
    self::assertNotFalse($helpPos);
    self::assertGreaterThan($checkoutPos, $helpPos, 'help-pay slot must follow 去结算');
}

private function assert_CartJsPendingCoupon_testCartJsRegistersPendingCouponAndGuestRenewHelpers(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $cartJs = (string) \file_get_contents($root . '/view/statics/js/cart.js');
    $modules = (string) \file_get_contents($root . '/view/statics/frontend/weline.modules.js');
    $provider = (string) \file_get_contents($root . '/extends/module/Weline_Framework/Query/CartQueryProvider.php');
    self::assertStringContainsString('weline.cart.pending_coupon', $cartJs);
    self::assertStringContainsString('weline.cart.summary_cache', $cartJs);
    self::assertStringContainsString('summaryCacheKeyFor', $cartJs);
    self::assertStringContainsString("SUMMARY_CACHE_KEY + '.'", $cartJs);
    self::assertStringContainsString('currentDisplayCurrency', $cartJs);
    self::assertStringContainsString('summaryLocaleCurrencyMatches', $cartJs);
    self::assertStringContainsString('rememberSummary', $cartJs);
    self::assertStringContainsString('getCachedSummary', $cartJs);
    self::assertStringContainsString('clearCachedSummary', $cartJs);
    self::assertStringContainsString('SUMMARY_NEEDS_ORIGIN_KEY', $cartJs);
    self::assertStringContainsString('weline.cart.summary_needs_origin', $cartJs);
    self::assertStringContainsString('markNeedsOriginRefresh', $cartJs);
    self::assertStringContainsString('needsOriginRefresh', $cartJs);
    self::assertStringContainsString('consumeNeedsOriginRefresh', $cartJs);
    self::assertStringContainsString('invalidateAfterCheckout', $cartJs);
    self::assertStringContainsString('isCheckoutSuccessSurface', $cartJs);
    self::assertStringContainsString('weline:checkout:success', $cartJs);
    self::assertStringContainsString('weline:checkout:order-created', $cartJs);
    self::assertStringContainsString('weline:payment:outcome', $cartJs);
    self::assertStringContainsString('weline:payment:paid', $cartJs);
    self::assertStringContainsString('weline:payment:pending', $cartJs);
    self::assertStringContainsString('onPaymentLifecycleEvent', $cartJs);
    self::assertStringContainsString('logLifecycleReceive', $cartJs);
    self::assertStringContainsString('[WelineCart]', $cartJs);
    self::assertStringContainsString('payment\/handoff', $cartJs);
    self::assertStringContainsString('checkout\/success', $cartJs);
    self::assertStringContainsString('#payment-recovery', $cartJs);
    self::assertStringContainsString('data-checkout-payment-recovery', $cartJs);
    self::assertStringContainsString('cartType', $cartJs);
    self::assertStringContainsString('weline:cart:apply-coupon', $cartJs);
    self::assertStringContainsString('weline:maintenance:wait-gift-redeemed', $cartJs);
    self::assertStringContainsString('installCartStatus', $cartJs);
    self::assertStringContainsString('markCartActive', $cartJs);
    self::assertStringContainsString('CART_FLAG_KEY', $cartJs);
    self::assertStringContainsString('weline_cart_has_items', $cartJs);
    self::assertStringNotContainsString('installApiCartConfig', $cartJs);
    self::assertStringNotContainsString('cartFlagStorageKey', $cartJs);
    self::assertStringContainsString('load: "defer"', $modules);
    self::assertStringContainsString('renewGuestSession', $cartJs);
    self::assertStringContainsString('Weline_Cart::js/cart.js', $modules);
    self::assertStringContainsString("'renewGuestSession'", $provider);
    self::assertStringContainsString('function renewGuestSession', $provider);
    // Force / explicit Cookie sync must bypass needsGuestRenew TTL short-circuit.
    self::assertStringContainsString('opts.force === true', $cartJs);
    self::assertStringContainsString('if (!force && !needsGuestRenew(session))', $cartJs);
    self::assertStringContainsString('function syncGuestCookie()', $cartJs);
    self::assertStringContainsString('renewGuestSession({ force: true })', $cartJs);
    self::assertStringContainsString('syncGuestCookie: syncGuestCookie', $cartJs);
    self::assertStringContainsString('touchGuestCart', (string) \file_get_contents($root . '/Service/CartService.php'));
}

private function assert_CartJsPendingCoupon_testRenewGuestSessionForcePathDoesNotSkipApiOnTtlOk(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $cartJs = (string) \file_get_contents($root . '/view/statics/js/cart.js');
    // Non-force keeps TTL gate; force must reach client.renewGuestSession.
    self::assertMatchesRegularExpression('/function renewGuestSession\(options\)[\s\S]*?if \(!force && !needsGuestRenew\(session\)\)[\s\S]*?client\.renewGuestSession\(\{ guest_token:/s', $cartJs);
    self::assertStringContainsString('// Timer path: non-force (TTL short-circuit allowed).', $cartJs);
    self::assertStringContainsString('renewGuestSession().finally(function ()', $cartJs);
    // force=true must not soft-swallow API failure (ensureGuestToken needs cookieSyncedByRenew accuracy).
    self::assertStringContainsString('if (force) {', $cartJs);
    self::assertStringContainsString('throw err;', $cartJs);
    self::assertStringContainsString('renewGuestSession_failed', $cartJs);
    self::assertStringContainsString('renewGuestSession_unavailable', $cartJs);
}

private function assert_CartPageDealChrome_testCartPageBuildLineRendersWasAndCampaign(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/cart/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('weline-cart-shell__line-price-row', $src);
    self::assertStringContainsString('weline-cart-shell__line-price-now', $src);
    self::assertStringContainsString('weline-cart-shell__line-price-was', $src);
    self::assertStringContainsString('weline-cart-shell__line-price-campaign', $src);
    self::assertStringContainsString('compare_at_minor', $src);
    self::assertStringContainsString('campaign_label', $src);
}

private function assert_CartPageDealChrome_testCartPageLoadCartSoftFallsBackOnCommerceTypeGate(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/cart/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function isCommerceTypeGateFailure', $src);
    self::assertStringContainsString('function isCustomerLoggedIn', $src);
    self::assertStringContainsString('cart_commerce_type_login_required', $src);
    self::assertStringContainsString('cart_commerce_type_membership_required', $src);
    self::assertStringContainsString("await cart.getCart(await cartIdentity('toc'))", $src);
    self::assertStringContainsString('preferred_cart_type', $src);
    self::assertStringContainsString('Default / guest = retail', $src);
    self::assertStringContainsString('Do not paint wholesale login-required copy', $src);
}

private function assert_CartPageStateMutex_testPageShellUsesExclusiveDataCartViewCssAndInert(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $page = (string) \file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
    $css = (string) \file_get_contents($root . '/view/statics/css/cart-page.css');
    self::assertStringContainsString('data-cart-view="loading"', $page);
    self::assertStringContainsString('data-cart-page-state="loading"', $page);
    self::assertStringContainsString('data-cart-page-state="empty"', $page);
    self::assertStringContainsString('data-cart-page-state="error"', $page);
    self::assertStringContainsString('data-cart-page-state="ready"', $page);
    self::assertStringContainsString("root.setAttribute('data-cart-view', view)", $page);
    self::assertStringContainsString("node.setAttribute('aria-hidden', 'true')", $page);
    self::assertStringContainsString("node.setAttribute('inert', '')", $page);
    self::assertStringContainsString('showState(\'loading\')', $page);
    self::assertStringContainsString('.weline-cart-shell--cart-page > [data-cart-state]', $css);
    self::assertStringContainsString('display: none !important', $css);
    self::assertStringContainsString('[data-cart-view="loading"] > [data-cart-state="loading"]', $css);
    self::assertStringContainsString('[data-cart-view="empty"] > [data-cart-state="empty"]', $css);
    self::assertStringContainsString('[data-cart-view="error"] > [data-cart-state="error"]', $css);
    self::assertStringContainsString('[data-cart-view="ready"] > [data-cart-state="ready"]', $css);
}

private function assert_CartPageStateMutex_testCartHeaderTitleSitsRightOfTypeTabs(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $page = (string) \file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
    $css = (string) \file_get_contents($root . '/view/statics/css/cart-page.css');
    self::assertStringContainsString('class="weline-cart-shell__heading"', $page);
    self::assertStringContainsString('data-cart-page-type-host', $page);
    self::assertStringContainsString('data-cart-page-title', $page);
    self::assertStringContainsString('data-cart-subtitle', $page);
    self::assertStringContainsString('v=20261010-money-summary-row-flex1', $page);
    self::assertStringContainsString('.weline-cart-shell--cart-page .weline-cart-shell__header', $css);
    self::assertStringContainsString('justify-content: space-between', $css);
    self::assertStringContainsString('.weline-cart-shell--cart-page .weline-cart-shell__heading', $css);
    self::assertStringContainsString('margin-left: auto', $css);
    self::assertStringContainsString('text-align: right', $css);
}

private function assert_CartPageStateMutex_testMoneySummaryRowsKeepLabelAmountSpaceBetweenLikeDiscountLine(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $css = (string) \file_get_contents($root . '/view/statics/css/cart-page.css');
    self::assertStringContainsString('.weline-cart-shell--cart-page .w-storefront-money-summary__row', $css);
    self::assertStringContainsString('justify-content: space-between', $css);
    self::assertStringContainsString('.weline-cart-shell--cart-page .weline-cart-shell__discount-line', $css);
    self::assertStringContainsString('Widget CSS may bake into a print-deferred pack', $css);
}

private function assert_CartStorefrontQueryBin_testCartPageHydratesFromThePublishedQueryProvider(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    self::assertStringContainsString("await Weline.load('api')", $template);
    self::assertStringContainsString("api.resource('cart')", $template);
    self::assertStringContainsString("const guestTokenStorageKey = 'weline.cart.guest_token'", $template);
    self::assertStringContainsString('window.sessionStorage.getItem(guestTokenStorageKey)', $template);
    self::assertStringContainsString('issueGuestToken', $template);
    self::assertStringContainsString('ensureGuestToken', $template);
    self::assertStringContainsString('await cartIdentity()', $template);
    self::assertStringContainsString('data-cart-state="loading"', $template);
    self::assertStringContainsString('data-cart-state="empty"', $template);
    self::assertStringContainsString('data-cart-state="ready"', $template);
    self::assertStringContainsString('data-cart-state="error"', $template);
    self::assertStringContainsString('data-cart-view="loading"', $template);
    self::assertStringContainsString('data-cart-page-state', $template);
    self::assertStringContainsString('function showState(state)', $template);
    self::assertStringContainsString("root.setAttribute('data-cart-view', view)", $template);
    self::assertStringContainsString("node.setAttribute('inert', '')", $template);
}

private function assert_CartStorefrontQueryBin_testCartPageAlwaysIssuesGuestTokenAfterSharedSessionLoad(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    $ensureGuestToken = strpos($template, 'async function ensureGuestToken()');
    $loadCartModule = strpos($template, "await Weline.load('cart')", $ensureGuestToken ?: 0);
    $rereadStoredToken = strpos($template, 'guestToken = readStoredGuestToken()', $loadCartModule ?: 0);
    $forceRenew = strpos($template, 'renewGuestSession({ force: true })', $ensureGuestToken ?: 0);
    $issueEmpty = strpos($template, 'issueGuestToken({})', $forceRenew ?: 0);
    $issueWithToken = strpos($template, 'issueGuestToken({ guest_token: guestToken })', $ensureGuestToken ?: 0);
    // Early return on JS-only guestToken must stay gone (Cookie authority via always-issue).
    $earlyReturnReuse = preg_match('/async function ensureGuestToken\(\)\s*\{[\s\S]*?if \(guestToken\) \{\s*return guestToken;\s*\}/', $template);
    self::assertIsInt($ensureGuestToken);
    self::assertIsInt($loadCartModule, 'Cart page must load the shared Cart browser session first.');
    self::assertIsInt($rereadStoredToken, 'Cart page must re-read the shared guest token after loading Cart.');
    self::assertIsInt($forceRenew, 'Existing JS token must force-renew into Cookie before issue.');
    self::assertIsInt($issueEmpty, 'After renew OK (or no JS token) must issueGuestToken({}).');
    self::assertIsInt($issueWithToken, 'Renew fail/skip path must still try issueGuestToken({guest_token}).');
    self::assertSame(0, $earlyReturnReuse, 'Do not return a JS-only guestToken without issueGuestToken.');
    self::assertLessThan($rereadStoredToken, $loadCartModule);
    self::assertLessThan($forceRenew, $rereadStoredToken);
    self::assertLessThan($issueEmpty, $forceRenew);
    self::assertStringContainsString('cookieSyncedByRenew', $template);
    self::assertStringContainsString('isUnknownGuestTokenWorkerParam', $template);
    self::assertStringContainsString('Unknown frontend worker param:\s*guest_token', $template);
    // Must not always-issue with guest_token when Cookie was synced by renew.
    self::assertStringNotContainsString('const issueParams = guestToken ? { guest_token: guestToken } : {};', $template);
}

private function assert_CartStorefrontQueryBin_testCartPageDoesNotSendClientOwnedIdentityOrScope(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    self::assertStringNotContainsString('customer_id:', $template);
    self::assertStringNotContainsString('website_id:', $template);
    self::assertStringNotContainsString('store_code:', $template);
    self::assertStringNotContainsString('channel_code:', $template);
    self::assertStringNotContainsString('scope:', $template);
    self::assertStringNotContainsString('fetch(', $template);
    self::assertStringNotContainsString('XMLHttpRequest', $template);
}

private function assert_CartStorefrontQueryBin_testCartRowsUseDomTextInsteadOfHtmlInterpolation(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    self::assertStringContainsString('document.createElement', $template);
    self::assertStringContainsString('.textContent =', $template);
    self::assertStringNotContainsString('.innerHTML', $template);
}

private function assert_CartStorefrontQueryBin_testControllerAlwaysRendersTheHydrationCapableCartLayout(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Cart/Controller/Index.php');
    self::assertStringContainsString("\$this->layoutType = 'cart.default';", $controller);
    self::assertStringContainsString("\$this->request->setGet('layout_option', 'default');", $controller);
    self::assertStringNotContainsString("\$isEmpty ? 'cart.empty' : 'cart.default'", $controller);
}

private function assert_CartStorefrontQueryBin_testContinueShoppingKeepsTheLocaleAwareProductRoute(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    self::assertStringContainsString("@url{'products'}", $template);
    self::assertStringNotContainsString("@url{''}", $template);
}

private function assert_CartStorefrontQueryBin_testMoneyUsesLocaleAwareThousandsSeparatorsWithTwoDecimals(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    self::assertStringContainsString('new Intl.NumberFormat(undefined, {', $template);
    self::assertStringContainsString('minimumFractionDigits: 2', $template);
    self::assertStringContainsString('maximumFractionDigits: 2', $template);
    self::assertStringNotContainsString("Number(amount || 0).toFixed(2)", $template);
}

private function assert_CartStorefrontQueryBin_testQuantityActionLabelsNeverWrapVertically(): void
{
    $css = $this->commerceCss_CartStorefrontQueryBin();
    self::assertMatchesRegularExpression('/\.weline-cart-shell--cart-page \.weline-cart-shell__action\s*\{[^}]*white-space:\s*nowrap;/s', $css);
    self::assertMatchesRegularExpression('/\.weline-cart-shell--cart-page \.weline-cart-shell__qty\s*\{[^}]*flex-wrap:\s*nowrap;/s', $css);
}

private function assert_CartStorefrontQueryBin_testCartPageUsesCommerceSurfaceAndStylesheet(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    self::assertStringContainsString('weline-cart-shell--cart-page', $template);
    self::assertStringContainsString('@static(Weline_Cart::css/cart-page.css)&v=', $template);
    self::assertStringContainsString('weline-cart-shell__line', $template);
    self::assertStringContainsString('data-qty-decrease', $template);
    self::assertStringContainsString('weline-cart-shell__checkout', $template);
    self::assertStringNotContainsString('<table', $template);
}

private function assert_CartStorefrontQueryBin_testCartPageRendersSelectedOptionLabels(): void
{
    $template = $this->template_CartStorefrontQueryBin();
    $css = $this->commerceCss_CartStorefrontQueryBin();
    self::assertStringContainsString('function formatLineOptions(item)', $template);
    self::assertStringContainsString('function appendLineOptions(details, item)', $template);
    self::assertStringContainsString("weline-cart-shell__line-options", $template);
    self::assertStringContainsString('weline-cart-shell__line-option-swatch', $template);
    self::assertStringContainsString('data-cart-swatch-trigger', $template);
    self::assertStringContainsString('ensureCartSwatchPreview', $template);
    self::assertStringContainsString('option.swatch_image', $template);
    self::assertStringContainsString('option.value_label || option.value', $template);
    self::assertMatchesRegularExpression('/\.weline-cart-shell--cart-page \.weline-cart-shell__line-options\s*\{/', $css);
    self::assertMatchesRegularExpression('/\.weline-cart-shell--cart-page \.weline-cart-shell__line-option-swatch\s*\{/', $css);
    self::assertMatchesRegularExpression('/\.weline-cart-shell--cart-page \.weline-cart-shell__line-option-swatch-btn\s*\{/', $css);
    self::assertMatchesRegularExpression('/\.weline-cart-shell--cart-page \.weline-cart-shell__swatch-preview\s*\{/', $css);
}

private function template_CartStorefrontQueryBin(): string
{
    return (string) file_get_contents(BP . 'app/code/Weline/Cart/view/templates/frontend/cart/index.phtml');
}

private function commerceCss_CartStorefrontQueryBin(): string
{
    return (string) file_get_contents(BP . 'app/code/Weline/Cart/view/statics/css/cart-page.css');
}

private function assert_CartStorefrontSlotsCatalog_testCartDeclaresStorefrontSlotCatalogForInjectionDiscovery(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
    $tpl = 'Weline_Cart::templates/frontend/widgets/cart-storefront-slots.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $template = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/widgets/cart-storefront-slots.phtml';
    self::assertFileExists($template);
    $src = (string) file_get_contents($template);
    self::assertStringContainsString('discovery-only', $src);
    self::assertStringContainsString('@widget.code {cart-storefront-slots}', $src);
    self::assertStringContainsString('@widget.type {container}', $src);
    self::assertStringContainsString('@widget.is_container {true}', $src);
    self::assertStringContainsString('"cart-summary-discount"', $src);
    self::assertStringContainsString('"cart-summary-note"', $src);
    self::assertStringContainsString('"cart-summary-credit"', $src);
    self::assertStringContainsString('checkout-coupon', $src);
    self::assertStringContainsString('order-notice', $src);
    self::assertStringContainsString('b2b-checkout-credit', $src);
}

private function assert_CartStorefrontSlotsCatalog_testCartPageSummaryDeclaresCouponAndNoteSlots(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/cart/index.phtml';
    $template = (string) file_get_contents($path);
    self::assertStringContainsString('id="cart-summary-discount"', $template);
    self::assertStringContainsString('id="cart-summary-note"', $template);
    self::assertStringContainsString('id="cart-summary-credit"', $template);
    self::assertStringContainsString('class="weline-cart-shell__coupon-slot"', $template);
    self::assertStringContainsString('class="weline-cart-shell__note-slot"', $template);
    self::assertStringContainsString('class="weline-cart-shell__credit-slot"', $template);
    // toc fail-closed: credit slot SSR-hidden until tob JS reveals (extras tab gate).
    self::assertMatchesRegularExpression('/class="weline-cart-shell__credit-slot"\s+hidden\b/s', $template);
    self::assertStringContainsString('b2b-checkout-credit', $template);
    self::assertStringContainsString('data-cart-discount-breakdown', $template);
    self::assertStringContainsString('data-cart-goods-subtotal', $template);
    self::assertStringContainsString('data-cart-goods-subtotal-major', $template);
    self::assertStringContainsString('data-cart-discount-lines', $template);
    self::assertStringContainsString('discount_preview', $template);
    self::assertStringContainsString('weline:cart-updated', $template);
    self::assertStringNotContainsString('<w:widget', $template);
}

private function assert_CartTypeSwitchDecouple_testCartJsOwnsCartTypeChangedEventAndRequestApi(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $cartJs = (string) \file_get_contents($root . '/view/statics/js/cart.js');
    self::assertStringContainsString("CART_TYPE_CHANGED_EVENT = 'weline:cart-type-changed'", $cartJs);
    self::assertStringContainsString('function requestCartType', $cartJs);
    self::assertStringContainsString('requestCartType: requestCartType', $cartJs);
    self::assertStringContainsString('CART_TYPE_CHANGED_EVENT: CART_TYPE_CHANGED_EVENT', $cartJs);
    self::assertStringContainsString('weline_cart_type_explicit', $cartJs);
    self::assertStringContainsString('[data-cart-type-option', $cartJs);
    // forceNetwork must emit Event (not chrome-only click) so preferCache cannot drop reload.
    self::assertStringContainsString('!forceNetwork && options.clickChrome !== false', $cartJs);
    self::assertMatchesRegularExpression("/forceNetwork:\\s*forceNetwork/", $cartJs);
}

private function assert_CartTypeSwitchDecouple_testStorefrontConsumersUseCartApiNotB2bSelectors(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $cartPage = (string) \file_get_contents($root . '/view/templates/frontend/cart/index.phtml');
    $miniCart = (string) \file_get_contents(\dirname($root) . '/Theme/view/statics/js/widgets/mini-cart-icon.js');
    $b2b = (string) \file_get_contents(\dirname($root) . '/B2B/view/statics/js/selling-mode.js');
    $cartCss = (string) \file_get_contents($root . '/view/statics/css/cart-page.css');
    self::assertStringContainsString('WelineCart.requestCartType', $cartPage);
    self::assertStringContainsString('weline:cart-type-changed', $cartPage);
    self::assertStringContainsString("forceNetwork: true", $cartPage);
    self::assertStringContainsString('weline-cart-shell__sibling-cta', $cartPage);
    self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $cartPage);
    self::assertStringContainsString('readCartTypeFromQuery', $cartPage);
    self::assertStringContainsString('data-cart-type-handoff', $cartPage);
    self::assertStringContainsString("params.get('type')", $cartPage);
    self::assertStringContainsString("detail.source === 'enhanceMiniCarts'", $cartPage);
    self::assertStringContainsString('removeAttribute(\'data-cart-type-handoff\')', $cartPage);
    // Empty preferCache must not terminal-return; sibling with items clears opposite empty bucket.
    self::assertStringContainsString('localCartSummaryHasItems', $cartPage);
    self::assertStringContainsString('invalidateEmptyCachesClaimedBySiblings', $cartPage);
    self::assertStringContainsString('Empty typed cache cannot short-circuit', $cartPage);
    // ToB money summary parity with checkout / mini-cart: 本单共计 via B2B adjust.
    self::assertStringContainsString('adjustMoneySummaryDto', $cartPage);
    self::assertStringContainsString('order_total_minor', $cartPage);
    self::assertStringContainsString('本单共计', $cartPage);
    self::assertStringContainsString("'orderTotal'", $cartPage);
    self::assertStringContainsString('WelineCart.requestCartType', $miniCart);
    self::assertStringContainsString('weline:cart-type-changed', $miniCart);
    self::assertStringContainsString("forceNetwork: true", $miniCart);
    self::assertStringNotContainsString('data-b2b-mini-cart-type-option', $miniCart);
    self::assertStringContainsString('summaryCacheHasLineItems', $miniCart);
    self::assertStringContainsString('invalidateEmptyCachesClaimedBySiblings', $miniCart);
    self::assertStringContainsString('Empty typed cache cannot short-circuit', $miniCart);
    // B2B adapts Cart Event → setMode without re-emitting (avoids preferCache race).
    self::assertStringContainsString('weline:cart-type-changed', $b2b);
    self::assertStringContainsString('data-cart-type-option', $b2b);
    self::assertStringContainsString('emit: false', $b2b);
    self::assertStringContainsString('data-cart-type-handoff', $b2b);
    self::assertStringNotContainsString('function syncRetailOnlyHintBuyboxMirrors', $b2b);
    self::assertStringContainsString('.weline-cart-shell__sibling-cta', $cartCss);
    self::assertStringContainsString('.weline-cart-shell__empty-actions', $cartCss);
}

private function assert_MiniCartQtyHitGate_testCartOwnsMiniCartQtyHitCssGate(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart';
    $css = (string) file_get_contents($root . '/view/statics/css/mini-cart-drawer-qty-hit.css');
    $cartJs = (string) file_get_contents($root . '/view/statics/js/cart.js');
    $modules = (string) file_get_contents($root . '/view/statics/frontend/weline.modules.js');
    $themeWidget = (string) file_get_contents(dirname($root) . '/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml');
    $themeDrawerCss = (string) file_get_contents(dirname($root) . '/Theme/view/statics/css/widgets/mini-cart-drawer.css');
    // Stacking only — expanded body floor blocked sheet growth to −100px.
    self::assertStringContainsString('z-index: 1', $css);
    self::assertStringNotContainsString('min-height: min(40vh, 12rem)', $css);
    self::assertStringNotContainsString('max-height: calc(100% - min(40vh, 12rem) - 5.5rem)', $css);
    self::assertDoesNotMatchRegularExpression('/\.header-cart \.mini-cart-drawer__footer\s*\{[^}]*max-height\s*:/s', $css);
    self::assertStringContainsString('ensureMiniCartQtyHitCss', $cartJs);
    self::assertStringContainsString('mini-cart-drawer-qty-hit.css', $cartJs);
    self::assertStringContainsString('data-weline-merged-css', $cartJs);
    self::assertStringContainsString('20261015-qty-hit-fold-v1', $modules);
    // Theme widget no longer lists Cart qty-hit as a separate leaf — folded into drawer.
    self::assertStringNotContainsString('Weline_Cart::css/mini-cart-drawer-qty-hit.css', $themeWidget);
    self::assertStringContainsString('Weline_Theme::css/widgets/mini-cart-drawer.css', $themeWidget);
    self::assertStringContainsString('Folded here so the layout pack already paints it', $themeDrawerCss);
    // Theme owns drawer − 100px footer cap + content-sized details.
    self::assertMatchesRegularExpression('/\.header-cart \.mini-cart-drawer__footer\s*\{[^}]*max-height:\s*calc\(\s*100%\s*-\s*var\(--token-size-100px\)/s', $themeDrawerCss);
    self::assertMatchesRegularExpression('/\.header-cart \.mini-cart-drawer__footer-details\s*\{[^}]*flex:\s*0\s+1\s+auto/s', $themeDrawerCss);
    // Collapsed-only body floor remains Theme-owned.
    self::assertMatchesRegularExpression('/\.header-cart\.is-footer-collapsed \.mini-cart-drawer__body\s*\{[^}]*min-height:\s*min\(40vh,\s*var\(--token-size-12rem\)\)/s', $themeDrawerCss);
}

private function assert_ProductAddToCartWidget_testWidgetRegistrationPinsPurchaseActionsSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/extends/module/Weline_Widget/Weline_Cart/widget.php';
    $tpl = 'Weline_Cart::templates/frontend/widgets/product-add-to-cart.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/widgets/product-add-to-cart.phtml');
    self::assertStringContainsString('@widget.code {product-add-to-cart}', $src);
    self::assertStringContainsString('@widget.slot {product-purchase-actions}', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"slot":"product-purchase-actions"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_ProductAddToCartWidget_testWidgetTemplateUsesCartPurchaseActionsScript(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/widgets/product-add-to-cart.phtml');
    self::assertStringContainsString('data-testid="product-add-to-cart"', $template);
    self::assertStringContainsString('data-action="add"', $template);
    self::assertStringContainsString('!$quoteOnly', $template);
    self::assertStringContainsString('data-quote-only', $template);
    self::assertStringContainsString('data-weline-load="cart"', $template);
    self::assertStringNotContainsString('@static(Weline_Cart::js/widgets/product-purchase-actions.js)', $template);
    self::assertStringContainsString('data-purchase-loading', $template);
    self::assertStringContainsString('data-purchase-success', $template);
    self::assertStringContainsString('data-cart-url', $template);
    self::assertStringContainsString('data-cart-link-text', $template);
    self::assertStringNotContainsString('product-native-detail__cart', $template);
    self::assertStringNotContainsString('data-purchase-mark-added', $template);
    self::assertStringContainsString('StorefrontOfferResolver::resolve', $template);
}

private function assert_ProductAddToCartWidget_testPurchaseActionsScriptDispatchesCartUpdatedEvent(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    self::assertStringContainsString('notifyCartUpdated', $script);
    self::assertStringContainsString('weline:cart-updated', $script);
    self::assertStringContainsString('weline:cart:update', $script);
    self::assertStringContainsString('rememberSummary', $script);
    self::assertStringContainsString("load('miniCartIcon')", $script);
    self::assertStringContainsString('applyCachedSummary', $script);
}

private function assert_ProductAddToCartWidget_testPurchaseActionsEnsureGuestTokenAlwaysIssuesAndForceRenews(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    // Must not early-return on getGuestSession alone (Cookie authority via always-issue).
    self::assertDoesNotMatchRegularExpression('/async function ensureGuestToken\(\)\s*\{[\s\S]*?getGuestSession\(\)[\s\S]*?if \(existing && existing\.token\)[\s\S]*?return existing\.token;/', $script);
    self::assertStringContainsString('renewGuestSession({ force: true })', $script);
    self::assertStringContainsString('cookieSyncedByRenew', $script);
    self::assertStringContainsString('issueGuestToken({}, { silent: true })', $script);
    self::assertStringContainsString('issueGuestToken({ guest_token: existingToken }', $script);
    self::assertStringContainsString('isUnknownGuestTokenWorkerParam', $script);
    self::assertStringContainsString('Unknown frontend worker param:\s*guest_token', $script);
    self::assertStringContainsString('rememberGuestSession(adopted, expiresAt)', $script);
    // Always-issue must NOT unconditionally send guest_token when renew already synced Cookie.
    self::assertStringNotContainsString('const issueParams = existingToken ? { guest_token: existingToken } : {};', $script);
    // sessionStorage-only early return must also stay gone.
    self::assertDoesNotMatchRegularExpression('/async function ensureGuestToken\(\)\s*\{[\s\S]*?sessionStorage\.getItem\(GUEST_TOKEN_STORAGE_KEY\)[\s\S]*?if \(guestToken\) \{\s*[\s\S]*?return guestToken;/', $script);
}

private function assert_ProductAddToCartWidget_testPurchaseActionsSubmitSelectedEavVariantValues(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    self::assertStringContainsString('function readEavSelection(button)', $script);
    self::assertStringContainsString("'[data-variant-option].is-selected'", $script);
    self::assertStringContainsString('selection: readEavSelection(button)', $script);
}

private function assert_ProductAddToCartWidget_testPurchasePanelReopenClearsNativeDialogHidden(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/frontend/weline.modules.js');
    // Weline.UI.dialog close() sets hidden on native <dialog>; reopen must clear it
    // or the second listing Add-to-Cart looks like a no-op (invisible modal).
    self::assertStringContainsString('function revealPurchasePanel(dialog)', $script);
    self::assertStringContainsString('function closePurchasePanel(dialog)', $script);
    self::assertStringContainsString('dialog.removeAttribute(\'hidden\')', $script);
    self::assertStringContainsString('revealPurchasePanel(dialog)', $script);
    self::assertStringContainsString('closePurchasePanel(dialog)', $script);
    self::assertStringContainsString('Weline.UI.dialog', $script);
    self::assertStringContainsString('product-purchase-actions-w18n-v3.js', $modules);
}

private function assert_ProductAddToCartWidget_testPurchasePanelFetchFailureReplacesLoadingWithError(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    self::assertStringContainsString('function humanizePurchaseError(error, fallback)', $script);
    self::assertStringContainsString('function showPurchasePanelError(body, message, onClose)', $script);
    self::assertStringContainsString('w-product-purchase-panel__error', $script);
    self::assertStringContainsString('failed to fetch|networkerror', $script);
    self::assertStringContainsString('worker_timeout', $script);
    self::assertStringContainsString('worker request timed out', $script);
    self::assertStringContainsString('网络异常，无法打开加购面板，请稍后重试', $script);
    self::assertStringContainsString('/^theme_[a-z0-9_]+$/i', $script);
    self::assertStringContainsString('暂时无法打开加购面板，请稍后重试', $script);
    self::assertStringContainsString('showPurchasePanelError(body, msg, opts.onErrorClose)', $script);
}

private function assert_ProductAddToCartWidget_testPurchasePanelLoadsViaBinQueryProductProvider(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/widgets/product-card-add-to-cart.phtml');
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/frontend/weline.modules.js');
    // Storefront business I/O must be BinQuery — never native fetch to REST panel URL.
    self::assertStringContainsString('function waitForProductApi()', $script);
    self::assertStringContainsString("resource('product')", $script);
    self::assertStringContainsString('getPurchasePanel(panelParams', $script);
    self::assertStringNotContainsString('fetch(url.toString()', $script);
    self::assertStringNotContainsString('data-purchase-panel-url', $template);
    self::assertStringContainsString('product-purchase-actions-w18n-v3.js', $modules);
    self::assertStringContainsString('function mountPurchasePanelInto(body, options)', $script);
    self::assertStringContainsString('mountPurchasePanelInto: mountPurchasePanelInto', $script);
    self::assertStringContainsString('return mountPurchasePanelInto(body, {', $script);
    // Inline-edit session marks panel HTML; peel scripts + re-decorate after mount.
    self::assertStringContainsString('function peelInlineEditMarkers', $script);
    self::assertStringContainsString('notifyInlineEditDecorate(body)', $script);
    self::assertStringContainsString('WelineI18nInlineEdit', $script);
}

private function assert_ProductAddToCartWidget_testPurchasePanelEnsuresProductInfoAssetsOnListingHosts(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    // Listing pages never bake product-info @widget.source; panel must ensure CSS/JS.
    self::assertStringContainsString('function ensurePurchasePanelProductInfoAssets()', $script);
    self::assertStringContainsString('ensurePurchasePanelProductInfoAssets()', $script);
    self::assertStringContainsString('Weline_Product::css/widgets/product-native-detail.css', $script);
    self::assertStringContainsString('Weline_Product::js/widgets/widget-product-info-0.js', $script);
    self::assertStringContainsString('data-purchase-panel-css', $script);
}

private function assert_ProductAddToCartWidget_testPurchasePanelMountDropsSupersededResponses(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cart/view/statics/js/widgets/';
    foreach (['product-purchase-actions.js', 'product-purchase-actions-w18n-v3.js'] as $file) {
        $script = (string) file_get_contents($root . $file);
        // A slower earlier response must not overwrite the panel of a newer open (quick view reuses one body).
        self::assertStringContainsString('body.dataset.purchasePanelMountSeq = mountSeq', $script, $file);
        self::assertGreaterThanOrEqual(3, substr_count($script, 'return { superseded: true };'), $file);
    }
    $compare = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare/view/statics/js/product-card-actions.js');
    self::assertStringContainsString('result.superseded', $compare);
}

private function assert_ProductAddToCartWidget_testAddOfferResolvesCartTypeFromPreferredModeBeforeSsrHtml(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    self::assertStringContainsString('function resolveAddCartType(button)', $script);
    self::assertStringContainsString('function productAllowsWholesaleAdd(button)', $script);
    self::assertStringContainsString('WelineB2BSellingMode.preferredMode', $script);
    self::assertStringNotContainsString('confirmCartTypeForAdd', $script);
    self::assertStringContainsString('weline_cart_type_explicit', $script);
    self::assertStringContainsString('cart_type: sellingMode', $script);
    self::assertStringContainsString('syncChromeAfterRetailOnlyAdd', $script);
    self::assertStringContainsString('Keep tob preference', $script);
    self::assertStringContainsString('retailSiblingAddMessage', $script);
    self::assertStringContainsString('appendCheckoutCartTypeHandoff', $script);
    self::assertStringContainsString('checkoutCartTypeHandoff', $script);
    self::assertStringContainsString('previewRetailSiblingMiniCart', $script);
    self::assertStringContainsString('retailSiblingPreview', $script);
    self::assertStringContainsString("source: 'retail-only-add-preview'", $script);
    self::assertStringNotContainsString("source: 'retail-only-product-add'", $script);
    self::assertStringContainsString('var mode = preferred || fromButton || fromHtml || \'toc\'', $script);
    // Old FPC-first chain must stay gone.
    self::assertStringNotContainsString("button.dataset.sellingMode\n            || button.dataset.cartType\n            || (document.documentElement.getAttribute('data-selling-mode') || '')\n            || (window.WelineB2BSellingMode", $script);
}

private function assert_ProductCardAddToCartHook_testThemePartialUsesProductCardPurchaseHooks(): void
{
    $helper = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Helper/ProductCardAddToCartParams.php';
    $partial = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/partials/product/add-to-cart.phtml';
    self::assertFileExists($helper);
    self::assertFileExists($partial);
    $content = (string) file_get_contents($helper);
    $tpl = (string) file_get_contents($partial);
    self::assertStringContainsString("getHook('Weline_Theme::frontend::partials::product-card::add-to-cart')", $content);
    self::assertStringContainsString("getHook('Weline_Theme::frontend::partials::product-card::buy-now')", $content);
    self::assertStringContainsString('function renderSlot', $content);
    self::assertStringContainsString('FiberOutputBuffer::hasActiveCapture()', $content);
    self::assertStringContainsString('ProductCardAddToCartParams::renderSlot', $tpl);
    self::assertStringNotContainsString('Weline_Cart::templates/frontend/widgets/product-card-add-to-cart.phtml', $content);
    self::assertStringNotContainsString('Weline_Checkout::templates/frontend/widgets/product-card-buy-now.phtml', $content);
    self::assertStringNotContainsString('product-purchase-actions.js', $content);
    self::assertStringContainsString('data-weline-load="cart,storefrontShopperToast"', $content);
}

private function assert_ProductCardAddToCartHook_testCartHookRendersCardWidgetTemplate(): void
{
    $hook = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/hooks/Weline_Theme/frontend/partials/product-card/add-to-cart.phtml';
    self::assertFileExists($hook);
    $content = (string) file_get_contents($hook);
    self::assertStringContainsString('Weline_Cart::templates/frontend/widgets/product-card-add-to-cart.phtml', $content);
    self::assertStringNotContainsString('product-purchase-actions.js', $content);
}

private function assert_ProductCardAddToCartHook_testCardWidgetUsesCartAddAction(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/widgets/product-card-add-to-cart.phtml');
    self::assertStringContainsString('data-testid="product-card-add-to-cart"', $template);
    self::assertStringContainsString('data-action="add"', $template);
    self::assertStringContainsString('data-weline-load="cart"', $template);
    self::assertStringContainsString('weline-cart-product-card-add-to-cart', $template);
    self::assertStringContainsString('StorefrontOfferResolver::resolve', $template);
    self::assertStringContainsString('btn-add-to-cart', $template);
    self::assertStringContainsString('StorefrontOfferDetailQuery::params', $template);
    self::assertStringContainsString("\$quoteHrefParams['quote'] = '1'", $template);
    self::assertStringContainsString('#quote', $template);
    self::assertStringContainsString('data-quote-only="1"', $template);
    self::assertStringContainsString('product-storefront__quote', $template);
    self::assertStringContainsString('amz-card__quote', $template);
    self::assertStringContainsString('btn-quote', $template);
    self::assertStringNotContainsString("\$controlClass = 'product-storefront__buy-now'", $template);
}

private function assert_ProductCardAddToCartHook_testThemeHookRegistryPublishesProductCardAddToCartSlot(): void
{
    $hook = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/hook.php';
    self::assertFileExists($hook);
    $content = (string) file_get_contents($hook);
    self::assertStringContainsString('Weline_Theme::frontend::partials::product-card::add-to-cart', $content);
}

private function assert_ProductCardAddToCartHook_testPurchaseActionsScriptBindsCardWidgetRoot(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/statics/js/widgets/product-purchase-actions.js');
    self::assertStringContainsString('weline-cart-product-card-add-to-cart', $script);
    self::assertStringContainsString('[data-action="add"]', $script);
    self::assertStringNotContainsString('[data-action="add-v2"]', $script);
    self::assertStringContainsString('markCardButtonAdded', $script);
    self::assertStringContainsString('showFloatingToast', $script);
    self::assertStringContainsString('showCartAddedNoticePanel', $script);
    self::assertStringNotContainsString('openMiniCartDrawer', $script);
    self::assertStringContainsString('showCartAddedNotice', $script);
    self::assertStringContainsString('w-amz-cart-added', $script);
    self::assertStringContainsString('guestTokenPromise', $script);
    self::assertStringContainsString('silent: true', $script);
    self::assertStringContainsString('formatStorefrontMoney', $script);
    self::assertStringContainsString('Weline.UI.toast', $script);
    self::assertStringContainsString('data-card-add-label', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/view/templates/frontend/widgets/product-card-add-to-cart.phtml'));
    self::assertStringContainsString('storefront-category-catalog', $script);
    self::assertStringContainsString('data-mini-cart-trigger', $script);
    $registrySrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/Service/CartItemSnapshotProviderRegistry.php');
    self::assertStringContainsString('relative_path', $registrySrc);
    self::assertStringContainsString("extends/module/", $registrySrc);
    self::assertStringNotContainsString('$extension[\'file\']', $registrySrc);
}
}
