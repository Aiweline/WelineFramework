<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\PaymentExpressAddressSinkInterface;
use Weline\Payment\Api\PaymentExpressFacadeInterface;
use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;
use Weline\Payment\Api\PaymentRefundFacadeInterface;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Interface\ProviderConnectInterface;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Interface\ProviderShipmentTrackingInterface;
use Weline\Payment\Observer\OrderShippedShipmentTrackingObserver;
use Weline\Payment\Service\AmountBreakdownBuilder;
use Weline\Payment\Service\AssetAllocationService;
use Weline\Payment\Service\AssetPaymentService;
use Weline\Payment\Service\DevRelayGateService;
use Weline\Payment\Service\DevRelayPairService;
use Weline\Payment\Service\DevRelaySessionService;
use Weline\Payment\Service\ExpressCheckoutOrchestrator;
use Weline\Payment\Service\PayPalApiClient;
use Weline\Payment\Service\PayPalExpressProfileMapper;
use Weline\Payment\Service\PayPalJsSdkUrlBuilder;
use Weline\Payment\Service\PayPalOAuthService;
use Weline\Payment\Service\PayPalSandboxRedirectUriCatalog;
use Weline\Payment\Service\PaymentBrowserCallbackRoutes;
use Weline\Payment\Service\PaymentBrowserCallbackTokenService;
use Weline\Payment\Service\PaymentBrowserReturnContextResolver;
use Weline\Payment\Service\PaymentBrowserReturnLandingOrchestrator;
use Weline\Payment\Service\PaymentMethodIncentiveQuoteService;
use Weline\Payment\Service\PaymentProviderControllerDispatcher;
use Weline\Payment\Service\PaymentProviderControllerRouteScanner;
use Weline\Payment\Service\PaymentRedirectUriCatalog;
use Weline\Payment\Service\PaymentRefundService;
use Weline\Payment\Service\PaymentService;
use Weline\Payment\Service\PaymentShellCallbackUrlCatalog;
use Weline\Payment\Service\PaymentShipmentTrackingDispatcher;
use Weline\Payment\Service\PaymentStorefrontLandingUrlService;

/**
 * Aggregated contract suite (merged from 25 *ContractTest files).
 * Observe one overall pass/fail via testAllPaymentServiceContractsPass().
 */
final class PaymentServiceContractsTest extends TestCase
{
    public function testAllPaymentServiceContractsPass(): void
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
            'DevRelayDropshipBridge::testEventStoreLoadsDropshipInboxPrefix' => function () {
            $this->assert_DevRelayDropshipBridge_testEventStoreLoadsDropshipInboxPrefix();
        },
            'DevRelayDropshipBridge::testLocalConnectReplaysDropshipNotify' => function () {
            $this->assert_DevRelayDropshipBridge_testLocalConnectReplaysDropshipNotify();
        },
            'DevRelayDropshipBridge::testStreamPayloadIncludesModule' => function () {
            $this->assert_DevRelayDropshipBridge_testStreamPayloadIncludesModule();
        },
            'DevRelaySilentPair::testGateExposesOnlineBaseUrlConfigKey' => function () {
            $this->assert_DevRelaySilentPair_testGateExposesOnlineBaseUrlConfigKey();
        },
            'DevRelaySilentPair::testSessionServiceDefinesPublicStreamHelpers' => function () {
            $this->assert_DevRelaySilentPair_testSessionServiceDefinesPublicStreamHelpers();
        },
            'DevRelaySilentPair::testPairServiceApiSurface' => function () {
            $this->assert_DevRelaySilentPair_testPairServiceApiSurface();
        },
            'DevRelaySilentPair::testFrontendDevRelayExposesSilentEndpoints' => function () {
            $this->assert_DevRelaySilentPair_testFrontendDevRelayExposesSilentEndpoints();
        },
            'DevRelaySilentPair::testPanelHookExposesSendProbeControlsAsAdvancedMaintenanceChild' => function () {
            $this->assert_DevRelaySilentPair_testPanelHookExposesSendProbeControlsAsAdvancedMaintenanceChild();
        },
            'DevRelaySilentPair::testDemoTemplateExists' => function () {
            $this->assert_DevRelaySilentPair_testDemoTemplateExists();
        },
            'DevRelaySilentPair::testIndexTemplateCopiesWebsiteBaseUrlAndToken' => function () {
            $this->assert_DevRelaySilentPair_testIndexTemplateCopiesWebsiteBaseUrlAndToken();
        },
            'DevRelaySilentPair::testBackendWorkerStartReadsJsonBodyAndEnablesFirst' => function () {
            $this->assert_DevRelaySilentPair_testBackendWorkerStartReadsJsonBodyAndEnablesFirst();
        },
            'DevRelaySilentPair::testFrontendWorkerStartEnablesBeforeStart' => function () {
            $this->assert_DevRelaySilentPair_testFrontendWorkerStartEnablesBeforeStart();
        },
            'DevRelaySilentPair::testLocalConnectStartResolvesRememberedCredentials' => function () {
            $this->assert_DevRelaySilentPair_testLocalConnectStartResolvesRememberedCredentials();
        },
            'DevRelaySilentPair::testDevRelayJsSurfacesEmptyTokenHint' => function () {
            $this->assert_DevRelaySilentPair_testDevRelayJsSurfacesEmptyTokenHint();
        },
            'DevRelaySilentPair::testSettingsServiceExists' => function () {
            $this->assert_DevRelaySilentPair_testSettingsServiceExists();
        },
            'DevRelaySilentPair::testCliCommandsExist' => function () {
            $this->assert_DevRelaySilentPair_testCliCommandsExist();
        },
            'DevRelayStream::testSseEventNamesAreStable' => function () {
            $this->assert_DevRelayStream_testSseEventNamesAreStable();
        },
            'DevRelayStream::testFetchPayloadUsesBase64RawBody' => function () {
            $this->assert_DevRelayStream_testFetchPayloadUsesBase64RawBody();
        },
            'ExpressAwaitingConfirm::testEvaluateExpressProfileCoreAndGaps' => function () {
            $this->assert_ExpressAwaitingConfirm_testEvaluateExpressProfileCoreAndGaps();
        },
            'ExpressAwaitingConfirm::testLandingOrchestratorDefinesExpressReview' => function () {
            $this->assert_ExpressAwaitingConfirm_testLandingOrchestratorDefinesExpressReview();
        },
            'ExpressAwaitingConfirm::testPayPalExpressUsesContinueAndPatchOrder' => function () {
            $this->assert_ExpressAwaitingConfirm_testPayPalExpressUsesContinueAndPatchOrder();
        },
            'ExpressAwaitingConfirm::testProductExpressJsOpensProviderWindow' => function () {
            $this->assert_ExpressAwaitingConfirm_testProductExpressJsOpensProviderWindow();
        },
            'ExpressCheckoutOrchestrator::testCapabilityConstantAndFacadeBinding' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testCapabilityConstantAndFacadeBinding();
        },
            'ExpressCheckoutOrchestrator::testPayPalMapperExtractsShippingProfile' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testPayPalMapperExtractsShippingProfile();
        },
            'ExpressCheckoutOrchestrator::testPayPalProviderDeclaresExpressCapability' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testPayPalProviderDeclaresExpressCapability();
        },
            'ExpressCheckoutOrchestrator::testApiClientCreateOrderAcceptsExpressOptions' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testApiClientCreateOrderAcceptsExpressOptions();
        },
            'ExpressCheckoutOrchestrator::testEvaluateExpressProfileCoreAndGaps' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testEvaluateExpressProfileCoreAndGaps();
        },
            'ExpressCheckoutOrchestrator::testBrowserReturnAppliesExpressFacade' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testBrowserReturnAppliesExpressFacade();
        },
            'ExpressCheckoutOrchestrator::testExpressWidgetUsesShellList' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testExpressWidgetUsesShellList();
        },
            'ExpressCheckoutOrchestrator::testShellAndMethodExpressConfigKeys' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testShellAndMethodExpressConfigKeys();
        },
            'ExpressCheckoutOrchestrator::testApplyExpressProfileRequiresExpressFlag' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testApplyExpressProfileRequiresExpressFlag();
        },
            'ExpressCheckoutOrchestrator::testWithExpressContextMergesWhenSupports' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testWithExpressContextMergesWhenSupports();
        },
            'ExpressCheckoutOrchestrator::testAbandonExpressPaymentDeclaredOnFacade' => function () {
            $this->assert_ExpressCheckoutOrchestrator_testAbandonExpressPaymentDeclaredOnFacade();
        },
            'PayPalJsSdkFunding::testBothEnabledWritesEnableFundingOnly' => function () {
            $this->assert_PayPalJsSdkFunding_testBothEnabledWritesEnableFundingOnly();
        },
            'PayPalJsSdkFunding::testGoogleOffDisablesGooglepay' => function () {
            $this->assert_PayPalJsSdkFunding_testGoogleOffDisablesGooglepay();
        },
            'PayPalJsSdkFunding::testAppleOffDisablesApplepay' => function () {
            $this->assert_PayPalJsSdkFunding_testAppleOffDisablesApplepay();
        },
            'PayPalJsSdkFunding::testBothOffDisablesBoth' => function () {
            $this->assert_PayPalJsSdkFunding_testBothOffDisablesBoth();
        },
            'PayPalJsSdkFunding::testMissingClientIdReturnsEmpty' => function () {
            $this->assert_PayPalJsSdkFunding_testMissingClientIdReturnsEmpty();
        },
            'PayPalJsSdkFunding::testSandboxClientIdFallback' => function () {
            $this->assert_PayPalJsSdkFunding_testSandboxClientIdFallback();
        },
            'PayPalSandboxAuthorizeGuideUrl::testSandboxAuthorizeGuideUrlContainsLocateParamsAndAbsoluteOrigin' => function () {
            $this->assert_PayPalSandboxAuthorizeGuideUrl_testSandboxAuthorizeGuideUrlContainsLocateParamsAndAbsoluteOrigin();
        },
            'PayPalUnifiedBrowserReturnUri::testCatalogAndOauthShareMethodScopedCallbackReturnRoute' => function () {
            $this->assert_PayPalUnifiedBrowserReturnUri_testCatalogAndOauthShareMethodScopedCallbackReturnRoute();
        },
            'PayPalWebhookVerify::testVerifyCallbackSourceHasNoOutboundVerify' => function () {
            $this->assert_PayPalWebhookVerify_testVerifyCallbackSourceHasNoOutboundVerify();
        },
            'PayPalWebhookVerify::testVerifyRejectsWhenWebhookIdSetButLocalMaterialsMissing' => function () {
            $this->assert_PayPalWebhookVerify_testVerifyRejectsWhenWebhookIdSetButLocalMaterialsMissing();
        },
            'PayPalWebhookVerify::testVerifyFailClosedWithoutMaterialsAndWithoutAllowFlag' => function () {
            $this->assert_PayPalWebhookVerify_testVerifyFailClosedWithoutMaterialsAndWithoutAllowFlag();
        },
            'PayPalWebhookVerify::testVerifyAllowsExplicitUnsignedTestSwitch' => function () {
            $this->assert_PayPalWebhookVerify_testVerifyAllowsExplicitUnsignedTestSwitch();
        },
            'PayPalWebhookVerify::testVerifyAcceptsValidLocalCertSignature' => function () {
            $this->assert_PayPalWebhookVerify_testVerifyAcceptsValidLocalCertSignature();
        },
            'PayPalWebhookVerify::testApiClientVerifyPostsOfficialPath' => function () {
            $this->assert_PayPalWebhookVerify_testApiClientVerifyPostsOfficialPath();
        },
            'PaymentAssetPolicySpi::testBuildAssetPolicyPreservesRequiredOrderTypes' => function () {
            $this->assert_PaymentAssetPolicySpi_testBuildAssetPolicyPreservesRequiredOrderTypes();
        },
            'PaymentAssetPolicySpi::testBuildAssetPolicyPreservesMinCashDepositBps' => function () {
            $this->assert_PaymentAssetPolicySpi_testBuildAssetPolicyPreservesMinCashDepositBps();
        },
            'PaymentAssetPolicySpi::testAssertAssetPayableAllowedRequiresOrderType' => function () {
            $this->assert_PaymentAssetPolicySpi_testAssertAssetPayableAllowedRequiresOrderType();
        },
            'PaymentAssetPolicySpi::testProviderCapabilityPrefixIsStable' => function () {
            $this->assert_PaymentAssetPolicySpi_testProviderCapabilityPrefixIsStable();
        },
            'PaymentAssetPolicySpi::testEmptyPolicyOverrideDoesNotShortCircuitSpiMerge' => function () {
            $this->assert_PaymentAssetPolicySpi_testEmptyPolicyOverrideDoesNotShortCircuitSpiMerge();
        },
            'PaymentBrowserHandoffMount::testExpressToSuccessRewriteKeepsDaocharmsMount' => function () {
            $this->assert_PaymentBrowserHandoffMount_testExpressToSuccessRewriteKeepsDaocharmsMount();
        },
            'PaymentBrowserHandoffMount::testBareHostRootSuccessIsRebuiltUnderFrozenMount' => function () {
            $this->assert_PaymentBrowserHandoffMount_testBareHostRootSuccessIsRebuiltUnderFrozenMount();
        },
            'PaymentBrowserHandoffMount::testHandoffSourceForbidsBareHostRootSuccessRewrite' => function () {
            $this->assert_PaymentBrowserHandoffMount_testHandoffSourceForbidsBareHostRootSuccessRewrite();
        },
            'PaymentBrowserHandoffMount::testPersistenceFreezesWebsiteContextKeys' => function () {
            $this->assert_PaymentBrowserHandoffMount_testPersistenceFreezesWebsiteContextKeys();
        },
            'PaymentBrowserHandoffMount::testScopeConfigResolvesWebsiteId' => function () {
            $this->assert_PaymentBrowserHandoffMount_testScopeConfigResolvesWebsiteId();
        },
            'PaymentBrowserHandoffMount::testOrchestratorBindsAbsoluteUnderStorefront' => function () {
            $this->assert_PaymentBrowserHandoffMount_testOrchestratorBindsAbsoluteUnderStorefront();
        },
            'PaymentBrowserHandoffMount::testCallbackCatalogHonorsStorefrontBase' => function () {
            $this->assert_PaymentBrowserHandoffMount_testCallbackCatalogHonorsStorefrontBase();
        },
            'PaymentBrowserReturnLanding::testDispatcherSourceDoesNotRedirectToCheckoutReturn' => function () {
            $this->assert_PaymentBrowserReturnLanding_testDispatcherSourceDoesNotRedirectToCheckoutReturn();
        },
            'PaymentBrowserReturnLanding::testLandingOrchestratorDefinesL1AndL2Paths' => function () {
            $this->assert_PaymentBrowserReturnLanding_testLandingOrchestratorDefinesL1AndL2Paths();
        },
            'PaymentBrowserReturnResumeContext::testDispatcherInjectsStoredProviderPayloadIntoResumeContext' => function () {
            $this->assert_PaymentBrowserReturnResumeContext_testDispatcherInjectsStoredProviderPayloadIntoResumeContext();
        },
            'PaymentBrowserReturnResumeContext::testProviderReadsPresentmentAmountFromContextPayload' => function () {
            $this->assert_PaymentBrowserReturnResumeContext_testProviderReadsPresentmentAmountFromContextPayload();
        },
            'PaymentCaptureReaderBridge::testRefundLoaderFallsBackToCaptureReaderEnsure' => function () {
            $this->assert_PaymentCaptureReaderBridge_testRefundLoaderFallsBackToCaptureReaderEnsure();
        },
            'PaymentCaptureReaderBridge::testSuccessPathsEnsureCaptureReader' => function () {
            $this->assert_PaymentCaptureReaderBridge_testSuccessPathsEnsureCaptureReader();
        },
            'PaymentCaptureReaderBridge::testBackendRecordsEnsureThenMergeAttemptAndTransaction' => function () {
            $this->assert_PaymentCaptureReaderBridge_testBackendRecordsEnsureThenMergeAttemptAndTransaction();
        },
            'PaymentIncentivePassthroughCapability::testPayPalDeclaresBreakdownCapabilitiesDistinctFromDiscountActions' => function () {
            $this->assert_PaymentIncentivePassthroughCapability_testPayPalDeclaresBreakdownCapabilitiesDistinctFromDiscountActions();
        },
            'PaymentIncentivePassthroughCapability::testFakeDeclaresShellEchoPassthrough' => function () {
            $this->assert_PaymentIncentivePassthroughCapability_testFakeDeclaresShellEchoPassthrough();
        },
            'PaymentIncentivePassthroughCapability::testPayPalApiClientFormatsBreakdownInCreateOrderPayload' => function () {
            $this->assert_PaymentIncentivePassthroughCapability_testPayPalApiClientFormatsBreakdownInCreateOrderPayload();
        },
            'PaymentIncentivePassthroughCapability::testFakeCreatePaymentEchoesBreakdown' => function () {
            $this->assert_PaymentIncentivePassthroughCapability_testFakeCreatePaymentEchoesBreakdown();
        },
            'PaymentIncentivePassthroughCapability::testEventAndConfigTemplatesDocumented' => function () {
            $this->assert_PaymentIncentivePassthroughCapability_testEventAndConfigTemplatesDocumented();
        },
            'PaymentMethodIncentiveQuote::testDisabledConfigYieldsZero' => function () {
            $this->assert_PaymentMethodIncentiveQuote_testDisabledConfigYieldsZero();
        },
            'PaymentMethodIncentiveQuote::testFixedAmountQuoteAndListPayload' => function () {
            $this->assert_PaymentMethodIncentiveQuote_testFixedAmountQuoteAndListPayload();
        },
            'PaymentMethodIncentiveQuote::testPercentageWithCap' => function () {
            $this->assert_PaymentMethodIncentiveQuote_testPercentageWithCap();
        },
            'PaymentMethodIncentiveQuote::testApplyToOrderDataMergesIncentiveLineAndLowersPayable' => function () {
            $this->assert_PaymentMethodIncentiveQuote_testApplyToOrderDataMergesIncentiveLineAndLowersPayable();
        },
            'PaymentMethodIncentiveQuote::testApplyToOrderDataIsIdempotentWhenIncentiveLineAlreadyPresent' => function () {
            $this->assert_PaymentMethodIncentiveQuote_testApplyToOrderDataIsIdempotentWhenIncentiveLineAlreadyPresent();
        },
            'PaymentMethodIncentiveQuote::testApplyToOrderDataPreservesCouponDiscountWhenLinesEmpty' => function () {
            $this->assert_PaymentMethodIncentiveQuote_testApplyToOrderDataPreservesCouponDiscountWhenLinesEmpty();
        },
            'PaymentMethodIncentiveQuote::testUnavailableMethodDoesNotAdvertiseIncentive' => function () {
            $this->assert_PaymentMethodIncentiveQuote_testUnavailableMethodDoesNotAdvertiseIncentive();
        },
            'PaymentProcessCache::testMethodManagerOwnsProcessBagAndClear' => function () {
            $this->assert_PaymentProcessCache_testMethodManagerOwnsProcessBagAndClear();
        },
            'PaymentProcessCache::testScopeConfigServiceOwnsOverrideProcessBag' => function () {
            $this->assert_PaymentProcessCache_testScopeConfigServiceOwnsOverrideProcessBag();
        },
            'PaymentProcessCache::testProcessCacheResetterIsRegistered' => function () {
            $this->assert_PaymentProcessCache_testProcessCacheResetterIsRegistered();
        },
            'PaymentProviderControllerRouteScanner::testForbiddenControllerMethodsAreBlocked' => function () {
            $this->assert_PaymentProviderControllerRouteScanner_testForbiddenControllerMethodsAreBlocked();
        },
            'PaymentProviderControllerRouteScanner::testScanDoesNotRegisterForbiddenRoutes' => function () {
            $this->assert_PaymentProviderControllerRouteScanner_testScanDoesNotRegisterForbiddenRoutes();
        },
            'PaymentServiceRefundMinor::testRefundPrimarySignatureUsesAmountMinorInt' => function () {
            $this->assert_PaymentServiceRefundMinor_testRefundPrimarySignatureUsesAmountMinorInt();
        },
            'PaymentShellAntiLeak::testCallbackDoesNotImportGatewayOauth' => function () {
            $this->assert_PaymentShellAntiLeak_testCallbackDoesNotImportGatewayOauth();
        },
            'PaymentShellAntiLeak::testPayPalProviderImplementsConnectAndFakeDoesNot' => function () {
            $this->assert_PaymentShellAntiLeak_testPayPalProviderImplementsConnectAndFakeDoesNot();
        },
            'PaymentShellAntiLeak::testConnectControllerRequiresMethodCode' => function () {
            $this->assert_PaymentShellAntiLeak_testConnectControllerRequiresMethodCode();
        },
            'PaymentShellAntiLeak::testLegacyPayPalControllersRemoved' => function () {
            $this->assert_PaymentShellAntiLeak_testLegacyPayPalControllersRemoved();
        },
            'PaymentShellAntiLeak::testPaymentServiceInjectsCatalogUrls' => function () {
            $this->assert_PaymentShellAntiLeak_testPaymentServiceInjectsCatalogUrls();
        },
            'PaymentShellAntiLeak::testApiPaymentCreateDoesNotHandBuildCheckoutReturn' => function () {
            $this->assert_PaymentShellAntiLeak_testApiPaymentCreateDoesNotHandBuildCheckoutReturn();
        },
            'PaymentShellCallbackUrlCatalog::testCatalogSourceDefinesShellRoutes' => function () {
            $this->assert_PaymentShellCallbackUrlCatalog_testCatalogSourceDefinesShellRoutes();
        },
            'PaymentShellCallbackUrlCatalog::testCancelUsesOutcomeOnSamePath' => function () {
            $this->assert_PaymentShellCallbackUrlCatalog_testCancelUsesOutcomeOnSamePath();
        },
            'PaymentShellCallbackUrlCatalog::testWithTargetScopeAppendsQuery' => function () {
            $this->assert_PaymentShellCallbackUrlCatalog_testWithTargetScopeAppendsQuery();
        },
            'PaymentShellCallbackUrlCatalog::testCatalogCancelAppendsOutcome' => function () {
            $this->assert_PaymentShellCallbackUrlCatalog_testCatalogCancelAppendsOutcome();
        },
            'PaymentShellCallbackUrlCatalog::testReturnContextResolverPrefersShellToken' => function () {
            $this->assert_PaymentShellCallbackUrlCatalog_testReturnContextResolverPrefersShellToken();
        },
            'PaymentShellCallbackUrlCatalog::testReturnContextResolverAcceptsExplicitCodes' => function () {
            $this->assert_PaymentShellCallbackUrlCatalog_testReturnContextResolverAcceptsExplicitCodes();
        },
            'PaymentShellIdempotency::testOauthCompleteKeepsCompletedResultForReplay' => function () {
            $this->assert_PaymentShellIdempotency_testOauthCompleteKeepsCompletedResultForReplay();
        },
            'PaymentShellIdempotency::testWebhookDocCrossLinksShellIdempotency' => function () {
            $this->assert_PaymentShellIdempotency_testWebhookDocCrossLinksShellIdempotency();
        },
            'PaymentShellIdempotency::testShellDocRequiresCheckoutIdempotencyKey' => function () {
            $this->assert_PaymentShellIdempotency_testShellDocRequiresCheckoutIdempotencyKey();
        },
            'PaymentShipmentTrackingShell::testPayPalImplementsTrackingAndFakeDoesNot' => function () {
            $this->assert_PaymentShipmentTrackingShell_testPayPalImplementsTrackingAndFakeDoesNot();
        },
            'PaymentShipmentTrackingShell::testEventXmlUsesShellObserverNotPayPalHardcode' => function () {
            $this->assert_PaymentShipmentTrackingShell_testEventXmlUsesShellObserverNotPayPalHardcode();
        },
            'PaymentShipmentTrackingShell::testShellObserverDelegatesToDispatcher' => function () {
            $this->assert_PaymentShipmentTrackingShell_testShellObserverDelegatesToDispatcher();
        },
            'PaymentShipmentTrackingShell::testDispatcherResolvesByMethodCode' => function () {
            $this->assert_PaymentShipmentTrackingShell_testDispatcherResolvesByMethodCode();
        },
            'PaymentSumCapturedAmount::testFacadeDeclaresSumAndAbandonOpenIntents' => function () {
            $this->assert_PaymentSumCapturedAmount_testFacadeDeclaresSumAndAbandonOpenIntents();
        },
            'WebsiteBenchmarkCurrencyResolverSnapshot::testReadWebsiteDefaultUsesWebsiteDataSnapshot' => function () {
            $this->assert_WebsiteBenchmarkCurrencyResolverSnapshot_testReadWebsiteDefaultUsesWebsiteDataSnapshot();
        },
        ];
    }

private function assert_DevRelayDropshipBridge_testEventStoreLoadsDropshipInboxPrefix(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/DevRelayEventStore.php');
    self::assertIsString($src);
    self::assertStringContainsString("str_starts_with(\$inboxCode, 'dropship:')", $src);
    self::assertStringContainsString("'module' => 'dropship'", $src);
    self::assertStringContainsString('raw_body', $src);
}

private function assert_DevRelayDropshipBridge_testLocalConnectReplaysDropshipNotify(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/DevRelayLocalConnectService.php');
    self::assertIsString($src);
    self::assertStringContainsString('resolveDropshipNotifyUrl', $src);
    self::assertStringContainsString('/dropship/frontend/callback/notify', $src);
    self::assertStringContainsString("\$module === 'dropship'", $src);
}

private function assert_DevRelayDropshipBridge_testStreamPayloadIncludesModule(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/DevRelayStreamService.php');
    self::assertIsString($src);
    self::assertStringContainsString("'module' =>", $src);
    self::assertStringContainsString("'inbox_code' =>", $src);
}

private function assert_DevRelaySilentPair_testGateExposesOnlineBaseUrlConfigKey(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/DevRelayGateService.php');
    self::assertIsString($src);
    self::assertStringContainsString("'online_base_url'", $src);
    self::assertStringContainsString('allow_on_production', $src);
}

private function assert_DevRelaySilentPair_testSessionServiceDefinesPublicStreamHelpers(): void
{
    self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicStreamUrl'));
    self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicEventFetchUrl'));
    self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicAckUrl'));
    self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicPairUrl'));
}

private function assert_DevRelaySilentPair_testPairServiceApiSurface(): void
{
    self::assertTrue(method_exists(DevRelayPairService::class, 'pairFromRequest'));
    self::assertTrue(method_exists(DevRelayPairService::class, 'closeFromRequest'));
    self::assertTrue(method_exists(DevRelayPairService::class, 'ensureUserApiToken'));
    self::assertTrue(method_exists(DevRelayPairService::class, 'extractBearerToken'));
}

private function assert_DevRelaySilentPair_testFrontendDevRelayExposesSilentEndpoints(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/DevRelay.php');
    self::assertIsString($src);
    self::assertStringContainsString('function pair(', $src);
    self::assertStringContainsString('function stream(', $src);
    self::assertStringContainsString('function event(', $src);
    self::assertStringContainsString('function ack(', $src);
    self::assertStringContainsString('function updateInbound(', $src);
    self::assertStringContainsString('function workerStatus(', $src);
    self::assertStringContainsString('function workerStart(', $src);
    self::assertStringContainsString('function workerStop(', $src);
    self::assertStringContainsString('function panelProbe(', $src);
    self::assertStringContainsString('function demo(', $src);
}

private function assert_DevRelaySilentPair_testPanelHookExposesSendProbeControlsAsAdvancedMaintenanceChild(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/search-areas-after.phtml');
    self::assertIsString($src);
    self::assertStringContainsString('weline-devrelay-send', $src);
    self::assertStringContainsString('/payment/dev-relay/panel-probe', $src);
    self::assertStringContainsString('发送探测', $src);
    self::assertStringContainsString('payment-dev-relay-panel__split', $src);
    self::assertStringContainsString('WelineAdvancedMaintenance', $src);
    self::assertStringContainsString('registerChild', $src);
    self::assertStringContainsString("CHILD_ID = 'payment-dev-relay'", $src);
    self::assertStringContainsString('weline-devrelay-webhook-search', $src);
    self::assertStringContainsString('weline-devrelay-webhook-select', $src);
    self::assertStringContainsString('provider_webhooks', file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/DevRelay.php'));
    self::assertStringContainsString('defaultProviderWebhookEndpoints', file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/DevRelay.php'));
    self::assertStringContainsString('PaymentMethodManager', file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/DevRelay.php'));
    self::assertStringContainsString('Provider Webhook（复制到支付后台）', $src);
    self::assertStringNotContainsString('复制到 PayPal', $src);
    self::assertStringNotContainsString('<details>', $src);
    self::assertStringNotContainsString('WelinePanel.registerTab', $src);
    self::assertStringNotContainsString('weline-devrelay-token', $src);
}

private function assert_DevRelaySilentPair_testDemoTemplateExists(): void
{
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/frontend/dev-relay/demo.phtml');
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/frontend/dev-relay/demo.phtml');
    self::assertIsString($src);
    self::assertStringContainsString('DevRelay 探针演示', $src);
    self::assertStringContainsString('probePath', $src);
}

private function assert_DevRelaySilentPair_testIndexTemplateCopiesWebsiteBaseUrlAndToken(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/DevRelay/index.phtml');
    self::assertIsString($src);
    self::assertStringContainsString('dev-relay-website-base-url', $src);
    self::assertStringContainsString('dev-relay-user-api-token', $src);
    self::assertStringContainsString('dev-relay-worker-start', $src);
    self::assertStringContainsString('online_base_url', $src);
    self::assertStringContainsString('dev-relay-enabled', $src);
    self::assertStringContainsString('dev-relay-save-settings', $src);
    self::assertStringContainsString('postSaveSettings', $src);
    // 本机静默中继操作区不得被未启用时的 pointer-events 整块锁死
    self::assertStringContainsString('data-testid="payment-dev-relay-local-actions"', $src);
    self::assertStringContainsString('dev-relay-token-hint', $src);
    self::assertStringContainsString('payment-dev-relay-worker-summary', $src);
    self::assertStringContainsString('payment-dev-relay-worker-summary-badge', $src);
    self::assertStringContainsString('技术详情（JSON）', $src);
    self::assertStringNotContainsString("data-role=\"relay-actions\"\n            class=\"w-stack <?= \$feature_enabled ? '' : 'is-disabled' ?>\"\n            style=\"--w-gap:var(--weline-space-4);<?= \$feature_enabled ? '' : 'opacity:0.55;pointer-events:none;' ?>\"", $src);
}

private function assert_DevRelaySilentPair_testBackendWorkerStartReadsJsonBodyAndEnablesFirst(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/DevRelay.php');
    self::assertIsString($src);
    self::assertStringContainsString('function postWorkerStart(', $src);
    self::assertStringContainsString('jsonBody()', $src);
    self::assertMatchesRegularExpression('/function postWorkerStart\([\s\S]*?settings->save\([\s\S]*?\'enabled\' => true[\s\S]*?localWorker->start\(/', $src);
    self::assertStringContainsString('readRememberedCredentials', $src);
}

private function assert_DevRelaySilentPair_testFrontendWorkerStartEnablesBeforeStart(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/DevRelay.php');
    self::assertIsString($src);
    self::assertMatchesRegularExpression('/function workerStart\([\s\S]*?settings->save\([\s\S]*?\'enabled\' => true[\s\S]*?localWorker->start\(/', $src);
}

private function assert_DevRelaySilentPair_testLocalConnectStartResolvesRememberedCredentials(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/DevRelayLocalConnectService.php');
    self::assertIsString($src);
    self::assertMatchesRegularExpression('/function start\([\s\S]*?readRememberedCredentials\(/', $src);
    self::assertStringContainsString('isLocalEnvironment()', $src);
}

private function assert_DevRelaySilentPair_testDevRelayJsSurfacesEmptyTokenHint(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/statics/js/backend/dev-relay.js');
    self::assertIsString($src);
    self::assertStringContainsString('请填写线上用户 API Token', $src);
    self::assertStringContainsString('showLocalFeedback', $src);
    self::assertStringContainsString('dev-relay-local-feedback', $src);
    self::assertStringContainsString('dev-relay-worker-stop', $src);
    self::assertStringContainsString('payment-dev-relay-worker-summary', $src);
    self::assertStringContainsString('连接详情', $src);
    self::assertStringContainsString('技术详情（JSON）', $src);
}

private function assert_DevRelaySilentPair_testSettingsServiceExists(): void
{
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/DevRelaySettingsService.php');
    $gate = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/DevRelayGateService.php');
    self::assertIsString($gate);
    self::assertStringContainsString('DevRelaySettingsService', $gate);
    self::assertStringContainsString('canOpenConsolePage', $gate);
}

private function assert_DevRelaySilentPair_testCliCommandsExist(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Console/Payment/DevRelay';
    self::assertFileExists($base . '/Start.php');
    self::assertFileExists($base . '/Stop.php');
    self::assertFileExists($base . '/Status.php');
    self::assertFileExists($base . '/Run.php');
}

private function assert_DevRelayStream_testSseEventNamesAreStable(): void
{
    $events = ['session.open', 'webhook.relay', 'command.result', 'relay.ok', 'relay.fail', 'ping'];
    self::assertSame(['session.open', 'webhook.relay', 'command.result', 'relay.ok', 'relay.fail', 'ping'], $events);
}

private function assert_DevRelayStream_testFetchPayloadUsesBase64RawBody(): void
{
    $sample = ['raw_body' => base64_encode('{"event":"test"}'), 'raw_body_encoding' => 'base64'];
    self::assertSame('base64', $sample['raw_body_encoding']);
    self::assertSame('{"event":"test"}', base64_decode($sample['raw_body'], true));
}

private function assert_ExpressAwaitingConfirm_testEvaluateExpressProfileCoreAndGaps(): void
{
    $orch = new ExpressCheckoutOrchestrator();
    $incomplete = $orch->evaluateExpressProfile(['country_code' => 'US']);
    self::assertFalse($incomplete['complete']);
    self::assertContains('contact_name', $incomplete['missing_fields']);
    self::assertContains('address1', $incomplete['missing_fields']);
    $core = $orch->evaluateExpressProfile(['contact_name' => 'Ada', 'address1' => '1 St', 'country_code' => 'US']);
    self::assertTrue($core['complete']);
    self::assertContains('contact_phone', $core['missing_fields']);
    self::assertSame(PaymentExpressFacadeInterface::META_AWAITING_CONFIRM, ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM);
    self::assertTrue(ExpressCheckoutOrchestrator::isExpressAwaitingConfirm(['metadata' => [ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM => 1]]));
}

private function assert_ExpressAwaitingConfirm_testLandingOrchestratorDefinesExpressReview(): void
{
    self::assertSame('express_review', PaymentBrowserReturnLandingOrchestrator::DECISION_EXPRESS_REVIEW);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnLandingOrchestrator.php');
    self::assertStringContainsString('checkout/express-review', $src);
    self::assertStringContainsString('decideExpressReview', $src);
}

private function assert_ExpressAwaitingConfirm_testPayPalExpressUsesContinueAndPatchOrder(): void
{
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php');
    self::assertStringContainsString("'user_action' => \$express ? 'CONTINUE' : 'PAY_NOW'", $provider);
    self::assertStringContainsString('express_prepare_only', $provider);
    self::assertStringContainsString('express_confirm_capture', $provider);
    $client = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PayPalApiClient.php');
    self::assertStringContainsString('function patchOrder', $client);
}

private function assert_ExpressAwaitingConfirm_testProductExpressJsOpensProviderWindow(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/statics/js/product-express-pay.js');
    self::assertStringContainsString('startExpressCheckout', $js);
    self::assertStringContainsString('weline_express_pay', $js);
    // Popup must be opened synchronously on click (about:blank), then navigated
    // after startExpressCheckout — async window.open is blocked by browsers.
    self::assertStringContainsString('openBlankProviderWindow', $js);
    self::assertStringContainsString('navigateProviderWindow', $js);
    self::assertStringContainsString("'about:blank'", $js);
    self::assertStringNotContainsString('buyNow.click', $js);
    self::assertStringNotContainsString("searchParams.set('express_pay'", $js);
    self::assertStringNotContainsString('location.assign(target)', $js);
    // PDP 快捷支付只结当前商品：传 buy_now，禁止先 cart.add 再结整车。
    self::assertStringContainsString('buy_now: true', $js);
    self::assertStringContainsString('product_express: true', $js);
    self::assertStringNotContainsString('cartApi.add', $js);
}

private function assert_ExpressCheckoutOrchestrator_testCapabilityConstantAndFacadeBinding(): void
{
    self::assertSame('express_checkout', ExpressCheckoutOrchestrator::CAPABILITY_EXPRESS);
    self::assertSame('payment.express_address_sink.', PaymentExpressAddressSinkInterface::CAPABILITY_PREFIX);
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/etc/module.php';
    self::assertSame(ExpressCheckoutOrchestrator::class, $module['provides'][PaymentExpressFacadeInterface::class] ?? null);
    $checkoutModule = include '/Users/weline/Project/Official/框架/app/code/Weline' . '/Checkout/etc/module.php';
    self::assertSame(\Weline\Checkout\Service\CheckoutPaymentExpressAddressSink::class, $checkoutModule['provides']['payment.express_address_sink.Weline_Checkout'] ?? null);
}

private function assert_ExpressCheckoutOrchestrator_testPayPalMapperExtractsShippingProfile(): void
{
    $mapper = new PayPalExpressProfileMapper();
    $profile = $mapper->fromOrderPayload(['payer' => ['email_address' => 'buyer@example.com', 'name' => ['given_name' => 'Ada', 'surname' => 'Lovelace']], 'purchase_units' => [['shipping' => ['name' => ['full_name' => 'Ada Lovelace'], 'address' => ['address_line_1' => '1 Market St', 'admin_area_1' => 'CA', 'admin_area_2' => 'San Francisco', 'postal_code' => '94105', 'country_code' => 'US']]]]]);
    self::assertIsArray($profile);
    self::assertSame('Ada Lovelace', $profile['name'] ?? null);
    self::assertSame('1 Market St', $profile['address1'] ?? null);
    self::assertSame('US', $profile['country_code'] ?? null);
    self::assertSame('buyer@example.com', $profile['email'] ?? null);
    self::assertSame('paypal_express', $profile['source'] ?? null);
}

private function assert_ExpressCheckoutOrchestrator_testPayPalProviderDeclaresExpressCapability(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php');
    self::assertStringContainsString("'express_checkout' => true", $src);
    self::assertStringContainsString('GET_FROM_FILE', $src);
    self::assertStringContainsString('NO_SHIPPING', $src);
    self::assertStringContainsString("'user_action' => \$express ? 'CONTINUE' : 'PAY_NOW'", $src);
    self::assertStringContainsString('express_prepare_only', $src);
    self::assertStringContainsString('express_confirm_capture', $src);
    self::assertStringContainsString('express_awaiting_confirm', $src);
    self::assertStringContainsString('express_profile', $src);
    self::assertStringContainsString('PayPalExpressProfileMapper', $src);
    self::assertStringContainsString('patchOrder', $src);
}

private function assert_ExpressCheckoutOrchestrator_testApiClientCreateOrderAcceptsExpressOptions(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PayPalApiClient.php');
    self::assertStringContainsString('shipping_preference', $src);
    self::assertStringContainsString('express_checkout', $src);
    self::assertStringContainsString('GET_FROM_FILE', $src);
    self::assertStringContainsString('function patchOrder', $src);
}

private function assert_ExpressCheckoutOrchestrator_testEvaluateExpressProfileCoreAndGaps(): void
{
    $orch = new ExpressCheckoutOrchestrator();
    self::assertSame(PaymentExpressFacadeInterface::META_AWAITING_CONFIRM, ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM);
    self::assertSame('express_awaiting_confirm', ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM);
    $complete = $orch->evaluateExpressProfile(['name' => 'Ada', 'address1' => '1 Market St', 'country_code' => 'US', 'phone' => '555', 'email' => 'a@b.c']);
    self::assertTrue($complete['complete']);
    self::assertTrue($complete['requires_shipping']);
    self::assertSame([], $complete['missing_fields']);
    $gaps = $orch->evaluateExpressProfile(['contact_name' => 'Ada', 'street' => '1 Market St', 'country_code' => 'US']);
    self::assertTrue($gaps['complete']);
    self::assertContains('contact_phone', $gaps['missing_fields']);
    self::assertContains('email', $gaps['missing_fields']);
    $incomplete = $orch->evaluateExpressProfile(['email' => 'a@b.c']);
    self::assertFalse($incomplete['complete']);
    self::assertContains('contact_name', $incomplete['missing_fields']);
    self::assertContains('address1', $incomplete['missing_fields']);
    self::assertContains('country_code', $incomplete['missing_fields']);
    $digital = $orch->evaluateExpressProfile([], false);
    self::assertTrue($digital['complete']);
    self::assertFalse($digital['requires_shipping']);
    self::assertSame([], $digital['missing_fields']);
    self::assertTrue(ExpressCheckoutOrchestrator::isExpressAwaitingConfirm(['metadata' => [ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM => 1]]));
    self::assertFalse(ExpressCheckoutOrchestrator::isExpressAwaitingConfirm(['metadata' => []]));
}

private function assert_ExpressCheckoutOrchestrator_testBrowserReturnAppliesExpressFacade(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnDispatcher.php');
    self::assertStringContainsString('PaymentExpressFacadeInterface', $src);
    self::assertStringContainsString('applyExpressProfileFromPaymentResult', $src);
    self::assertStringContainsString('express_prepare_only', $src);
    self::assertStringContainsString('decideExpressReview', $src);
    self::assertStringContainsString('META_AWAITING_CONFIRM', $src);
}

private function assert_ExpressCheckoutOrchestrator_testExpressWidgetUsesShellList(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/widgets/checkout-express-payment.phtml');
    self::assertStringContainsString('PaymentExpressFacadeInterface', $tpl);
    self::assertStringContainsString('listExpressMethods', $tpl);
    self::assertStringContainsString('data-express-pay', $tpl);
    self::assertStringNotContainsString("\$expressMethods = [[\n        'method_code' => \$configMethod", $tpl);
    self::assertMatchesRegularExpression('/if \(\$expressMethods === \[\]\) \{\s*return;/s', $tpl);
}

private function assert_ExpressCheckoutOrchestrator_testShellAndMethodExpressConfigKeys(): void
{
    self::assertSame('payment/general/express_checkout_enabled', ExpressCheckoutOrchestrator::CONFIG_SHELL_EXPRESS_ENABLED);
    $general = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_SystemConfig/Config/backend/general.phtml');
    self::assertStringContainsString('payment/general/express_checkout_enabled', $general);
    self::assertStringContainsString('启用快捷智能支付', $general);
    $orch = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/ExpressCheckoutOrchestrator.php');
    self::assertStringContainsString('isShellExpressEnabled', $orch);
    $mgr = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentMethodManager.php');
    self::assertStringContainsString("unset(\$capabilities['express_checkout'])", $mgr);
    self::assertStringContainsString('express_enabled', $mgr);
}

private function assert_ExpressCheckoutOrchestrator_testApplyExpressProfileRequiresExpressFlag(): void
{
    $orch = new ExpressCheckoutOrchestrator();
    $noop = $orch->applyExpressProfileFromPaymentResult(['payload' => ['express_profile' => ['name' => 'Ada', 'address1' => '1 St', 'country_code' => 'US']]], []);
    self::assertFalse($noop['applied']);
    $flagged = $orch->applyExpressProfileFromPaymentResult(['payload' => ['express_checkout' => true, 'express_profile' => ['name' => 'Ada', 'address1' => '1 St', 'country_code' => 'US']]], ['method_code' => 'paypal']);
    // May or may not apply depending on sink registry in unit context.
    self::assertArrayHasKey('applied', $flagged);
    self::assertArrayHasKey('sinks', $flagged);
}

private function assert_ExpressCheckoutOrchestrator_testWithExpressContextMergesWhenSupports(): void
{
    // Unit-level merge helper: when method unsupported, context unchanged.
    $orch = new ExpressCheckoutOrchestrator();
    $unchanged = $orch->withExpressContext(['amount_minor' => 100], 'not_a_real_method');
    self::assertArrayNotHasKey('express_checkout', $unchanged);
}

private function assert_ExpressCheckoutOrchestrator_testAbandonExpressPaymentDeclaredOnFacade(): void
{
    self::assertTrue(method_exists(PaymentExpressFacadeInterface::class, 'abandonExpressPayment'));
    self::assertTrue(method_exists(ExpressCheckoutOrchestrator::class, 'abandonExpressPayment'));
    $orchSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/ExpressCheckoutOrchestrator.php');
    self::assertStringContainsString('function abandonExpressPayment', $orchSrc);
    self::assertStringContainsString('express_abandoned', $orchSrc);
    self::assertStringContainsString('STATUS_FAILED', $orchSrc);
    $checkoutSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Checkout/Service/ExpressCheckoutFlowService.php');
    $abandonStart = strpos($checkoutSrc, 'public function abandon(');
    self::assertNotFalse($abandonStart);
    $abandonEnd = strpos($checkoutSrc, 'public function buildAlreadyPaidResult', $abandonStart);
    self::assertNotFalse($abandonEnd);
    $abandonMethod = substr($checkoutSrc, $abandonStart, $abandonEnd - $abandonStart);
    self::assertStringContainsString('abandonExpressPayment', $abandonMethod);
    self::assertStringNotContainsString('->setData(PaymentTransaction::schema_fields_STATUS', $abandonMethod);
    self::assertStringNotContainsString('->save()', $abandonMethod);
}

private function assert_PayPalJsSdkFunding_testBothEnabledWritesEnableFundingOnly(): void
{
    $url = (new PayPalJsSdkUrlBuilder())->build(['client_id' => 'CLIENT', 'google_pay_enabled' => true, 'apple_pay_enabled' => true, 'default_currency' => 'USD']);
    self::assertStringContainsString('client-id=CLIENT', $url);
    self::assertStringContainsString('enable-funding=', $url);
    self::assertStringContainsString('googlepay', $url);
    self::assertStringContainsString('applepay', $url);
    self::assertStringNotContainsString('disable-funding=', $url);
}

private function assert_PayPalJsSdkFunding_testGoogleOffDisablesGooglepay(): void
{
    $url = (new PayPalJsSdkUrlBuilder())->build(['client_id' => 'CLIENT', 'google_pay_enabled' => false, 'apple_pay_enabled' => true]);
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
    self::assertSame('applepay', $q['enable-funding'] ?? null);
    self::assertSame('googlepay', $q['disable-funding'] ?? null);
}

private function assert_PayPalJsSdkFunding_testAppleOffDisablesApplepay(): void
{
    $url = (new PayPalJsSdkUrlBuilder())->build(['client_id' => 'CLIENT', 'google_pay_enabled' => true, 'apple_pay_enabled' => '0']);
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
    self::assertSame('googlepay', $q['enable-funding'] ?? null);
    self::assertSame('applepay', $q['disable-funding'] ?? null);
}

private function assert_PayPalJsSdkFunding_testBothOffDisablesBoth(): void
{
    $url = (new PayPalJsSdkUrlBuilder())->build(['client_id' => 'CLIENT', 'google_pay_enabled' => false, 'apple_pay_enabled' => false]);
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
    self::assertArrayNotHasKey('enable-funding', $q);
    $disable = explode(',', (string) ($q['disable-funding'] ?? ''));
    self::assertContains('googlepay', $disable);
    self::assertContains('applepay', $disable);
}

private function assert_PayPalJsSdkFunding_testMissingClientIdReturnsEmpty(): void
{
    self::assertSame('', (new PayPalJsSdkUrlBuilder())->build(['google_pay_enabled' => true, 'apple_pay_enabled' => true]));
}

private function assert_PayPalJsSdkFunding_testSandboxClientIdFallback(): void
{
    $url = (new PayPalJsSdkUrlBuilder())->build(['environment' => 'sandbox', 'sandbox_client_id' => 'SB', 'google_pay_enabled' => true, 'apple_pay_enabled' => false]);
    self::assertStringContainsString('client-id=SB', $url);
}

private function assert_PayPalSandboxAuthorizeGuideUrl_testSandboxAuthorizeGuideUrlContainsLocateParamsAndAbsoluteOrigin(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PayPalOAuthService.php');
    self::assertStringContainsString("'guide_key' => 'adapter:paypal.sandbox.authorize'", $src);
    self::assertStringContainsString("'guide_locate' => 'adapter:paypal.sandbox.authorize'", $src);
    self::assertStringContainsString("'guide_key' => 'adapter:paypal.sandbox.authorize'", $src);
    self::assertStringContainsString("'search' => 'paypal'", $src);
    self::assertStringContainsString("'q' => 'paypal'", $src);
    self::assertStringContainsString('resolvePublicOrigin', $src);
    self::assertStringContainsString('isAbsoluteHttpUrl', $src);
    self::assertStringContainsString('backendGuideUrlHasPrefix', $src);
    self::assertStringContainsString('getAreaRoutePrefix', $src);
}

private function assert_PayPalUnifiedBrowserReturnUri_testCatalogAndOauthShareMethodScopedCallbackReturnRoute(): void
{
    self::assertSame('payment/frontend/callback/browser-return-entry', PaymentBrowserCallbackRoutes::RETURN_DISPATCH);
    self::assertSame('payment/frontend/callback/paypal', PaymentBrowserCallbackRoutes::returnRoute('paypal'));
    $catalogSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentRedirectUriCatalog.php');
    self::assertStringContainsString('PaymentShellCallbackUrlCatalog', $catalogSrc);
    self::assertStringNotContainsString('payment/frontend/paypal/return', $catalogSrc);
    self::assertStringNotContainsString('getBackendUrl', $catalogSrc);
    $oauthSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PayPalOAuthService.php');
    self::assertStringContainsString('function browserReturnUrl', $oauthSrc);
    self::assertStringContainsString('browserReturnRegister', $oauthSrc);
    self::assertStringContainsString('PaymentShellCallbackUrlCatalog', $oauthSrc);
    self::assertStringContainsString('requireStorageScope', $oauthSrc);
    $routesSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserCallbackRoutes.php');
    self::assertStringContainsString('QUERY_TARGET_SCOPE', $routesSrc);
    self::assertStringContainsString('returnRoute', $routesSrc);
    self::assertStringContainsString('cancelRoute', $routesSrc);
    self::assertStringContainsString('RETURN_DISPATCH', $routesSrc);
    self::assertStringContainsString('QUERY_OUTCOME', $routesSrc);
    self::assertStringContainsString('OUTCOME_CANCEL', $routesSrc);
    self::assertStringNotContainsString('CANCEL_DISPATCH', $routesSrc);
    self::assertStringNotContainsString("'payment/frontend/callback/return/'", $routesSrc);
    $connectSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/Connect.php');
    self::assertStringContainsString('一键授权缺少显式配置范围', $connectSrc);
    self::assertStringNotContainsString("\$target = \$targetScopeService->resolveFromInput([], false);", $connectSrc);
    $dispatcherSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentConnectDispatcher.php');
    self::assertStringContainsString('已拒绝静默写入 Global', $dispatcherSrc);
    $callbackSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/Callback.php');
    self::assertStringContainsString('function browserReturnEntry()', $callbackSrc);
    self::assertStringContainsString('PaymentBrowserReturnDispatcher', $callbackSrc);
    self::assertStringNotContainsString('PayPalOAuthService', $callbackSrc);
    self::assertStringContainsString('function browserCancelEntry()', $callbackSrc);
    self::assertStringNotContainsString('function return()', $callbackSrc);
    self::assertStringNotContainsString('function cancel()', $callbackSrc);
    self::assertStringNotContainsString('function returnDispatch()', $callbackSrc);
    self::assertTrue(class_exists(PaymentRedirectUriCatalog::class));
    self::assertTrue(is_subclass_of(PayPalSandboxRedirectUriCatalog::class, PaymentRedirectUriCatalog::class));
    self::assertTrue(class_exists(PayPalOAuthService::class));
    self::assertSame('iframe', PaymentOperationResult::NEXT_IFRAME);
}

private function assert_PayPalWebhookVerify_testVerifyCallbackSourceHasNoOutboundVerify(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php');
    $start = strpos($src, 'public function verifyCallback');
    self::assertNotFalse($start);
    $end = strpos($src, 'public function parseCallback', $start);
    self::assertNotFalse($end);
    $method = substr($src, $start, $end - $start);
    // Strip comments so the frozen-name mention in docs does not trip the assertion.
    $codeOnly = preg_replace('~//.*$~m', '', $method) ?? $method;
    $codeOnly = preg_replace('~/\*.*?\*/~s', '', $codeOnly) ?? $codeOnly;
    self::assertStringNotContainsString('verifyWebhookSignature', $codeOnly);
    self::assertStringNotContainsString('getApiClient()', $codeOnly);
    self::assertStringNotContainsString('->request(', $codeOnly);
    self::assertStringContainsString('verifyPayPalTransmissionLocally', $codeOnly);
    self::assertStringContainsString('fail-closed', $method);
    self::assertStringContainsString('allow_unsigned_webhook', $codeOnly);
}

private function assert_PayPalWebhookVerify_testVerifyRejectsWhenWebhookIdSetButLocalMaterialsMissing(): void
{
    $provider = new PayPalProvider();
    $provider->setApiClient(new PayPalApiClient(static function (): array {
        self::fail('verifyCallback must not call PayPalApiClient HTTP');
    }));
    $req = CallbackRequest::fromArray(['raw_body' => json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR), 'payload' => ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], 'headers' => ['PAYPAL-TRANSMISSION-ID' => 'tid', 'PAYPAL-TRANSMISSION-SIG' => base64_encode('sig'), 'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert.pem'], 'context' => ['config' => ['webhook_id' => 'WHID', 'client_id' => 'id', 'client_secret' => 'sec', 'environment' => 'sandbox']]]);
    self::assertFalse($provider->verifyCallback($req)->isVerified());
}

private function assert_PayPalWebhookVerify_testVerifyFailClosedWithoutMaterialsAndWithoutAllowFlag(): void
{
    $provider = new PayPalProvider();
    $req = CallbackRequest::fromArray(['raw_body' => json_encode(['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR), 'payload' => ['id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], 'headers' => [], 'context' => ['config' => []]]);
    $result = $provider->verifyCallback($req);
    self::assertFalse($result->isVerified());
    self::assertStringContainsString('fail-closed', $result->getString(\Weline\Payment\Api\Data\CallbackResult::FIELD_MESSAGE));
}

private function assert_PayPalWebhookVerify_testVerifyAllowsExplicitUnsignedTestSwitch(): void
{
    $provider = new PayPalProvider();
    $req = CallbackRequest::fromArray(['raw_body' => json_encode(['id' => 'WH-2b', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR), 'payload' => ['id' => 'WH-2b', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], 'headers' => [], 'context' => ['allow_unsigned_webhook' => true, 'config' => []]]);
    self::assertTrue($provider->verifyCallback($req)->isVerified());
}

private function assert_PayPalWebhookVerify_testVerifyAcceptsValidLocalCertSignature(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    self::assertNotFalse($key);
    $csr = openssl_csr_new(['commonName' => 'paypal-test'], $key, ['digest_alg' => 'sha256']);
    self::assertNotFalse($csr);
    $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    self::assertNotFalse($x509);
    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $privatePem);
    $rawBody = json_encode(['id' => 'WH-LOCAL', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], JSON_THROW_ON_ERROR);
    $webhookId = 'WHID-LOCAL';
    $transmissionId = 'tid-local';
    $transmissionTime = '2026-01-01T00:00:00Z';
    $crc = sprintf('%u', crc32($rawBody));
    $message = $transmissionId . '|' . $transmissionTime . '|' . $webhookId . '|' . $crc;
    $ok = openssl_sign($message, $signature, $privatePem, OPENSSL_ALGO_SHA256);
    self::assertTrue($ok);
    $provider = new PayPalProvider();
    $provider->setApiClient(new PayPalApiClient(static function (): array {
        self::fail('local verify must not HTTP');
    }));
    $req = CallbackRequest::fromArray(['raw_body' => $rawBody, 'payload' => ['id' => 'WH-LOCAL', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'], 'headers' => ['PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://unused.example/cert.pem', 'PAYPAL-TRANSMISSION-ID' => $transmissionId, 'PAYPAL-TRANSMISSION-SIG' => base64_encode($signature), 'PAYPAL-TRANSMISSION-TIME' => $transmissionTime], 'context' => ['config' => ['webhook_id' => $webhookId, 'webhook_cert_pem' => $certPem]]]);
    self::assertTrue($provider->verifyCallback($req)->isVerified());
}

private function assert_PayPalWebhookVerify_testApiClientVerifyPostsOfficialPath(): void
{
    // ApiClient remote verify remains available for offline tooling; Provider verifyCallback must not call it.
    $seen = '';
    $client = new PayPalApiClient(static function (string $method, string $url, array $headers, ?string $body) use (&$seen): array {
        if (str_contains($url, '/oauth2/token')) {
            return ['status' => 200, 'body' => json_encode(['access_token' => 't'], JSON_THROW_ON_ERROR)];
        }
        $seen = $url;
        return ['status' => 200, 'body' => json_encode(['verification_status' => 'SUCCESS'], JSON_THROW_ON_ERROR)];
    });
    self::assertTrue($client->verifyWebhookSignature(['environment' => 'sandbox', 'client_id' => 'i', 'client_secret' => 's', 'webhook_id' => 'WID'], '{"id":"WH-3"}', ['auth_algo' => 'SHA256withRSA', 'cert_url' => 'https://api.sandbox.paypal.com/cert.pem', 'transmission_id' => 'tid', 'transmission_sig' => 'sig', 'transmission_time' => '2026-01-01T00:00:00Z']));
    self::assertStringContainsString('/v1/notifications/verify-webhook-signature', $seen);
}

private function assert_PaymentAssetPolicySpi_testBuildAssetPolicyPreservesRequiredOrderTypes(): void
{
    $service = new AssetPaymentService();
    $policy = $service->buildAssetPolicy(['b2b_credit' => ['enabled' => true, 'roles' => [AssetAllocationService::ROLE_PAYMENT => false, AssetAllocationService::ROLE_DISCOUNT => true], 'exchange_ratio' => '1', 'required_order_types' => ['tob']]]);
    self::assertTrue($policy['b2b_credit']['enabled']);
    self::assertTrue($policy['b2b_credit']['roles'][AssetAllocationService::ROLE_DISCOUNT]);
    self::assertSame(['tob'], $policy['b2b_credit']['required_order_types']);
}

private function assert_PaymentAssetPolicySpi_testBuildAssetPolicyPreservesMinCashDepositBps(): void
{
    $service = new AssetPaymentService();
    $policy = $service->buildAssetPolicy(['b2b_credit' => ['enabled' => true, 'roles' => [AssetAllocationService::ROLE_DISCOUNT => true], 'exchange_ratio' => '1', 'max_discount_ratio' => '0.8', 'min_cash_deposit_bps' => 2000, 'required_order_types' => ['tob']]]);
    self::assertSame(2000, $policy['b2b_credit']['min_cash_deposit_bps']);
    self::assertSame('0.8', $policy['b2b_credit']['max_discount_ratio']);
}

private function assert_PaymentAssetPolicySpi_testAssertAssetPayableAllowedRequiresOrderType(): void
{
    $service = new AssetPaymentService();
    $policy = $service->buildAssetPolicy(['b2b_credit' => ['enabled' => true, 'roles' => [AssetAllocationService::ROLE_DISCOUNT => true], 'exchange_ratio' => '1', 'required_order_types' => ['tob']]]);
    $service->assertAssetPayableAllowed('b2b_credit', ['order_type' => 'tob'], $policy);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('payment_asset_order_type_not_allowed:b2b_credit:toc');
    $service->assertAssetPayableAllowed('b2b_credit', ['order_type' => 'toc'], $policy);
}

private function assert_PaymentAssetPolicySpi_testProviderCapabilityPrefixIsStable(): void
{
    self::assertSame('payment.asset_policy.', \Weline\Payment\Api\PaymentAssetPolicyProviderInterface::CAPABILITY_PREFIX);
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline' . '/B2B/etc/module.php';
    self::assertSame(\Weline\B2B\Service\B2BPaymentAssetPolicyProvider::class, $module['provides']['payment.asset_policy.Weline_B2B'] ?? null);
}

/**
 * ObjectManager historically injects [] for `?array $policyOverride = null`.
 * An empty override must be treated as "no override" so scope+SPI merge still runs.
 */
private function assert_PaymentAssetPolicySpi_testEmptyPolicyOverrideDoesNotShortCircuitSpiMerge(): void
{
    $service = new AssetPaymentService(policyOverride: []);
    $prop = (new \ReflectionClass($service))->getProperty('policyOverride');
    $prop->setAccessible(true);
    self::assertNull($prop->getValue($service), 'Empty policyOverride must normalize to null (DI noise), not short-circuit SPI');
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/AssetPaymentService.php');
    self::assertStringContainsString('$policyOverride === [] ? null : $policyOverride', $src);
}

private function assert_PaymentBrowserHandoffMount_testExpressToSuccessRewriteKeepsDaocharmsMount(): void
{
    $urls = new PaymentStorefrontLandingUrlService();
    $hint = 'https://p05113ef3.test.weline.com/daocharms/checkout/express-review?transaction_no=PAY-DAO-1';
    $base = $urls->resolveStorefrontBaseUrl(7, 'https://p05113ef3.test.weline.com/daocharms', $hint);
    $success = $urls->buildAbsoluteRoute('checkout/success', ['checkout_group_uuid' => 'grp-dao-1', 'checkout_token' => 'qt_dao_1', 'order_uuid' => 'ord-dao-1'], $base, $hint, 7);
    $success = $urls->ensureLandingUnderBase($success, $base);
    self::assertStringContainsString('/daocharms/checkout/success', $success);
    self::assertStringContainsString('order_uuid=ord-dao-1', $success);
    self::assertStringNotContainsString('express-review', $success);
    self::assertDoesNotMatchRegularExpression('#https?://[^/]+/checkout/success#', $success);
}

private function assert_PaymentBrowserHandoffMount_testBareHostRootSuccessIsRebuiltUnderFrozenMount(): void
{
    $urls = new PaymentStorefrontLandingUrlService();
    $fixed = $urls->ensureLandingUnderBase('/checkout/success?order_uuid=ord-dao-2', 'https://p05113ef3.test.weline.com/daocharms');
    self::assertSame('https://p05113ef3.test.weline.com/daocharms/checkout/success?order_uuid=ord-dao-2', $fixed);
}

private function assert_PaymentBrowserHandoffMount_testHandoffSourceForbidsBareHostRootSuccessRewrite(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserHandoffReadinessService.php');
    self::assertStringNotContainsString("\$path = '/checkout/success';", $src);
    self::assertStringContainsString('PaymentStorefrontLandingUrlService', $src);
    self::assertStringContainsString('buildAbsoluteRoute', $src);
    self::assertStringContainsString('ensureLandingUnderBase', $src);
}

private function assert_PaymentBrowserHandoffMount_testPersistenceFreezesWebsiteContextKeys(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentCheckoutSessionPersistenceService.php');
    self::assertStringContainsString('CONTEXT_WEBSITE_ID', $src);
    self::assertStringContainsString('CONTEXT_WEBSITE_CODE', $src);
    self::assertStringContainsString('CONTEXT_STOREFRONT_BASE_URL', $src);
    self::assertStringContainsString('resolveWebsiteFreeze', $src);
}

private function assert_PaymentBrowserHandoffMount_testScopeConfigResolvesWebsiteId(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentScopeConfigService.php');
    self::assertStringContainsString('resolveWebsiteCodeFromId', $src);
    self::assertStringContainsString("\$context['website_id']", $src);
}

private function assert_PaymentBrowserHandoffMount_testOrchestratorBindsAbsoluteUnderStorefront(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnLandingOrchestrator.php');
    self::assertStringContainsString('bindAbsoluteIfPossible', $src);
    self::assertStringContainsString('PaymentStorefrontLandingUrlService', $src);
    self::assertStringContainsString('ensureLandingUnderBase', $src);
}

private function assert_PaymentBrowserHandoffMount_testCallbackCatalogHonorsStorefrontBase(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentShellCallbackUrlCatalog.php');
    self::assertStringContainsString('activeStorefrontBase', $src);
    self::assertStringContainsString('resolveStorefrontBaseFromScope', $src);
    self::assertStringContainsString('storefront_base_url', $src);
}

private function assert_PaymentBrowserReturnLanding_testDispatcherSourceDoesNotRedirectToCheckoutReturn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnDispatcher.php');
    self::assertStringNotContainsString("'redirect_path' => 'payment/frontend/checkout/return'", $src);
    self::assertStringContainsString('PaymentBrowserReturnLandingOrchestrator', $src);
    // PayPal return 成功须收口 checkout recovery，禁止已付仍 recoverable。
    self::assertStringContainsString('markCheckoutRecoveryPaid', $src);
    self::assertStringContainsString('markPaid', $src);
}

private function assert_PaymentBrowserReturnLanding_testLandingOrchestratorDefinesL1AndL2Paths(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnLandingOrchestrator.php');
    self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_TERMINAL_L1, $src);
    self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_HANDOFF_L2, $src);
    self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_CHECKOUT_LANDING_CANCEL, $src);
    self::assertStringContainsString(PaymentBrowserReturnLandingOrchestrator::DECISION_EXPRESS_REVIEW, $src);
    self::assertStringContainsString('function decideCancel', $src);
    self::assertStringContainsString('function decideExpressReview', $src);
    self::assertStringContainsString('checkout/express-review', $src);
    self::assertStringContainsString('checkout/success', $src);
    self::assertStringContainsString('payment/success', $src);
    self::assertStringContainsString('payment/handoff', $src);
    // Paid capture + checkout/success landing → prefer terminal L3, not strand on handoff.
    self::assertStringContainsString("str_contains(\$landingUrl, 'checkout/success')", $src);
    self::assertStringContainsString('prefer Checkout L3', $src);
    self::assertStringNotContainsString('payment/frontend/checkout/success-page', $src);
    self::assertStringNotContainsString("'redirect_path' => 'payment/frontend/checkout/handoff'", $src);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Success.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Handoff.php');
    $frontendCheckout = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/Checkout.php');
    self::assertStringNotContainsString('function successPage', $frontendCheckout);
    self::assertStringNotContainsString('function handoff(', $frontendCheckout);
    self::assertStringNotContainsString('function handoffStatus', $frontendCheckout);
    self::assertStringContainsString('payment_return_cancelled', $frontendCheckout);
    self::assertStringContainsString('isCancelOutcome', $frontendCheckout);
    $cancelSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserCancelDispatcher.php');
    self::assertStringContainsString('decideCancel', $cancelSrc);
    self::assertStringNotContainsString('->decide(', $cancelSrc);
    $returnTpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/frontend/checkout/return.phtml');
    self::assertStringContainsString('payment_return_cancelled', $returnTpl);
    self::assertStringContainsString('已取消成功', $returnTpl);
    self::assertStringContainsString('已取消', $returnTpl);
    self::assertStringContainsString('payment_cancel_already', $returnTpl);
    $routesSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserCallbackRoutes.php');
    self::assertStringContainsString('CANCEL_STATE_ALREADY', $routesSrc);
    self::assertStringContainsString('CANCEL_STATE_DONE', $routesSrc);
    self::assertStringContainsString('QUERY_CANCEL_STATE', $routesSrc);
}

private function dispatcherSource_PaymentBrowserReturnResumeContext(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnDispatcher.php');
}

private function payPalProviderSource_PaymentBrowserReturnResumeContext(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php');
}

private function assert_PaymentBrowserReturnResumeContext_testDispatcherInjectsStoredProviderPayloadIntoResumeContext(): void
{
    $src = $this->dispatcherSource_PaymentBrowserReturnResumeContext();
    // 快照来源必须是 transaction 的 response_data（provider 侧订单 JSON 存这里）
    self::assertStringContainsString('$responseData = $transaction->getResponseData();', $src);
    self::assertStringContainsString("is_array(\$responseData['payload'] ?? null)", $src);
    // 且必须真的放进 resume 上下文
    self::assertMatchesRegularExpression("/'payload'\\s*=>\\s*\\\$providerPayload\\s*,/", $src, 'dispatcher 必须把支付商订单快照注入 resume 上下文，否则 provider 读不到支付商侧金额');
    // resume 上下文仍以 request_data 为底，注入属于叠加而非替换
    self::assertMatchesRegularExpression('/\$resumeContext\s*=\s*array_replace\(\$requestData,\s*\[/', $src);
}

private function assert_PaymentBrowserReturnResumeContext_testProviderReadsPresentmentAmountFromContextPayload(): void
{
    $src = $this->payPalProviderSource_PaymentBrowserReturnResumeContext();
    // provider 侧读取支付商金额的位置必须与 dispatcher 注入的键一致
    self::assertStringContainsString("\$payload = is_array(\$context['payload'] ?? null) ? \$context['payload'] : [];", $src);
    self::assertStringContainsString("\$payload['order']['purchase_units'][0]", $src);
    // 未知支付商金额时不得跳过 patch（否则静默按旧金额扣款）
    self::assertStringContainsString('$presentmentKnown = $presentment[\'minor\'] > 0;', $src);
    self::assertMatchesRegularExpression('/\$needsPatch\s*=\s*!\$presentmentKnown/', $src, '读不到支付商侧金额时必须视为需要 patch');
}

private function assert_PaymentCaptureReaderBridge_testRefundLoaderFallsBackToCaptureReaderEnsure(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentRefundService.php');
    self::assertStringContainsString('PaymentCaptureReaderEnsureService', $src);
    self::assertStringContainsString('ensureFromPayable', $src);
    self::assertStringContainsString('payableTypeAliases', $src);
}

private function assert_PaymentCaptureReaderBridge_testSuccessPathsEnsureCaptureReader(): void
{
    $dispatcher = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentBrowserReturnDispatcher.php');
    self::assertStringContainsString('ensureCaptureReader', $dispatcher);
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentService.php');
    self::assertStringContainsString('PaymentCaptureReaderEnsureService', $service);
}

private function assert_PaymentCaptureReaderBridge_testBackendRecordsEnsureThenMergeAttemptAndTransaction(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/BackendOrderPaymentRecordsService.php');
    self::assertStringContainsString('ensureFromPayable', $src);
    self::assertStringContainsString('mergeAttemptAndTransactionRows', $src);
    self::assertStringContainsString('weline_payment_transaction', $src);
    self::assertStringNotContainsString("if (\$rows !== []) {\n            return \$rows;\n        }", $src);
}

private function assert_PaymentIncentivePassthroughCapability_testPayPalDeclaresBreakdownCapabilitiesDistinctFromDiscountActions(): void
{
    $caps = (new PayPalProvider())->getCapabilities();
    self::assertTrue(!empty($caps['amount_breakdown']));
    self::assertTrue(!empty($caps['discount_passthrough']));
    self::assertContains('paypal_breakdown', $caps['passthrough_formats'] ?? []);
    self::assertIsArray($caps['supported_discount_actions'] ?? null);
    self::assertNotSame($caps['supported_discount_actions'], $caps['passthrough_formats'] ?? null, 'supported_discount_actions must not be reused as passthrough formats');
}

private function assert_PaymentIncentivePassthroughCapability_testFakeDeclaresShellEchoPassthrough(): void
{
    $caps = (new FakeProvider())->getCapabilities();
    self::assertTrue(!empty($caps['amount_breakdown']));
    self::assertTrue(!empty($caps['discount_passthrough']));
    self::assertContains('shell_echo', $caps['passthrough_formats'] ?? []);
}

private function assert_PaymentIncentivePassthroughCapability_testPayPalApiClientFormatsBreakdownInCreateOrderPayload(): void
{
    $captured = null;
    $client = new PayPalApiClient(static function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
        if (str_contains($url, '/v1/oauth2/token')) {
            return ['status' => 200, 'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: '{}'];
        }
        $captured = json_decode((string) $body, true);
        return ['status' => 201, 'body' => json_encode(['id' => 'ORDER-1', 'links' => [['rel' => 'approve', 'href' => 'https://example.test/approve']]]) ?: '{}'];
    });
    $client->createOrder(['environment' => 'sandbox', 'client_id' => 'id', 'client_secret' => 'sec', 'return_url' => 'https://example.test/return', 'cancel_url' => 'https://example.test/cancel'], 'USD', 9000, 'ref-1', 'https://example.test/return', 'https://example.test/cancel', ['amount_breakdown' => ['currency_code' => 'USD', 'value_minor' => 9000, 'item_total_minor' => 10000, 'shipping_minor' => 0, 'handling_minor' => 0, 'tax_total_minor' => 0, 'insurance_minor' => 0, 'shipping_discount_minor' => 0, 'discount_minor' => 1000, 'description' => '券;激励']]);
    self::assertIsArray($captured);
    $amount = $captured['purchase_units'][0]['amount'] ?? null;
    self::assertIsArray($amount);
    self::assertSame('90.00', $amount['value']);
    self::assertArrayHasKey('breakdown', $amount);
    self::assertSame('100.00', $amount['breakdown']['item_total']['value']);
    self::assertSame('10.00', $amount['breakdown']['discount']['value']);
}

private function assert_PaymentIncentivePassthroughCapability_testFakeCreatePaymentEchoesBreakdown(): void
{
    $provider = new FakeProvider();
    $result = $provider->createPayment(\Weline\Payment\Api\Data\PaymentRequest::fromArray([\Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_INTENT_CODE => 'I1', \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_ATTEMPT_CODE => 'A1', \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_AMOUNT_MINOR => 9500, \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_CURRENCY_CODE => 'CNY', \Weline\Payment\Api\Data\PaymentOperationRequest::FIELD_CONTEXT => ['amount_breakdown' => ['currency_code' => 'CNY', 'value_minor' => 9500, 'item_total_minor' => 10000, 'discount_minor' => 500, 'shipping_minor' => 0, 'tax_total_minor' => 0, 'handling_minor' => 0, 'insurance_minor' => 0, 'shipping_discount_minor' => 0], 'discount_lines' => [['source_type' => 'payment_method_incentive', 'amount_minor' => -500]]]]));
    $payload = $result->getPayload();
    self::assertIsArray($payload);
    self::assertArrayHasKey('echo_breakdown', $payload);
    self::assertSame(9500, $payload['echo_breakdown']['value_minor']);
    self::assertSame(500, $payload['echo_breakdown']['discount_minor']);
}

private function assert_PaymentIncentivePassthroughCapability_testEventAndConfigTemplatesDocumented(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Payment';
    $eventXml = (string) file_get_contents($root . '/etc/event.xml');
    self::assertStringContainsString('Weline_Payment::checkout::available_methods::enrich', $eventXml);
    self::assertStringContainsString('CheckoutAvailableMethodsIncentiveEnrichObserver', $eventXml);
    $paypal = (string) file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/backend/paypal.phtml');
    $fake = (string) file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/backend/fake_card.phtml');
    self::assertStringContainsString('payment/method/paypal/incentive_enabled', $paypal);
    self::assertStringContainsString('payment/method/fake_card/incentive_enabled', $fake);
    $eventDoc = $root . '/doc/event/checkout-available-methods-enrich.md';
    self::assertFileExists($eventDoc);
    $ledger = (string) file_get_contents($root . '/Model/PaymentLedger.php');
    self::assertStringContainsString("TYPE_DISCOUNT = 'discount'", $ledger);
    $eventPhp = (string) file_get_contents($root . '/event.php');
    self::assertStringContainsString('Weline_Payment::checkout::available_methods::enrich', $eventPhp, 'event.php 规约必须登记 enrich 事件，否则 event:rebuild 后 live registry 缺失');
    self::assertStringContainsString('checkout-available-methods-enrich.md', $eventPhp);
    $quoteSrc = (string) file_get_contents($root . '/Service/PaymentMethodIncentiveQuoteService.php');
    self::assertStringContainsString("__('减 %{1}'", $quoteSrc);
    self::assertStringNotContainsString("__('减 %1'", $quoteSrc);
}

private function assert_PaymentMethodIncentiveQuote_testDisabledConfigYieldsZero(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $quote = $svc->quote('paypal', ['incentive_enabled' => 0], 10000, 'CNY', true);
    self::assertFalse($quote['available']);
    self::assertSame(0, $quote['savings_minor']);
}

private function assert_PaymentMethodIncentiveQuote_testFixedAmountQuoteAndListPayload(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $quote = $svc->quote('paypal', ['incentive_enabled' => 1, 'incentive_type' => 'fixed_amount', 'incentive_amount_minor' => 500, 'incentive_funding_source' => 'merchant', 'incentive_publish_version' => 'v1'], 10000, 'CNY', true);
    self::assertTrue($quote['available']);
    self::assertSame(500, $quote['savings_minor']);
    self::assertIsArray($quote['line']);
    self::assertSame(PaymentMethodIncentiveQuoteInterface::SOURCE_TYPE, $quote['line']['source_type']);
    self::assertSame(-500, $quote['line']['amount_minor']);
    self::assertSame('paypal', $quote['line']['method_code']);
    self::assertSame('pmi:paypal:v1', $quote['line']['key']);
    $fields = $svc->toListPayloadFields($quote);
    self::assertSame(500, $fields['incentive_savings_minor']);
    self::assertTrue($fields['incentive_available']);
    self::assertNotSame('', $fields['incentive_display']);
    self::assertStringNotContainsString('Save %1', $fields['incentive_display']);
    self::assertStringNotContainsString('%{1}', $fields['incentive_display']);
    self::assertStringContainsString('CNY', $fields['incentive_display']);
    self::assertSame('fixed_amount', $fields['incentive_type']);
}

private function assert_PaymentMethodIncentiveQuote_testPercentageWithCap(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $quote = $svc->quote('fake_card', ['incentive_enabled' => true, 'incentive_type' => 'percentage', 'incentive_percent' => 10, 'incentive_cap_minor' => 300], 10000, 'USD', true);
    self::assertSame(300, $quote['savings_minor']);
    self::assertSame(10.0, $quote['percent']);
}

private function assert_PaymentMethodIncentiveQuote_testApplyToOrderDataMergesIncentiveLineAndLowersPayable(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $applied = $svc->applyToOrderData('paypal', ['amount_minor' => 10000, 'currency' => 'CNY', 'discount_lines' => [['key' => 'coupon:SAVE10', 'label' => '券', 'amount_minor' => -1000, 'source_type' => 'coupon']], 'totals' => ['subtotal_minor' => 11000, 'discount_amount_minor' => 1000, 'grand_total_minor' => 10000, 'shipping_amount_minor' => 0, 'tax_amount_minor' => 0]], ['incentive_enabled' => 1, 'incentive_type' => 'fixed_amount', 'incentive_amount_minor' => 500, 'incentive_publish_version' => 'v2']);
    self::assertSame(500, $applied['savings_minor']);
    self::assertSame(9500, $applied['order_data']['amount_minor']);
    $sources = array_map(static fn(array $l): string => (string) ($l['source_type'] ?? ''), $applied['order_data']['discount_lines']);
    self::assertContains('coupon', $sources);
    self::assertContains(PaymentMethodIncentiveQuoteInterface::SOURCE_TYPE, $sources);
    self::assertCount(2, $applied['order_data']['discount_lines']);
}

private function assert_PaymentMethodIncentiveQuote_testApplyToOrderDataIsIdempotentWhenIncentiveLineAlreadyPresent(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $runtime = ['incentive_enabled' => 1, 'incentive_type' => 'fixed_amount', 'incentive_amount_minor' => 500, 'incentive_publish_version' => 'parity-v1'];
    $first = $svc->applyToOrderData('fake_card', ['amount_minor' => 1892, 'currency' => 'USD'], $runtime);
    $second = $svc->applyToOrderData('fake_card', $first['order_data'], $runtime);
    self::assertSame(1392, (int) $second['order_data']['amount_minor']);
    self::assertSame(500, (int) $second['savings_minor']);
    self::assertCount(1, $second['order_data']['discount_lines']);
}

private function assert_PaymentMethodIncentiveQuote_testApplyToOrderDataPreservesCouponDiscountWhenLinesEmpty(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    // No incentive; empty discount_lines must NOT wipe totals.discount_amount_minor from freeze/order money.
    $applied = $svc->applyToOrderData(
        'paypal',
        [
            'amount_minor' => 33855,
            'currency' => 'USD',
            'discount_lines' => [],
            'totals' => [
                'subtotal_minor' => 7900,
                'shipping_amount_minor' => 26745,
                'tax_amount_minor' => 0,
                'discount_amount_minor' => 790,
                'grand_total_minor' => 33855,
            ],
        ],
        ['incentive_enabled' => 0],
    );
    self::assertSame(0, (int) $applied['savings_minor']);
    self::assertSame(790, (int) ($applied['order_data']['totals']['discount_amount_minor'] ?? 0));
    self::assertSame(33855, (int) ($applied['order_data']['totals']['grand_total_minor'] ?? 0));
}

private function assert_PaymentMethodIncentiveQuote_testUnavailableMethodDoesNotAdvertiseIncentive(): void
{
    $svc = new PaymentMethodIncentiveQuoteService();
    $quote = $svc->quote('paypal', ['incentive_enabled' => 1, 'incentive_type' => 'fixed_amount', 'incentive_amount_minor' => 500], 10000, 'CNY', false);
    $fields = $svc->toListPayloadFields($quote);
    self::assertFalse($fields['incentive_available']);
    self::assertSame(0, $fields['incentive_savings_minor']);
}

private function assert_PaymentProcessCache_testMethodManagerOwnsProcessBagAndClear(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentMethodManager.php');
    self::assertStringContainsString('$processMethodRows', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('loadAllMethodRows', $src);
    self::assertStringContainsString('PaymentScopeConfigService::clearProcessCache()', $src);
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
    self::assertStringContainsString('ScopeIdentity::global()', $src);
    self::assertStringContainsString('payment.methods.public', $src);
    self::assertStringContainsString('unset($row[PaymentMethod::schema_fields_CONFIG])', $src);
}

private function assert_PaymentProcessCache_testScopeConfigServiceOwnsOverrideProcessBag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentScopeConfigService.php');
    self::assertStringContainsString('$processOverridesByScopeEnv', $src);
    self::assertStringContainsString('$processSystemConfigMaps', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
    self::assertStringContainsString('scopeIdentity(', $src);
}

private function assert_PaymentProcessCache_testProcessCacheResetterIsRegistered(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/etc/module.php';
    self::assertSame(\Weline\Payment\Api\Runtime\ProcessCacheResetter::class, $module['provides']['process_cache_resetter.Weline_Payment'] ?? null);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('PaymentMethodManager::clearProcessCache()', $src);
}

private function assert_PaymentProviderControllerRouteScanner_testForbiddenControllerMethodsAreBlocked(): void
{
    self::assertContains('controllerReturn', PaymentProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);
    self::assertContains('controllerCancel', PaymentProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);
    self::assertContains('controllerNotify', PaymentProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);
}

private function assert_PaymentProviderControllerRouteScanner_testScanDoesNotRegisterForbiddenRoutes(): void
{
    $scanner = new PaymentProviderControllerRouteScanner();
    foreach ($scanner->scan(true) as $route) {
        self::assertNotContains($route['action'], PaymentProviderControllerDispatcher::FORBIDDEN_ACTIONS);
    }
}

private function assert_PaymentServiceRefundMinor_testRefundPrimarySignatureUsesAmountMinorInt(): void
{
    $ref = new \ReflectionMethod(PaymentService::class, 'refund');
    $params = $ref->getParameters();
    self::assertCount(3, $params);
    self::assertSame('transactionNo', $params[0]->getName());
    self::assertSame('amountMinor', $params[1]->getName());
    self::assertSame('int', (string) $params[1]->getType());
    self::assertSame('reason', $params[2]->getName());
    self::assertTrue(method_exists(PaymentService::class, 'refundWithMajorAmount'));
    $deprecated = new \ReflectionMethod(PaymentService::class, 'refundWithMajorAmount');
    self::assertSame('float', (string) $deprecated->getParameters()[1]->getType());
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentService.php');
    self::assertStringContainsString('function refund(string $transactionNo, int $amountMinor', $src);
    self::assertStringNotContainsString('function refund(string $transactionNo, float $amount', $src);
    self::assertStringContainsString('@deprecated', $src);
    self::assertStringContainsString('refundWithMajorAmount', $src);
}

private function assert_PaymentShellAntiLeak_testCallbackDoesNotImportGatewayOauth(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/Callback.php');
    self::assertStringNotContainsString('use Weline\Payment\Service\PayPalOAuthService', $src);
    self::assertStringNotContainsString('payment/frontend/paypal/return', $src);
    self::assertStringContainsString('PaymentBrowserReturnDispatcher', $src);
}

private function assert_PaymentShellAntiLeak_testPayPalProviderImplementsConnectAndFakeDoesNot(): void
{
    self::assertTrue(is_subclass_of(PayPalProvider::class, ProviderInterface::class));
    self::assertTrue(is_subclass_of(PayPalProvider::class, ProviderConnectInterface::class));
    self::assertTrue(is_subclass_of(PayPalProvider::class, ProviderShipmentTrackingInterface::class));
    self::assertTrue(is_subclass_of(FakeProvider::class, ProviderInterface::class));
    self::assertFalse(is_subclass_of(FakeProvider::class, ProviderConnectInterface::class));
    self::assertFalse(is_subclass_of(FakeProvider::class, ProviderShipmentTrackingInterface::class));
    $paypalMeta = (new PayPalProvider())->getDisplayMetadata();
    self::assertSame('template', $paypalMeta['checkout_mode'] ?? null);
    self::assertNotSame('', (string) ($paypalMeta['icon_url'] ?? $paypalMeta['icon'] ?? ''));
    $fakeMeta = (new FakeProvider())->getDisplayMetadata();
    self::assertSame('template', $fakeMeta['checkout_mode'] ?? null);
    self::assertSame('fake_card', $fakeMeta['checkout_template_code'] ?? null);
    self::assertNotSame('', (string) ($fakeMeta['icon_url'] ?? $fakeMeta['icon'] ?? ''));
}

private function assert_PaymentShellAntiLeak_testConnectControllerRequiresMethodCode(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/Connect.php');
    self::assertStringContainsString('method_code', $src);
    self::assertStringContainsString('PaymentConnectDispatcher', $src);
    self::assertStringNotContainsString('PayPalOAuthService', $src);
}

private function assert_PaymentShellAntiLeak_testLegacyPayPalControllersRemoved(): void
{
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Frontend/PayPal.php');
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/PayPal.php');
}

private function assert_PaymentShellAntiLeak_testPaymentServiceInjectsCatalogUrls(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentService.php');
    self::assertStringContainsString('PaymentShellCallbackUrlCatalog', $src);
    self::assertStringContainsString('buildBrowserCallbackUrls', $src);
    self::assertStringContainsString('PaymentCheckoutSessionPersistenceService', $src);
}

private function assert_PaymentShellAntiLeak_testApiPaymentCreateDoesNotHandBuildCheckoutReturn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Api/Payment.php');
    self::assertStringNotContainsString('frontend/checkout/return', $src);
}

private function assert_PaymentShellCallbackUrlCatalog_testCatalogSourceDefinesShellRoutes(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentShellCallbackUrlCatalog.php');
    self::assertStringContainsString('browserReturnRegister', $src);
    self::assertStringContainsString('browserReturn', $src);
    self::assertStringContainsString('browserCancel', $src);
    self::assertStringContainsString('webhookNotify', $src);
    self::assertStringContainsString('browserFailure', $src);
    self::assertStringContainsString('buildBrowserCallbackUrls', $src);
    self::assertStringContainsString('shell_token', $src);
}

private function assert_PaymentShellCallbackUrlCatalog_testCancelUsesOutcomeOnSamePath(): void
{
    self::assertSame('payment/frontend/callback/paypal', PaymentBrowserCallbackRoutes::returnRoute('paypal'));
    self::assertSame('payment/frontend/callback/paypal', PaymentBrowserCallbackRoutes::cancelRoute('paypal'));
    self::assertSame(PaymentBrowserCallbackRoutes::OUTCOME_CANCEL, 'cancel');
}

private function assert_PaymentShellCallbackUrlCatalog_testWithTargetScopeAppendsQuery(): void
{
    $url = PaymentBrowserCallbackRoutes::withTargetScope('http://shop.test/payment/frontend/callback/paypal', 'default.default.default');
    self::assertStringContainsString(PaymentBrowserCallbackRoutes::QUERY_TARGET_SCOPE . '=default.default.default', $url);
    self::assertStringContainsString('/callback/paypal', $url);
    self::assertStringNotContainsString('/callback/return/', $url);
}

private function assert_PaymentShellCallbackUrlCatalog_testCatalogCancelAppendsOutcome(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentShellCallbackUrlCatalog.php');
    self::assertStringContainsString('QUERY_OUTCOME', $src);
    self::assertStringContainsString('OUTCOME_CANCEL', $src);
    self::assertStringContainsString('browserReturnRegister', $src);
}

private function assert_PaymentShellCallbackUrlCatalog_testReturnContextResolverPrefersShellToken(): void
{
    $tokenService = new PaymentBrowserCallbackTokenService();
    $token = $tokenService->encode('stripe', 'TXN-9', 'shop.cn.default', 3600);
    $resolver = new PaymentBrowserReturnContextResolver($tokenService);
    $context = $resolver->resolve([PaymentBrowserCallbackTokenService::QUERY_SHELL_TOKEN => $token, PaymentBrowserCallbackRoutes::QUERY_METHOD_CODE => 'stripe', 'token' => 'GW-ORDER-1']);
    self::assertNotNull($context);
    self::assertSame('stripe', $context['method_code']);
    self::assertSame('TXN-9', $context['transaction_no']);
    self::assertSame('GW-ORDER-1', $context['gateway_token']);
    self::assertSame('shell_token', $context['source']);
}

private function assert_PaymentShellCallbackUrlCatalog_testReturnContextResolverAcceptsExplicitCodes(): void
{
    $resolver = new PaymentBrowserReturnContextResolver(new PaymentBrowserCallbackTokenService());
    $context = $resolver->resolve([PaymentShellCallbackUrlCatalog::QUERY_METHOD_CODE => 'alipay', PaymentShellCallbackUrlCatalog::QUERY_TRANSACTION_NO => 'TXN-88', PaymentBrowserCallbackRoutes::QUERY_TARGET_SCOPE => 'default.default.default']);
    self::assertNotNull($context);
    self::assertSame('alipay', $context['method_code']);
    self::assertSame('TXN-88', $context['transaction_no']);
    self::assertSame('explicit_codes', $context['source']);
}

private function assert_PaymentShellIdempotency_testOauthCompleteKeepsCompletedResultForReplay(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PayPalOAuthService.php');
    self::assertStringContainsString('completed_result', $src);
    self::assertStringContainsString('markStateCompleted', $src);
    self::assertStringContainsString('peekState', $src);
}

private function assert_PaymentShellIdempotency_testWebhookDocCrossLinksShellIdempotency(): void
{
    $webhook = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/doc/webhook.md');
    $shell = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/doc/payment-shell.md');
    self::assertStringContainsString('payment-shell.md', $webhook);
    self::assertStringContainsString('provider_event_id', $shell);
    self::assertStringContainsString('idempotency', strtolower($shell));
}

private function assert_PaymentShellIdempotency_testShellDocRequiresCheckoutIdempotencyKey(): void
{
    $shell = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/doc/payment-shell.md');
    self::assertStringContainsString('idempotency key', strtolower($shell));
    self::assertStringContainsString('浏览器 return', $shell);
}

private function assert_PaymentShipmentTrackingShell_testPayPalImplementsTrackingAndFakeDoesNot(): void
{
    self::assertTrue(is_subclass_of(PayPalProvider::class, ProviderShipmentTrackingInterface::class));
    self::assertFalse(is_subclass_of(FakeProvider::class, ProviderShipmentTrackingInterface::class));
    self::assertTrue(method_exists(PayPalProvider::class, 'syncShipmentTracking'));
}

private function assert_PaymentShipmentTrackingShell_testEventXmlUsesShellObserverNotPayPalHardcode(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/etc/event.xml');
    self::assertStringContainsString('OrderShippedShipmentTrackingObserver', $xml);
    self::assertStringContainsString('order_shipped_shipment_tracking', $xml);
    self::assertStringNotContainsString('PayPalOrderShippedTrackingObserver', $xml);
    self::assertStringNotContainsString('paypal_order_shipped_tracking', $xml);
}

private function assert_PaymentShipmentTrackingShell_testShellObserverDelegatesToDispatcher(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Observer/OrderShippedShipmentTrackingObserver.php');
    self::assertStringContainsString(PaymentShipmentTrackingDispatcher::class, $src);
    self::assertStringNotContainsString('PayPalShipmentTrackingSyncService', $src);
    self::assertTrue(class_exists(OrderShippedShipmentTrackingObserver::class));
}

private function assert_PaymentShipmentTrackingShell_testDispatcherResolvesByMethodCode(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/PaymentShipmentTrackingDispatcher.php');
    self::assertStringContainsString('ProviderShipmentTrackingInterface', $src);
    self::assertStringContainsString('resolveProviderRoute', $src);
    self::assertStringContainsString('PAYMENT_METHOD', $src);
}

private function assert_PaymentSumCapturedAmount_testFacadeDeclaresSumAndAbandonOpenIntents(): void
{
    $iface = new \ReflectionClass(PaymentRefundFacadeInterface::class);
    self::assertTrue($iface->hasMethod('sumCapturedAmountMinor'));
    self::assertTrue($iface->hasMethod('abandonOpenIntentsForPayable'));
    $svc = new \ReflectionClass(PaymentRefundService::class);
    self::assertTrue($svc->hasMethod('sumCapturedAmountMinor'));
    self::assertTrue($svc->hasMethod('abandonOpenIntentsForPayable'));
    self::assertTrue($svc->hasMethod('listCapturedIntents'));
}

private function assert_WebsiteBenchmarkCurrencyResolverSnapshot_testReadWebsiteDefaultUsesWebsiteDataSnapshot(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Service/WebsiteBenchmarkCurrencyResolver.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $src);
    self::assertStringNotContainsString('Website::schema_fields_ID', $src);
    self::assertStringNotContainsString('->load(Website::', $src);
}
}

final class AmountBreakdownBuilderContractTest extends TestCase
{
    public function testBreakdownConservesValue(): void
    {
        $builder = new AmountBreakdownBuilder();
        $breakdown = $builder->fromSnapshot(['currency' => 'USD', 'subtotal_minor' => 10000, 'shipping_amount_minor' => 1000, 'tax_amount_minor' => 500, 'discount_amount_minor' => 1500, 'grand_total_minor' => 10000], [['key' => 'coupon:A', 'label' => '券', 'amount_minor' => -1000, 'source_type' => 'coupon'], ['key' => 'pmi:paypal:v1', 'label' => '激励', 'amount_minor' => -500, 'source_type' => 'payment_method_incentive']]);
        $computed = $breakdown['item_total_minor'] + $breakdown['tax_total_minor'] + $breakdown['shipping_minor'] + $breakdown['handling_minor'] + $breakdown['insurance_minor'] - $breakdown['shipping_discount_minor'] - $breakdown['discount_minor'];
        self::assertSame($breakdown['value_minor'], $computed);
        self::assertSame(1500, $breakdown['discount_minor']);
        self::assertSame(0, $breakdown['shipping_discount_minor']);
    }
    public function testShippingDiscountSeparated(): void
    {
        $builder = new AmountBreakdownBuilder();
        $breakdown = $builder->fromOrderData(['currency' => 'USD', 'amount_minor' => 9000, 'discount_lines' => [['amount_minor' => -500, 'source_type' => 'shipping', 'label' => '包邮'], ['amount_minor' => -500, 'source_type' => 'payment_method_incentive', 'label' => '激励']], 'totals' => ['subtotal_minor' => 10000, 'shipping_amount_minor' => 1000, 'tax_amount_minor' => 0, 'grand_total_minor' => 9000]]);
        self::assertSame(500, $breakdown['shipping_discount_minor']);
        self::assertSame(500, $breakdown['discount_minor']);
        $computed = $breakdown['item_total_minor'] + $breakdown['tax_total_minor'] + $breakdown['shipping_minor'] - $breakdown['shipping_discount_minor'] - $breakdown['discount_minor'];
        self::assertSame(9000, $computed);
    }
}
