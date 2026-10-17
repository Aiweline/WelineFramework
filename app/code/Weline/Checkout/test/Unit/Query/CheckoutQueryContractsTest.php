<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 13 *ContractTest files).
 * Observe one overall pass/fail via testAllCheckoutQueryContractsPass().
 */
final class CheckoutQueryContractsTest extends TestCase
{
    public function testAllCheckoutQueryContractsPass(): void
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
            'CheckoutContinuePayAdopt::testAdoptReleaseAmendRegisteredOnProvider' => function () {
            $this->assert_CheckoutContinuePayAdopt_testAdoptReleaseAmendRegisteredOnProvider();
        },
            'CheckoutContinuePayAdopt::testTemplateAdoptsContinuePayInsteadOfRecoveryIslandAsEntry' => function () {
            $this->assert_CheckoutContinuePayAdopt_testTemplateAdoptsContinuePayInsteadOfRecoveryIslandAsEntry();
        },
            'CheckoutContinuePayAdopt::testSwitcherOnlyWhenMultipleBuckets' => function () {
            $this->assert_CheckoutContinuePayAdopt_testSwitcherOnlyWhenMultipleBuckets();
        },
            'CheckoutContinuePayAdopt::testContinuePayAddressCollapseAndChromeDedupe' => function () {
            $this->assert_CheckoutContinuePayAdopt_testContinuePayAddressCollapseAndChromeDedupe();
        },
            'CheckoutContinuePayAdopt::testContinuePayDoesNotPolluteBrowseQuoteToken' => function () {
            $this->assert_CheckoutContinuePayAdopt_testContinuePayDoesNotPolluteBrowseQuoteToken();
        },
            'CheckoutContinuePayAdopt::testContinuePayTotalsUseOrderMoneyAndAllowCouponAmend' => function () {
            $this->assert_CheckoutContinuePayAdopt_testContinuePayTotalsUseOrderMoneyAndAllowCouponAmend();
        },
            'CheckoutDigitalCartShippingRelease::testGetDataInjectsVirtualOnlyLaneAndRequiresShippingFlag' => function () {
            $this->assert_CheckoutDigitalCartShippingRelease_testGetDataInjectsVirtualOnlyLaneAndRequiresShippingFlag();
        },
            'CheckoutDigitalCartShippingRelease::testStorefrontGatesSubmitOnRequiresShipping' => function () {
            $this->assert_CheckoutDigitalCartShippingRelease_testStorefrontGatesSubmitOnRequiresShipping();
        },
            'CheckoutDigitalCartShippingRelease::testSsrPropagatesRequiresShipping' => function () {
            $this->assert_CheckoutDigitalCartShippingRelease_testSsrPropagatesRequiresShipping();
        },
            'CheckoutFaultSnapshot::testGetDataReturnsQuoteTokenAndUsesResponseDiagnostics' => function () {
            $this->assert_CheckoutFaultSnapshot_testGetDataReturnsQuoteTokenAndUsesResponseDiagnostics();
        },
            'CheckoutFaultSnapshot::testStorefrontPersistsQuoteToken' => function () {
            $this->assert_CheckoutFaultSnapshot_testStorefrontPersistsQuoteToken();
        },
            'CheckoutFaultSnapshot::testAdminSessionFilterHasErrorOnly' => function () {
            $this->assert_CheckoutFaultSnapshot_testAdminSessionFilterHasErrorOnly();
        },
            'CheckoutFaultSnapshot::testFreezeQuotePassesCheckoutEntry' => function () {
            $this->assert_CheckoutFaultSnapshot_testFreezeQuotePassesCheckoutEntry();
        },
            'CheckoutFreezeCartType::testFreezeQuotePassesResolvedCartTypeToSnapshotFreeze' => function () {
            $this->assert_CheckoutFreezeCartType_testFreezeQuotePassesResolvedCartTypeToSnapshotFreeze();
        },
            'CheckoutGetDataCartTaxFields::testGetDataCartDtoForwardsTaxAmountMinorAndCombinedTaxEstimate' => function () {
            $this->assert_CheckoutGetDataCartTaxFields_testGetDataCartDtoForwardsTaxAmountMinorAndCombinedTaxEstimate();
        },
            'CheckoutMissingWeightShipping::testCheckoutQuoteLinesUseRealWeightWithoutSilentFallback' => function () {
            $this->assert_CheckoutMissingWeightShipping_testCheckoutQuoteLinesUseRealWeightWithoutSilentFallback();
        },
            'CheckoutMissingWeightShipping::testExpressPathsAlsoRefuseSilentHalfKgFallback' => function () {
            $this->assert_CheckoutMissingWeightShipping_testExpressPathsAlsoRefuseSilentHalfKgFallback();
        },
            'CheckoutMissingWeightShipping::testLocalPricingPropagatesMissingWeightReason' => function () {
            $this->assert_CheckoutMissingWeightShipping_testLocalPricingPropagatesMissingWeightReason();
        },
            'CheckoutPaymentEntry::testGetDataAppliesMoneySummaryPolicyHook' => function () {
            $this->assert_CheckoutPaymentEntry_testGetDataAppliesMoneySummaryPolicyHook();
        },
            'CheckoutPaymentEntry::testResumePaymentStampsContinuePayEntry' => function () {
            $this->assert_CheckoutPaymentEntry_testResumePaymentStampsContinuePayEntry();
        },
            'CheckoutPaymentEntry::testCheckoutPayServiceStampsPaymentEntry' => function () {
            $this->assert_CheckoutPaymentEntry_testCheckoutPayServiceStampsPaymentEntry();
        },
            'CheckoutPaymentEntry::testStorefrontResumePassesContinuePayMode' => function () {
            $this->assert_CheckoutPaymentEntry_testStorefrontResumePassesContinuePayMode();
        },
            'CheckoutPlaceOrderShippingGate::testPlaceOrderRejectsEmptyOrUnknownShippingMethod' => function () {
            $this->assert_CheckoutPlaceOrderShippingGate_testPlaceOrderRejectsEmptyOrUnknownShippingMethod();
        },
            'CheckoutQueryProviderPaymentRecovery::testProviderUsesPublishedCartScopeContractAndPublishesRecoveryOperation' => function () {
            $this->assert_CheckoutQueryProviderPaymentRecovery_testProviderUsesPublishedCartScopeContractAndPublishesRecoveryOperation();
        },
            'CheckoutQueryProviderPaymentRecovery::testProviderPublishesAuthenticatedCustomerDefaultDeliveryAddress' => function () {
            $this->assert_CheckoutQueryProviderPaymentRecovery_testProviderPublishesAuthenticatedCustomerDefaultDeliveryAddress();
        },
            'CheckoutQuoteSelectedAddress::testGetDataResolvesShippingAddressBeforeQuote' => function () {
            $this->assert_CheckoutQuoteSelectedAddress_testGetDataResolvesShippingAddressBeforeQuote();
        },
            'CheckoutQuoteSelectedAddress::testResolverPrefersSelectedOverCascadeCnDefault' => function () {
            $this->assert_CheckoutQuoteSelectedAddress_testResolverPrefersSelectedOverCascadeCnDefault();
        },
            'CheckoutQuoteSelectedAddress::testExpressFlowUsesSharedAddressResolver' => function () {
            $this->assert_CheckoutQuoteSelectedAddress_testExpressFlowUsesSharedAddressResolver();
        },
            'CheckoutQuoteSelectedAddress::testCheckoutFormAddressPrefersWidgetResolveQuoteAddress' => function () {
            $this->assert_CheckoutQuoteSelectedAddress_testCheckoutFormAddressPrefersWidgetResolveQuoteAddress();
        },
            'CheckoutQuoteSelectedAddress::testSelectAddressLooksUpAcrossCountries' => function () {
            $this->assert_CheckoutQuoteSelectedAddress_testSelectAddressLooksUpAcrossCountries();
        },
            'CheckoutRenderDeliveryAddressWidget::testProviderExposesLazyAddressWidgetOperation' => function () {
            $this->assert_CheckoutRenderDeliveryAddressWidget_testProviderExposesLazyAddressWidgetOperation();
        },
            'CheckoutShippingMethodsHomology::testLoadShippingMethodsUsesListQuoteOptionsNotGetByLocation' => function () {
            $this->assert_CheckoutShippingMethodsHomology_testLoadShippingMethodsUsesListQuoteOptionsNotGetByLocation();
        },
            'CheckoutSubmitV2ExpressParam::testSubmitV2DescriptorAllowsExpressAndB2bCreditParams' => function () {
            $this->assert_CheckoutSubmitV2ExpressParam_testSubmitV2DescriptorAllowsExpressAndB2bCreditParams();
        },
            'CheckoutSubmitV2ExpressParam::testTraditionalSubmitOmitsExpressCheckoutUnlessOptedIn' => function () {
            $this->assert_CheckoutSubmitV2ExpressParam_testTraditionalSubmitOmitsExpressCheckoutUnlessOptedIn();
        },
        ];
    }

private function providerSource_CheckoutContinuePayAdopt(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
}

private function assert_CheckoutContinuePayAdopt_testAdoptReleaseAmendRegisteredOnProvider(): void
{
    $src = $this->providerSource_CheckoutContinuePayAdopt();
    self::assertStringContainsString("'adoptContinuePaySession'", $src);
    self::assertStringContainsString("'releaseContinuePaySession'", $src);
    self::assertStringContainsString("'amendUnpaidCheckoutAddress'", $src);
    self::assertStringContainsString("'listCheckoutSessionBuckets'", $src);
    self::assertStringContainsString("'name' => 'adoptContinuePaySession'", $src);
}

private function assert_CheckoutContinuePayAdopt_testTemplateAdoptsContinuePayInsteadOfRecoveryIslandAsEntry(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('adoptContinuePayFromRecovery', $template);
    self::assertStringContainsString('data-checkout-continue-chrome', $template);
    self::assertStringContainsString('data-checkout-type-switch', $template);
    self::assertStringContainsString('weline-checkout__header-actions', $template);
    self::assertMatchesRegularExpression('/weline-checkout__header-actions[\s\S]*data-checkout-type-switch[\s\S]*weline-checkout__back/', $template);
    self::assertStringContainsString('isContinuePayMode()', $template);
    self::assertStringContainsString('amendUnpaidCheckoutAddress', $template);
    // Entry path must call adopt, not showPaymentRecovery(initial…)
    self::assertMatchesRegularExpression('/initialPaymentRecovery[\s\S]*adoptContinuePayFromRecovery\(initialPaymentRecovery\)/', $template);
    self::assertDoesNotMatchRegularExpression('/if\s*\(\s*initialPaymentRecovery\s*\)\s*\{\s*showPaymentRecovery\(initialPaymentRecovery\)/', $template);
}

private function assert_CheckoutContinuePayAdopt_testSwitcherOnlyWhenMultipleBuckets(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('buckets.length >= 2', $template);
}

private function assert_CheckoutContinuePayAdopt_testContinuePayAddressCollapseAndChromeDedupe(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    $binding = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ContinuePayBindingService.php');
    self::assertStringContainsString('syncContinuePayAddressPanel', $template);
    self::assertStringContainsString('presentOrderAddress', $template);
    self::assertStringContainsString('continue_pay_address_incomplete', $template);
    self::assertStringContainsString('data-address-incomplete', $template);
    self::assertStringNotContainsString('可修改地址后再次支付', $template);
    self::assertStringNotContainsString('可修改地址后再次支付', $binding);
    self::assertStringContainsString('isShippingAddressComplete', $binding);
    self::assertStringContainsString('enrichSnapshotAddressFromSession', $binding);
    self::assertStringContainsString('expand_address', $binding);
    self::assertStringContainsString('请补全收货信息后再支付。', $binding);
    self::assertStringContainsString('正在继续支付未完成订单。', $binding);
    self::assertStringContainsString('resolveOrderItemsChrome', $binding);
    self::assertStringContainsString('items_html', $binding);
    self::assertStringContainsString('items_html', $template);
    self::assertStringContainsString('weline-checkout__item-title', $template);
    self::assertStringNotContainsString("return '<div class=\"weline-checkout__item\"><strong>'", $template);
}

private function assert_CheckoutContinuePayAdopt_testContinuePayDoesNotPolluteBrowseQuoteToken(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    $src = $this->providerSource_CheckoutContinuePayAdopt();
    self::assertStringContainsString('continuePayQuoteTokenStorageKey', $template);
    self::assertStringContainsString('persistContinuePayQuoteToken', $template);
    self::assertStringContainsString('getDataParams.continue_pay = true', $template);
    self::assertStringContainsString("'continue_pay'", $src);
    self::assertStringContainsString('$continuePayRequest', $src);
    self::assertStringContainsString('continue_pay_order', $src);
    self::assertStringContainsString('releaseContinuePayIsolation', $template);
    self::assertStringContainsString('wipePollutedBrowseQuoteToken', $template);
    self::assertStringContainsString('weline_checkout_cpay_isolate_v2_', $template);
    // Traditional getData must ignore bound payment/shipping selection.
    self::assertStringContainsString('硬隔离：传统 getData 忽略续付绑定的 payment/shipping', $src);
    self::assertMatchesRegularExpression('/\$selectedPayment\s*=\s*\$continuePayRequest/s', $src);
    self::assertMatchesRegularExpression('/\$selectedShipping\s*=\s*\$continuePayRequest/s', $src);
    // adopt must not write recovery token into the normal browse slot.
    self::assertMatchesRegularExpression('/adoptContinuePayFromRecovery[\s\S]*persistContinuePayQuoteToken\(state\.quote_token\)/', $template);
    self::assertDoesNotMatchRegularExpression('/adoptContinuePayFromRecovery[\s\S]*persistQuoteToken\(state\.quote_token\)/', $template);
}

private function assert_CheckoutContinuePayAdopt_testContinuePayTotalsUseOrderMoneyAndAllowCouponAmend(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    $binding = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ContinuePayBindingService.php');
    $amend = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressUnpaidOrderAmend.php');
    $provider = $this->providerSource_CheckoutContinuePayAdopt();
    self::assertStringContainsString('continuePayFrozenMoney', $template);
    self::assertStringContainsString('applyContinuePayFrozenTotals', $template);
    self::assertStringContainsString('amendContinuePayOrderMoney', $template);
    self::assertStringContainsString('mergeContinuePayOrderTotals', $template);
    self::assertStringContainsString('schema_fields_TAX_AMOUNT', $binding);
    self::assertStringContainsString('schema_fields_DISCOUNT_AMOUNT', $binding);
    self::assertStringContainsString("'tax_amount'", $binding);
    self::assertStringContainsString("'discount_amount'", $binding);
    self::assertStringContainsString("'coupon_code'", $binding);
    // Shipping chrome must take order shipping_amount (not hard-coded 0).
    self::assertMatchesRegularExpression('/resolveShippingMethodChrome\(\s*\(string\)\(\$orderSnapshot\[[\'"]shipping_method[\'"]\].*?\),\s*\(string\)\(\$orderSnapshot\[[\'"]currency[\'"]\].*?\),\s*\(float\)\(\$orderSnapshot\[[\'"]shipping_amount[\'"]\].*?\)\s*\)/s', $binding);
    self::assertDoesNotMatchRegularExpression("/'amount'\\s*=>\\s*0\\.0,\\s*'fee'\\s*=>\\s*0\\.0,\\s*'source'\\s*=>\\s*'continue_pay_order'/", $binding);
    // 续付=旧结账会话：券可写回未付订单。
    self::assertStringContainsString('resolveMarketingSessionCouponCode', $amend);
    self::assertStringContainsString('\Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface', $amend);
    self::assertStringContainsString("array_key_exists('coupon_code', \$options)", $amend);
    self::assertStringContainsString("array_key_exists('coupon_code', \$params)", $provider);
    // amendUnpaidCheckoutAddress 描述必须声明 coupon_code，否则加券 toast Unknown frontend worker param。
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'amendUnpaidCheckoutAddress'[\\s\\S]*?'coupon_code'\\s*=>\\s*\\[[\\s\\S]*?'type'\\s*=>\\s*'string'/s", $provider);
    self::assertStringContainsString('await amendContinuePayOrderMoney({})', $template);
    self::assertStringContainsString("source === 'continue-pay-amend'", $template);
    self::assertStringContainsString('paintCheckoutCouponTag', $template);
    self::assertStringContainsString('data-checkout-discount-label', $template);
    self::assertStringContainsString('setDiscountRowLabel', $template);
    // 因果顺序：券区在小计之上；其后小计/运费/优惠/应付同一 totals 块连排。
    self::assertMatchesRegularExpression('/weline-checkout__extras[\s\S]*data-subtotal[\s\S]*data-shipping-amount[\s\S]*data-checkout-discount-row[\s\S]*data-grand-total/s', $template);
    // 续付摘要禁止裸 browse preview 顶替订单 money（须经 amend）。
    self::assertMatchesRegularExpression('/checkoutState\.cart\s*=\s*isContinuePayMode\(\)/', $template);
    // 混币门禁：amend 订单币种权威；已付 adopt 失败不得 fallback 成可续付。
    self::assertStringContainsString('schema_fields_CURRENCY', $amend);
    self::assertStringContainsString('resolveOrderGrandTotalMinor', $provider);
    self::assertStringContainsString('claimPaidRecoveryIfSettled', $provider);
    self::assertStringContainsString('continue_pay_order_not_pending', $binding);
    self::assertStringContainsString('orderHasSuccessfulPayment', $binding);
    self::assertStringContainsString('navigateToSuccess', $template);
    self::assertStringContainsString("errCode === 'continue_pay_order_not_pending'", $template);
    self::assertStringContainsString('can no longer', $template);
    self::assertStringContainsString('continuePayBinding.order.currency', $template);
}

private function assert_CheckoutDigitalCartShippingRelease_testGetDataInjectsVirtualOnlyLaneAndRequiresShippingFlag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('cartItemsRequireShipping', $src);
    self::assertStringContainsString('virtualOnlyShippingMethod', $src);
    self::assertStringContainsString("'free_reason' => 'virtual_only'", $src);
    self::assertStringContainsString("'code' => 'none'", $src);
    self::assertStringContainsString("'requires_shipping' => \$requiresShipping", $src);
    self::assertStringContainsString('无需配送', $src);
    self::assertStringContainsString('电子交付', $src);
}

private function assert_CheckoutDigitalCartShippingRelease_testStorefrontGatesSubmitOnRequiresShipping(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('requiresShipping: true', $tpl);
    self::assertStringContainsString('cartRequiresShipping()', $tpl);
    self::assertStringContainsString('(cartRequiresShipping() && !checkoutState.shippingMethods.length)', $tpl);
    self::assertStringContainsString("shippingMethod = 'none'", $tpl);
    self::assertStringContainsString('syncDigitalFulfillmentPanels', $tpl);
    self::assertStringContainsString('data-digital-was-required', $tpl);
    self::assertStringContainsString('requireFormValidity && cartRequiresShipping() && !form.reportValidity()', $tpl);
    self::assertStringContainsString('data-checkout-shipping-panel', $tpl);
    self::assertStringContainsString('data-checkout-address-panel', $tpl);
}

private function assert_CheckoutDigitalCartShippingRelease_testSsrPropagatesRequiresShipping(): void
{
    $ssr = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutStorefrontSsrService.php');
    self::assertStringContainsString("'requires_shipping' =>", $ssr);
    self::assertStringContainsString('cartItemsRequireShipping', $ssr);
    $index = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller/Index.php');
    self::assertStringContainsString('checkout_ssr_requires_shipping', $index);
}

private function assert_CheckoutFaultSnapshot_testGetDataReturnsQuoteTokenAndUsesResponseDiagnostics(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'quote_token' => \$quoteToken", $src);
    self::assertStringContainsString('existingQuoteToken:', $src);
    self::assertStringContainsString("'quote_diagnostics' => \$quoteDiagnostics", $src);
    self::assertStringNotContainsString('getLastQuoteDiagnostics()', $src);
    self::assertStringContainsString("'name' => 'getData'", $src);
    self::assertStringContainsString("'quote_token' => ['type' => 'string', 'required' => false, 'max_length' => 64]", $src);
}

private function assert_CheckoutFaultSnapshot_testStorefrontPersistsQuoteToken(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('quote_token: readStoredQuoteToken()', $tpl);
    self::assertStringContainsString('persistQuoteToken', $tpl);
    self::assertStringContainsString('weline_checkout_quote_token_w', $tpl);
}

private function assert_CheckoutFaultSnapshot_testAdminSessionFilterHasErrorOnly(): void
{
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller/Backend/Session.php');
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/templates/backend/Session/index.phtml');
    self::assertStringContainsString("where(CheckoutSession::schema_fields_ERROR_CODE, null, 'is not null')", $ctrl);
    self::assertStringContainsString('只看卡住的结账', $tpl);
    self::assertStringContainsString('checkout-session-error-snapshot', $tpl);
    self::assertStringContainsString('技术明细', $tpl);
    self::assertStringContainsString('CheckoutSessionAdminPresenter', $ctrl);
    self::assertStringContainsString('schema_fields_CHECKOUT_ENTRY', $ctrl);
    self::assertStringContainsString('checkout-session-entry-filter', $tpl);
    self::assertStringContainsString('checkout-session-entry', $tpl);
    self::assertStringContainsString('data-w-width="auto"', $tpl);
    self::assertStringNotContainsString('weline_checkout_fault_snapshot', $ctrl);
}

private function assert_CheckoutFaultSnapshot_testFreezeQuotePassesCheckoutEntry(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('checkoutEntry: $this->resolveCheckoutEntry($params)', $src);
    self::assertStringContainsString('function resolveCheckoutEntry', $src);
    $express = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    self::assertStringContainsString("CheckoutEntry::EXPRESS", $express);
}

private function assert_CheckoutFreezeCartType_testFreezeQuotePassesResolvedCartTypeToSnapshotFreeze(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('resolveCartTypePreference', $src);
    self::assertStringContainsString('cartSnapshots()->freeze($scopeIdentity, $guestToken, $customerId, $cartType)', $src);
    self::assertStringContainsString("'name' => 'freezeQuote'", $src);
    self::assertMatchesRegularExpression("/'name' => 'freezeQuote'[\\s\\S]*?'cart_type' => \\['type' => 'string'[\\s\\S]*?'selling_mode' => \\['type' => 'string'/", $src);
    self::assertStringContainsString("weline_selling_mode_w' . \$websiteId", $src);
    self::assertStringContainsString("\$mode === 'toc' && \$this->currentCustomerId() !== null", $src);
    self::assertStringContainsString('assertExplicitCartTypeMatchesShell', $src);
    self::assertStringContainsString('checkout_cart_type_mismatch', $src);
    self::assertStringContainsString('isValidTobDepositPayload', $src);
    self::assertStringContainsString("\$cartType === 'tob' && \$deposit !== [] && \$this->isValidTobDepositPayload(\$deposit)", $src);
    self::assertStringNotContainsString("\$cartType === 'tob' || \$deposit !== []", $src);
}

private function assert_CheckoutGetDataCartTaxFields_testGetDataCartDtoForwardsTaxAmountMinorAndCombinedTaxEstimate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'tax_amount_minor' => max(0, (int)(\$cart['tax_amount_minor'] ?? 0))", $src);
    self::assertStringContainsString("'sales_tax_amount_minor' => \$salesTaxMinor", $src);
    self::assertStringContainsString("\$taxEstimate['sales_tax_amount_minor'] = \$salesTaxMinor", $src);
    self::assertStringContainsString("\$taxEstimate['tax_amount_minor'] = \$salesTaxMinor + \$dutyChargedMinor", $src);
    self::assertStringContainsString('resolveCheckoutDestinationTaxMeta', $src);
    self::assertStringContainsString("\$taxEstimate['destination_policy']", $src);
    self::assertStringContainsString("\$taxEstimate['note']", $src);
    self::assertStringContainsString('CheckoutTaxAdvisorInterface', $src);
    $viewModel = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutPageViewModel.php');
    self::assertStringContainsString("'tax_amount_minor' => \$taxAmountMinor", $viewModel);
    self::assertStringContainsString("'sales_tax_amount_minor' => \$salesTaxAmountMinor", $viewModel);
    self::assertStringContainsString('syncShippingMethodDutyDescription', $src);
}

private function assert_CheckoutMissingWeightShipping_testCheckoutQuoteLinesUseRealWeightWithoutSilentFallback(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringNotContainsString('function chargeableWeightMinor(', $src);
    self::assertStringNotContainsString('? $weightMinor : 500', $src);
    self::assertStringContainsString('quoteLineWeight()->resolveLineWeightMinor($item)', $src);
    self::assertStringContainsString('CheckoutShippingUnavailablePresenter', $src);
    self::assertStringContainsString('shipping_unavailable', $src);
    self::assertStringContainsString('$shippingEmptyReason', $src);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutShippingUnavailablePresenter.php');
    $presenter = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutShippingUnavailablePresenter.php');
    self::assertStringContainsString('暂时无法计算运费', $presenter);
    self::assertStringContainsString("'reason_code' => 'missing_weight'", $presenter);
    self::assertStringContainsString('缺少重量', $presenter);
    self::assertStringNotContainsString('拒因码', $presenter);
    self::assertStringNotContainsString('->getLastQuoteDiagnostics()', $src);
}

private function assert_CheckoutMissingWeightShipping_testExpressPathsAlsoRefuseSilentHalfKgFallback(): void
{
    $express = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    $amend = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressUnpaidOrderAmend.php');
    $resolver = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutQuoteLineWeightResolver.php');
    self::assertStringNotContainsString('function chargeableWeightMinor(', $express);
    self::assertStringNotContainsString('? $weightMinor : 500', $express);
    self::assertStringNotContainsString(': 500, // 0.5kg', $amend);
    self::assertStringContainsString('CheckoutQuoteLineWeightResolver', $express);
    self::assertStringContainsString('quoteLineWeight()->resolveLineWeightMinor', $express);
    self::assertStringContainsString('missing_weight', $express);
    self::assertStringContainsString('lastListQuoteDiagnostics', $express);
    self::assertStringNotContainsString('getLastQuoteDiagnostics', $express);
    self::assertStringContainsString('CheckoutQuoteLineWeightResolver', $amend);
    self::assertStringNotContainsString('function catalogWeightMinor(', $amend);
    self::assertStringContainsString('Never invent 0.5kg', $resolver);
    self::assertStringNotContainsString('return 500', $resolver);
}

private function assert_CheckoutMissingWeightShipping_testLocalPricingPropagatesMissingWeightReason(): void
{
    $pricing = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Shipping/Service/Provider/LocalTemplatePricingService.php');
    $mgr = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Shipping/Service/ShippingServiceManager.php');
    self::assertStringContainsString('unavailable_reasons', $pricing);
    self::assertStringContainsString("'missing_weight' =>", $mgr);
    self::assertStringContainsString("in_array('missing_weight'", $mgr);
}

private function assert_CheckoutPaymentEntry_testGetDataAppliesMoneySummaryPolicyHook(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('applyMoneySummaryPolicy', $src);
    self::assertStringContainsString('StorefrontMoneySummaryPolicyInterface', $src);
}

private function assert_CheckoutPaymentEntry_testResumePaymentStampsContinuePayEntry(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("PaymentEntry::CONTINUE_PAY", $src);
    self::assertStringContainsString("'payment_mode' => 'continue_pay'", $src);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'resumePaymentV2'[\\s\\S]*?'payment_mode'\\s*=>/", $src);
}

private function assert_CheckoutPaymentEntry_testCheckoutPayServiceStampsPaymentEntry(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutOrderPaymentService.php');
    self::assertStringContainsString('PaymentEntry::stampContext', $src);
    self::assertStringContainsString('continue_pay', $src);
}

private function assert_CheckoutPaymentEntry_testStorefrontResumePassesContinuePayMode(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString("payment_mode: 'continue_pay'", $src);
    self::assertStringContainsString('continue_pay: true', $src);
}

private function assert_CheckoutPlaceOrderShippingGate_testPlaceOrderRejectsEmptyOrUnknownShippingMethod(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('province_region_id', $src);
    self::assertStringContainsString('city_region_id', $src);
    self::assertStringContainsString('district_region_id', $src);
    self::assertStringContainsString('当前收货地址暂不可配送，请更换地址后再下单。', $src);
    self::assertStringContainsString('所选配送方式不可用，请重新选择配送方式。', $src);
    self::assertStringContainsString('$shippingMethods === []', $src);
    self::assertStringContainsString('$shippingAmount === null', $src);
}

private function assert_CheckoutQueryProviderPaymentRecovery_testProviderUsesPublishedCartScopeContractAndPublishesRecoveryOperation(): void
{
    $provider = $this->read_CheckoutQueryProviderPaymentRecovery('app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('use Weline\Cart\Api\CartScopeResolverInterface;', $provider);
    self::assertStringNotContainsString('use Weline\Cart\Service\CartScopeResolver;', $provider);
    self::assertStringContainsString("'resumePaymentV2' => \$this->resumePaymentV2(\$params)", $provider);
    self::assertStringContainsString("'outcome' => 'failed'", $provider);
    self::assertStringContainsString("'checkout_group_uuid' => \$result->checkoutGroupUuid", $provider);
    self::assertStringContainsString("'order_uuids' => \$result->orderUuids", $provider);
    self::assertStringContainsString("'checkout_token' => \$quoteToken", $provider);
    self::assertStringContainsString("'name' => 'resumePaymentV2'", $provider);
    self::assertStringContainsString('->beginRetry(', $provider);
}

private function assert_CheckoutQueryProviderPaymentRecovery_testProviderPublishesAuthenticatedCustomerDefaultDeliveryAddress(): void
{
    $provider = $this->read_CheckoutQueryProviderPaymentRecovery('app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('use Weline\Shipping\Model\DeliveryAddress;', $provider);
    self::assertStringContainsString('use Weline\Shipping\Service\DeliveryAddressService;', $provider);
    self::assertStringContainsString('use Weline\Checkout\Service\CheckoutDeliveryContextService;', $provider);
    self::assertStringContainsString("'default_shipping_address' => \$this->customerAddressPrefill()", $provider);
    self::assertStringContainsString("'delivery' => \$this->deliveryContextService->getContext(\$params)", $provider);
    self::assertStringContainsString("'getDeliveryContext' => \$this->deliveryContext(\$params)", $provider);
    self::assertStringContainsString('private function customerAddressPrefill(): array', $provider);
    self::assertStringContainsString('->getDefaultByCustomer($identity->getId())', $provider);
}

private function read_CheckoutQueryProviderPaymentRecovery(string $relativePath): string
{
    $path = '/Users/weline/Project/Official/框架' . '/' . $relativePath;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function assert_CheckoutQuoteSelectedAddress_testGetDataResolvesShippingAddressBeforeQuote(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('CheckoutShippingAddressResolver', $src);
    self::assertStringContainsString('$shippingAddress = $this->shippingAddressResolver->resolve($shippingAddress, $params);', $src);
    self::assertStringContainsString('$address = $this->shippingAddressResolver->resolve($address, $params);', $src);
    self::assertStringContainsString('shipping_quote_diagnostics', $src);
}

private function assert_CheckoutQuoteSelectedAddress_testResolverPrefersSelectedOverCascadeCnDefault(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutShippingAddressResolver.php');
    self::assertStringContainsString('class CheckoutShippingAddressResolver', $src);
    self::assertStringContainsString("\$clientCc === 'CN' && \$selCc !== 'CN'", $src);
    self::assertStringContainsString('shipping_address_id', $src);
}

private function assert_CheckoutQuoteSelectedAddress_testExpressFlowUsesSharedAddressResolver(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    self::assertStringContainsString('CheckoutShippingAddressResolver', $src);
    self::assertStringContainsString('addressResolver->resolve', $src);
    self::assertGreaterThanOrEqual(3, substr_count($src, 'CheckoutShippingAddressResolver'));
}

private function assert_CheckoutQuoteSelectedAddress_testCheckoutFormAddressPrefersWidgetResolveQuoteAddress(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('resolveQuoteAddress', $src);
    self::assertStringContainsString('WelineShippingCheckoutAddress', $src);
    self::assertStringContainsString('shipping_address_id', $src);
    self::assertStringContainsString('text(shipping.country_code).trim().toUpperCase()', $src);
}

private function assert_CheckoutQuoteSelectedAddress_testSelectAddressLooksUpAcrossCountries(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutDeliveryContextService.php');
    // 意图：跨国家查找（首参恒 ''），禁止按当前国家过滤；后续加了 purpose 维度不违此意。
    self::assertMatchesRegularExpression("/foreach \\(\\\$this->listAddresses\\(''/", $src);
    self::assertStringNotContainsString('foreach ($this->listAddresses($this->currentCountryCode()) as $address)', $src);
}

private function assert_CheckoutRenderDeliveryAddressWidget_testProviderExposesLazyAddressWidgetOperation(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'renderDeliveryAddressWidget' => \$this->renderDeliveryAddressWidget(\$params)", $src);
    self::assertStringContainsString("'name' => 'renderDeliveryAddressWidget'", $src);
    self::assertStringContainsString("'frontend' => true", $src);
    self::assertStringContainsString('data-helppay-address-host', $src);
    self::assertStringContainsString('Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml', $src);
    self::assertStringContainsString("'title' => (string) __('收货地址')", $src);
    self::assertStringContainsString('shipping_address_widget_unavailable', $src);
}

private function assert_CheckoutShippingMethodsHomology_testLoadShippingMethodsUsesListQuoteOptionsNotGetByLocation(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("w_query('shippingInfo', 'listQuoteOptions'", $src);
    self::assertStringNotContainsString("w_query('shippingInfo', 'getByLocation'", $src);
    self::assertStringContainsString('fulfillment_metadata', $src);
    self::assertStringContainsString('shipping_profile_code', $src);
    self::assertStringContainsString('shipping_hazard_class', $src);
    self::assertStringContainsString('delivery_point_type', $src);
    self::assertStringContainsString("'scope' =>", $src);
    self::assertStringContainsString('website_id', $src);
    self::assertStringContainsString('store_id', $src);
    self::assertStringContainsString('channel_id', $src);
    self::assertStringContainsString('labelForDutyNoticeCode', $src);
    self::assertStringContainsString('ShippingIncotermService', $src);
    self::assertStringContainsString('__($rawLabel)', $src);
    self::assertStringNotContainsString("? (\$description . ' · ' . \$dutyNotice)", $src);
    // 空态文案已收口到 CheckoutShippingUnavailablePresenter（getData 经 present() 消费），
    // Provider 不再内联拒因文案。
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutShippingUnavailablePresenter.php');
    self::assertStringContainsString('shippingUnavailablePresenter', $src);
    $express = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    self::assertStringContainsString('labelForDutyNoticeCode', $express);
    self::assertStringContainsString('ShippingIncotermService', $express);
    self::assertStringContainsString("__(\$label)", $express);
}

private function assert_CheckoutSubmitV2ExpressParam_testSubmitV2DescriptorAllowsExpressAndB2bCreditParams(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'name' => 'submitV2'", $src);
    self::assertMatchesRegularExpression("/'name' => 'submitV2'[\\s\\S]*?'express_checkout' => \\['type' => 'boolean'[\\s\\S]*?'b2b_credit_apply_minor' => \\['type' => 'integer'/", $src);
}

private function assert_CheckoutSubmitV2ExpressParam_testTraditionalSubmitOmitsExpressCheckoutUnlessOptedIn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('if (opts.express_checkout)', $src);
    self::assertStringContainsString('submitPayload.express_checkout = true', $src);
    self::assertStringNotContainsString('express_checkout: !!opts.express_checkout', $src);
}
}
