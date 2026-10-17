<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod as ReflectionMethod;
use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\Ga4Vendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\GtmVendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\SystemVendor;
use Weline\Visitor\Interface\PixelEventVendorInterface;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\EventChainCollector;
use Weline\Visitor\Service\EventChainService;
use Weline\Visitor\Service\EventDictionaryService;
use Weline\Visitor\Service\EventPickerTokenService;
use Weline\Visitor\Service\PixelBootstrapHtmlService;
use Weline\Visitor\Service\PixelDashboardWidgetData;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\PixelEventVendorManager;
use Weline\Visitor\Service\PixelMarkerScanner;
use Weline\Visitor\Service\PixelPageTypeClassifier;
use Weline\Visitor\Service\PixelStatisticsService;
use Weline\Visitor\Service\Report\PixelDetailReportTabService;
use Weline\Visitor\Service\Report\PixelReportCatalog;
use Weline\Visitor\Service\VisitorDashboardPageInstaller;

/**
 * Aggregated contract suite (merged from 22 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorServiceContractsPass().
 */
final class VisitorServiceContractsTest extends TestCase
{
    public function testAllVisitorServiceContractsPass(): void
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
            'AutoDiscoverLockedEvents::test_annotation_service_exposes_origin_and_lock' => function () {
            $this->assert_AutoDiscoverLockedEvents_test_annotation_service_exposes_origin_and_lock();
        },
            'AutoDiscoverLockedEvents::test_test_residual_purge_helpers' => function () {
            $this->assert_AutoDiscoverLockedEvents_test_test_residual_purge_helpers();
        },
            'AutoDiscoverLockedEvents::test_discovered_remove_and_controller_refuse_locked' => function () {
            $this->assert_AutoDiscoverLockedEvents_test_discovered_remove_and_controller_refuse_locked();
        },
            'AutoDiscoverLockedEvents::test_register_auto_sandbox_gate_and_idempotent' => function () {
            $this->assert_AutoDiscoverLockedEvents_test_register_auto_sandbox_gate_and_idempotent();
        },
            'AutoDiscoverLockedEvents::test_admin_ui_badge_and_delete_disabled' => function () {
            $this->assert_AutoDiscoverLockedEvents_test_admin_ui_badge_and_delete_disabled();
        },
            'AutoDiscoverLockedEvents::test_pixel_local_cache_and_sandbox_only_register' => function () {
            $this->assert_AutoDiscoverLockedEvents_test_pixel_local_cache_and_sandbox_only_register();
        },
            'ConversionEventDedupe::testVisitorTrackingConfigExposesConversionDedupeRuntime' => function () {
            $this->assert_ConversionEventDedupe_testVisitorTrackingConfigExposesConversionDedupeRuntime();
        },
            'ConversionEventDedupe::testTrackingPhtmlDeclaresDedupeFields' => function () {
            $this->assert_ConversionEventDedupe_testTrackingPhtmlDeclaresDedupeFields();
        },
            'ConversionEventDedupe::testPixelEventServiceSkipsDuplicateReason' => function () {
            $this->assert_ConversionEventDedupe_testPixelEventServiceSkipsDuplicateReason();
        },
            'ConversionEventDedupe::testPixelJsHasLocalStorageDedupeGate' => function () {
            $this->assert_ConversionEventDedupe_testPixelJsHasLocalStorageDedupeGate();
        },
            'ConversionEventDedupe::testCheckoutSuccessExplicitTrackWithoutSessionStorageDedupe' => function () {
            $this->assert_ConversionEventDedupe_testCheckoutSuccessExplicitTrackWithoutSessionStorageDedupe();
        },
            'ConversionEventDedupe::testPixelSandboxKeepsGa4EcommerceOnDedupeEmit' => function () {
            $this->assert_ConversionEventDedupe_testPixelSandboxKeepsGa4EcommerceOnDedupeEmit();
        },
            'EventChainModuleRegister::testCollectorEventNameConstant' => function () {
            $this->assert_EventChainModuleRegister_testCollectorEventNameConstant();
        },
            'EventChainModuleRegister::testExtendsDeclaresEventChainProvider' => function () {
            $this->assert_EventChainModuleRegister_testExtendsDeclaresEventChainProvider();
        },
            'EventChainModuleRegister::testDocDescribesCollectEvent' => function () {
            $this->assert_EventChainModuleRegister_testDocDescribesCollectEvent();
        },
            'EventChainModuleRegister::testServiceMergesRegisteredChainsById' => function () {
            $this->assert_EventChainModuleRegister_testServiceMergesRegisteredChainsById();
        },
            'EventDictionaryAndAudit::testResolveCheckoutSuccessMapsToPurchase' => function () {
            $this->assert_EventDictionaryAndAudit_testResolveCheckoutSuccessMapsToPurchase();
        },
            'EventDictionaryAndAudit::testPageViewSkipsGtmPush' => function () {
            $this->assert_EventDictionaryAndAudit_testPageViewSkipsGtmPush();
        },
            'EventDictionaryAndAudit::testCtaOverride' => function () {
            $this->assert_EventDictionaryAndAudit_testCtaOverride();
        },
            'EventDictionaryAndAudit::testClassifierHomeAndCheckout' => function () {
            $this->assert_EventDictionaryAndAudit_testClassifierHomeAndCheckout();
        },
            'EventDictionaryAndAudit::testScannerFindsMarkers' => function () {
            $this->assert_EventDictionaryAndAudit_testScannerFindsMarkers();
        },
            'EventDictionaryAndAudit::testScannerMissingMarkers' => function () {
            $this->assert_EventDictionaryAndAudit_testScannerMissingMarkers();
        },
            'EventDictionaryAndAudit::testScannerIgnoresInlineEventDictionaryBleed' => function () {
            $this->assert_EventDictionaryAndAudit_testScannerIgnoresInlineEventDictionaryBleed();
        },
            'EventDictionaryAndAudit::testEcommerceEventsDoNotScopeToContentViaStar' => function () {
            $this->assert_EventDictionaryAndAudit_testEcommerceEventsDoNotScopeToContentViaStar();
        },
            'EventDictionaryAndAudit::testGa4RecommendedEcommerceEventsExistAsIdentity' => function () {
            $this->assert_EventDictionaryAndAudit_testGa4RecommendedEcommerceEventsExistAsIdentity();
        },
            'EventDictionaryAndAudit::testPixelGa4ParamsAliasPaymentAndShipping' => function () {
            $this->assert_EventDictionaryAndAudit_testPixelGa4ParamsAliasPaymentAndShipping();
        },
            'PixelBootstrapModuleStaticUrl::testPixelAndPanelBootstrapNormalizeThemePathViaPublicThemeNamespace' => function () {
            $this->assert_PixelBootstrapModuleStaticUrl_testPixelAndPanelBootstrapNormalizeThemePathViaPublicThemeNamespace();
        },
            'PixelBootstrapOncePerRequest::testEmittedFlagApiAndBodyEndSkipsDuplicateConfig' => function () {
            $this->setUp_PixelBootstrapOncePerRequest();
            try {
                $this->assert_PixelBootstrapOncePerRequest_testEmittedFlagApiAndBodyEndSkipsDuplicateConfig();
            } finally {
                $this->tearDown_PixelBootstrapOncePerRequest();
            }
        },
            'PixelChannelsWidget::testWidgetPhpRegistersPixelChannels' => function () {
            $this->assert_PixelChannelsWidget_testWidgetPhpRegistersPixelChannels();
        },
            'PixelChannelsWidget::testWidgetCodeMatchesReportCatalog' => function () {
            $this->assert_PixelChannelsWidget_testWidgetCodeMatchesReportCatalog();
        },
            'PixelChannelsWidget::testTemplateUsesEngineReportAndDrilldown' => function () {
            $this->assert_PixelChannelsWidget_testTemplateUsesEngineReportAndDrilldown();
        },
            'PixelChannelsWidget::testChannelDrilldownUrlCarriesChannelCode' => function () {
            $this->assert_PixelChannelsWidget_testChannelDrilldownUrlCarriesChannelCode();
        },
            'PixelChannelsWidget::testChannelsReportCodeIsMountedOnDetailTabs' => function () {
            $this->assert_PixelChannelsWidget_testChannelsReportCodeIsMountedOnDetailTabs();
        },
            'PixelDashboardWidgetDetailUrl::testDetailUrlWithWebsiteGoesToDetailReport' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testDetailUrlWithWebsiteGoesToDetailReport();
        },
            'PixelDashboardWidgetDetailUrl::testDetailUrlAcceptsDefaultWebsiteIdZero' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testDetailUrlAcceptsDefaultWebsiteIdZero();
        },
            'PixelDashboardWidgetDetailUrl::testDetailUrlWithEventGoesToIndexNotDetail' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testDetailUrlWithEventGoesToIndexNotDetail();
        },
            'PixelDashboardWidgetDetailUrl::testDetailUrlWithoutWebsiteGoesToIndex' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testDetailUrlWithoutWebsiteGoesToIndex();
        },
            'PixelDashboardWidgetDetailUrl::testDetailUrlQueryKeysStayWithinContract' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testDetailUrlQueryKeysStayWithinContract();
        },
            'PixelDashboardWidgetDetailUrl::testListUrlUsesListPathAndC07Keys' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testListUrlUsesListPathAndC07Keys();
        },
            'PixelDashboardWidgetDetailUrl::testCatalogWidgetsWireDetailAndListUrls' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testCatalogWidgetsWireDetailAndListUrls();
        },
            'PixelDashboardWidgetDetailUrl::testCatalogReportPayloadDocumentsDetailAndListFields' => function () {
            $this->assert_PixelDashboardWidgetDetailUrl_testCatalogReportPayloadDocumentsDetailAndListFields();
        },
            'PixelEcommerceWidgets::testWidgetPhpRegistersThreeEcommerceWidgets' => function () {
            $this->assert_PixelEcommerceWidgets_testWidgetPhpRegistersThreeEcommerceWidgets();
        },
            'PixelEcommerceWidgets::testInstallerAppendsEcommerceWithoutReplacingCatalog' => function () {
            $this->assert_PixelEcommerceWidgets_testInstallerAppendsEcommerceWithoutReplacingCatalog();
        },
            'PixelEcommerceWidgets::testTemplatesWireServiceMethodsAndAnchors' => function () {
            $this->assert_PixelEcommerceWidgets_testTemplatesWireServiceMethodsAndAnchors();
        },
            'PixelEcommerceWidgets::testEcommerceWidgetBaseRequiresWebsiteAndAnchorsDetail' => function () {
            $this->assert_PixelEcommerceWidgets_testEcommerceWidgetBaseRequiresWebsiteAndAnchorsDetail();
        },
            'PixelEcommerceWidgets::testEcommerceCodesStayOutOfReportCatalog' => function () {
            $this->assert_PixelEcommerceWidgets_testEcommerceCodesStayOutOfReportCatalog();
        },
            'PixelEventDiscovery::testDictionaryHasLeadSubmitLoginRegisterAndLabels' => function () {
            $this->assert_PixelEventDiscovery_testDictionaryHasLeadSubmitLoginRegisterAndLabels();
        },
            'PixelEventDiscovery::testVendorDefaultsUseLoginRegisterNotSuccessSuffix' => function () {
            $this->assert_PixelEventDiscovery_testVendorDefaultsUseLoginRegisterNotSuccessSuffix();
        },
            'PixelEventDiscovery::testFilterMappableMapKeepsCustomKeys' => function () {
            $this->assert_PixelEventDiscovery_testFilterMappableMapKeepsCustomKeys();
        },
            'PixelEventDiscovery::testDictionaryNormalizeKeepsCustomEventNames' => function () {
            $this->assert_PixelEventDiscovery_testDictionaryNormalizeKeepsCustomEventNames();
        },
            'PixelEventDiscovery::testPickerTokenIssueAndValidate' => function () {
            $this->assert_PixelEventDiscovery_testPickerTokenIssueAndValidate();
        },
            'PixelEventDiscovery::testPickerAccumulateBuffer' => function () {
            $this->assert_PixelEventDiscovery_testPickerAccumulateBuffer();
        },
            'PixelEventDiscovery::testPickerAccumulateStoresChainPayload' => function () {
            $this->assert_PixelEventDiscovery_testPickerAccumulateStoresChainPayload();
        },
            'PixelEventDiscovery::testPickerAccumulateStoresParams' => function () {
            $this->assert_PixelEventDiscovery_testPickerAccumulateStoresParams();
        },
            'PixelEventDiscovery::testPickerAccumulateSinceAndClear' => function () {
            $this->assert_PixelEventDiscovery_testPickerAccumulateSinceAndClear();
        },
            'PixelEventDiscovery::testAdminTemplateHasPickerAndAccumulate' => function () {
            $this->assert_PixelEventDiscovery_testAdminTemplateHasPickerAndAccumulate();
        },
            'PixelEventDiscovery::testDiscoveredScopeKeysAreIsolatedAndCustomLabelled' => function () {
            $this->assert_PixelEventDiscovery_testDiscoveredScopeKeysAreIsolatedAndCustomLabelled();
        },
            'PixelEventValueWidget::testWidgetPhpRegistersPixelEventValue' => function () {
            $this->assert_PixelEventValueWidget_testWidgetPhpRegistersPixelEventValue();
        },
            'PixelEventValueWidget::testWidgetCodeMatchesReportCatalog' => function () {
            $this->assert_PixelEventValueWidget_testWidgetCodeMatchesReportCatalog();
        },
            'PixelEventValueWidget::testTemplateUsesEngineReportAvgValueAndEventDrilldown' => function () {
            $this->assert_PixelEventValueWidget_testTemplateUsesEngineReportAvgValueAndEventDrilldown();
        },
            'PixelEventValueWidget::testEventValueDrilldownUrlCarriesEvent' => function () {
            $this->assert_PixelEventValueWidget_testEventValueDrilldownUrlCarriesEvent();
        },
            'PixelEventValueWidget::testEventValueReportCodeIsMountedOnDetailTabs' => function () {
            $this->assert_PixelEventValueWidget_testEventValueReportCodeIsMountedOnDetailTabs();
        },
            'PixelEventVendor::testBuiltInVendorsImplementInterfaceAndDefaultSandbox' => function () {
            $this->assert_PixelEventVendor_testBuiltInVendorsImplementInterfaceAndDefaultSandbox();
        },
            'PixelEventVendor::testSystemVendorAlwaysEnabledAndLocked' => function () {
            $this->assert_PixelEventVendor_testSystemVendorAlwaysEnabledAndLocked();
        },
            'PixelEventVendor::testFilterMappableMapDropsSkipGtmPushEvents' => function () {
            $this->assert_PixelEventVendor_testFilterMappableMapDropsSkipGtmPushEvents();
        },
            'PixelEventVendor::testExtendsDeclaresPixelEventVendorPoint' => function () {
            $this->assert_PixelEventVendor_testExtendsDeclaresPixelEventVendorPoint();
        },
            'PixelEventVendor::testAdminSplitLayoutTemplateExists' => function () {
            $this->assert_PixelEventVendor_testAdminSplitLayoutTemplateExists();
        },
            'PixelEventVendor::testTrackingVendorRedirectsKeepBackendAreaSegment' => function () {
            $this->assert_PixelEventVendor_testTrackingVendorRedirectsKeepBackendAreaSegment();
        },
            'PixelEventVendor::testSaveCustomClearsDefaultMapWhenWritingEventMap' => function () {
            $this->assert_PixelEventVendor_testSaveCustomClearsDefaultMapWhenWritingEventMap();
        },
            'PixelEventVendor::testAutosaveAndTabPersistContract' => function () {
            $this->assert_PixelEventVendor_testAutosaveAndTabPersistContract();
        },
            'PixelEventVendor::testMenuRegistersTrackingVendor' => function () {
            $this->assert_PixelEventVendor_testMenuRegistersTrackingVendor();
        },
            'PixelEventVendor::testPixelJsFansOutVendors' => function () {
            $this->assert_PixelEventVendor_testPixelJsFansOutVendors();
        },
            'PixelEventVendorRequestMemo::testListRuntimeVendorsUsesRequestContextAndProcessMemo' => function () {
            $this->assert_PixelEventVendorRequestMemo_testListRuntimeVendorsUsesRequestContextAndProcessMemo();
        },
            'PixelHotBufferFlushAttribution::testHydrateFillsLegacyBufferedRowWithoutFlatColumns' => function () {
            $this->assert_PixelHotBufferFlushAttribution_testHydrateFillsLegacyBufferedRowWithoutFlatColumns();
        },
            'PixelHotBufferFlushAttribution::testPrepareAndHydrateAgreeOnWch' => function () {
            $this->assert_PixelHotBufferFlushAttribution_testPrepareAndHydrateAgreeOnWch();
        },
            'PixelHotBufferFlushAttribution::testHotBufferFlushSourceCallsHydrate' => function () {
            $this->assert_PixelHotBufferFlushAttribution_testHotBufferFlushSourceCallsHydrate();
        },
            'PixelPaidWidget::testWidgetPhpRegistersPixelPaid' => function () {
            $this->assert_PixelPaidWidget_testWidgetPhpRegistersPixelPaid();
        },
            'PixelPaidWidget::testWidgetCodeMatchesReportCatalogWithPaidFilter' => function () {
            $this->assert_PixelPaidWidget_testWidgetCodeMatchesReportCatalogWithPaidFilter();
        },
            'PixelPaidWidget::testTemplateUsesEngineReportAndPaidDrilldown' => function () {
            $this->assert_PixelPaidWidget_testTemplateUsesEngineReportAndPaidDrilldown();
        },
            'PixelPaidWidget::testPaidDrilldownUrlCarriesTrafficTypeAndCampaign' => function () {
            $this->assert_PixelPaidWidget_testPaidDrilldownUrlCarriesTrafficTypeAndCampaign();
        },
            'PixelPaidWidget::testPaidReportCodeIsMountedOnDetailTabs' => function () {
            $this->assert_PixelPaidWidget_testPaidReportCodeIsMountedOnDetailTabs();
        },
            'PixelSandboxPanelWhitelist::testWhitelistCommands' => function () {
            $this->assert_PixelSandboxPanelWhitelist_testWhitelistCommands();
        },
            'PixelSocialWidget::testWidgetPhpRegistersPixelSocial' => function () {
            $this->assert_PixelSocialWidget_testWidgetPhpRegistersPixelSocial();
        },
            'PixelSocialWidget::testWidgetCodeMatchesReportCatalogWithSocialFilter' => function () {
            $this->assert_PixelSocialWidget_testWidgetCodeMatchesReportCatalogWithSocialFilter();
        },
            'PixelSocialWidget::testTemplateUsesEngineReportAndSocialDrilldown' => function () {
            $this->assert_PixelSocialWidget_testTemplateUsesEngineReportAndSocialDrilldown();
        },
            'PixelSocialWidget::testSocialDrilldownUrlCarriesTrafficTypeAndChannel' => function () {
            $this->assert_PixelSocialWidget_testSocialDrilldownUrlCarriesTrafficTypeAndChannel();
        },
            'PixelSocialWidget::testSocialReportCodeIsMountedOnDetailTabs' => function () {
            $this->assert_PixelSocialWidget_testSocialReportCodeIsMountedOnDetailTabs();
        },
            'PixelStatisticsServiceListDrilldown::testListDrilldownQueryKeysAreStable' => function () {
            $this->assert_PixelStatisticsServiceListDrilldown_testListDrilldownQueryKeysAreStable();
        },
            'PixelStatisticsServiceListDrilldown::testBuildQueryOnlyEmitsContractKeys' => function () {
            $this->assert_PixelStatisticsServiceListDrilldown_testBuildQueryOnlyEmitsContractKeys();
        },
            'PixelStatisticsServiceListDrilldown::testListFormAndControllerAcceptAllContractKeys' => function () {
            $this->assert_PixelStatisticsServiceListDrilldown_testListFormAndControllerAcceptAllContractKeys();
        },
            'PixelStatisticsServiceListDrilldown::testDrilldownQueryRoundTripsThroughNormalize' => function () {
            $this->assert_PixelStatisticsServiceListDrilldown_testDrilldownQueryRoundTripsThroughNormalize();
        },
            'PixelStatisticsServiceListDrilldown::testAllDrilldownSurfacesUseSharedBuilder' => function () {
            $this->assert_PixelStatisticsServiceListDrilldown_testAllDrilldownSurfacesUseSharedBuilder();
        },
            'PixelStatisticsServiceListDrilldown::testTrafficChannelDetailLinksListWithChannelCode' => function () {
            $this->assert_PixelStatisticsServiceListDrilldown_testTrafficChannelDetailLinksListWithChannelCode();
        },
            'PixelStatisticsServiceListDrilldown::testListSpotCheckChannelFilterReducesOrEqualsTotalWhenQueryable' => function () {
            $this->assert_PixelStatisticsServiceListDrilldown_testListSpotCheckChannelFilterReducesOrEqualsTotalWhenQueryable();
        },
            'PixelTrafficTypeWidget::testWidgetPhpRegistersPixelTrafficType' => function () {
            $this->assert_PixelTrafficTypeWidget_testWidgetPhpRegistersPixelTrafficType();
        },
            'PixelTrafficTypeWidget::testWidgetCodeMatchesReportCatalog' => function () {
            $this->assert_PixelTrafficTypeWidget_testWidgetCodeMatchesReportCatalog();
        },
            'PixelTrafficTypeWidget::testTemplateUsesEngineReportAndDrilldown' => function () {
            $this->assert_PixelTrafficTypeWidget_testTemplateUsesEngineReportAndDrilldown();
        },
            'PixelTrafficTypeWidget::testTrafficTypeDrilldownUrlCarriesTrafficType' => function () {
            $this->assert_PixelTrafficTypeWidget_testTrafficTypeDrilldownUrlCarriesTrafficType();
        },
            'PixelTrafficTypeWidget::testTrafficTypeReportCodeIsMountedOnDetailTabs' => function () {
            $this->assert_PixelTrafficTypeWidget_testTrafficTypeReportCodeIsMountedOnDetailTabs();
        },
            'PixelValueByChannelWidget::testWidgetPhpRegistersPixelValueByChannel' => function () {
            $this->assert_PixelValueByChannelWidget_testWidgetPhpRegistersPixelValueByChannel();
        },
            'PixelValueByChannelWidget::testWidgetCodeMatchesReportCatalogNarrowMetrics' => function () {
            $this->assert_PixelValueByChannelWidget_testWidgetCodeMatchesReportCatalogNarrowMetrics();
        },
            'PixelValueByChannelWidget::testTemplateUsesEngineReportAndChannelDrilldown' => function () {
            $this->assert_PixelValueByChannelWidget_testTemplateUsesEngineReportAndChannelDrilldown();
        },
            'PixelValueByChannelWidget::testValueByChannelDrilldownUrlCarriesChannelCode' => function () {
            $this->assert_PixelValueByChannelWidget_testValueByChannelDrilldownUrlCarriesChannelCode();
        },
            'PixelValueByChannelWidget::testValueByChannelReportCodeIsMountedOnDetailTabs' => function () {
            $this->assert_PixelValueByChannelWidget_testValueByChannelReportCodeIsMountedOnDetailTabs();
        },
            'VisitorDashboardInstallerCatalogWidgets::testInstallerKeepsReplaceLayoutFalse' => function () {
            $this->assert_VisitorDashboardInstallerCatalogWidgets_testInstallerKeepsReplaceLayoutFalse();
        },
            'VisitorDashboardInstallerCatalogWidgets::testInstallerSeedContainsLegacyAndCatalogWidgets' => function () {
            $this->assert_VisitorDashboardInstallerCatalogWidgets_testInstallerSeedContainsLegacyAndCatalogWidgets();
        },
            'VisitorDashboardInstallerCatalogWidgets::testCatalogCodesMatchReportCatalogEnabled' => function () {
            $this->assert_VisitorDashboardInstallerCatalogWidgets_testCatalogCodesMatchReportCatalogEnabled();
        },
            'VisitorDashboardInstallerCatalogWidgets::testWidgetPhpInjectionsRemainOptional' => function () {
            $this->assert_VisitorDashboardInstallerCatalogWidgets_testWidgetPhpInjectionsRemainOptional();
        },
            'VisitorTrackingRuntimeReload::test_visitor_tracking_config_exposes_revision_and_custom_events' => function () {
            $this->assert_VisitorTrackingRuntimeReload_test_visitor_tracking_config_exposes_revision_and_custom_events();
        },
            'VisitorTrackingRuntimeReload::test_mutation_services_bump_runtime_revision' => function () {
            $this->assert_VisitorTrackingRuntimeReload_test_mutation_services_bump_runtime_revision();
        },
            'VisitorTrackingRuntimeReload::test_sandbox_notes_revision_then_reloads_runtime' => function () {
            $this->assert_VisitorTrackingRuntimeReload_test_sandbox_notes_revision_then_reloads_runtime();
        },
        ];
    }

private function assert_AutoDiscoverLockedEvents_test_annotation_service_exposes_origin_and_lock(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/EventAnnotationService.php');
    self::assertStringContainsString("ORIGIN_AUTO_DISCOVERED = 'auto_discovered'", $src);
    self::assertStringContainsString("ORIGIN_MANUAL = 'manual'", $src);
    self::assertStringContainsString("'deletable'", $src);
    self::assertStringContainsString('function isLockedMeta', $src);
    self::assertStringContainsString('function isDeletable', $src);
    self::assertStringContainsString('promote', $src);
    self::assertStringContainsString('origin_label', $src);
    self::assertStringContainsString('自动发现', $src);
    self::assertStringContainsString('function removeMeta', $src);
}

private function assert_AutoDiscoverLockedEvents_test_test_residual_purge_helpers(): void
{
    $disc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/DiscoveredEventService.php');
    self::assertStringContainsString('function isTestResidualAutoDiscoverName', $disc);
    self::assertStringContainsString('function purgeTestResiduals', $disc);
    self::assertStringContainsString('(?:browser_)?auto_disc_\d+', $disc);
    self::assertStringContainsString('e2e_(?:auto_disc|sf_auto)_\d+', $disc);
    self::assertStringContainsString('removeMeta', $disc);
    self::assertStringContainsString("'promote' => 1", $disc);
    $svc = new \Weline\Visitor\Service\DiscoveredEventService(new \Weline\Visitor\Service\EventDictionaryService());
    self::assertTrue($svc->isTestResidualAutoDiscoverName('browser_auto_disc_1789115306348'));
    self::assertTrue($svc->isTestResidualAutoDiscoverName('browser_auto_disc_1789115306348_direct'));
    self::assertTrue($svc->isTestResidualAutoDiscoverName('auto_disc_1789115085'));
    self::assertTrue($svc->isTestResidualAutoDiscoverName('e2e_auto_disc_1789117910212'));
    self::assertTrue($svc->isTestResidualAutoDiscoverName('e2e_sf_auto_1789117845068'));
    self::assertFalse($svc->isTestResidualAutoDiscoverName('banner_title'));
    self::assertFalse($svc->isTestResidualAutoDiscoverName('buy_now'));
    self::assertFalse($svc->isTestResidualAutoDiscoverName('add_to_wishlist'));
}

private function assert_AutoDiscoverLockedEvents_test_discovered_remove_and_controller_refuse_locked(): void
{
    $disc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/DiscoveredEventService.php');
    $tv = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php');
    self::assertStringContainsString('isLockedMeta', $disc);
    self::assertStringContainsString('locked_auto_discovered', $tv);
    self::assertStringContainsString('isDeletable', $tv);
    self::assertStringContainsString('403', $tv);
}

private function assert_AutoDiscoverLockedEvents_test_register_auto_sandbox_gate_and_idempotent(): void
{
    $picker = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Analytics/EventPicker.php');
    self::assertStringContainsString('function postRegisterAuto', $picker);
    self::assertStringContainsString('function wantsRegisterAuto', $picker);
    self::assertStringContainsString('register_auto', $picker);
    self::assertStringContainsString('sandbox_required', $picker);
    self::assertStringContainsString("sandbox !== '1'", $picker);
    self::assertStringContainsString("'already' => true", $picker);
    self::assertStringContainsString('ORIGIN_AUTO_DISCOVERED', $picker);
    self::assertStringContainsString('invalidateAfterMutation', $picker);
    self::assertStringContainsString('rate_limited', $picker);
    self::assertStringContainsString('generic_name', $picker);
    self::assertStringContainsString('dictionary', $picker);
    self::assertStringContainsString("'event_name'", $picker);
    self::assertStringContainsString('private, no-store', $picker);
}

private function assert_AutoDiscoverLockedEvents_test_admin_ui_badge_and_delete_disabled(): void
{
    $admin = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml');
    self::assertStringContainsString('tv-badge-auto-discovered', $admin);
    self::assertStringContainsString('自动发现', $admin);
    self::assertStringContainsString('data-locked="1"', $admin);
    self::assertStringContainsString('不可删', $admin);
    self::assertStringContainsString('locked_auto_discovered', $admin);
}

private function assert_AutoDiscoverLockedEvents_test_pixel_local_cache_and_sandbox_only_register(): void
{
    $pixel = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/taglib/js/pixel.phtml');
    foreach ([$pixel, $phtml] as $src) {
        self::assertStringContainsString('weline-visitor-custom-events:', $src);
        self::assertStringContainsString('pending_register', $src);
        self::assertStringContainsString('__syncCustomEventsLocalCacheFromRuntime', $src);
        self::assertStringContainsString('__maybeRegisterAutoDiscoveredEvent', $src);
        self::assertStringContainsString('__isSandboxMonitorActiveForAutoRegister', $src);
        self::assertStringContainsString('register_auto', $src);
        self::assertStringContainsString("sandbox', '1'", $src);
        self::assertStringNotContainsString('setInterval', $src);
        // 不走 stream/observe 写池
        self::assertStringNotContainsString('sandbox_stream=1', $src);
    }
}

private function assert_ConversionEventDedupe_testVisitorTrackingConfigExposesConversionDedupeRuntime(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/VisitorTrackingConfig.php');
    self::assertStringContainsString('KEY_CONVERSION_DEDUPE_ENABLED', $src);
    self::assertStringContainsString('KEY_CONVERSION_DEDUPE_TTL_DAYS', $src);
    self::assertStringContainsString('KEY_CONVERSION_DEDUPE_EVENTS', $src);
    self::assertStringContainsString("'conversionDedupe'", $src);
    self::assertStringContainsString('buildConversionDedupeRuntime', $src);
}

private function assert_ConversionEventDedupe_testTrackingPhtmlDeclaresDedupeFields(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml');
    self::assertStringContainsString('visitor/tracking/conversion_dedupe_enabled', $src);
    self::assertStringContainsString('visitor/tracking/conversion_dedupe_ttl_days', $src);
    self::assertStringContainsString('visitor/tracking/conversion_dedupe_events', $src);
    self::assertStringContainsString('visitor_conversion_dedupe', $src);
}

private function assert_ConversionEventDedupe_testPixelEventServiceSkipsDuplicateReason(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelEventService.php');
    self::assertStringContainsString('conversionDedupe()', $src);
    self::assertStringContainsString('skipReason', $src);
    self::assertStringContainsString('PixelConversionDedupeService', $src);
}

private function assert_ConversionEventDedupe_testPixelJsHasLocalStorageDedupeGate(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/taglib/js/pixel.phtml');
    foreach ([$js, $phtml] as $source) {
        self::assertStringContainsString('function __isConversionDedupeDuplicate', $source);
        self::assertStringContainsString('function __markConversionDedupeSeen', $source);
        self::assertStringContainsString('function __emitConversionDedupeSandbox', $source);
        self::assertStringContainsString('function __conversionLedgerFamily', $source);
        self::assertStringContainsString('function __collectConversionAliasKeys', $source);
        self::assertStringContainsString('function __conversionBridgeGate', $source);
        self::assertStringContainsString('weline_pixel_dedupe:', $source);
        self::assertStringContainsString('conversionDedupe', $source);
        self::assertStringContainsString('sandboxDedupeIsDup', $source);
        self::assertStringContainsString('function __resolvePixelVendorArea', $source);
        self::assertStringContainsString('_earlyBuffer', $source);
        self::assertStringContainsString('回放缓冲：成功页 SSR track', $source);
        self::assertStringContainsString("__emitConversionDedupeSandbox(gate.name", $source);
        self::assertStringContainsString("hit_kind: 'dedupe'", $source);
        self::assertStringContainsString('bridge_status', $source);
        self::assertStringContainsString('未发送·去重', $source);
        self::assertStringNotContainsString("__shouldDropConversionDedupe(normalizedEventName, meta || {})", $source);
        self::assertStringContainsString('转化去重不拦截主 track', $source);
    }
}

private function assert_ConversionEventDedupe_testCheckoutSuccessExplicitTrackWithoutSessionStorageDedupe(): void
{
    $success = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Checkout/view/frontend/checkout/success.phtml');
    self::assertStringContainsString("WelinePixel.track('checkout_success'", $success);
    self::assertStringContainsString('maxAttempts = 80', $success);
    self::assertStringContainsString('items:', $success);
    self::assertStringContainsString('currency:', $success);
    self::assertStringContainsString('value:', $success);
    self::assertStringNotContainsString('sessionStorage', $success);
    $payment = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Payment/view/templates/frontend/checkout/payment-success.phtml');
    self::assertStringContainsString("WelinePixel.track('payment_success'", $payment);
    self::assertStringNotContainsString('sessionStorage.getItem(dedupeKey)', $payment);
    $return = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Payment/view/templates/frontend/checkout/return.phtml');
    self::assertStringContainsString("WelinePixel.track('payment_success'", $return);
    self::assertStringContainsString('items:', $return);
}

private function assert_ConversionEventDedupe_testPixelSandboxKeepsGa4EcommerceOnDedupeEmit(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/taglib/js/pixel.phtml');
    foreach ([$js, $phtml] as $source) {
        self::assertStringContainsString('putEcommerceBag', $source);
        self::assertStringContainsString('items_count', $source);
        self::assertStringContainsString("'item_' + n + '_name'", $source);
        self::assertStringContainsString("params.items = items.slice(0, 20)", $source);
        self::assertStringContainsString('function __getOrderConfirmItems', $source);
    }
}

private function assert_EventChainModuleRegister_testCollectorEventNameConstant(): void
{
    self::assertSame('Weline_Visitor::event_chain_collect', EventChainCollector::EVENT_COLLECT);
}

private function assert_EventChainModuleRegister_testExtendsDeclaresEventChainProvider(): void
{
    $extends = require '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends.php';
    self::assertArrayHasKey('EventChainProvider', $extends['extends'] ?? []);
    self::assertSame(\Weline\Visitor\Interface\EventChainProviderInterface::class, $extends['extends']['EventChainProvider']['interface'] ?? null);
}

private function assert_EventChainModuleRegister_testDocDescribesCollectEvent(): void
{
    $doc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/doc/event/事件链注册.md');
    self::assertStringContainsString('Weline_Visitor::event_chain_collect', $doc);
    self::assertStringContainsString('EventChainProviderInterface', $doc);
    self::assertStringContainsString('complete_event', $doc);
}

private function assert_EventChainModuleRegister_testServiceMergesRegisteredChainsById(): void
{
    $dict = new EventDictionaryService();
    $collector = new class extends EventChainCollector
    {
        public function collect(int $websiteId = 0): array
        {
            return [['id' => 'checkout_express_pay', 'name' => '快捷支付结账', 'owner' => 'Weline_Checkout', 'complete_event' => 'express_pay_checkout_success', 'steps' => [['type' => 'track', 'event' => 'express_pay'], ['type' => 'track', 'event' => 'express_pay_started'], ['type' => 'track', 'event' => 'express_pay_confirmed'], ['type' => 'track', 'event' => 'express_pay_transaction'], ['type' => 'track', 'event' => 'checkout_success']]]];
        }
    };
    $svc = new EventChainService($dict, null, null, $collector);
    $bundle = $svc->getBundle(0);
    self::assertNotEmpty($bundle['chains']);
    $ids = array_map(static fn($c) => (string) ($c['id'] ?? ''), $bundle['chains']);
    self::assertContains('checkout_express_pay', $ids);
    self::assertTrue($svc->isRegisteredCompleteEvent(0, 'express_pay_checkout_success'));
    self::assertFalse($svc->isRegisteredCompleteEvent(0, 'checkout_success'));
}

private function assert_EventDictionaryAndAudit_testResolveCheckoutSuccessMapsToPurchase(): void
{
    $dict = new EventDictionaryService();
    $resolved = $dict->resolve('checkout_success');
    $this->assertNotNull($resolved);
    $this->assertSame('purchase', $resolved['ga4_event'] ?? null);
    $this->assertSame('dictionary', $resolved['weline_mapping_source'] ?? null);
}

private function assert_EventDictionaryAndAudit_testPageViewSkipsGtmPush(): void
{
    $dict = new EventDictionaryService();
    $resolved = $dict->resolve('page_view');
    $this->assertNotNull($resolved);
    $this->assertTrue(!empty($resolved['skip_gtm_push']));
}

private function assert_EventDictionaryAndAudit_testCtaOverride(): void
{
    $dict = new EventDictionaryService();
    $resolved = $dict->resolve('cta_click', ['cta_event_name' => 'generate_lead']);
    $this->assertNotNull($resolved);
    $this->assertSame('generate_lead', $resolved['ga4_event'] ?? null);
    $this->assertSame('site_cta_override', $resolved['weline_mapping_source'] ?? null);
}

private function assert_EventDictionaryAndAudit_testClassifierHomeAndCheckout(): void
{
    $c = new PixelPageTypeClassifier();
    $this->assertSame('home', $c->classify(['is_home' => true]));
    $this->assertSame('checkout', $c->classify(['url' => 'https://x.test/checkout']));
    $this->assertSame('account', $c->classify(['type' => 'login']));
    $this->assertSame('content', $c->classify(['handle' => 'about', 'url' => 'https://x.test/about']));
    $this->assertSame('page_type_unknown', $c->classify([]));
}

private function assert_EventDictionaryAndAudit_testScannerFindsMarkers(): void
{
    $scanner = new PixelMarkerScanner();
    $html = '<a class="weline-pixel::cta_click pb-c-cta" data-pixel-event="cta_click" href="/go">Go</a>';
    $scan = $scanner->scanHtml($html);
    $this->assertContains('cta_click', $scan['events']);
    $this->assertTrue($scanner->matchesMarkers(['classes' => ['weline-pixel::cta_click'], 'attrs' => ['data-pixel-event=cta_click']], $scan));
}

private function assert_EventDictionaryAndAudit_testScannerMissingMarkers(): void
{
    $scanner = new PixelMarkerScanner();
    $html = '<div class="hero"><button>Buy</button></div>';
    $scan = $scanner->scanHtml($html);
    $this->assertSame([], $scan['events']);
    $this->assertFalse($scanner->matchesMarkers(['classes' => ['weline-pixel::cta_click'], 'attrs' => ['data-cta']], $scan));
}

private function assert_EventDictionaryAndAudit_testScannerIgnoresInlineEventDictionaryBleed(): void
{
    $scanner = new PixelMarkerScanner();
    $html = <<<'HTML'
    <html><body>
    <button class="pb-c-cta weline-pixel::cta_click">Go</button>
    <script>
    var visitorTrackingConfig = {"eventDictionary":{"events":[
      {"weline_event":"add_to_cart","markers":{"classes":["weline-pixel::add_to_cart"],"attrs":["data-pixel-event=add_to_cart"]}},
      {"weline_event":"begin_checkout","markers":{"classes":["weline-pixel::begin_checkout"],"attrs":["data-pixel-event=begin_checkout"]}}
    ]}};
    </script>
    </body></html>
    HTML;
    $scan = $scanner->scanHtml($html);
    $this->assertContains('cta_click', $scan['events']);
    $this->assertNotContains('add_to_cart', $scan['events']);
    $this->assertNotContains('begin_checkout', $scan['events']);
    $this->assertFalse($scanner->matchesMarkers(['classes' => ['weline-pixel::add_to_cart'], 'attrs' => ['data-pixel-event=add_to_cart']], $scan));
}

private function assert_EventDictionaryAndAudit_testEcommerceEventsDoNotScopeToContentViaStar(): void
{
    $dict = new EventDictionaryService();
    $events = $dict->getEvents();
    $byName = [];
    foreach ($events as $entry) {
        if (\is_array($entry) && isset($entry['weline_event'])) {
            $byName[(string) $entry['weline_event']] = $entry;
        }
    }
    $this->assertArrayHasKey('add_to_cart', $byName);
    $this->assertArrayHasKey('friend_help_pay', $byName);
    $this->assertArrayHasKey('selection_share', $byName);
    $this->assertArrayHasKey('quick_buy', $byName);
    $this->assertArrayHasKey('express_pay', $byName);
    $this->assertArrayHasKey('express_pay_started', $byName);
    $this->assertArrayHasKey('express_pay_confirmed', $byName);
    $this->assertArrayHasKey('express_pay_transaction', $byName);
    $this->assertArrayHasKey('express_pay_checkout_success', $byName);
    $this->assertSame('找朋友代付', $byName['friend_help_pay']['label_zh'] ?? null);
    $this->assertSame('分享给朋友', $byName['selection_share']['label_zh'] ?? null);
    $this->assertSame('快捷购买', $byName['quick_buy']['label_zh'] ?? null);
    $this->assertSame('快捷支付', $byName['express_pay']['label_zh'] ?? null);
    $this->assertSame('快捷支付确认方式', $byName['express_pay_confirmed']['label_zh'] ?? null);
    $this->assertSame('快捷支付交易', $byName['express_pay_transaction']['label_zh'] ?? null);
    $this->assertSame('share', $byName['selection_share']['ga4_event'] ?? null);
    $this->assertSame('add_payment_info', $byName['express_pay']['ga4_event'] ?? null);
    $this->assertSame(['currency', 'value', 'items', 'payment_type'], $byName['express_pay']['required_params'] ?? null);
    $this->assertSame('begin_checkout', $byName['express_pay_started']['ga4_event'] ?? null);
    $this->assertSame(['currency', 'value', 'items'], $byName['express_pay_started']['required_params'] ?? null);
    $this->assertSame('add_shipping_info', $byName['express_pay_confirmed']['ga4_event'] ?? null);
    $this->assertSame(['currency', 'value', 'items', 'shipping_tier'], $byName['express_pay_confirmed']['required_params'] ?? null);
    $this->assertTrue(!empty($byName['express_pay_transaction']['skip_gtm_push']));
    $this->assertSame('purchase', $byName['express_pay_checkout_success']['ga4_event'] ?? null);
    $this->assertSame(['checkout'], $byName['add_to_cart']['page_scopes'] ?? null);
    $this->assertSame(['checkout'], $byName['view_item']['page_scopes'] ?? null);
    $this->assertSame(['checkout'], $byName['begin_checkout']['page_scopes'] ?? null);
}

private function assert_EventDictionaryAndAudit_testGa4RecommendedEcommerceEventsExistAsIdentity(): void
{
    $dict = new EventDictionaryService();
    $byName = [];
    foreach ($dict->getEvents() as $entry) {
        if (\is_array($entry) && isset($entry['weline_event'])) {
            $byName[(string) $entry['weline_event']] = $entry;
        }
    }
    $identity = ['view_item', 'view_item_list', 'select_item', 'view_cart', 'add_to_cart', 'remove_from_cart', 'add_to_wishlist', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase', 'refund', 'view_promotion', 'select_promotion', 'share', 'search', 'select_content', 'login', 'sign_up', 'generate_lead', 'page_view'];
    foreach ($identity as $name) {
        $this->assertArrayHasKey($name, $byName, $name);
        $this->assertSame($name, $byName[$name]['ga4_event'] ?? null, $name);
        $this->assertTrue(!empty($byName[$name]['google_recommended']), $name);
    }
    $ga4 = new \Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\Ga4Vendor();
    $map = $ga4->getDefaultEventMap();
    $this->assertSame('add_payment_info', $map['express_pay'] ?? null);
    $this->assertSame('purchase', $map['express_pay_checkout_success'] ?? null);
    $this->assertSame('purchase', $map['purchase'] ?? null);
}

private function assert_EventDictionaryAndAudit_testPixelGa4ParamsAliasPaymentAndShipping(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel.js');
    $this->assertStringContainsString('params.payment_type', $js);
    $this->assertStringContainsString('params.shipping_tier', $js);
    $this->assertStringContainsString('transaction_no', $js);
    $this->assertStringContainsString("'payment_type'", $js);
    $this->assertStringContainsString('express_pay_confirmed', $js);
    $phtml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/taglib/js/pixel.phtml');
    $this->assertStringContainsString('params.payment_type', $phtml);
    $this->assertStringContainsString('params.shipping_tier', $phtml);
}

private function assert_PixelBootstrapModuleStaticUrl_testPixelAndPanelBootstrapNormalizeThemePathViaPublicThemeNamespace(): void
{
    foreach (['/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelBootstrapHtmlService.php', '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/VisitorPanelBootstrapHtmlService.php'] as $path) {
        self::assertFileExists($path);
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('PublicThemeNamespace::resolve', $src, $path);
        self::assertDoesNotMatchRegularExpression('/\$url\s*=\s*[\'"]\/static\/[\'"]\s*\.\s*\\\\str_replace\(/', $src, 'Raw theme.path must not be str_replace-spliced into /static/: ' . $path);
    }
}

private function setUp_PixelBootstrapOncePerRequest(): void
{
    Context::leave();
    Context::enter(new Context());
    RequestContext::remove(PixelBootstrapHtmlService::EMITTED_REQUEST_KEY);
}

private function tearDown_PixelBootstrapOncePerRequest(): void
{
    RequestContext::remove(PixelBootstrapHtmlService::EMITTED_REQUEST_KEY);
    Context::leave();
    parent::tearDown();
}

private function assert_PixelBootstrapOncePerRequest_testEmittedFlagApiAndBodyEndSkipsDuplicateConfig(): void
{
    self::assertFalse(PixelBootstrapHtmlService::wasEmittedThisRequest());
    PixelBootstrapHtmlService::markEmittedThisRequest();
    self::assertTrue(PixelBootstrapHtmlService::wasEmittedThisRequest());
    $serviceSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelBootstrapHtmlService.php');
    self::assertStringContainsString('EMITTED_REQUEST_KEY', $serviceSrc);
    self::assertStringContainsString('wasEmittedThisRequest', $serviceSrc);
    self::assertStringContainsString('markEmittedThisRequest', $serviceSrc);
    $bodyEnd = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertStringContainsString('wasEmittedThisRequest()', $bodyEnd);
    self::assertStringContainsString('$__header_pixel_emitted', $bodyEnd);
    self::assertStringContainsString('data-weline-pixel-bootstrap=', $bodyEnd);
    self::assertStringContainsString('CTX_LAYOUT_TYPE', $bodyEnd);
    self::assertStringContainsString('homepage', $bodyEnd);
    self::assertStringNotContainsString("if (self::wasEmittedThisRequest()) {\n            return '';", (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelBootstrapHtmlService.php'), 'early empty return poisons chrome live include after cached peek mark');
    $chromeSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Service/LayoutEntity/ThemeLayoutEntityChrome.php');
    self::assertStringContainsString('noteVisitorPixelBootstrapIfPresent', $chromeSrc);
}

private function assert_PixelChannelsWidget_testWidgetPhpRegistersPixelChannels(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-channels.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/dashboard/widgets/pixel-channels.phtml');
    self::assertStringContainsString('@widget.code {pixel_channels}', $src);
    self::assertStringContainsString('@widget.type {table}', $src);
    self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
    self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
    self::assertStringContainsString('"required":false', $src);
}

private function assert_PixelChannelsWidget_testWidgetCodeMatchesReportCatalog(): void
{
    $catalog = (new PixelReportCatalog())->require('pixel_channels');
    self::assertSame('pixel_channels', $catalog['widget_code']);
    self::assertSame('channel_code', $catalog['dimension']);
}

private function assert_PixelChannelsWidget_testTemplateUsesEngineReportAndDrilldown(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/dashboard/widgets/pixel-channels.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('data-pixel-widget="pixel_channels"', $src);
    self::assertStringContainsString('getChannelsReport', $src);
    self::assertStringContainsString('channelDrilldownUrl', $src);
    self::assertStringContainsString('listUrl', $src);
    self::assertStringContainsString('详情报表', $src);
    // E05：已增量写入 Installer 种子布局
    $installer = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'pixel_channels'", $installer);
    self::assertStringContainsString("'replace_layout' => false", $installer);
}

private function assert_PixelChannelsWidget_testChannelDrilldownUrlCarriesChannelCode(): void
{
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $captured = null;
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(static function (string $path, array $query = []) use (&$captured): string {
        $captured = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . '?' . http_build_query($query);
    });
    $service = new PixelDashboardWidgetData($request, $url);
    $href = $service->channelDrilldownUrl(['range' => '7d', 'website_id' => 3], 'channel_code', 'summer_sale');
    self::assertStringContainsString('pixel-dashboard/list', $href);
    self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
    self::assertSame('summer_sale', $captured['query']['channel_code'] ?? null);
    self::assertSame('3', $captured['query']['websiteId'] ?? null);
    self::assertSame('7d', $captured['query']['range'] ?? null);
}

private function assert_PixelChannelsWidget_testChannelsReportCodeIsMountedOnDetailTabs(): void
{
    self::assertTrue((new PixelDetailReportTabService())->isMounted('pixel_channels'));
}

/** @var list<array{path:string,query:array<string,mixed>}> */
private array $calls_PixelDashboardWidgetDetailUrl = [];

private function assert_PixelDashboardWidgetDetailUrl_testDetailUrlWithWebsiteGoesToDetailReport(): void
{
    $service = $this->newService_PixelDashboardWidgetDetailUrl();
    $href = $service->detailUrl(['range' => '7d', 'website_id' => 23]);
    self::assertStringContainsString('pixel-dashboard/detail', $href);
    self::assertStringNotContainsString('pixel_dashboard', $href);
    self::assertSame('visitor/backend/pixel-dashboard/detail', $this->lastPath_PixelDashboardWidgetDetailUrl());
    self::assertSame('23', $this->lastQuery_PixelDashboardWidgetDetailUrl()['websiteId'] ?? null);
    self::assertSame('7d', $this->lastQuery_PixelDashboardWidgetDetailUrl()['range'] ?? null);
    self::assertArrayNotHasKey('event', $this->lastQuery_PixelDashboardWidgetDetailUrl());
}

private function assert_PixelDashboardWidgetDetailUrl_testDetailUrlAcceptsDefaultWebsiteIdZero(): void
{
    $service = $this->newService_PixelDashboardWidgetDetailUrl();
    $service->detailUrl(['range' => '30d', 'website_id' => 0]);
    self::assertSame('visitor/backend/pixel-dashboard/detail', $this->lastPath_PixelDashboardWidgetDetailUrl());
    self::assertSame('0', $this->lastQuery_PixelDashboardWidgetDetailUrl()['websiteId'] ?? null);
    self::assertSame('30d', $this->lastQuery_PixelDashboardWidgetDetailUrl()['range'] ?? null);
}

private function assert_PixelDashboardWidgetDetailUrl_testDetailUrlWithEventGoesToIndexNotDetail(): void
{
    $service = $this->newService_PixelDashboardWidgetDetailUrl();
    $href = $service->detailUrl(['range' => 'today', 'website_id' => 23, 'event' => 'purchase']);
    self::assertStringContainsString('pixel-dashboard/index', $href);
    self::assertSame('visitor/backend/pixel-dashboard/index', $this->lastPath_PixelDashboardWidgetDetailUrl());
    self::assertSame('purchase', $this->lastQuery_PixelDashboardWidgetDetailUrl()['event'] ?? null);
    self::assertSame('23', $this->lastQuery_PixelDashboardWidgetDetailUrl()['websiteId'] ?? null);
}

private function assert_PixelDashboardWidgetDetailUrl_testDetailUrlWithoutWebsiteGoesToIndex(): void
{
    $service = $this->newService_PixelDashboardWidgetDetailUrl();
    $service->detailUrl(['range' => '7d']);
    self::assertSame('visitor/backend/pixel-dashboard/index', $this->lastPath_PixelDashboardWidgetDetailUrl());
    self::assertArrayNotHasKey('websiteId', $this->lastQuery_PixelDashboardWidgetDetailUrl());
    self::assertSame('7d', $this->lastQuery_PixelDashboardWidgetDetailUrl()['range'] ?? null);
}

private function assert_PixelDashboardWidgetDetailUrl_testDetailUrlQueryKeysStayWithinContract(): void
{
    $service = $this->newService_PixelDashboardWidgetDetailUrl();
    $service->detailUrl(['range' => '7d', 'website_id' => 9, 'event' => 'page_view', 'channel_code' => 'must_not_leak', 'foo' => 'bar']);
    foreach (\array_keys($this->lastQuery_PixelDashboardWidgetDetailUrl()) as $key) {
        self::assertContains($key, ['websiteId', 'event', 'range'], 'detailUrl 不得泄漏非契约键: ' . $key);
    }
}

private function assert_PixelDashboardWidgetDetailUrl_testListUrlUsesListPathAndC07Keys(): void
{
    $service = $this->newService_PixelDashboardWidgetDetailUrl();
    $href = $service->listUrl(['range' => '7d', 'website_id' => 5], ['channel_code' => 'summer', 'traffic_type' => 'paid', 'utm_campaign' => 'july', 'page' => '2']);
    self::assertStringContainsString('pixel-dashboard/list', $href);
    self::assertSame('visitor/backend/pixel-dashboard/list', $this->lastPath_PixelDashboardWidgetDetailUrl());
    foreach (\array_keys($this->lastQuery_PixelDashboardWidgetDetailUrl()) as $key) {
        self::assertContains($key, PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS);
    }
    self::assertArrayNotHasKey('page', $this->lastQuery_PixelDashboardWidgetDetailUrl());
    self::assertSame('summer', $this->lastQuery_PixelDashboardWidgetDetailUrl()['channel_code'] ?? null);
    self::assertSame('paid', $this->lastQuery_PixelDashboardWidgetDetailUrl()['traffic_type'] ?? null);
    self::assertSame('july', $this->lastQuery_PixelDashboardWidgetDetailUrl()['utm_campaign'] ?? null);
}

private function assert_PixelDashboardWidgetDetailUrl_testCatalogWidgetsWireDetailAndListUrls(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $templates = ['pixel_channels' => 'pixel-channels.phtml', 'pixel_traffic_type' => 'pixel-traffic-type.phtml', 'pixel_paid' => 'pixel-paid.phtml', 'pixel_social' => 'pixel-social.phtml', 'pixel_event_value' => 'pixel-event-value.phtml', 'pixel_value_by_channel' => 'pixel-value-by-channel.phtml'];
    self::assertSame(VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES, \array_keys($templates));
    foreach ($templates as $code => $file) {
        $src = (string) \file_get_contents($root . '/view/templates/dashboard/widgets/' . $file);
        self::assertStringContainsString('detailUrl', $src, $code);
        self::assertStringContainsString('listUrl', $src, $code);
        self::assertStringContainsString('详情报表', $src, $code);
        self::assertStringContainsString('事件列表', $src, $code);
        self::assertStringContainsString("\$detailUrl", $src, $code);
        self::assertStringContainsString("\$listUrl", $src, $code);
        // 详情报表链到 detailUrl，事件列表链到 listUrl（禁止交叉）
        self::assertMatchesRegularExpression('/href="<\?= \$h\(\$detailUrl\) \?>"[^>]*>[\s\S]*?详情报表/', $src, $code . ' 详情报表须绑 detailUrl');
        self::assertMatchesRegularExpression('/href="<\?= \$h\(\$listUrl\) \?>"[^>]*>[\s\S]*?事件列表/', $src, $code . ' 事件列表须绑 listUrl');
    }
}

private function assert_PixelDashboardWidgetDetailUrl_testCatalogReportPayloadDocumentsDetailAndListFields(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $src = (string) \file_get_contents($root . '/Service/PixelDashboardWidgetData.php');
    self::assertStringContainsString("'detail_url' => \$detailUrl", $src);
    self::assertStringContainsString("'list_url' => \$listUrl", $src);
    self::assertStringContainsString('pixel-dashboard/detail', $src);
    self::assertStringContainsString('pixel-dashboard/list', $src);
    self::assertStringNotContainsString('pixel_dashboard/detail', $src);
    self::assertStringNotContainsString('pixel_dashboard/list', $src);
}

private function newService_PixelDashboardWidgetDetailUrl(): PixelDashboardWidgetData
{
    $this->calls_PixelDashboardWidgetDetailUrl = [];
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(function (string $path, array $query = []): string {
        $this->calls_PixelDashboardWidgetDetailUrl[] = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    });
    return new PixelDashboardWidgetData($request, $url);
}

private function lastPath_PixelDashboardWidgetDetailUrl(): string
{
    self::assertNotEmpty($this->calls_PixelDashboardWidgetDetailUrl);
    return (string) ($this->calls_PixelDashboardWidgetDetailUrl[\array_key_last($this->calls_PixelDashboardWidgetDetailUrl)]['path'] ?? '');
}

/**
 * @return array<string, mixed>
 */
private function lastQuery_PixelDashboardWidgetDetailUrl(): array
{
    self::assertNotEmpty($this->calls_PixelDashboardWidgetDetailUrl);
    $query = $this->calls_PixelDashboardWidgetDetailUrl[\array_key_last($this->calls_PixelDashboardWidgetDetailUrl)]['query'] ?? [];
    return \is_array($query) ? $query : [];
}

private function assert_PixelEcommerceWidgets_testWidgetPhpRegistersThreeEcommerceWidgets(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    foreach (['pixel_ecommerce_funnel' => 'pixel-ecommerce-funnel.phtml', 'pixel_ecommerce_revenue' => 'pixel-ecommerce-revenue.phtml', 'pixel_ecommerce_items' => 'pixel-ecommerce-items.phtml'] as $code => $file) {
        $tpl = 'Weline_Visitor::templates/dashboard/widgets/' . $file;
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl), $code);
        $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/dashboard/widgets/' . $file);
        self::assertStringContainsString('@widget.code {' . $code . '}', $src);
    }
}

private function assert_PixelEcommerceWidgets_testInstallerAppendsEcommerceWithoutReplacingCatalog(): void
{
    self::assertSame(['pixel_ecommerce_funnel', 'pixel_ecommerce_revenue', 'pixel_ecommerce_items'], VisitorDashboardPageInstaller::ECOMMERCE_WIDGET_CODES);
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $src = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'replace_layout' => false", $src);
    self::assertStringContainsString('ecommerceWidgets()', $src);
    foreach (VisitorDashboardPageInstaller::ECOMMERCE_WIDGET_CODES as $code) {
        self::assertStringContainsString("'{$code}'", $src);
    }
    foreach (VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
        self::assertStringContainsString("'{$code}'", $src, 'catalog still present');
    }
}

private function assert_PixelEcommerceWidgets_testTemplatesWireServiceMethodsAndAnchors(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $cases = ['pixel-ecommerce-funnel.phtml' => ['getEcommerceFunnelReport', 'pixel_ecommerce_funnel', '电商漏斗'], 'pixel-ecommerce-revenue.phtml' => ['getEcommerceRevenueReport', 'pixel_ecommerce_revenue', '购成与收入'], 'pixel-ecommerce-items.phtml' => ['getEcommerceItemsReport', 'pixel_ecommerce_items', '商品表现']];
    foreach ($cases as $file => [$method, $code, $title]) {
        $tpl = $root . '/view/templates/dashboard/widgets/' . $file;
        self::assertFileExists($tpl);
        $src = (string) \file_get_contents($tpl);
        self::assertStringContainsString($method, $src);
        self::assertStringContainsString('data-pixel-widget="' . $code . '"', $src);
        self::assertStringContainsString($title, $src);
        self::assertStringContainsString('详情报表', $src);
        self::assertStringContainsString('事件列表', $src);
    }
    $dataSrc = (string) \file_get_contents($root . '/Service/PixelDashboardWidgetData.php');
    self::assertStringContainsString("ecommerce-funnel", $dataSrc);
    self::assertStringContainsString("ecommerce-revenue", $dataSrc);
    self::assertStringContainsString("ecommerce-items", $dataSrc);
}

private function assert_PixelEcommerceWidgets_testEcommerceWidgetBaseRequiresWebsiteAndAnchorsDetail(): void
{
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $captured = [];
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(static function (string $path, array $query = []) use (&$captured): string {
        $captured[] = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . '?' . http_build_query($query);
    });
    $service = new PixelDashboardWidgetData($request, $url);
    $noSite = $service->ecommerceWidgetBase(['range' => '7d'], 'ecommerce-funnel');
    self::assertSame('website scope required', $noSite['error']);
    self::assertNull($noSite['website_id']);
    self::assertStringNotContainsString('#', (string) $noSite['detail_url']);
    $withSite = $service->ecommerceWidgetBase(['range' => '7d', 'website_id' => 9], 'ecommerce-funnel');
    self::assertSame(9, $withSite['website_id']);
    self::assertSame('', $withSite['error']);
    self::assertStringContainsString('#ecommerce-funnel', (string) $withSite['detail_url']);
    self::assertStringContainsString('pixel-dashboard/detail', (string) $withSite['detail_url']);
    self::assertNotSame('', (string) $withSite['start_date']);
    self::assertNotSame('', (string) $withSite['end_date']);
    $paths = array_column($captured, 'path');
    self::assertContains('visitor/backend/pixel-dashboard/detail', $paths);
    self::assertContains('visitor/backend/pixel-dashboard/list', $paths);
}

private function assert_PixelEcommerceWidgets_testEcommerceCodesStayOutOfReportCatalog(): void
{
    $catalogFile = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/etc/report_catalog.json';
    self::assertFileExists($catalogFile);
    $json = (string) \file_get_contents($catalogFile);
    foreach (VisitorDashboardPageInstaller::ECOMMERCE_WIDGET_CODES as $code) {
        self::assertStringNotContainsString('"' . $code . '"', $json);
    }
}

private function assert_PixelEventDiscovery_testDictionaryHasLeadSubmitLoginRegisterAndLabels(): void
{
    $dict = new EventDictionaryService();
    $by = [];
    foreach ($dict->getEvents() as $entry) {
        if (\is_array($entry) && isset($entry['weline_event'])) {
            $by[(string) $entry['weline_event']] = $entry;
        }
    }
    self::assertArrayHasKey('lead_submit', $by);
    self::assertSame('线索提交', $by['lead_submit']['label_zh'] ?? null);
    self::assertArrayHasKey('login', $by);
    self::assertSame('登录成功', $by['login']['label_zh'] ?? null);
    self::assertArrayHasKey('register', $by);
    self::assertSame('注册成功', $by['register']['label_zh'] ?? null);
    self::assertArrayNotHasKey('login_success', $by);
    $mappable = $dict->listMappableEvents();
    $names = \array_column($mappable, 'name');
    self::assertContains('login', $names);
    self::assertContains('register', $names);
    self::assertContains('lead_submit', $names);
    self::assertNotContains('page_view', $names);
}

private function assert_PixelEventDiscovery_testVendorDefaultsUseLoginRegisterNotSuccessSuffix(): void
{
    foreach ([new SystemVendor(), new Ga4Vendor(), new GtmVendor()] as $vendor) {
        $map = $vendor->getDefaultEventMap();
        self::assertArrayHasKey('login', $map);
        self::assertArrayHasKey('register', $map);
        self::assertArrayHasKey('lead_submit', $map);
        self::assertArrayNotHasKey('login_success', $map);
        self::assertArrayNotHasKey('register_success', $map);
    }
    self::assertSame('login', (new Ga4Vendor())->getDefaultEventMap()['login']);
    self::assertSame('sign_up', (new Ga4Vendor())->getDefaultEventMap()['register']);
}

private function assert_PixelEventDiscovery_testFilterMappableMapKeepsCustomKeys(): void
{
    $ref = new \ReflectionClass(PixelEventVendorManager::class);
    $manager = $ref->newInstanceWithoutConstructor();
    $filtered = $manager->filterMappableMap(['page_view' => 'page_view', 'login' => 'login', 'my_custom_event' => 'custom_tp']);
    self::assertArrayNotHasKey('page_view', $filtered);
    self::assertSame('login', $filtered['login'] ?? null);
    self::assertSame('custom_tp', $filtered['my_custom_event'] ?? null);
}

private function assert_PixelEventDiscovery_testDictionaryNormalizeKeepsCustomEventNames(): void
{
    $dict = new EventDictionaryService();
    self::assertSame('custom_browser_verify_event', $dict->normalizeEventName('custom_browser_verify_event'));
    self::assertSame('all_add_cart', $dict->normalizeEventName('All-Add-Cart'));
    self::assertSame('page_exit', $dict->normalizeEventName('page_exit'));
    self::assertNotSame('', $dict->normalizeEventName('persist_probe_mtvdo0av'));
}

private function assert_PixelEventDiscovery_testPickerTokenIssueAndValidate(): void
{
    $svc = new EventPickerTokenService(new EventDictionaryService());
    $issued = $svc->issue(1, 'ga4', 9);
    self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $issued['token']);
    $payload = $svc->validate($issued['token']);
    self::assertNotNull($payload);
    self::assertSame(1, $payload['website_id']);
    self::assertSame('ga4', $payload['vendor_code']);
    self::assertNull($svc->validate('deadbeefdeadbeefdeadbeefdeadbeef'));
}

private function assert_PixelEventDiscovery_testPickerAccumulateBuffer(): void
{
    $svc = new EventPickerTokenService(new EventDictionaryService());
    $svc->pushAccumulate(99, ['weline_event' => 'Login-Success!!', 'third_party_event' => 'login', 'source' => 'test', 'summary' => 'x', 'path' => '/a']);
    $list = $svc->listAccumulate(99, 10);
    self::assertNotEmpty($list);
    self::assertSame('login_success', $list[0]['weline_event'] ?? null);
}

private function assert_PixelEventDiscovery_testPickerAccumulateStoresChainPayload(): void
{
    $svc = new EventPickerTokenService(new EventDictionaryService());
    $svc->pushAccumulate(77, ['weline_event' => 'checkout_flow', 'third_party_event' => 'begin_checkout', 'source' => 'picker_chain_record', 'summary' => '操作链 3 步', 'path' => '/cart', 'kind' => 'chain', 'chain_steps' => 3, 'chain' => ['version' => 1, 'steps' => [['type' => 'page', 'path' => '/'], ['type' => 'click', 'label' => 'Buy'], ['type' => 'submit', 'label' => 'form']]]]);
    $list = $svc->listAccumulate(77, 5);
    self::assertSame('checkout_flow', $list[0]['weline_event'] ?? null);
    self::assertSame('chain', $list[0]['kind'] ?? null);
    self::assertSame(3, (int) ($list[0]['chain_steps'] ?? 0));
    self::assertIsArray($list[0]['chain'] ?? null);
}

private function assert_PixelEventDiscovery_testPickerAccumulateStoresParams(): void
{
    $svc = new EventPickerTokenService(new EventDictionaryService());
    $svc->pushAccumulate(66, ['weline_event' => 'cta_click', 'third_party_event' => 'click', 'source' => 'picker_observe', 'path' => '/x?utm_source=ad', 'params' => ['utm_source' => 'ad', 'value' => 12.5], 'hit_kind' => 'system']);
    $list = $svc->listAccumulate(66, 5);
    self::assertNotEmpty($list);
    self::assertSame('cta_click', $list[0]['weline_event'] ?? null);
    self::assertIsArray($list[0]['params'] ?? null);
    self::assertSame('ad', $list[0]['params']['utm_source'] ?? null);
    self::assertSame('system', $list[0]['hit_kind'] ?? null);
}

private function assert_PixelEventDiscovery_testPickerAccumulateSinceAndClear(): void
{
    $svc = new EventPickerTokenService(new EventDictionaryService());
    $wid = 55;
    $svc->clearAccumulate($wid);
    $svc->pushAccumulate($wid, ['weline_event' => 'click', 'source' => 'sandbox_stream', 'path' => '/', 'params' => ['x' => 1], 'event_hit' => false]);
    $svc->pushAccumulate($wid, ['weline_event' => 'page_view', 'source' => 'sandbox_stream', 'path' => '/', 'hit_kind' => 'system']);
    $all = $svc->listAccumulate($wid, 10);
    self::assertCount(2, $all);
    self::assertGreaterThan(0, (int) ($all[0]['seq'] ?? 0));
    $firstSeq = (int) ($all[1]['seq'] ?? 0);
    $since = $svc->listAccumulateSince($wid, $firstSeq, 10);
    self::assertNotEmpty($since['events']);
    self::assertSame('page_view', $since['events'][0]['weline_event'] ?? null);
    self::assertGreaterThan($firstSeq, (int) $since['cursor']);
    self::assertTrue($svc->clearAccumulate($wid));
    self::assertSame([], $svc->listAccumulate($wid, 10));
    $empty = $svc->listAccumulateSince($wid, 0, 10);
    self::assertSame([], $empty['events']);
    self::assertSame(0, (int) $empty['cursor']);
}

private function assert_PixelEventDiscovery_testAdminTemplateHasPickerAndAccumulate(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('tv-start-picker', $src);
    self::assertStringContainsString('tv-map-accumulate', $src);
    self::assertStringContainsString('本会话沙盒', $src);
    self::assertStringContainsString('短间隔读取前台沙盒继电缓冲', $src);
    self::assertStringContainsString('tv-acc-clear', $src);
    self::assertStringContainsString('liveEventsUrl', $src);
    self::assertStringContainsString('startLiveTunnel', $src);
    self::assertStringContainsString('clearSessionSandbox', $src);
    self::assertStringContainsString('sandboxClearEpoch', $src);
    self::assertStringContainsString('sandboxGeneration', $src);
    self::assertStringContainsString('generation=', $src);
    self::assertStringContainsString('sandbox_only=1', $src);
    // 纠偏：禁止 wait_ms 服务端长轮询；map 可见时短间隔短请求即可。
    self::assertStringNotContainsString('wait_ms=2500', $src);
    self::assertStringNotContainsString('wait_ms=', $src);
    self::assertStringContainsString('LIVE_POLL_MS', $src);
    self::assertStringContainsString('setTimeout(tick, LIVE_POLL_MS)', $src);
    self::assertStringContainsString('启动事件拾取', $src);
    self::assertStringContainsString('tv-ga4-event-form', $src);
    self::assertStringContainsString('tv-ga4-create-dialog', $src);
    self::assertStringContainsString('data-w-component="dialog"', $src);
    self::assertStringContainsString('openGa4CreateDialog', $src);
    self::assertStringContainsString('closeGa4CreateDialog', $src);
    self::assertStringContainsString('Weline.UI.dialog.open', $src);
    self::assertStringContainsString('tv-open-ga4-create', $src);
    self::assertStringContainsString('创建', $src);
    self::assertStringContainsString('匹配条件', $src);
    self::assertStringContainsString('tv-ga4-conditions', $src);
    self::assertStringContainsString('tv-match-type-event', $src);
    self::assertStringContainsString('tv-match-type-url', $src);
    self::assertStringContainsString('seedConditionsForType', $src);
    self::assertStringContainsString('conditions_json', $src);
    self::assertStringContainsString('match_param_options_json', $src);
    self::assertStringContainsString('tv-ga4-param-list', $src);
    self::assertStringContainsString('list="tv-ga4-param-list"', $src);
    self::assertStringContainsString('tv-ga4-param-pick', $src);
    self::assertStringContainsString('param_mappings_json', $src);
    self::assertStringContainsString('seedParamMappings', $src);
    self::assertStringContainsString('tv-ga4-map-table', $src);
    self::assertStringContainsString('data-cond-param', $src);
    self::assertStringContainsString('ensureMatchParamDatalist', $src);
    self::assertStringContainsString('参数可点选预设，也可手写', $src);
    self::assertStringContainsString('elementInfo.text', $src);
    self::assertStringContainsString('创建事件', $src);
    self::assertStringContainsString('tv-custom-create', $src);
    self::assertStringContainsString('focusCreateEvent', $src);
    self::assertStringContainsString('is_custom', $src);
    self::assertStringContainsString('parseUrlInsights', $src);
    self::assertStringContainsString('add_custom_event_url', $src);
    self::assertStringContainsString('data-map-variant="subtabs-stream"', $src);
    self::assertStringContainsString('tvp-map-stream', $src);
    self::assertStringContainsString('本会话沙盒数据流', $src);
    self::assertStringContainsString('data-testid="tv-stream-tabs"', $src);
    self::assertStringContainsString('data-testid="tv-stream-tab-system"', $src);
    self::assertStringContainsString('data-testid="tv-stream-tab-custom"', $src);
    self::assertStringContainsString('data-testid="tv-stream-tab-stream"', $src);
    self::assertStringContainsString('tvp_session_stream_v1', $src);
    self::assertStringContainsString('mergeSessionStream', $src);
    self::assertStringContainsString('hit_kind', $src);
    self::assertStringContainsString('data-hit-kind', $src);
    self::assertStringContainsString('eventParamsHtml', $src);
    self::assertStringContainsString('showSandboxCompanion', $src);
    self::assertStringContainsString('tvp-map-stream--float', $src);
    self::assertStringContainsString('data-float', $src);
    self::assertStringContainsString('tv-sandbox-float-close', $src);
    self::assertStringContainsString('copyableParamsHtml', $src);
    self::assertStringContainsString('data-testid="tv-acc-copy-key"', $src);
    self::assertStringContainsString('data-testid="tv-acc-copy-value"', $src);
    // 沙盒：按事件名搜索；点事件栏单开；禁止底部「收起参数」按钮。
    self::assertStringContainsString('data-testid="tv-acc-search"', $src);
    self::assertStringContainsString('id="tv-acc-search"', $src);
    self::assertStringContainsString('streamEventExpandKey', $src);
    self::assertStringContainsString('data-acc-bar', $src);
    self::assertStringContainsString('data-testid="tv-acc-bar"', $src);
    self::assertStringNotContainsString('收起参数', $src);
    self::assertStringNotContainsString('data-testid="tv-acc-expand"', $src);
    self::assertStringNotContainsString('tv-sandbox-companion', $src);
    self::assertStringContainsString('data-testid="tv-map-subtab-custom"', $src);
    self::assertStringContainsString('class="w-button"', $src);
    self::assertStringNotContainsString('data-map-subtab="sandbox"', $src);
    self::assertStringContainsString('confirm_signup', $src);
    self::assertStringNotContainsString('placeholder="add_to_cart"', $src);
    self::assertStringContainsString('mappable_event_rows', $src);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel-event-picker.js');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Analytics/EventPicker.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Api/Rest/V1/EventPicker.php');
    $pickerJs = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel-event-picker.js');
    self::assertStringContainsString('/visitor/analytics/event-picker/observe', $pickerJs);
    self::assertStringContainsString('/visitor/analytics/event-picker/record', $pickerJs);
    self::assertStringContainsString('data-wpp-mode="chain"', $pickerJs);
    self::assertStringContainsString('自动发现', $pickerJs);
    self::assertStringContainsString('进自定义池仍须', $pickerJs);
    self::assertStringContainsString("source: 'track'", $pickerJs);
    self::assertStringContainsString('suggestCustomName', $pickerJs);
    self::assertStringContainsString('flashCaptureNotice', $pickerJs);
    self::assertStringContainsString('点击捕捉', $pickerJs);
    self::assertStringContainsString('任意可点', $pickerJs);
    self::assertStringContainsString('data-testid="wpp-discover-empty"', $pickerJs);
    self::assertStringContainsString('data-testid="wpp-capture-flash"', $pickerJs);
    self::assertStringContainsString('1.1.12-record-bump-rev', $pickerJs);
    self::assertStringContainsString('applyRecordRevisionAfterPersist', $pickerJs);
    self::assertStringContainsString('noteConfigRevision', $pickerJs);
    self::assertStringNotContainsString('禁止点击 invent custom_*', $pickerJs);
    self::assertStringNotContainsString('已打标点击仅对照系统事件', $pickerJs);
    self::assertStringContainsString('高级事件链', $pickerJs);
    self::assertStringContainsString('已默认无需重新录入', $pickerJs);
    self::assertStringContainsString('isSystemOwned', $pickerJs);
    self::assertStringContainsString('录入自定义', $pickerJs);
    self::assertStringContainsString('named_custom', $pickerJs);
    self::assertStringContainsString('自定义池门禁', $pickerJs);
    self::assertStringContainsString('进自定义池仍须改名并点「录入自定义」', $pickerJs);
    self::assertStringContainsString('recordErrorText', $pickerJs);
    self::assertStringContainsString('invalid_token', $pickerJs);
    self::assertStringContainsString('maintenance', $pickerJs);
    self::assertStringContainsString('sessionStorage', $pickerJs);
    self::assertStringContainsString('chain_json', $pickerJs);
    self::assertStringContainsString('data-wpp-mode="mapped"', $pickerJs);
    self::assertStringContainsString('已录入', $pickerJs);
    self::assertStringContainsString('/visitor/analytics/event-picker/mapped', $pickerJs);
    self::assertStringContainsString('confirmDuplicateRecord', $pickerJs);
    self::assertStringContainsString('duplicate', $pickerJs);
    self::assertStringContainsString('force', $pickerJs);
    self::assertStringContainsString('wpp_session_v1', $pickerJs);
    self::assertStringContainsString('persistPickerSession', $pickerJs);
    self::assertStringContainsString('weline_pixel_picker_token', $pickerJs);
    self::assertStringContainsString('rewriteSameOriginNav', $pickerJs);
    self::assertStringContainsString('valuePick', $pickerJs);
    self::assertStringContainsString('enterValuePick', $pickerJs);
    self::assertStringContainsString('extractElementValue', $pickerJs);
    self::assertStringContainsString('wpp-custom-pick-value', $pickerJs);
    self::assertStringContainsString('选值模式', $pickerJs);
    self::assertStringContainsString('attachValueFields', $pickerJs);
    self::assertStringContainsString('1.1.12-record-bump-rev', $pickerJs);
    self::assertStringNotContainsString('window.confirm(', $pickerJs);
    self::assertStringContainsString('Weline.UI.dialog.confirm', $pickerJs);
    self::assertStringNotContainsString('window.confirm(', $src);
    self::assertStringContainsString('Weline.UI.dialog.confirm', $src);
    self::assertStringContainsString('themeConfirm', $src);
    self::assertStringNotContainsString('alert(', $src);
    $bodyEnd = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertStringContainsString('wpp_session_v1', $bodyEnd);
    self::assertStringContainsString('weline_pixel_picker', $bodyEnd);
    self::assertStringContainsString('data-wpp-src', $bodyEnd);
    self::assertStringContainsString('tv-custom-event-pool', $src);
    self::assertStringContainsString('tv-custom-scope-tip', $src);
    self::assertStringContainsString('本范围自定义事件', $src);
    self::assertStringContainsString('custom_events_url', $src);
    self::assertStringContainsString('refreshCustomEvents', $src);
    $scopeToolbar = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/partials/scope-toolbar.phtml');
    self::assertStringContainsString('tv-work-scope-custom-tip', $scopeToolbar);
    self::assertStringContainsString('自定义事件不随范围继承', $scopeToolbar);
    $discoveredSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/DiscoveredEventService.php');
    self::assertStringContainsString('scopeKeyFromStorage', $discoveredSrc);
    self::assertStringContainsString('listCustomEvents', $discoveredSrc);
    $tvPhp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php');
    self::assertStringContainsString('function getCustomEvents', $tvPhp);
    self::assertStringContainsString('function postDeleteCustomEvent', $tvPhp);
    self::assertStringContainsString('function postAddCustomEvent', $tvPhp);
    self::assertStringContainsString('add-custom-event', $tvPhp);
    self::assertStringContainsString('match_param_options_json', $tvPhp);
    self::assertStringContainsString('ResponseTerminateException', $tvPhp);
    self::assertStringContainsString('// redirect() 以 Error 终止响应，不可当业务失败', $tvPhp);
    self::assertStringContainsString('tv-map-subtabs', $src);
    self::assertStringContainsString('data-map-subtab', $src);
    self::assertStringContainsString('tv-custom-search', $src);
    self::assertStringContainsString('data-custom-edit', $src);
    self::assertStringContainsString('focusEditEvent', $src);
    self::assertStringContainsString('activateMapSubtab', $src);
    self::assertStringContainsString('tvp_map_subtab_v1', $src);
    self::assertStringContainsString('data-custom-del', $src);
    self::assertStringContainsString('deleteCustomEvent', $src);
    self::assertStringContainsString('delete_custom_event_url', $src);
    self::assertStringContainsString('不会一直累积', $src);
    self::assertStringContainsString('data-event-star', $src);
    self::assertStringContainsString('annotate_event_url', $src);
    self::assertStringContainsString('data-event-filter', $src);
    self::assertStringContainsString('有值', $src);
    $annSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/EventAnnotationService.php');
    self::assertStringContainsString('function setStarred', $annSrc);
    self::assertStringContainsString('function setCategory', $annSrc);
    self::assertStringContainsString('function markHasValue', $annSrc);
    self::assertStringContainsString('function enrichRows', $annSrc);
    self::assertStringContainsString('function normalizeMatchConditions', $annSrc);
    self::assertStringContainsString('MATCH_PARAM_LABELS', $annSrc);
    self::assertStringContainsString("'className'", $annSrc);
    self::assertStringContainsString('a-zA-Z0-9_', $annSrc);
    $tvPhp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php');
    self::assertStringContainsString('function postAnnotateEvent', $tvPhp);
    $discoveredSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/DiscoveredEventService.php');
    self::assertStringContainsString('function remove', $discoveredSrc);
    $pickerPhp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Analytics/EventPicker.php');
    self::assertStringContainsString('function postMapped', $pickerPhp);
    self::assertStringContainsString("'error' => 'duplicate'", $pickerPhp);
    self::assertStringContainsString('自定义池门禁', $pickerPhp);
    self::assertStringContainsString("\$kind === 'chain' && \$persistMap && \$chain !== null", $pickerPhp);
    self::assertStringContainsString("'category' => 'chain'", $pickerPhp);
    self::assertStringContainsString("'chain' => '事件链'", $annSrc);
    self::assertMatchesRegularExpression('/自定义池门禁[\s\S]{0,200}if \(\$persistMap\) \{[\s\S]{0,80}\$discovered->add/', $pickerPhp);
    $restPhp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Api/Rest/V1/EventPicker.php');
    self::assertStringContainsString('自定义池门禁', $restPhp);
    self::assertMatchesRegularExpression('/自定义池门禁[\s\S]{0,200}if \(\$persistMap\) \{[\s\S]{0,80}\$discovered->add/', $restPhp);
}

private function assert_PixelEventDiscovery_testDiscoveredScopeKeysAreIsolatedAndCustomLabelled(): void
{
    $svc = new \Weline\Visitor\Service\DiscoveredEventService(new EventDictionaryService());
    $a = 'site_a.store_x.channel_1';
    $b = 'site_a.store_y.channel_1';
    self::assertNotSame($svc->scopeKeyFromStorage($a, 1), $svc->scopeKeyFromStorage($b, 1));
    self::assertStringStartsWith('scope.', $svc->scopeKeyFromStorage($a, 1));
    self::assertSame('website.3', $svc->scopeKeyFromStorage('', 3));
}

private function assert_PixelEventValueWidget_testWidgetPhpRegistersPixelEventValue(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-event-value.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-social.phtml'));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-value-by-channel.phtml'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/dashboard/widgets/pixel-event-value.phtml');
    self::assertStringContainsString('@widget.code {pixel_event_value}', $src);
    self::assertStringContainsString('@widget.type {table}', $src);
    self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
    self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
    self::assertStringContainsString('"required":false', $src);
}

private function assert_PixelEventValueWidget_testWidgetCodeMatchesReportCatalog(): void
{
    $catalog = (new PixelReportCatalog())->require('pixel_event_value');
    self::assertSame('pixel_event_value', $catalog['widget_code']);
    self::assertSame('event_name', $catalog['dimension']);
    self::assertSame([], $catalog['filters']);
    self::assertContains('value_sum', $catalog['metrics']);
}

private function assert_PixelEventValueWidget_testTemplateUsesEngineReportAvgValueAndEventDrilldown(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/dashboard/widgets/pixel-event-value.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('data-pixel-widget="pixel_event_value"', $src);
    self::assertStringContainsString('getEventValueReport', $src);
    self::assertStringContainsString('channelDrilldownUrl', $src);
    self::assertStringContainsString('avg_value', $src);
    self::assertStringContainsString('详情报表', $src);
    $installer = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'pixel_event_value'", $installer);
    self::assertStringContainsString("'replace_layout' => false", $installer);
}

private function assert_PixelEventValueWidget_testEventValueDrilldownUrlCarriesEvent(): void
{
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $captured = null;
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(static function (string $path, array $query = []) use (&$captured): string {
        $captured = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . '?' . http_build_query($query);
    });
    $service = new PixelDashboardWidgetData($request, $url);
    $href = $service->channelDrilldownUrl(['range' => '7d', 'website_id' => 1], 'event_name', 'purchase');
    self::assertStringContainsString('pixel-dashboard/list', $href);
    self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
    self::assertSame('purchase', $captured['query']['event'] ?? null);
    self::assertSame('1', $captured['query']['websiteId'] ?? null);
    self::assertSame('7d', $captured['query']['range'] ?? null);
}

private function assert_PixelEventValueWidget_testEventValueReportCodeIsMountedOnDetailTabs(): void
{
    self::assertTrue((new PixelDetailReportTabService())->isMounted('pixel_event_value'));
}

private function assert_PixelEventVendor_testBuiltInVendorsImplementInterfaceAndDefaultSandbox(): void
{
    $ga4 = new Ga4Vendor();
    $gtm = new GtmVendor();
    $system = new SystemVendor();
    self::assertInstanceOf(PixelEventVendorInterface::class, $ga4);
    self::assertInstanceOf(PixelEventVendorInterface::class, $gtm);
    self::assertInstanceOf(PixelEventVendorInterface::class, $system);
    self::assertSame('ga4', $ga4->getCode());
    self::assertSame('gtm', $gtm->getCode());
    self::assertSame('weline', $system->getCode());
    self::assertSame('sandbox', $ga4->getDefaultMode());
    self::assertSame('sandbox', $gtm->getDefaultMode());
    self::assertSame('sandbox', $system->getDefaultMode());
    self::assertArrayHasKey('checkout_success', $ga4->getDefaultEventMap());
    self::assertSame('purchase', $ga4->getDefaultEventMap()['checkout_success']);
    self::assertSame('checkout_success', $system->getDefaultEventMap()['checkout_success'] ?? null);
    self::assertArrayHasKey('measurement_id', $ga4->getConfigSchema());
    self::assertArrayHasKey('container_id', $gtm->getConfigSchema());
    self::assertTrue(!empty($system->getCapabilities()['system_native']));
    self::assertNotEmpty($ga4->cspDirectives()['script-src'] ?? []);
    self::assertNotEmpty($gtm->cspDirectives()['frame-src'] ?? []);
    self::assertSame([], $system->cspDirectives());
}

private function assert_PixelEventVendor_testSystemVendorAlwaysEnabledAndLocked(): void
{
    $ref = new \ReflectionClass(PixelEventVendorManager::class);
    $manager = $ref->newInstanceWithoutConstructor();
    $seed = $ref->getMethod('legacyEnabledSeed');
    $seed->setAccessible(true);
    self::assertTrue($seed->invoke($manager, 'weline', 0));
    self::assertTrue($seed->invoke($manager, 'weline', 1));
    $isSys = $ref->getMethod('isSystemVendorCode');
    $isSys->setAccessible(true);
    self::assertTrue($isSys->invoke($manager, 'weline'));
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelEventVendorManager.php');
    self::assertStringContainsString('isSystemVendorCode($code)', $src);
    self::assertMatchesRegularExpression('/isSystemVendorCode\(\$code\)[\s\S]{0,40}return\s+true;/', $src);
    $tpl = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml');
    self::assertStringContainsString('data-locked="1"', $tpl);
    self::assertStringContainsString('系统像素默认启用且不可关闭', $tpl);
}

private function assert_PixelEventVendor_testFilterMappableMapDropsSkipGtmPushEvents(): void
{
    $ref = new \ReflectionClass(PixelEventVendorManager::class);
    $manager = $ref->newInstanceWithoutConstructor();
    $filtered = $manager->filterMappableMap(['page_view' => 'page_view', 'cta_click' => 'cta_click', 'checkout_success' => 'purchase']);
    self::assertArrayNotHasKey('page_view', $filtered);
    self::assertSame('cta_click', $filtered['cta_click'] ?? null);
    self::assertSame('purchase', $filtered['checkout_success'] ?? null);
}

private function assert_PixelEventVendor_testExtendsDeclaresPixelEventVendorPoint(): void
{
    $extends = include '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends.php';
    self::assertIsArray($extends);
    self::assertArrayHasKey('extends', $extends);
    self::assertArrayHasKey('PixelEventVendor', $extends['extends']);
    self::assertSame(PixelEventVendorInterface::class, $extends['extends']['PixelEventVendor']['interface']);
}

private function assert_PixelEventVendor_testAdminSplitLayoutTemplateExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('data-variant-winner="2"', $src);
    self::assertStringContainsString('data-layout="split"', $src);
    self::assertStringContainsString('tv-nav', $src);
    self::assertStringContainsString('事件搭接', $src);
    self::assertStringContainsString('范围', $src);
    self::assertStringContainsString('data-tab="scope"', $src);
    self::assertStringContainsString('DEV：不上报', $src);
    self::assertStringContainsString('tracking-vendor-admin.css', $src);
    self::assertStringContainsString('tvp-detail__foot', $src);
    self::assertStringContainsString('data-tv-js-editor', $src);
    self::assertStringContainsString('data-tv-js-format', $src);
    self::assertStringContainsString('data-tv-js-theme-float', $src);
    self::assertStringContainsString('data-tv-js-theme="material-darker"', $src);
    self::assertStringContainsString('data-tv-js-theme="dracula"', $src);
    self::assertStringNotContainsString('data-testid="tv-js-theme"', $src);
    self::assertStringNotContainsString('<select class="w-input" data-size="sm" data-tv-js-theme', $src);
    self::assertStringContainsString('tracking-vendor-js-editor.js', $src);
    self::assertStringContainsString('codemirror.js', $src);
    self::assertStringContainsString('tv-start-picker', $src);
    self::assertStringContainsString('tv-map-accumulate', $src);
    self::assertStringContainsString('markMapCustomized', $src);
    self::assertStringContainsString('tv-use-default-map', $src);
    self::assertStringContainsString('tv-default-map-hint', $src);
    self::assertStringContainsString('（自定义）', $src);
    $tvPhp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php');
    self::assertStringContainsString('mergeEventMapKeysIntoMappable', $tvPhp);
    self::assertStringContainsString('已保存搭接中的自定义 key 必须进入下拉', $tvPhp);
    $css = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/css/tracking-vendor-admin.css');
    self::assertStringContainsString('max-inline-size: none', $css);
    self::assertStringContainsString('inline-size: 100%', $css);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/tracking-vendor-js-editor.js');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/libs/codemirror/theme/material-darker.css');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/libs/codemirror/theme/dracula.css');
}

private function assert_PixelEventVendor_testTrackingVendorRedirectsKeepBackendAreaSegment(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("redirect('visitor/backend/tracking-vendor/index'", $src);
    self::assertStringNotContainsString("redirect('*/tracking-vendor/index'", $src);
    // * 仅展开为 module router「visitor」，会生成 /visitor/tracking-vendor → 404
    self::assertStringNotContainsString("'*/tracking-vendor/", $src);
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/backend/tracking-vendor-work-scope.js');
    self::assertStringContainsString('/visitor/backend/tracking-vendor/', $js);
    self::assertStringContainsString("replace('/visitor/tracking-vendor/', '/visitor/backend/tracking-vendor/')", $js);
}

private function assert_PixelEventVendor_testSaveCustomClearsDefaultMapWhenWritingEventMap(): void
{
    $mgr = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelEventVendorManager.php');
    self::assertStringContainsString('自定义搭接门禁', $mgr);
    self::assertStringContainsString('providerDefaultEventMap', $mgr);
    self::assertStringContainsString('误标「使用默认」却含自定义键', $mgr);
    self::assertStringContainsString('array_diff_key($current, $defaultMap)', $mgr);
    $picker = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Analytics/EventPicker.php');
    self::assertStringContainsString("'use_default_map' => false", $picker);
    $rest = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Api/Rest/V1/EventPicker.php');
    self::assertStringContainsString("'use_default_map' => false", $rest);
}

private function assert_PixelEventVendor_testAutosaveAndTabPersistContract(): void
{
    $tv = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php');
    self::assertStringContainsString('function postAutosave', $tv);
    self::assertStringContainsString('payloadFromSaveRequest', $tv);
    self::assertStringContainsString("'tab' => \$uiTab", $tv);
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml');
    self::assertStringContainsString('autosave_url', $src);
    self::assertStringContainsString('scheduleAutosave', $src);
    self::assertStringContainsString('tvp_ui_tab_v1', $src);
    self::assertStringContainsString('activateTab', $src);
    self::assertStringContainsString('tv-autosave-status', $src);
    self::assertStringContainsString('name="ui_tab"', $src);
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/tracking-vendor-js-editor.js');
    self::assertStringContainsString('__tvFlushJsEditors', $js);
    self::assertStringContainsString('tv:js-dirty', $js);
}

private function assert_PixelEventVendor_testMenuRegistersTrackingVendor(): void
{
    $menu = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/etc/backend/menu.xml');
    self::assertStringContainsString('Weline_Visitor::tracking_vendor', $menu);
    self::assertStringContainsString('visitor/backend/tracking-vendor/index', $menu);
    self::assertStringContainsString('title="事件供应商"', $menu);
}

private function assert_PixelEventVendor_testPixelJsFansOutVendors(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel.js');
    self::assertStringContainsString('__fanoutPixelVendors', $js);
    self::assertStringContainsString('publishVendor', $js);
    self::assertStringContainsString('ensureVendorFrame', $js);
    self::assertStringContainsString('__vendorScopeMatches', $js);
}

private function assert_PixelEventVendorRequestMemo_testListRuntimeVendorsUsesRequestContextAndProcessMemo(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelEventVendorManager.php');
    self::assertStringContainsString('visitor.pixel_event_vendors.runtime.v1.', $src);
    self::assertStringContainsString('RequestContext::has', $src);
    self::assertStringContainsString('RequestContext::set', $src);
    self::assertStringContainsString('$processRuntimeVendors', $src);
    self::assertStringContainsString('clearProcessVendorCache', $src);
}

private function assert_PixelHotBufferFlushAttribution_testHydrateFillsLegacyBufferedRowWithoutFlatColumns(): void
{
    /** @var PixelEventService $service */
    $service = ObjectManager::getInstance(PixelEventService::class);
    $hydrated = $service->hydratePreparedAttribution(['url' => 'https://example.test/x?wch=flush_ad&utm_source=google&utm_medium=cpc&utm_campaign=x', 'referer' => 'https://google.com/', 'session_id' => 'wps-a05-legacy', 'additionalInfo' => []], ['url' => 'https://example.test/x?wch=flush_ad&utm_source=google&utm_medium=cpc&utm_campaign=x', 'website_id' => 930501, 'referer' => 'https://google.com/']);
    self::assertSame('wps-a05-legacy', $hydrated['session_id']);
    self::assertSame('flush_ad', $hydrated['channel_code']);
    self::assertSame('paid', $hydrated['traffic_type']);
    self::assertSame('google', $hydrated['utm_source']);
    self::assertSame('cpc', $hydrated['utm_medium']);
    self::assertSame('x', $hydrated['utm_campaign']);
}

private function assert_PixelHotBufferFlushAttribution_testPrepareAndHydrateAgreeOnWch(): void
{
    /** @var PixelEventService $service */
    $service = ObjectManager::getInstance(PixelEventService::class);
    $payload = ['eventName' => 'page_view', 'websiteId' => 930503, 'url' => 'https://example.test/p?wch=same_code&utm_source=x&utm_medium=cpc', 'additionalInfo' => ['environment' => ['session_id' => 'wps-a05-agree']]];
    $prepare = new ReflectionMethod(PixelEventService::class, 'prepare');
    $prepare->setAccessible(true);
    /** @var array{post: array<string, mixed>, data: array<string, mixed>} $prepared */
    $prepared = $prepare->invoke($service, $payload);
    $legacyData = ['url' => $prepared['data']['url'], 'website_id' => $prepared['data']['website_id'], 'referer' => $prepared['data']['referer'], 'session_id' => $prepared['data']['session_id']];
    $hydrated = $service->hydratePreparedAttribution($prepared['post'], $legacyData);
    self::assertSame($prepared['data']['channel_code'], $hydrated['channel_code']);
    self::assertSame($prepared['data']['traffic_type'], $hydrated['traffic_type']);
    self::assertSame($prepared['data']['utm_source'], $hydrated['utm_source']);
    self::assertSame($prepared['data']['utm_medium'], $hydrated['utm_medium']);
    self::assertSame($prepared['data']['utm_campaign'], $hydrated['utm_campaign']);
    self::assertSame('same_code', $hydrated['channel_code']);
}

private function assert_PixelHotBufferFlushAttribution_testHotBufferFlushSourceCallsHydrate(): void
{
    $source = (string) \file_get_contents(BP . '/app/code/Weline/Visitor/Service/PixelHotBufferService.php');
    self::assertStringContainsString('hydratePreparedAttribution', $source);
    self::assertStringContainsString('persistPrepared($event[\'post\'], $data)', $source);
}

private function assert_PixelPaidWidget_testWidgetPhpRegistersPixelPaid(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-paid.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-channels.phtml'));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-traffic-type.phtml'));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-social.phtml'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/dashboard/widgets/pixel-paid.phtml');
    self::assertStringContainsString('@widget.code {pixel_paid}', $src);
    self::assertStringContainsString('@widget.type {table}', $src);
    self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
    self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
    self::assertStringContainsString('"required":false', $src);
}

private function assert_PixelPaidWidget_testWidgetCodeMatchesReportCatalogWithPaidFilter(): void
{
    $catalog = (new PixelReportCatalog())->require('pixel_paid');
    self::assertSame('pixel_paid', $catalog['widget_code']);
    self::assertSame('utm_campaign', $catalog['dimension']);
    self::assertSame(['traffic_type' => 'paid'], $catalog['filters']);
}

private function assert_PixelPaidWidget_testTemplateUsesEngineReportAndPaidDrilldown(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/dashboard/widgets/pixel-paid.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('data-pixel-widget="pixel_paid"', $src);
    self::assertStringContainsString('getPaidReport', $src);
    self::assertStringContainsString('channelDrilldownUrl', $src);
    self::assertStringContainsString("traffic_type' => 'paid'", $src);
    self::assertStringContainsString('详情报表', $src);
    $installer = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'pixel_paid'", $installer);
    self::assertStringContainsString("'replace_layout' => false", $installer);
}

private function assert_PixelPaidWidget_testPaidDrilldownUrlCarriesTrafficTypeAndCampaign(): void
{
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $captured = null;
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(static function (string $path, array $query = []) use (&$captured): string {
        $captured = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . '?' . http_build_query($query);
    });
    $service = new PixelDashboardWidgetData($request, $url);
    $href = $service->channelDrilldownUrl(['range' => '7d', 'website_id' => 5], 'utm_campaign', 'summer', ['traffic_type' => 'paid']);
    self::assertStringContainsString('pixel-dashboard/list', $href);
    self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
    self::assertSame('paid', $captured['query']['traffic_type'] ?? null);
    self::assertSame('summer', $captured['query']['utm_campaign'] ?? null);
    self::assertSame('5', $captured['query']['websiteId'] ?? null);
    self::assertSame('7d', $captured['query']['range'] ?? null);
}

private function assert_PixelPaidWidget_testPaidReportCodeIsMountedOnDetailTabs(): void
{
    self::assertTrue((new PixelDetailReportTabService())->isMounted('pixel_paid'));
}

private function assert_PixelSandboxPanelWhitelist_testWhitelistCommands(): void
{
    $allowed = ['sandbox_ready', 'subscribe', 'last_match', 'sandbox_log', 'sandbox_error'];
    foreach ($allowed as $cmd) {
        $this->assertTrue($this->isAllowed_PixelSandboxPanelWhitelist($cmd));
    }
    $this->assertFalse($this->isAllowed_PixelSandboxPanelWhitelist('eval'));
    $this->assertFalse($this->isAllowed_PixelSandboxPanelWhitelist('track'));
    $this->assertFalse($this->isAllowed_PixelSandboxPanelWhitelist('dataLayer.push'));
}

private function isAllowed_PixelSandboxPanelWhitelist(string $command): bool
{
    static $whitelist = ['sandbox_ready' => true, 'subscribe' => true, 'last_match' => true, 'sandbox_log' => true, 'sandbox_error' => true];
    return isset($whitelist[$command]);
}

private function assert_PixelSocialWidget_testWidgetPhpRegistersPixelSocial(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-social.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-paid.phtml'));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-channels.phtml'));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-event-value.phtml'));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-value-by-channel.phtml'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/dashboard/widgets/pixel-social.phtml');
    self::assertStringContainsString('@widget.code {pixel_social}', $src);
    self::assertStringContainsString('@widget.type {table}', $src);
    self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
    self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
    self::assertStringContainsString('"required":false', $src);
}

private function assert_PixelSocialWidget_testWidgetCodeMatchesReportCatalogWithSocialFilter(): void
{
    $catalog = (new PixelReportCatalog())->require('pixel_social');
    self::assertSame('pixel_social', $catalog['widget_code']);
    self::assertSame('channel_code', $catalog['dimension']);
    self::assertSame(['traffic_type' => 'social'], $catalog['filters']);
}

private function assert_PixelSocialWidget_testTemplateUsesEngineReportAndSocialDrilldown(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/dashboard/widgets/pixel-social.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('data-pixel-widget="pixel_social"', $src);
    self::assertStringContainsString('getSocialReport', $src);
    self::assertStringContainsString('channelDrilldownUrl', $src);
    self::assertStringContainsString("traffic_type' => 'social'", $src);
    self::assertStringContainsString('详情报表', $src);
    $installer = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'pixel_social'", $installer);
    self::assertStringContainsString("'replace_layout' => false", $installer);
}

private function assert_PixelSocialWidget_testSocialDrilldownUrlCarriesTrafficTypeAndChannel(): void
{
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $captured = null;
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(static function (string $path, array $query = []) use (&$captured): string {
        $captured = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . '?' . http_build_query($query);
    });
    $service = new PixelDashboardWidgetData($request, $url);
    $href = $service->channelDrilldownUrl(['range' => '7d', 'website_id' => 4], 'channel_code', 'wechat', ['traffic_type' => 'social']);
    self::assertStringContainsString('pixel-dashboard/list', $href);
    self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
    self::assertSame('social', $captured['query']['traffic_type'] ?? null);
    self::assertSame('wechat', $captured['query']['channel_code'] ?? null);
    self::assertSame('4', $captured['query']['websiteId'] ?? null);
    self::assertSame('7d', $captured['query']['range'] ?? null);
}

private function assert_PixelSocialWidget_testSocialReportCodeIsMountedOnDetailTabs(): void
{
    self::assertTrue((new PixelDetailReportTabService())->isMounted('pixel_social'));
}

private function assert_PixelStatisticsServiceListDrilldown_testListDrilldownQueryKeysAreStable(): void
{
    self::assertSame(['websiteId', 'event', 'range', 'startDate', 'endDate', 'channel_code', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'], PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS);
}

private function assert_PixelStatisticsServiceListDrilldown_testBuildQueryOnlyEmitsContractKeys(): void
{
    $query = PixelStatisticsService::buildListDrilldownQuery([
        'websiteId' => '9',
        'event' => 'purchase',
        'range' => '7d',
        'channel_code' => 'summer',
        'traffic_type' => PixelChannel::TRAFFIC_PAID,
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring',
        'page' => '2',
        // 非契约键，不得泄漏
        'foo' => 'bar',
    ]);
    foreach (\array_keys($query) as $key) {
        self::assertContains($key, PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS);
    }
    self::assertArrayNotHasKey('page', $query);
    self::assertArrayNotHasKey('foo', $query);
    self::assertSame('summer', $query['channel_code']);
    self::assertSame(PixelChannel::TRAFFIC_PAID, $query['traffic_type']);
}

private function assert_PixelStatisticsServiceListDrilldown_testListFormAndControllerAcceptAllContractKeys(): void
{
    $listTpl = (string) \file_get_contents(BP . '/app/code/Weline/Visitor/view/templates/backend/PixelDashboard/list.phtml');
    $controller = (string) \file_get_contents(BP . '/app/code/Weline/Visitor/Controller/Backend/PixelDashboard.php');
    $filterStart = strpos($controller, 'function getDashboardRequestFilters');
    self::assertNotFalse($filterStart);
    $filterChunk = substr($controller, (int) $filterStart, 1500);
    foreach (PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS as $key) {
        self::assertStringContainsString('name="' . $key . '"', $listTpl, "list 表单缺少 {$key}");
        self::assertStringContainsString("'{$key}'", $filterChunk, "控制器未透传 {$key}");
    }
}

private function assert_PixelStatisticsServiceListDrilldown_testDrilldownQueryRoundTripsThroughNormalize(): void
{
    $query = PixelStatisticsService::buildListDrilldownQuery(['websiteId' => '42', 'event' => 'page_view', 'range' => '7d', 'channel_code' => 'summer_sale', 'traffic_type' => PixelChannel::TRAFFIC_SOCIAL, 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'july']);
    $normalized = PixelStatisticsService::normalizeDashboardFilters($query);
    self::assertSame(42, $normalized['website_id']);
    self::assertSame('42', $normalized['website_id_raw']);
    self::assertSame('page_view', $normalized['event']);
    self::assertSame('7d', $normalized['range']);
    self::assertSame('summer_sale', $normalized['channel_code']);
    self::assertSame(PixelChannel::TRAFFIC_SOCIAL, $normalized['traffic_type']);
    self::assertSame('meta', $normalized['utm_source']);
    self::assertSame('paid_social', $normalized['utm_medium']);
    self::assertSame('july', $normalized['utm_campaign']);
}

private function assert_PixelStatisticsServiceListDrilldown_testAllDrilldownSurfacesUseSharedBuilder(): void
{
    $surfaces = [BP . '/app/code/Weline/Visitor/view/templates/backend/PixelDashboard/index.phtml', BP . '/app/code/Weline/Visitor/view/templates/backend/PixelDashboard/detail.phtml', BP . '/app/code/Weline/Visitor/view/templates/backend/TrafficChannel/detail.phtml'];
    foreach ($surfaces as $path) {
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('buildListDrilldownQuery', $src, basename(dirname($path)) . '/' . basename($path) . ' 须走统一下钻构造器');
        self::assertStringContainsString('pixel-dashboard/list', $src);
    }
}

private function assert_PixelStatisticsServiceListDrilldown_testTrafficChannelDetailLinksListWithChannelCode(): void
{
    $tpl = (string) \file_get_contents(BP . '/app/code/Weline/Visitor/view/templates/backend/TrafficChannel/detail.phtml');
    self::assertStringContainsString("'channel_code' => \$channelCode", $tpl);
    self::assertStringContainsString('像素事件列表', $tpl);
    self::assertStringContainsString('LIST_DRILLDOWN_QUERY_KEYS', (string) \file_get_contents(BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'));
}

private function assert_PixelStatisticsServiceListDrilldown_testListSpotCheckChannelFilterReducesOrEqualsTotalWhenQueryable(): void
{
    $base = PixelStatisticsService::getDashboardEventListPage(['range' => '7d'], 1, 20);
    if ($base['error'] !== '') {
        self::assertTrue(true, '热表未就绪，跳过抽检：' . $base['error']);
        return;
    }
    $channels = PixelStatisticsService::getDashboardChannelRows(['range' => '7d'], 5);
    if ($channels === []) {
        // 无 channel_code 时用不可能命中的码抽检契约仍可走通
        $query = PixelStatisticsService::buildListDrilldownQuery(['range' => '7d'], ['channel_code' => '__c07_no_such_channel__']);
        $filtered = PixelStatisticsService::getDashboardEventListPage($query, 1, 20);
        if ($filtered['error'] !== '') {
            self::assertStringContainsString('setup:upgrade', $filtered['error']);
            return;
        }
        self::assertSame(0, $filtered['total']);
        self::assertSame('__c07_no_such_channel__', $filtered['filters']['channel_code'] ?? null);
        return;
    }
    $code = (string) ($channels[0]['channel_code'] ?? '');
    self::assertNotSame('', $code);
    $query = PixelStatisticsService::buildListDrilldownQuery(['range' => '7d'], ['channel_code' => $code, 'traffic_type' => (string) ($channels[0]['traffic_type'] ?? '')]);
    $filtered = PixelStatisticsService::getDashboardEventListPage($query, 1, 20);
    if ($filtered['error'] !== '') {
        self::assertStringContainsString('setup:upgrade', $filtered['error']);
        return;
    }
    self::assertSame($code, $filtered['filters']['channel_code'] ?? null);
    self::assertLessThanOrEqual($base['total'], $filtered['total']);
    foreach ($filtered['rows'] as $row) {
        self::assertSame($code, (string) ($row['channel_code'] ?? ''));
    }
}

private function assert_PixelTrafficTypeWidget_testWidgetPhpRegistersPixelTrafficType(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-traffic-type.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Visitor::templates/dashboard/widgets/pixel-channels.phtml'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/dashboard/widgets/pixel-traffic-type.phtml');
    self::assertStringContainsString('@widget.code {pixel_traffic_type}', $src);
    self::assertStringContainsString('@widget.type {table}', $src);
    self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
    self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
    self::assertStringContainsString('"required":false', $src);
}

private function assert_PixelTrafficTypeWidget_testWidgetCodeMatchesReportCatalog(): void
{
    $catalog = (new PixelReportCatalog())->require('pixel_traffic_type');
    self::assertSame('pixel_traffic_type', $catalog['widget_code']);
    self::assertSame('traffic_type', $catalog['dimension']);
}

private function assert_PixelTrafficTypeWidget_testTemplateUsesEngineReportAndDrilldown(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/dashboard/widgets/pixel-traffic-type.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('data-pixel-widget="pixel_traffic_type"', $src);
    self::assertStringContainsString('getTrafficTypeReport', $src);
    self::assertStringContainsString('channelDrilldownUrl', $src);
    self::assertStringContainsString('listUrl', $src);
    self::assertStringContainsString('详情报表', $src);
    $installer = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'pixel_traffic_type'", $installer);
    self::assertStringContainsString("'replace_layout' => false", $installer);
}

private function assert_PixelTrafficTypeWidget_testTrafficTypeDrilldownUrlCarriesTrafficType(): void
{
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $captured = null;
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(static function (string $path, array $query = []) use (&$captured): string {
        $captured = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . '?' . http_build_query($query);
    });
    $service = new PixelDashboardWidgetData($request, $url);
    $href = $service->channelDrilldownUrl(['range' => '7d', 'website_id' => 2], 'traffic_type', 'paid');
    self::assertStringContainsString('pixel-dashboard/list', $href);
    self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
    self::assertSame('paid', $captured['query']['traffic_type'] ?? null);
    self::assertSame('2', $captured['query']['websiteId'] ?? null);
    self::assertSame('7d', $captured['query']['range'] ?? null);
    self::assertArrayNotHasKey('channel_code', $captured['query'] ?? []);
}

private function assert_PixelTrafficTypeWidget_testTrafficTypeReportCodeIsMountedOnDetailTabs(): void
{
    self::assertTrue((new PixelDetailReportTabService())->isMounted('pixel_traffic_type'));
}

private function assert_PixelValueByChannelWidget_testWidgetPhpRegistersPixelValueByChannel(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-value-by-channel.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/dashboard/widgets/pixel-value-by-channel.phtml');
    self::assertStringContainsString('@widget.code {pixel_value_by_channel}', $src);
    self::assertStringContainsString('@widget.type {table}', $src);
    self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
    self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
    self::assertStringContainsString('"required":false', $src);
}

private function assert_PixelValueByChannelWidget_testWidgetCodeMatchesReportCatalogNarrowMetrics(): void
{
    $catalog = (new PixelReportCatalog())->require('pixel_value_by_channel');
    self::assertSame('pixel_value_by_channel', $catalog['widget_code']);
    self::assertSame('channel_code', $catalog['dimension']);
    self::assertSame(['events', 'value_sum', 'valued_events'], $catalog['metrics']);
    self::assertSame([], $catalog['filters']);
}

private function assert_PixelValueByChannelWidget_testTemplateUsesEngineReportAndChannelDrilldown(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/dashboard/widgets/pixel-value-by-channel.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('data-pixel-widget="pixel_value_by_channel"', $src);
    self::assertStringContainsString('getValueByChannelReport', $src);
    self::assertStringContainsString('channelDrilldownUrl', $src);
    self::assertStringContainsString('valued_events', $src);
    self::assertStringContainsString('详情报表', $src);
    $installer = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'pixel_value_by_channel'", $installer);
    self::assertStringContainsString("'replace_layout' => false", $installer);
}

private function assert_PixelValueByChannelWidget_testValueByChannelDrilldownUrlCarriesChannelCode(): void
{
    $request = $this->createMock(Request::class);
    $request->method('getParam')->willReturn(null);
    $captured = null;
    $url = $this->createMock(Url::class);
    $url->method('getBackendUrlPath')->willReturnCallback(static function (string $path, array $query = []) use (&$captured): string {
        $captured = ['path' => $path, 'query' => $query];
        return '/backend/' . $path . '?' . http_build_query($query);
    });
    $service = new PixelDashboardWidgetData($request, $url);
    $href = $service->channelDrilldownUrl(['range' => '7d', 'website_id' => 9], 'channel_code', 'summer_sale');
    self::assertStringContainsString('pixel-dashboard/list', $href);
    self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
    self::assertSame('summer_sale', $captured['query']['channel_code'] ?? null);
    self::assertSame('9', $captured['query']['websiteId'] ?? null);
    self::assertSame('7d', $captured['query']['range'] ?? null);
}

private function assert_PixelValueByChannelWidget_testValueByChannelReportCodeIsMountedOnDetailTabs(): void
{
    self::assertTrue((new PixelDetailReportTabService())->isMounted('pixel_value_by_channel'));
}

private function assert_VisitorDashboardInstallerCatalogWidgets_testInstallerKeepsReplaceLayoutFalse(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $src = (string) \file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
    self::assertStringContainsString("'replace_layout' => false", $src);
    self::assertStringNotContainsString("'replace_layout' => true", $src);
}

private function assert_VisitorDashboardInstallerCatalogWidgets_testInstallerSeedContainsLegacyAndCatalogWidgets(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $widgetPhp = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    foreach (\Weline\Visitor\Service\VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
        $tpl = null;
        foreach (\Weline\Widget\Test\Support\SlimWidgetPhpListing::templatePaths($widgetPhp) as $path) {
            $view = \Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($path);
            if (!is_file($view)) {
                continue;
            }
            $src = (string) file_get_contents($view);
            if (str_contains($src, '@widget.code {' . $code . '}')) {
                $tpl = $path;
                self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src, $code);
                self::assertStringContainsString('"required":false', $src, $code);
                break;
            }
        }
        self::assertNotNull($tpl, $code . ' missing from slim listing/templates');
    }
}

private function assert_VisitorDashboardInstallerCatalogWidgets_testCatalogCodesMatchReportCatalogEnabled(): void
{
    $enabled = (new PixelReportCatalog())->codes(true);
    self::assertSame(VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES, $enabled);
}

private function assert_VisitorDashboardInstallerCatalogWidgets_testWidgetPhpInjectionsRemainOptional(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $widgetPhp = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    foreach (VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
        $matched = false;
        foreach (\Weline\Widget\Test\Support\SlimWidgetPhpListing::templatePaths($widgetPhp) as $path) {
            $view = \Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($path);
            if (!is_file($view)) {
                continue;
            }
            $src = (string) file_get_contents($view);
            if (!str_contains($src, '@widget.code {' . $code . '}')) {
                continue;
            }
            $matched = true;
            self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src, $code);
            self::assertStringContainsString('"required":false', $src, $code . ' must stay optional');
            break;
        }
        self::assertTrue($matched, $code . ' missing from slim listing/templates');
    }
}

private function assert_VisitorTrackingRuntimeReload_test_visitor_tracking_config_exposes_revision_and_custom_events(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/VisitorTrackingConfig.php');
    self::assertStringContainsString('KEY_RUNTIME_REVISION', $src);
    self::assertStringContainsString('function invalidateAfterMutation', $src);
    self::assertStringContainsString('clearStorefrontFullPageCaches', $src);
    self::assertStringContainsString("'customEvents'", $src);
    self::assertStringContainsString("'configRevision'", $src);
    self::assertStringContainsString('function resolveStorageScopeForRuntime', $src);
    self::assertStringContainsString('default.default.default', $src);
    self::assertStringContainsString("'storageScope'", $src);
}

private function assert_VisitorTrackingRuntimeReload_test_mutation_services_bump_runtime_revision(): void
{
    $ann = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/EventAnnotationService.php');
    $disc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/DiscoveredEventService.php');
    $chain = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/EventChainService.php');
    $tv = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php');
    $picker = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Analytics/EventPicker.php');
    self::assertStringContainsString('invalidateAfterMutation', $ann);
    self::assertStringContainsString('invalidateAfterMutation', $disc);
    self::assertStringContainsString('invalidateAfterMutation', $chain);
    self::assertStringContainsString('withRuntimeReloadMeta', $tv);
    self::assertStringContainsString('currentRuntimeRevisionMeta', $picker);
    self::assertStringContainsString('bumpRuntimeRevisionMeta', $picker);
    self::assertStringContainsString('只要录入（persist）成功就发新版本', $picker);
    self::assertStringContainsString('凡店面提交响应都带当前 config_revision', $picker);
}

private function assert_VisitorTrackingRuntimeReload_test_sandbox_notes_revision_then_reloads_runtime(): void
{
    $pixel = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/taglib/js/pixel.phtml');
    $admin = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml');
    self::assertStringContainsString('noteConfigRevision', $pixel);
    self::assertStringContainsString('__noteServerConfigRevision', $pixel);
    self::assertStringContainsString('rebuildVendorFrames', $pixel);
    self::assertStringContainsString('runtime_config=1', $pixel);
    self::assertStringContainsString('__matchCustomEventsByConditions', $pixel);
    self::assertStringContainsString('__trackMatchedCustomEvents', $pixel);
    self::assertStringContainsString('__extractParamsForCustomEvent', $pixel);
    self::assertStringContainsString('__normalizeParamMappings', $pixel);
    self::assertStringContainsString('__resolveParamTemplate', $pixel);
    self::assertStringContainsString('__resolveNestedValue', $pixel);
    self::assertStringContainsString('__sandboxCompactParams', $pixel);
    self::assertStringContainsString('event_hit: false', $pixel);
    self::assertStringContainsString('param_mappings', $pixel);
    self::assertStringContainsString('extract_params', $pixel);
    self::assertStringContainsString('storage_scope=', $pixel);
    self::assertStringContainsString('custom_match', $pixel);
    self::assertStringNotContainsString('__probeVisitorTrackingRevision', $pixel);
    self::assertStringNotContainsString('setInterval', $pixel);
    self::assertStringContainsString('noteConfigRevision', $phtml);
    self::assertStringContainsString('__noteServerConfigRevision', $phtml);
    self::assertStringContainsString('__matchCustomEventsByConditions', $phtml);
    self::assertStringContainsString('__trackMatchedCustomEvents', $phtml);
    self::assertStringContainsString('__extractParamsForCustomEvent', $phtml);
    self::assertStringContainsString('__normalizeParamMappings', $phtml);
    self::assertStringContainsString('__resolveParamTemplate', $phtml);
    self::assertStringNotContainsString('__probeVisitorTrackingRevision', $phtml);
    self::assertStringNotContainsString('setInterval', $phtml);
    self::assertStringContainsString('broadcastTrackingReload', $admin);
    self::assertStringContainsString('weline-visitor-tracking-config', $admin);
}
}
