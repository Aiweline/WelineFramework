<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Extends\Module\Weline_Checkout\CheckoutType\TobCheckoutTypeProvider;
use Weline\Checkout\CheckoutType\StandardCheckoutTypeProvider;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutDeliveryContextService;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Checkout\Service\CheckoutSessionContact;
use Weline\Checkout\Service\CheckoutSuccessUrlBuilder;
use Weline\Checkout\Service\CheckoutTypeProviderRegistry;
use Weline\Checkout\Service\ContinueCheckoutUrlBuilder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\Framework\Http\Url;
use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\Data\PaymentTransactionRecord;
use Weline\Payment\Api\PaymentFacadeInterface;
use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;
use Weline\Payment\Service\PaymentMethodIncentiveQuoteService;
use Weline\Shipping\Service\AddressFormatter;

/**
 * Aggregated contract suite (merged from 26 *ContractTest files).
 * Observe one overall pass/fail via testAllCheckoutServiceContractsPass().
 */
final class CheckoutServiceContractsTest extends TestCase
{
    public function testAllCheckoutServiceContractsPass(): void
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
            'AbandonedCheckoutSignal::testContactExtractsGuestAndAddressEmail' => function () {
            $this->assert_AbandonedCheckoutSignal_testContactExtractsGuestAndAddressEmail();
        },
            'AbandonedCheckoutSignal::testContinueCheckoutUrlShape' => function () {
            $this->assert_AbandonedCheckoutSignal_testContinueCheckoutUrlShape();
        },
            'AbandonedCheckoutSignal::testQuotedWithEmailGetsExtendedTtl' => function () {
            $this->assert_AbandonedCheckoutSignal_testQuotedWithEmailGetsExtendedTtl();
        },
            'AbandonedCheckoutSignal::testProviderContractSource' => function () {
            $this->assert_AbandonedCheckoutSignal_testProviderContractSource();
        },
            'CheckoutAddressCountryHint::testCanonicalCountryNameStripsPostalPlaceHint' => function () {
            $this->assert_CheckoutAddressCountryHint_testCanonicalCountryNameStripsPostalPlaceHint();
        },
            'CheckoutAddressCountryHint::testCanonicalizeCountryFieldsTouchesOnlyCountryNames' => function () {
            $this->assert_CheckoutAddressCountryHint_testCanonicalizeCountryFieldsTouchesOnlyCountryNames();
        },
            'CheckoutAddressCountryHint::testCanonicalizeCountryFieldsIgnoresMissingAndNonString' => function () {
            $this->assert_CheckoutAddressCountryHint_testCanonicalizeCountryFieldsIgnoresMissingAndNonString();
        },
            'CheckoutAddressCountryHint::testCheckoutResolveAndFreezeStripCountryHint' => function () {
            $this->assert_CheckoutAddressCountryHint_testCheckoutResolveAndFreezeStripCountryHint();
        },
            'CheckoutDeliveryContextPickerAllAddresses::testGetContextSupportsListAllAddressesFlag' => function () {
            $this->assert_CheckoutDeliveryContextPickerAllAddresses_testGetContextSupportsListAllAddressesFlag();
        },
            'CheckoutDeliveryEmbargoGate::testSelectAndSaveAssertEmbargoService' => function () {
            $this->assert_CheckoutDeliveryEmbargoGate_testSelectAndSaveAssertEmbargoService();
        },
            'CheckoutDeliveryEmbargoGate::testToFormAddressPassesRegionIds' => function () {
            $this->assert_CheckoutDeliveryEmbargoGate_testToFormAddressPassesRegionIds();
        },
            'CheckoutDeliveryFallbackCountry::testLocaleMapsToWesternAndCnSchemas' => function () {
            $this->assert_CheckoutDeliveryFallbackCountry_testLocaleMapsToWesternAndCnSchemas();
        },
            'CheckoutDeliveryFallbackCountry::testCurrentCountryFallbackUsesStorefrontResolverNotHardCn' => function () {
            $this->assert_CheckoutDeliveryFallbackCountry_testCurrentCountryFallbackUsesStorefrontResolverNotHardCn();
        },
            'CheckoutDeliveryFallbackCountry::testCheckoutWidgetSeedsFallbackCountry' => function () {
            $this->assert_CheckoutDeliveryFallbackCountry_testCheckoutWidgetSeedsFallbackCountry();
        },
            'CheckoutDeliveryPurposeFilter::testContextResolvesAddressPurposeAndSaveMeta' => function () {
            $this->assert_CheckoutDeliveryPurposeFilter_testContextResolvesAddressPurposeAndSaveMeta();
        },
            'CheckoutDeliveryPurposeFilter::testClearDeliveryBookStripsCustomerCheckoutPurpose' => function () {
            $this->assert_CheckoutDeliveryPurposeFilter_testClearDeliveryBookStripsCustomerCheckoutPurpose();
        },
            'CheckoutDeliveryPurposeFilter::testSubmitV2PromotesSelectedAddressToCheckout' => function () {
            $this->assert_CheckoutDeliveryPurposeFilter_testSubmitV2PromotesSelectedAddressToCheckout();
        },
            'CheckoutDeliveryPurposeFilter::testCheckoutWidgetSeedsCheckoutPurpose' => function () {
            $this->assert_CheckoutDeliveryPurposeFilter_testCheckoutWidgetSeedsCheckoutPurpose();
        },
            'CheckoutDeliveryPurposeFilter::testQueryProviderDeclaresPurposeParams' => function () {
            $this->assert_CheckoutDeliveryPurposeFilter_testQueryProviderDeclaresPurposeParams();
        },
            'CheckoutDiscountPaymentAssert::testAssertSessionDiscountQuoteSkipsGhostActionsWithoutEffect' => function () {
            $this->assert_CheckoutDiscountPaymentAssert_testAssertSessionDiscountQuoteSkipsGhostActionsWithoutEffect();
        },
            'CheckoutDiscountsBannedGate::testDiscountsBannedUsesOrderCalculatorGateWithoutTobHardcode' => function () {
            $this->assert_CheckoutDiscountsBannedGate_testDiscountsBannedUsesOrderCalculatorGateWithoutTobHardcode();
        },
            'CheckoutFreezeQuoteEnrichEvent::testFreezeAndQuoteDispatchesEnrichEventWithoutB2bHardCouple' => function () {
            $this->assert_CheckoutFreezeQuoteEnrichEvent_testFreezeAndQuoteDispatchesEnrichEventWithoutB2bHardCouple();
        },
            'CheckoutFreezeQuoteEnrichEvent::testCheckoutEventCatalogDocumentsFreezeQuoteEnrich' => function () {
            $this->assert_CheckoutFreezeQuoteEnrichEvent_testCheckoutEventCatalogDocumentsFreezeQuoteEnrich();
        },
            'CheckoutGroupSubmitPricingChrome::testSubmitServicePreservesCompareAtAndCampaignOnBucketAndCommandLines' => function () {
            $this->assert_CheckoutGroupSubmitPricingChrome_testSubmitServicePreservesCompareAtAndCampaignOnBucketAndCommandLines();
        },
            'CheckoutLegacyPaymentServiceDeprecation::testCheckoutPaymentServiceIsDeprecated' => function () {
            $this->assert_CheckoutLegacyPaymentServiceDeprecation_testCheckoutPaymentServiceIsDeprecated();
        },
            'CheckoutOrderPaymentHangPurpose::testDepositPurposeChargesDepositAndDoesNotNotifyFullPaid' => function () {
            $this->assert_CheckoutOrderPaymentHangPurpose_testDepositPurposeChargesDepositAndDoesNotNotifyFullPaid();
        },
            'CheckoutOrderPaymentHangPurpose::testBalancePurposeChargesBalanceAndNotifiesPaid' => function () {
            $this->assert_CheckoutOrderPaymentHangPurpose_testBalancePurposeChargesBalanceAndNotifiesPaid();
        },
            'CheckoutOrderPaymentIncentiveMoneyConservation::test_sync_source_uses_with_computed_grand_total_not_gateway_amount' => function () {
            $this->assert_CheckoutOrderPaymentIncentiveMoneyConservation_test_sync_source_uses_with_computed_grand_total_not_gateway_amount();
        },
            'CheckoutOrderPaymentIncentiveMoneyConservation::test_taxed_incentive_money_conserves_via_money_snapshot' => function () {
            $this->assert_CheckoutOrderPaymentIncentiveMoneyConservation_test_taxed_incentive_money_conserves_via_money_snapshot();
        },
            'CheckoutPaymentIncentiveApplier::test_apply_merges_payment_method_incentive_into_discount_lines_and_reduces_grand' => function () {
            $this->assert_CheckoutPaymentIncentiveApplier_test_apply_merges_payment_method_incentive_into_discount_lines_and_reduces_grand();
        },
            'CheckoutPaymentIncentiveApplier::test_apply_is_idempotent_when_discount_lines_already_contain_incentive' => function () {
            $this->assert_CheckoutPaymentIncentiveApplier_test_apply_is_idempotent_when_discount_lines_already_contain_incentive();
        },
            'CheckoutPaymentIncentiveApplier::test_checkout_applier_and_submit_wiring_sources_exist' => function () {
            $this->assert_CheckoutPaymentIncentiveApplier_test_checkout_applier_and_submit_wiring_sources_exist();
        },
            'CheckoutPaymentMethodFallback::testCheckoutTemplateDoesNotHardcodeProviderMethodCode' => function () {
            $this->assert_CheckoutPaymentMethodFallback_testCheckoutTemplateDoesNotHardcodeProviderMethodCode();
        },
            'CheckoutPaymentMethodFallback::testZeroDepositExemptionIsAlignedBetweenGateAndSubmit' => function () {
            $this->assert_CheckoutPaymentMethodFallback_testZeroDepositExemptionIsAlignedBetweenGateAndSubmit();
        },
            'CheckoutPaymentMethodFallback::testServerAcceptsEmptyMethodForAssetOnlySettlement' => function () {
            $this->assert_CheckoutPaymentMethodFallback_testServerAcceptsEmptyMethodForAssetOnlySettlement();
        },
            'CheckoutShippingFreightOverlayEvent::testFreezeAndQuoteDispatchesShippingQuoteOverlay' => function () {
            $this->assert_CheckoutShippingFreightOverlayEvent_testFreezeAndQuoteDispatchesShippingQuoteOverlay();
        },
            'CheckoutShippingFreightOverlayEvent::testLoadShippingMethodsPassesOfferIdAndEnrichEvent' => function () {
            $this->assert_CheckoutShippingFreightOverlayEvent_testLoadShippingMethodsPassesOfferIdAndEnrichEvent();
        },
            'CheckoutShippingFreightOverlayEvent::testEventCatalogDocumentsOverlayEvents' => function () {
            $this->assert_CheckoutShippingFreightOverlayEvent_testEventCatalogDocumentsOverlayEvents();
        },
            'CheckoutSplitShipping::testFreezeUsesSplitQuoteAndWhSplitKey' => function () {
            $this->assert_CheckoutSplitShipping_testFreezeUsesSplitQuoteAndWhSplitKey();
        },
            'CheckoutSplitShipping::testExpressReviewShowsPackageBreakdown' => function () {
            $this->assert_CheckoutSplitShipping_testExpressReviewShowsPackageBreakdown();
        },
            'CheckoutSubmitPaymentMethodPersist::testSubmitLockedPassesPaymentMethodIntoCreateCommandOptions' => function () {
            $this->assert_CheckoutSubmitPaymentMethodPersist_testSubmitLockedPassesPaymentMethodIntoCreateCommandOptions();
        },
            'CheckoutSubmitPaymentMethodPersist::testHangDepositPartialBackfillsPaymentMethod' => function () {
            $this->assert_CheckoutSubmitPaymentMethodPersist_testHangDepositPartialBackfillsPaymentMethod();
        },
            'CheckoutTaxAdvisorRuntimeResolve::testFreezePathResolvesTaxAdvisorAtRuntime' => function () {
            $this->assert_CheckoutTaxAdvisorRuntimeResolve_testFreezePathResolvesTaxAdvisorAtRuntime();
        },
            'CheckoutTypeProviderRegistry::testBuiltInStandardAndCartTypeMapping' => function () {
            $this->assert_CheckoutTypeProviderRegistry_testBuiltInStandardAndCartTypeMapping();
        },
            'CheckoutTypeProviderRegistry::testRejectsContinuePayAsType' => function () {
            $this->assert_CheckoutTypeProviderRegistry_testRejectsContinuePayAsType();
        },
            'CheckoutTypeProviderRegistry::testTobProviderMapsCartType' => function () {
            $this->assert_CheckoutTypeProviderRegistry_testTobProviderMapsCartType();
        },
            'CheckoutTypeProviderRegistry::testStandardProviderCapabilities' => function () {
            $this->assert_CheckoutTypeProviderRegistry_testStandardProviderCapabilities();
        },
            'DeliveryAddressCaptchaGuardLazy::testGuardRendersLazyHostInsteadOfChallengeHtml' => function () {
            $this->assert_DeliveryAddressCaptchaGuardLazy_testGuardRendersLazyHostInsteadOfChallengeHtml();
        },
            'ExpressBuyNowIsolation::testFlowIsolatesBuyNowThenRestoresBrowseCart' => function () {
            $this->setUp_ExpressBuyNowIsolation();
            $this->assert_ExpressBuyNowIsolation_testFlowIsolatesBuyNowThenRestoresBrowseCart();
        },
            'ExpressBuyNowIsolation::testProductExpressJsPassesBuyNowWithoutCartAdd' => function () {
            $this->setUp_ExpressBuyNowIsolation();
            $this->assert_ExpressBuyNowIsolation_testProductExpressJsPassesBuyNowWithoutCartAdd();
        },
            'ExpressBuyNowIsolation::testStartExpressCheckoutDescriptorAllowsBuyNowParams' => function () {
            $this->setUp_ExpressBuyNowIsolation();
            $this->assert_ExpressBuyNowIsolation_testStartExpressCheckoutDescriptorAllowsBuyNowParams();
        },
            'ExpressConfirmCaptureAmountSync::testConfirmPassesAmendedTotalsIntoMarkConfirmCapture' => function () {
            $this->setUp_ExpressConfirmCaptureAmountSync();
            $this->assert_ExpressConfirmCaptureAmountSync_testConfirmPassesAmendedTotalsIntoMarkConfirmCapture();
        },
            'ExpressConfirmCaptureAmountSync::testMarkConfirmCaptureRewritesStaleAmountsInRequestSnapshot' => function () {
            $this->setUp_ExpressConfirmCaptureAmountSync();
            $this->assert_ExpressConfirmCaptureAmountSync_testMarkConfirmCaptureRewritesStaleAmountsInRequestSnapshot();
        },
            'ExpressStartDeferredShipping::testStartNeverFallsBackToBlindCnCountry' => function () {
            $this->setUp_ExpressStartDeferredShipping();
            $this->assert_ExpressStartDeferredShipping_testStartNeverFallsBackToBlindCnCountry();
        },
            'ExpressStartDeferredShipping::testShippingPreflightOnlyWithTrustedCountry' => function () {
            $this->setUp_ExpressStartDeferredShipping();
            $this->assert_ExpressStartDeferredShipping_testShippingPreflightOnlyWithTrustedCountry();
        },
            'ExpressStartDeferredShipping::testFreezeRejectionRetriesOnceAsDeferredShipping' => function () {
            $this->setUp_ExpressStartDeferredShipping();
            $this->assert_ExpressStartDeferredShipping_testFreezeRejectionRetriesOnceAsDeferredShipping();
        },
            'ExpressUnpaidOrderAmendTaxIdentity::testAmendMergesBuyerTaxIntoTaxSnapshotJson' => function () {
            $this->assert_ExpressUnpaidOrderAmendTaxIdentity_testAmendMergesBuyerTaxIntoTaxSnapshotJson();
        },
            'ExpressUnpaidOrderAmendTaxIdentity::testExpressFlowPassesTaxIdentityOnStartAndConfirm' => function () {
            $this->assert_ExpressUnpaidOrderAmendTaxIdentity_testExpressFlowPassesTaxIdentityOnStartAndConfirm();
        },
            'ExpressUnpaidOrderAmendTaxIdentity::testConfirmQueryAcceptsTaxIdentity' => function () {
            $this->assert_ExpressUnpaidOrderAmendTaxIdentity_testConfirmQueryAcceptsTaxIdentity();
        },
            'StorefrontMoneyTotalsBuilder::testBuilderExistsAndOwnsPayableFormula' => function () {
            $this->assert_StorefrontMoneyTotalsBuilder_testBuilderExistsAndOwnsPayableFormula();
        },
            'StorefrontMoneyTotalsBuilder::testBuilderArithmeticLocksIncentiveAndCouponTogether' => function () {
            $this->assert_StorefrontMoneyTotalsBuilder_testBuilderArithmeticLocksIncentiveAndCouponTogether();
        },
            'StorefrontMoneyTotalsBuilder::testSelectedPaymentChangesIncentiveOnSameChannel' => function () {
            $this->assert_StorefrontMoneyTotalsBuilder_testSelectedPaymentChangesIncentiveOnSameChannel();
        },
        ];
    }

private function assert_AbandonedCheckoutSignal_testContactExtractsGuestAndAddressEmail(): void
{
    self::assertSame('g@example.com', CheckoutSessionContact::extractEmail(['guest_email' => 'g@example.com']));
    self::assertSame('a@example.com', CheckoutSessionContact::extractEmail(['address' => ['email' => 'a@example.com']]));
    self::assertSame('', CheckoutSessionContact::extractEmail(['address' => ['city' => 'x']]));
}

private function assert_AbandonedCheckoutSignal_testContinueCheckoutUrlShape(): void
{
    $b = new ContinueCheckoutUrlBuilder('https://shop.test.weline.com:9555');
    $r = $b->build('tok-abc', 1, 'en_US');
    self::assertTrue($r['reachable']);
    self::assertStringContainsString('/en_US/checkout?', $r['continue_checkout_url']);
    self::assertStringContainsString('quote_token=tok-abc', $r['continue_checkout_url']);
    self::assertStringNotContainsString('resumePayment', $r['continue_checkout_url']);
}

private function assert_AbandonedCheckoutSignal_testQuotedWithEmailGetsExtendedTtl(): void
{
    $store = new InMemoryCheckoutSessionStore();
    $store->put('t-email', ['state' => CheckoutSession::STATE_QUOTED, 'guest_email' => 'buyer@example.com', 'cart_fingerprint' => 'fp1']);
    $row = (new \ReflectionClass($store))->getProperty('rows');
    $row->setAccessible(true);
    /** @var array<string,mixed> $rows */
    $rows = $row->getValue($store);
    $expires = (string) ($rows['t-email']['expires_at'] ?? '');
    $ts = strtotime($expires . ' UTC');
    self::assertNotFalse($ts);
    self::assertGreaterThan(time() + 86400, $ts);
    $store2 = new InMemoryCheckoutSessionStore();
    $store2->put('t-no', ['state' => CheckoutSession::STATE_QUOTED, 'cart_fingerprint' => 'fp2']);
    $rows2 = $row->getValue($store2);
    $expires2 = (string) ($rows2['t-no']['expires_at'] ?? '');
    $ts2 = strtotime($expires2 . ' UTC');
    self::assertNotFalse($ts2);
    self::assertLessThan(time() + 7200, $ts2);
}

private function assert_AbandonedCheckoutSignal_testProviderContractSource(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutSignalsQueryProvider.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("return 'checkout_signals'", $src);
    self::assertStringContainsString('list_stale_quotes', $src);
    self::assertStringContainsString('get_stale_quote', $src);
    self::assertStringContainsString('AbandonedCheckoutSignalService', $src);
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/AbandonedCheckoutSignalService.php');
    self::assertStringContainsString("'line_items'", $svc);
    self::assertStringContainsString('lineItemsFromPayload', $svc);
}

private function assert_CheckoutAddressCountryHint_testCanonicalCountryNameStripsPostalPlaceHint(): void
{
    self::assertSame('United States', AddressFormatter::canonicalCountryName('United States · San Francisco'));
    self::assertSame('United States', AddressFormatter::canonicalCountryName('United States'));
    self::assertSame('United States', AddressFormatter::canonicalCountryName('  United   States  '));
    self::assertSame('United States', AddressFormatter::canonicalCountryName('United States · San Francisco · CA'));
    self::assertSame('', AddressFormatter::canonicalCountryName(''));
    self::assertSame('', AddressFormatter::canonicalCountryName('   '));
}

private function assert_CheckoutAddressCountryHint_testCanonicalizeCountryFieldsTouchesOnlyCountryNames(): void
{
    $address = ['country' => 'United States · San Francisco', 'country_name' => 'Japan · Tokyo', 'country_code' => 'US', 'city' => 'City and County of San Francisco', 'province' => 'California'];
    $out = AddressFormatter::canonicalizeCountryFields($address);
    self::assertSame('United States', $out['country']);
    self::assertSame('Japan', $out['country_name']);
    self::assertSame('US', $out['country_code']);
    // 其它字段不得被改动
    self::assertSame('City and County of San Francisco', $out['city']);
    self::assertSame('California', $out['province']);
}

private function assert_CheckoutAddressCountryHint_testCanonicalizeCountryFieldsIgnoresMissingAndNonString(): void
{
    self::assertSame([], AddressFormatter::canonicalizeCountryFields([]));
    $out = AddressFormatter::canonicalizeCountryFields(['country' => ['nested']]);
    self::assertSame(['nested'], $out['country']);
}

private function assert_CheckoutAddressCountryHint_testCheckoutResolveAndFreezeStripCountryHint(): void
{
    // 两条提交路径都必须收口：legacy placeOrder / getData 走 resolver；
    // V2 freeze→quote 走 CheckoutGroupSubmitService::freezeAndQuote（含 billing_address）。
    $resolver = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutShippingAddressResolver.php');
    self::assertStringContainsString('AddressFormatter::canonicalizeCountryFields', $resolver);
    $submit = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString('AddressFormatter::canonicalizeCountryFields($address)', $submit);
    self::assertStringContainsString('AddressFormatter::canonicalizeCountryFields($billingAddress)', $submit);
}

private function assert_CheckoutDeliveryContextPickerAllAddresses_testGetContextSupportsListAllAddressesFlag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutDeliveryContextService.php');
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('wantsAllAddresses', $src);
    self::assertStringContainsString("listAddresses(\$listAll ? '' : \$countryCode)", $src);
    self::assertStringContainsString("'list_all_addresses'", $src);
    self::assertStringContainsString("'list_all_addresses' => ['type' => 'boolean'", $provider);
    self::assertStringContainsString("'for_picker' => ['type' => 'boolean'", $provider);
}

private function assert_CheckoutDeliveryEmbargoGate_testSelectAndSaveAssertEmbargoService(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutDeliveryContextService.php');
    self::assertStringContainsString('use Weline\Shipping\Service\EmbargoService;', $src);
    self::assertStringContainsString('assertDestinationAllowed', $src);
    self::assertStringContainsString('assertAllowed', $src);
    self::assertStringContainsString('annotateEmbargoOne', $src);
    self::assertStringContainsString('embargo_blocked', $src);
    self::assertStringContainsString('$this->assertDestinationAllowed($match);', $src);
    self::assertStringContainsString('$this->assertDestinationAllowed($normalized);', $src);
    self::assertStringContainsString("'province_region_id'", $src);
    self::assertStringContainsString("'city_region_id'", $src);
    self::assertStringContainsString("'district_region_id'", $src);
}

private function assert_CheckoutDeliveryEmbargoGate_testToFormAddressPassesRegionIds(): void
{
    $form = CheckoutDeliveryContextService::toFormAddress(['id' => 'addr-1', 'contact_name' => 'A', 'contact_phone' => '1', 'country_code' => 'CN', 'province' => '上海', 'province_region_id' => 31, 'city' => '上海市', 'city_region_id' => 3101, 'district' => '浦东新区', 'district_region_id' => 310115, 'street' => '测试路1号', 'street_id' => 9], 'a@example.com');
    self::assertSame('31', $form['province_region_id']);
    self::assertSame('3101', $form['city_region_id']);
    self::assertSame('310115', $form['district_region_id']);
    self::assertSame('9', $form['street_id']);
}

private function assert_CheckoutDeliveryFallbackCountry_testLocaleMapsToWesternAndCnSchemas(): void
{
    self::assertSame('US', CheckoutDeliveryContextService::defaultCountryFromLocale('en_US'));
    self::assertSame('GB', CheckoutDeliveryContextService::defaultCountryFromLocale('en_GB'));
    self::assertSame('JP', CheckoutDeliveryContextService::defaultCountryFromLocale('ja_JP'));
    self::assertSame('CN', CheckoutDeliveryContextService::defaultCountryFromLocale('zh_Hans_CN'));
    self::assertSame('US', CheckoutDeliveryContextService::defaultCountryFromLocale('en'));
    self::assertSame('CN', CheckoutDeliveryContextService::defaultCountryFromLocale('zh'));
}

private function assert_CheckoutDeliveryFallbackCountry_testCurrentCountryFallbackUsesStorefrontResolverNotHardCn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutDeliveryContextService.php');
    self::assertStringContainsString('resolveStorefrontFallbackCountry', $src);
    self::assertStringContainsString('defaultCountryFromLocale', $src);
    self::assertStringContainsString('currentWebsiteDefaultLanguage', $src);
    self::assertStringContainsString('localeAllowedOnWebsite', $src);
    self::assertStringContainsString('return self::resolveStorefrontFallbackCountry();', $src);
    // currentCountryCode 末级须走店面回落，禁止在 Cookie 分支后直接裸 return 'CN'
    self::assertMatchesRegularExpression("/COOKIE_LOCATION[\\s\\S]*?return self::resolveStorefrontFallbackCountry\\(\\);/", $src);
}

private function assert_CheckoutDeliveryFallbackCountry_testCheckoutWidgetSeedsFallbackCountry(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml');
    self::assertStringContainsString('resolveStorefrontFallbackCountry', $tpl);
    self::assertStringContainsString('$fallbackCountry', $tpl);
}

private function assert_CheckoutDeliveryPurposeFilter_testContextResolvesAddressPurposeAndSaveMeta(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutDeliveryContextService.php');
    self::assertStringContainsString('resolveAddressPurpose', $src);
    self::assertStringContainsString('extractPurposeMeta', $src);
    self::assertStringContainsString("filters['purpose_checkout'] = 1", $src);
    self::assertStringContainsString("filters['purpose_receiving'] = 1", $src);
    self::assertStringContainsString("'address_purpose' => \$listPurpose", $src);
    self::assertStringContainsString('guestMatchesPurpose', $src);
    self::assertStringContainsString('stripCheckoutPurposeForCustomer', $src);
    self::assertStringContainsString('promoteSelectedAddressToCheckout', $src);
}

private function assert_CheckoutDeliveryPurposeFilter_testClearDeliveryBookStripsCustomerCheckoutPurpose(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutDeliveryContextService.php');
    self::assertStringContainsString('stripCheckoutPurposeForCustomer', $src);
    self::assertStringContainsString('仅清空结账用途地址', $src);
    self::assertStringContainsString('promoteSelectedAddressToCheckout', $src);
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Shipping/Service/DeliveryAddressService.php');
    self::assertStringContainsString('function stripCheckoutPurposeForCustomer', $service);
    self::assertStringContainsString('function ensureCheckoutPurposeForAddressId', $service);
    self::assertStringContainsString("schema_fields_PURPOSE_CHECKOUT, 0", $service);
}

private function assert_CheckoutDeliveryPurposeFilter_testSubmitV2PromotesSelectedAddressToCheckout(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString('promoteSelectedAddressToCheckout', $src);
}

private function assert_CheckoutDeliveryPurposeFilter_testCheckoutWidgetSeedsCheckoutPurpose(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml');
    self::assertStringContainsString("getContext(['address_purpose' => 'checkout'])", $tpl);
    self::assertStringContainsString('data-also-use-receiving', $tpl);
}

private function assert_CheckoutDeliveryPurposeFilter_testQueryProviderDeclaresPurposeParams(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'address_purpose'", $src);
    self::assertStringContainsString("'purpose_source'", $src);
    self::assertStringContainsString("'also_use_receiving'", $src);
    self::assertStringContainsString("'also_use_checkout'", $src);
}

private function assert_CheckoutDiscountPaymentAssert_testAssertSessionDiscountQuoteSkipsGhostActionsWithoutEffect(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString('hasDiscountEffect', $source);
    self::assertStringContainsString('!$hasDiscountEffect', $source);
    self::assertStringContainsString('ghost actions', $source);
    self::assertStringContainsString("__('当前支付方式「%{1}」不支持已选优惠方式：%{2}', [\$resolvedPayment, \$actionCode])", $source);
}

private function assert_CheckoutDiscountsBannedGate_testDiscountsBannedUsesOrderCalculatorGateWithoutTobHardcode(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString('function discountsBannedForCartType', $src);
    self::assertStringContainsString('OrderCalculatorGate', $src);
    self::assertStringContainsString('allowsPriceChangingCalculators', $src);
    self::assertStringContainsString('defer_inventory still rides this flag', $src);
    self::assertStringNotContainsString("if (\$code === 'tob')", $src);
    self::assertStringNotContainsString('if ($code === "tob")', $src);
}

private function assert_CheckoutFreezeQuoteEnrichEvent_testFreezeAndQuoteDispatchesEnrichEventWithoutB2bHardCouple(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString("Weline_Checkout::checkout::freeze_quote::enrich", $src);
    self::assertStringContainsString("Weline_Checkout::checkout::asset_discount::apply", $src);
    self::assertStringNotContainsString('B2BCheckoutCreditQuote', $src);
    self::assertStringNotContainsString('B2BDepositCreditOrchestrator', $src);
    self::assertStringNotContainsString("class_exists(\\Weline\\B2B\\Service\\B2BCheckoutCreditQuote::class)", $src);
}

private function assert_CheckoutFreezeQuoteEnrichEvent_testCheckoutEventCatalogDocumentsFreezeQuoteEnrich(): void
{
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/event.php');
    self::assertStringContainsString("Weline_Checkout::checkout::freeze_quote::enrich", $catalog);
    self::assertStringContainsString("Weline_Checkout::checkout::asset_discount::apply", $catalog);
}

private function assert_CheckoutGroupSubmitPricingChrome_testSubmitServicePreservesCompareAtAndCampaignOnBucketAndCommandLines(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertNotSame('', $src);
    self::assertStringContainsString('function pricingChromeFromLine', $src);
    self::assertStringContainsString('compare_at_minor', $src);
    self::assertStringContainsString('campaign_label', $src);
    self::assertStringContainsString("'image'", $src);
    self::assertStringContainsString('pricingChromeFromLine($line)', $src);
    self::assertStringContainsString('pricingChromeFromLine($item)', $src);
}

private function assert_CheckoutLegacyPaymentServiceDeprecation_testCheckoutPaymentServiceIsDeprecated(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/PaymentService.php');
    self::assertStringContainsString('@deprecated', $src);
    self::assertStringContainsString('GAP-PAY-007', $src);
}

private function assert_CheckoutOrderPaymentHangPurpose_testDepositPurposeChargesDepositAndDoesNotNotifyFullPaid(): void
{
    $order = new OrderReadResult(orderUuid: 'order-tob-deposit-1', checkoutGroupUuid: 'group-tob-1', status: 'pending', currency: 'CNY', websiteId: 0, storeId: 0, money: ['grand_total_minor' => 11500], customerId: 47, orderType: 'tob', typePayload: ['deposit_amount_minor' => 3000, 'balance_amount_minor' => 8500, 'hang_status' => 'awaiting_deposit']);
    $orders = $this->createMock(OrderFacadeInterface::class);
    $orders->expects(self::once())->method('get')->with('order-tob-deposit-1')->willReturn($order);
    $orders->expects(self::never())->method('notifyOrderPaid');
    $payments = $this->createMock(PaymentFacadeInterface::class);
    $payments->expects(self::once())->method('tryCreatePayment')->with('fake_card', self::callback(static function (array $context): bool {
        return (int) $context['amount_minor'] === 3000 && ($context['metadata']['purpose'] ?? '') === 'deposit';
    }))->willReturn(new PaymentTransactionRecord(id: 201, transactionNumber: 'FAKE-DEP-1', methodCode: 'fake_card', status: PaymentTransactionRecord::STATUS_SUCCESS, response: []));
    $result = $this->service_CheckoutOrderPaymentHangPurpose($orders, $payments)->pay(['order-tob-deposit-1'], 'fake_card', 'dep-key-1', ['purpose' => 'deposit', 'deposit_amount_minor' => 3000]);
    self::assertFalse($result['paid']);
    self::assertSame('partial', $result['outcome']);
    self::assertSame('deposit', $result['purpose'] ?? null);
}

private function assert_CheckoutOrderPaymentHangPurpose_testBalancePurposeChargesBalanceAndNotifiesPaid(): void
{
    $order = new OrderReadResult(orderUuid: 'order-tob-balance-1', checkoutGroupUuid: 'group-tob-2', status: 'pending', currency: 'CNY', websiteId: 0, storeId: 0, money: ['grand_total_minor' => 11500], customerId: 47, orderType: 'tob', typePayload: ['deposit_amount_minor' => 3000, 'balance_amount_minor' => 8500, 'hang_status' => 'awaiting_balance']);
    $orders = $this->createMock(OrderFacadeInterface::class);
    $orders->expects(self::once())->method('get')->willReturn($order);
    $orders->expects(self::once())->method('notifyOrderPaid');
    $payments = $this->createMock(PaymentFacadeInterface::class);
    $payments->expects(self::once())->method('tryCreatePayment')->with('fake_card', self::callback(static function (array $context): bool {
        return (int) $context['amount_minor'] === 8500 && ($context['metadata']['purpose'] ?? '') === 'balance';
    }))->willReturn(new PaymentTransactionRecord(id: 202, transactionNumber: 'FAKE-BAL-1', methodCode: 'fake_card', status: PaymentTransactionRecord::STATUS_SUCCESS, response: []));
    $result = $this->service_CheckoutOrderPaymentHangPurpose($orders, $payments)->pay(['order-tob-balance-1'], 'fake_card', 'bal-key-1', ['purpose' => 'balance', 'balance_amount_minor' => 8500]);
    self::assertTrue($result['paid']);
    self::assertSame('paid', $result['outcome']);
    self::assertSame('balance', $result['purpose'] ?? null);
}

private function service_CheckoutOrderPaymentHangPurpose(OrderFacadeInterface $orders, PaymentFacadeInterface $payments): CheckoutOrderPaymentService
{
    $url = $this->createMock(Url::class);
    $url->method('getUrl')->willReturnCallback(static function (string $path, array $params = []): string {
        $query = $params === [] ? '' : '?' . http_build_query($params);
        return 'http://shop.test/' . ltrim($path, '/') . $query;
    });
    return new CheckoutOrderPaymentService($orders, $payments, new CheckoutSuccessUrlBuilder($url), new InMemoryCheckoutSessionStore());
}

private function assert_CheckoutOrderPaymentIncentiveMoneyConservation_test_sync_source_uses_with_computed_grand_total_not_gateway_amount(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutOrderPaymentService.php');
    self::assertStringContainsString('withComputedGrandTotal', $src);
    self::assertStringContainsString('ensureOrderMoneyConserved', $src);
    self::assertStringContainsString('logOrderPaidNotificationFailure', $src);
    self::assertStringContainsString('notification_retry', $src);
    // Must not assign gateway amount_minor directly as snapshot grand.
    self::assertDoesNotMatchRegularExpression('/money\[\'grand_total_minor\'\]\s*=\s*\$newGrand/', $src);
}

private function assert_CheckoutOrderPaymentIncentiveMoneyConservation_test_taxed_incentive_money_conserves_via_money_snapshot(): void
{
    // Reproduces UC-4 class of failure: UI payable 193.00 with tax still 15.84.
    $brokenGrand = 19300;
    $components = ['currency' => 'CNY', 'subtotal_minor' => 19800, 'shipping_amount_minor' => 0, 'tax_amount_minor' => 1584, 'discount_amount_minor' => 500, 'grand_total_minor' => $brokenGrand];
    $broken = MoneySnapshot::fromArray($components);
    self::assertNotSame($broken->subtotalMinor + $broken->shippingAmountMinor + $broken->taxAmountMinor - $broken->discountAmountMinor + $broken->codFeeAmountMinor, $broken->grandTotalMinor);
    $fixed = $broken->withComputedGrandTotal();
    self::assertSame(20884, $fixed->grandTotalMinor);
    self::assertSame($fixed->subtotalMinor + $fixed->shippingAmountMinor + $fixed->taxAmountMinor - $fixed->discountAmountMinor + $fixed->codFeeAmountMinor, $fixed->grandTotalMinor);
}

private function assert_CheckoutPaymentIncentiveApplier_test_apply_merges_payment_method_incentive_into_discount_lines_and_reduces_grand(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $runtime = ['incentive_enabled' => 1, 'incentive_type' => 'fixed_amount', 'incentive_amount_minor' => 500, 'incentive_funding_source' => 'merchant', 'incentive_publish_version' => 'parity-v1'];
    $applied = $svc->applyToOrderData('fake_card', ['amount_minor' => 1892, 'currency' => 'USD', 'discount_lines' => []], $runtime);
    self::assertSame(500, (int) $applied['savings_minor']);
    self::assertSame(1392, (int) $applied['order_data']['amount_minor']);
    $sources = array_map(static fn(array $line): string => (string) ($line['source_type'] ?? ''), $applied['order_data']['discount_lines']);
    self::assertContains(PaymentMethodIncentiveQuoteInterface::SOURCE_TYPE, $sources);
    self::assertSame(-500, (int) $applied['order_data']['payment_method_incentive_amount_minor']);
}

private function assert_CheckoutPaymentIncentiveApplier_test_apply_is_idempotent_when_discount_lines_already_contain_incentive(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $runtime = ['incentive_enabled' => 1, 'incentive_type' => 'fixed_amount', 'incentive_amount_minor' => 500, 'incentive_publish_version' => 'parity-v1'];
    $first = $svc->applyToOrderData('fake_card', ['amount_minor' => 1892, 'currency' => 'USD'], $runtime);
    $second = $svc->applyToOrderData('fake_card', $first['order_data'], $runtime);
    self::assertSame(1392, (int) $second['order_data']['amount_minor']);
    self::assertSame(500, (int) $second['savings_minor']);
    self::assertCount(1, $second['order_data']['discount_lines']);
}

private function assert_CheckoutPaymentIncentiveApplier_test_checkout_applier_and_submit_wiring_sources_exist(): void
{
    $applier = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutPaymentIncentiveApplier.php');
    self::assertStringContainsString('PaymentMethodIncentiveQuoteInterface', $applier);
    self::assertStringContainsString('applyToPaymentContext', $applier);
    self::assertStringContainsString("'totals' => \$totals", $applier);
    self::assertStringContainsString('Never let incentive merge claim discount=0', $applier);
    $submit = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString('CheckoutPaymentIncentiveApplier', $submit);
    self::assertStringContainsString('discount_lines', $submit);
    self::assertStringContainsString('payment_method_incentive_amount_minor', $submit);
    $pay = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutOrderPaymentService.php');
    self::assertStringContainsString('CheckoutPaymentIncentiveApplier', $pay);
    self::assertStringContainsString('syncOrderPaymentIncentive', $pay);
    self::assertStringContainsString("source_type' => \$couponCode !== '' ? 'coupon' : 'cart'", $pay);
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("\$payContext['coupon_code'] = \$sessionCoupon", $provider);
}

private function assert_CheckoutPaymentMethodFallback_testCheckoutTemplateDoesNotHardcodeProviderMethodCode(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertNotSame('', $template);
    self::assertStringNotContainsString("'fake_card'", $template, '结账模板不得硬编码 fake_card 兜底；不可用的支付方式应由 Provider 列表决定是否出现。');
    self::assertStringNotContainsString('paymentMethod = paymentMethod ||', $template, '结账模板不得用 || 编造支付方式码。');
}

private function assert_CheckoutPaymentMethodFallback_testZeroDepositExemptionIsAlignedBetweenGateAndSubmit(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    // 提交前门禁与 submitCheckoutPayment 内的豁免必须同款，否则零定金单会被必填拦下。
    self::assertSame(2, substr_count($template, 'window.WelineB2BCheckoutTob.cashDepositMinor() === 0'), '零定金豁免应在提交门禁与提交函数中各出现一次。');
}

private function assert_CheckoutPaymentMethodFallback_testServerAcceptsEmptyMethodForAssetOnlySettlement(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutOrderPaymentService.php');
    self::assertNotSame('', $source);
    // method code 校验必须落在扣款点之后，而不是函数入口。
    $guardPos = strpos($source, "throw new \\InvalidArgumentException('checkout_payment_method_required')");
    $amountGuardPos = strpos($source, "throw new \\RuntimeException('checkout_payment_amount_invalid')");
    self::assertIsInt($guardPos, 'method code 校验不得被删除。');
    self::assertIsInt($amountGuardPos, '金额校验不得被删除。');
    self::assertGreaterThan($amountGuardPos, $guardPos, 'method code 校验须位于「金额无效」判定之后，即只在真正发起网关扣款时要求。');
}

private function assert_CheckoutShippingFreightOverlayEvent_testFreezeAndQuoteDispatchesShippingQuoteOverlay(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString("Weline_Checkout::checkout::shipping_quote::overlay", $src);
    self::assertStringContainsString('dropship_freight_degraded', $src);
    self::assertStringContainsString('货源运费暂不可用', $src);
}

private function assert_CheckoutShippingFreightOverlayEvent_testLoadShippingMethodsPassesOfferIdAndEnrichEvent(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'offer_id'", $src);
    self::assertStringContainsString("Weline_Checkout::checkout::shipping_methods::enrich", $src);
}

private function assert_CheckoutShippingFreightOverlayEvent_testEventCatalogDocumentsOverlayEvents(): void
{
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/event.php');
    self::assertStringContainsString("Weline_Checkout::checkout::shipping_quote::overlay", $catalog);
    self::assertStringContainsString("Weline_Checkout::checkout::shipping_methods::enrich", $catalog);
}

private function assert_CheckoutSplitShipping_testFreezeUsesSplitQuoteAndWhSplitKey(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString('applyFulfillmentSplitKeys', $src);
    self::assertStringContainsString('applyDropshipPreferredWarehouses', $src);
    self::assertStringContainsString('quoteSplit', $src);
    self::assertStringContainsString('shipping_request_hash', $src);
    self::assertStringContainsString('shipping_packages', $src);
    self::assertStringContainsString('SplitShippingQuoteServiceInterface', $src);
}

private function assert_CheckoutSplitShipping_testExpressReviewShowsPackageBreakdown(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/statics/js/express-review.js');
    self::assertStringContainsString('shipping-packages', $js);
    self::assertStringContainsString('分仓运费明细', $js);
    $phtml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/express-review.phtml');
    self::assertStringContainsString('checkout-split-shipping-packages', $phtml);
}

private function assert_CheckoutSubmitPaymentMethodPersist_testSubmitLockedPassesPaymentMethodIntoCreateCommandOptions(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertNotSame('', $src);
    self::assertStringContainsString("'payment_method' => \$resolvedPaymentMethod", $src);
    self::assertStringContainsString('$resolvedPaymentMethod = strtolower(trim((string)($paymentMethod ?? $session[\'payment_method\'] ?? \'\')));', $src);
}

private function assert_CheckoutSubmitPaymentMethodPersist_testHangDepositPartialBackfillsPaymentMethod(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutOrderPaymentService.php');
    self::assertNotSame('', $src);
    self::assertStringContainsString('rememberOrderPaymentMethod', $src);
    self::assertStringContainsString('markOrderPaymentPartial($orderUuid, $methodCode)', $src);
    self::assertStringContainsString('Order::schema_fields_PAYMENT_METHOD, $methodCode', $src);
}

private function assert_CheckoutTaxAdvisorRuntimeResolve_testFreezePathResolvesTaxAdvisorAtRuntime(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString('resolveRuntimeTax', $src);
    self::assertStringContainsString('taxAdvisorService', $src);
    self::assertStringContainsString('CheckoutTaxAdvisorInterface::class', $src);
    self::assertStringContainsString('DutyEstimateService::NOTICE_DDU', $src);
    self::assertMatchesRegularExpression('/\$taxAdvisor\s*=\s*\$this->taxAdvisorService\(\)/', $src);
    self::assertDoesNotMatchRegularExpression('/\$tax\s*=\s*\$this->taxAdvisor\s*!==\s*null/', $src);
}

private function assert_CheckoutTypeProviderRegistry_testBuiltInStandardAndCartTypeMapping(): void
{
    $reg = CheckoutTypeProviderRegistry::forTesting();
    self::assertTrue($reg->has('standard'));
    self::assertSame('toc', $reg->cartTypeFromTypeCode('standard'));
    self::assertSame('standard', $reg->typeCodeFromCartType('toc'));
    self::assertSame('standard', $reg->typeCodeFromCartType(''));
}

private function assert_CheckoutTypeProviderRegistry_testRejectsContinuePayAsType(): void
{
    $reg = CheckoutTypeProviderRegistry::forTesting();
    $this->expectException(\InvalidArgumentException::class);
    $reg->register(new class implements \Weline\Checkout\Api\CheckoutTypeProviderInterface
    {
        public function getCode(): string
        {
            return 'continue_pay';
        }
        public function getLabel(): string
        {
            return 'x';
        }
        public function getSortOrder(): int
        {
            return 1;
        }
        public function isAvailable(array $context = []): bool
        {
            return true;
        }
        public function getCartTypeCode(): string
        {
            return 'toc';
        }
        public function getCapabilities(): array
        {
            return [];
        }
    });
}

private function assert_CheckoutTypeProviderRegistry_testTobProviderMapsCartType(): void
{
    $reg = CheckoutTypeProviderRegistry::forTesting([new TobCheckoutTypeProvider()]);
    self::assertTrue($reg->has('tob'));
    self::assertSame('tob', $reg->cartTypeFromTypeCode('tob'));
    self::assertSame('tob', $reg->typeCodeFromCartType('tob'));
    $labels = array_map(static fn($p) => $p->getCode(), $reg->all());
    self::assertSame(['standard', 'tob'], $labels);
}

private function assert_CheckoutTypeProviderRegistry_testStandardProviderCapabilities(): void
{
    $p = new StandardCheckoutTypeProvider();
    self::assertTrue($p->getCapabilities()['needs_shipping_address']);
}

private function assert_DeliveryAddressCaptchaGuardLazy_testGuardRendersLazyHostInsteadOfChallengeHtml(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/DeliveryAddressCaptchaGuard.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('LazyCaptchaClientRuntime', $source);
    self::assertStringContainsString('LazyCaptchaClientRuntime::hostMarkup', $source);
    self::assertStringNotContainsString('->renderChallenge(', $source);
}

private string $flowSrc_ExpressBuyNowIsolation;

private string $jsSrc_ExpressBuyNowIsolation;

private string $querySrc_ExpressBuyNowIsolation;

private function setUp_ExpressBuyNowIsolation(): void
{
    $this->flowSrc_ExpressBuyNowIsolation = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    $this->jsSrc_ExpressBuyNowIsolation = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Payment/view/statics/js/product-express-pay.js');
    $this->querySrc_ExpressBuyNowIsolation = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
}

private function assert_ExpressBuyNowIsolation_testFlowIsolatesBuyNowThenRestoresBrowseCart(): void
{
    self::assertStringContainsString('wantsBuyNowIsolation', $this->flowSrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('isolateBuyNowCart', $this->flowSrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('restorePriorCartLines', $this->flowSrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('express_buy_now_product_required', $this->flowSrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('isConcreteShippingAddress', $this->flowSrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('$buyNowActive', $this->flowSrc_ExpressBuyNowIsolation);
    $groupSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutGroupSubmitService.php');
    self::assertStringContainsString('express_deferred_shipping', $groupSrc);
    // finally 还原：成功/失败都不得永久清空浏览车。
    self::assertMatchesRegularExpression('/try\s*\{\s*return \$this->startAfterCartReady[\s\S]*?\}\s*finally\s*\{\s*if \(\$buyNowActive\)/', $this->flowSrc_ExpressBuyNowIsolation);
}

private function assert_ExpressBuyNowIsolation_testProductExpressJsPassesBuyNowWithoutCartAdd(): void
{
    self::assertStringContainsString('buy_now: true', $this->jsSrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('product_express: true', $this->jsSrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('global_offer_uuid: offerUuid', $this->jsSrc_ExpressBuyNowIsolation);
    self::assertStringNotContainsString('cartApi.add', $this->jsSrc_ExpressBuyNowIsolation);
}

private function assert_ExpressBuyNowIsolation_testStartExpressCheckoutDescriptorAllowsBuyNowParams(): void
{
    self::assertStringContainsString("'buy_now' => ['type' => 'boolean'", $this->querySrc_ExpressBuyNowIsolation);
    self::assertStringContainsString("'global_offer_uuid'", $this->querySrc_ExpressBuyNowIsolation);
    self::assertStringContainsString('buy_now isolates PDP product', $this->querySrc_ExpressBuyNowIsolation);
}

private string $src_ExpressConfirmCaptureAmountSync;

private function setUp_ExpressConfirmCaptureAmountSync(): void
{
    $this->src_ExpressConfirmCaptureAmountSync = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
}

private function assert_ExpressConfirmCaptureAmountSync_testConfirmPassesAmendedTotalsIntoMarkConfirmCapture(): void
{
    // 权威金额只能来自 amendUnpaidOrder 的重报价结果
    self::assertStringContainsString('$amended = $this->amendUnpaidOrder($orderUuid, $address, [', $this->src_ExpressConfirmCaptureAmountSync);
    self::assertMatchesRegularExpression('/\$this->markConfirmCapture\(\s*\$transactionNo,\s*\$grandMinor,\s*\(string\) \(\$order\[\'currency\'\] \?\? \'CNY\'\),\s*\\\\is_array\(\$amended\[\'totals\'\] \?\? null\) \? \$amended\[\'totals\'\] : \[\],\s*\);/', $this->src_ExpressConfirmCaptureAmountSync, 'confirm 必须把 amend 后的 totals 传给 markConfirmCapture');
}

private function assert_ExpressConfirmCaptureAmountSync_testMarkConfirmCaptureRewritesStaleAmountsInRequestSnapshot(): void
{
    self::assertMatchesRegularExpression('/private function markConfirmCapture\(string \$transactionNo, int \$grandMinor, string \$currency, array \$totals = \[\]\): void/', $this->src_ExpressConfirmCaptureAmountSync);
    // 合计与金额必须覆盖为最新值
    self::assertStringContainsString("\$request['amount_minor'] = \$grandMinor;", $this->src_ExpressConfirmCaptureAmountSync);
    self::assertStringContainsString("\$request['amount'] = \$grandMinor / 100.0;", $this->src_ExpressConfirmCaptureAmountSync);
    // totals 必须合并写回（保留原有键，仅覆盖本次重报价给出的键）
    self::assertMatchesRegularExpression('/\$request\[\'totals\'\] = array_replace\(\s*\$existingTotals,/', $this->src_ExpressConfirmCaptureAmountSync, 'totals 必须合并写回，不能整体丢弃原有键');
    // patch 目标金额仍必须存在（provider 侧据此 patch 支付商订单金额）
    self::assertStringContainsString("\$request['patch_amount_minor'] = \$grandMinor;", $this->src_ExpressConfirmCaptureAmountSync);
    // 确认扣款必须丢弃创建时的过期 amount_breakdown，并按 totals 回写 discount_lines
    self::assertStringContainsString("unset(\$request['amount_breakdown']);", $this->src_ExpressConfirmCaptureAmountSync);
    self::assertStringContainsString("\$request['discount_lines'] = [[", $this->src_ExpressConfirmCaptureAmountSync);
}

private string $src_ExpressStartDeferredShipping;

private function setUp_ExpressStartDeferredShipping(): void
{
    $this->src_ExpressStartDeferredShipping = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
}

private function assert_ExpressStartDeferredShipping_testStartNeverFallsBackToBlindCnCountry(): void
{
    self::assertStringNotContainsString("\$address['country_code'] = 'CN';", $this->src_ExpressStartDeferredShipping);
}

private function assert_ExpressStartDeferredShipping_testShippingPreflightOnlyWithTrustedCountry(): void
{
    self::assertMatchesRegularExpression('/\$serviceCode = \'\';\s*if \(\$requiresShipping && \$trustedCountry !== \'\'\)/', $this->src_ExpressStartDeferredShipping);
}

private function assert_ExpressStartDeferredShipping_testFreezeRejectionRetriesOnceAsDeferredShipping(): void
{
    self::assertStringContainsString('$deferred = $freezeQuote([', $this->src_ExpressStartDeferredShipping);
    self::assertMatchesRegularExpression('/if \(\$requiresShipping && \$trustedCountry === \'\'\) \{\s*\$deferred = \$freezeQuote/', $this->src_ExpressStartDeferredShipping);
    // deferred 重冻必须清空地址与 service_code，交由 review 回跳后重估。
    self::assertMatchesRegularExpression("/'address' => \\[\\],\\s*'billing_address' => \\[\\],/", $this->src_ExpressStartDeferredShipping);
}

private function assert_ExpressUnpaidOrderAmendTaxIdentity_testAmendMergesBuyerTaxIntoTaxSnapshotJson(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressUnpaidOrderAmend.php');
    self::assertStringContainsString('mergeBuyerTaxIdentity', $src);
    self::assertStringContainsString('TAX_SNAPSHOT_JSON', $src);
    self::assertStringContainsString('BuyerTaxIdentityService', $src);
    self::assertStringContainsString('mergeIntoTaxSnapshot', $src);
}

private function assert_ExpressUnpaidOrderAmendTaxIdentity_testExpressFlowPassesTaxIdentityOnStartAndConfirm(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/ExpressCheckoutFlowService.php');
    self::assertStringContainsString("'tax_identity'", $src);
    self::assertGreaterThanOrEqual(2, substr_count($src, "'tax_identity'"));
}

private function assert_ExpressUnpaidOrderAmendTaxIdentity_testConfirmQueryAcceptsTaxIdentity(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
    self::assertStringContainsString("'name' => 'confirmExpressCheckout'", $src);
    self::assertMatchesRegularExpression("/'name' => 'confirmExpressCheckout'[\\s\\S]*?'tax_identity' => \\['type' => 'array'/", $src);
}

private function builderSrc_StorefrontMoneyTotalsBuilder(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/StorefrontMoneyTotalsBuilder.php');
}

private function assert_StorefrontMoneyTotalsBuilder_testBuilderExistsAndOwnsPayableFormula(): void
{
    $src = $this->builderSrc_StorefrontMoneyTotalsBuilder();
    self::assertStringContainsString('final class StorefrontMoneyTotalsBuilder', $src);
    self::assertStringContainsString('function build', $src);
    self::assertStringContainsString('function mergeIntoPayload', $src);
    self::assertStringContainsString('payment_incentive_minor', $src);
    self::assertStringContainsString('discount_minor', $src);
    self::assertStringContainsString('discount_preview', $src);
    self::assertStringContainsString('payable_minor', $src);
    self::assertStringContainsString('adjustDto', $src);
    // Cart baseline must not be overwritten with payable (channel semantics).
    self::assertStringContainsString('Do not overwrite cart.grand_total with payable', $src);
}

private function assert_StorefrontMoneyTotalsBuilder_testBuilderArithmeticLocksIncentiveAndCouponTogether(): void
{
    require_once '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/StorefrontMoneyTotalsBuilder.php';
    $builder = new \Weline\Checkout\Service\StorefrontMoneyTotalsBuilder();
    $dto = $builder->build(['currency' => 'USD', 'cart' => ['subtotal' => 320.35, 'subtotal_minor' => 32035, 'cart_type' => 'toc', 'discount_preview' => ['amount' => 10.0, 'amount_minor' => 1000, 'coupon_code' => 'DEMO10']], 'shipping_methods' => [['code' => 'STD', 'amount' => 241.82, 'amount_minor' => 24182]], 'payment_methods' => [['code' => 'fake_card', 'incentive_available' => true, 'incentive_savings_minor' => 500, 'cod_fee_amount_minor' => 0]], 'tax_estimate' => ['sales_tax_amount_minor' => 0, 'duty_amount_minor' => 4497, 'import_tax_amount_minor' => 0], 'selected_payment_method' => 'fake_card', 'selected_shipping_method' => 'STD', 'cart_type' => 'toc']);
    // 32035 + 24182 - 1000 + 4497 + 0 - 500 = 59214
    self::assertSame(1000, (int) $dto['discount_minor']);
    self::assertSame(500, (int) $dto['payment_incentive_minor']);
    self::assertSame(4497, (int) $dto['duty_minor']);
    self::assertSame(59214, (int) $dto['payable_minor']);
    self::assertNotSame('', (string) $dto['incentive_text']);
    self::assertStringContainsString('-', (string) $dto['incentive_text']);
}

private function assert_StorefrontMoneyTotalsBuilder_testSelectedPaymentChangesIncentiveOnSameChannel(): void
{
    require_once '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/StorefrontMoneyTotalsBuilder.php';
    $builder = new \Weline\Checkout\Service\StorefrontMoneyTotalsBuilder();
    $base = ['currency' => 'USD', 'cart' => ['subtotal_minor' => 10000, 'cart_type' => 'toc'], 'shipping_methods' => [['code' => 'STD', 'amount_minor' => 0]], 'payment_methods' => [['code' => 'fake_card', 'incentive_available' => true, 'incentive_savings_minor' => 500], ['code' => 'paypal', 'incentive_available' => false, 'incentive_savings_minor' => 0]], 'tax_estimate' => [], 'cart_type' => 'toc'];
    $withIncentive = $builder->build($base + ['selected_payment_method' => 'fake_card']);
    $without = $builder->build($base + ['selected_payment_method' => 'paypal']);
    self::assertSame(500, (int) $withIncentive['payment_incentive_minor']);
    self::assertSame(9500, (int) $withIncentive['payable_minor']);
    self::assertSame(0, (int) $without['payment_incentive_minor']);
    self::assertSame(10000, (int) $without['payable_minor']);
}
}
