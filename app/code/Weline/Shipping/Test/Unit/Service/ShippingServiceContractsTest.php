<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationCatalog;
use Weline\Shipping\Api\Quote\ShippingQuoteRequest;
use Weline\Shipping\Api\Quote\SplitShippingQuote;
use Weline\Shipping\Api\WarehouseShippingOriginInterface;
use Weline\Shipping\Exception\ShippingRateUnavailableException;
use Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider\YanwenDestinationCoverage;
use Weline\Shipping\Model\CarrierRegion;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Model\EmbargoReason;
use Weline\Shipping\Model\EmbargoReason\LocalDescription as EmbargoReasonLocalDescription;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Model\FreeShippingConditionType;
use Weline\Shipping\Model\FreeShippingConditionType\LocalDescription as FreeShippingConditionTypeLocalDescription;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\AddressCatalog\TsvGzReader;
use Weline\Shipping\Service\AddressCountryProfileService;
use Weline\Shipping\Service\CarrierCoverageAdminService;
use Weline\Shipping\Service\CarrierCoverageProviderRegistry;
use Weline\Shipping\Service\ChargeableWeightService;
use Weline\Shipping\Service\CoverageRuleMatcher;
use Weline\Shipping\Service\DefaultCarrierCoverageProvider;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;
use Weline\Shipping\Service\DeliveryAddressService;
use Weline\Shipping\Service\EmbargoAdminService;
use Weline\Shipping\Service\EmbargoReasonAdminService;
use Weline\Shipping\Service\EmbargoService;
use Weline\Shipping\Service\FreeShippingConditionTypeAdminService;
use Weline\Shipping\Service\FreeShippingRuleSeedService;
use Weline\Shipping\Service\OrderShipmentTrackingQueryService;
use Weline\Shipping\Service\PackingSplitter;
use Weline\Shipping\Service\RateBracketValidator;
use Weline\Shipping\Service\RateCalculationService;
use Weline\Shipping\Service\RegionCascadeEnsureService;
use Weline\Shipping\Service\RegionService;
use Weline\Shipping\Service\SeedWeightBracketFactory;
use Weline\Shipping\Service\ServiceLaneMatchService;
use Weline\Shipping\Service\ShippingCapabilityGate;
use Weline\Shipping\Service\ShippingCheckoutAddonService;
use Weline\Shipping\Service\ShippingCommercePolicyService;
use Weline\Shipping\Service\ShippingConfigScopeService;
use Weline\Shipping\Service\ShippingIncotermService;
use Weline\Shipping\Service\ShippingSeasonalSurchargeService;
use Weline\Shipping\Service\ShippingServiceManager;
use Weline\Shipping\Service\ShippingSurchargeService;
use Weline\Shipping\Service\SplitShipmentShippingService;
use Weline\Shipping\Service\SplitShippingQuoteService;
use Weline\Shipping\Service\SystemEmbargoAdminService;
use Weline\Shipping\Service\WarehouseLaneCopyService;
use Weline\Shipping\Service\WarehouseShippingOriginService;

/**
 * Aggregated contract suite (merged from 38 *ContractTest files).
 * Observe one overall pass/fail via testAllShippingServiceContractsPass().
 */
final class ShippingServiceContractsTest extends TestCase
{
    public function testAllShippingServiceContractsPass(): void
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
            'AddressSoleDefault::testDeliveryServiceEnsuresSoleAddressIsDefault' => function () {
            $this->assert_AddressSoleDefault_testDeliveryServiceEnsuresSoleAddressIsDefault();
        },
            'AddressSoleDefault::testShippingServiceEnsuresSoleAddressIsDefault' => function () {
            $this->assert_AddressSoleDefault_testShippingServiceEnsuresSoleAddressIsDefault();
        },
            'CarrierCoverageAddressMeta::testQuoteRatesPassesAddressMetaWithRegionIds' => function () {
            $this->assert_CarrierCoverageAddressMeta_testQuoteRatesPassesAddressMetaWithRegionIds();
        },
            'CarrierCoverage::testDefaultProviderReturnsChinaCountry' => function () {
            $this->assert_CarrierCoverage_testDefaultProviderReturnsChinaCountry();
        },
            'CarrierCoverage::testNormalizeRowsAcceptsMultiSelectionShape' => function () {
            $this->assert_CarrierCoverage_testNormalizeRowsAcceptsMultiSelectionShape();
        },
            'CarrierCoverage::testFormatRowLabelsIsHumanReadable' => function () {
            $this->assert_CarrierCoverage_testFormatRowLabelsIsHumanReadable();
        },
            'CarrierCoverage::testCoverageRuleMatcherCountryCoversProvinceAddress' => function () {
            $this->assert_CarrierCoverage_testCoverageRuleMatcherCountryCoversProvinceAddress();
        },
            'CarrierCoverage::testCoverageRuleMatcherRejectsOtherCountry' => function () {
            $this->assert_CarrierCoverage_testCoverageRuleMatcherRejectsOtherCountry();
        },
            'ChinaRegionPack::testCnAddressCatalogExistsAndJsonPackRemoved' => function () {
            $this->assert_ChinaRegionPack_testCnAddressCatalogExistsAndJsonPackRemoved();
        },
            'ChinaRegionPack::testRegionQueryProviderDeclaresSuggest' => function () {
            $this->assert_ChinaRegionPack_testRegionQueryProviderDeclaresSuggest();
        },
            'CommerceEdgesChapter4::testIncotermDutyNoticeDoesNotChangeSemanticsOfAmount' => function () {
            $this->assert_CommerceEdgesChapter4_testIncotermDutyNoticeDoesNotChangeSemanticsOfAmount();
        },
            'CommerceEdgesChapter4::testSplitFirstOnlySecondShipmentIsZero' => function () {
            $this->assert_CommerceEdgesChapter4_testSplitFirstOnlySecondShipmentIsZero();
        },
            'CommerceEdgesChapter4::testSplitEachShipmentRecalculatesPositive' => function () {
            $this->assert_CommerceEdgesChapter4_testSplitEachShipmentRecalculatesPositive();
        },
            'CommerceEdgesChapter4::testReturnSellerPaysZeroAndBuyerTemplateWired' => function () {
            $this->assert_CommerceEdgesChapter4_testReturnSellerPaysZeroAndBuyerTemplateWired();
        },
            'CommerceEdgesChapter4::testConfigVersionAndModule290' => function () {
            $this->assert_CommerceEdgesChapter4_testConfigVersionAndModule290();
        },
            'CommerceEdgesChapter4::testCodFeeCalculatorIntoGrandTotalContract' => function () {
            $this->assert_CommerceEdgesChapter4_testCodFeeCalculatorIntoGrandTotalContract();
        },
            'CommerceEdgesChapter4::testAdminLaneIncotermEditable' => function () {
            $this->assert_CommerceEdgesChapter4_testAdminLaneIncotermEditable();
        },
            'CountryNameDbOnly::testAddressRegionEmbargoForbidSymfonyCountries' => function () {
            $this->assert_CountryNameDbOnly_testAddressRegionEmbargoForbidSymfonyCountries();
        },
            'DefaultOriginFallback::testResolveDefaultOriginFallsBackBeyondIsDefaultFlag' => function () {
            $this->assert_DefaultOriginFallback_testResolveDefaultOriginFallsBackBeyondIsDefaultFlag();
        },
            'DefaultOriginFallback::testSeedPromotesPickedAddressToDefault' => function () {
            $this->assert_DefaultOriginFallback_testSeedPromotesPickedAddressToDefault();
        },
            'DeliveryAddressPurposeFlags::testModelDeclaresPurposeColumns' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testModelDeclaresPurposeColumns();
        },
            'DeliveryAddressPurposeFlags::testUpgradeMigratesPurposeFlags' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testUpgradeMigratesPurposeFlags();
        },
            'DeliveryAddressPurposeFlags::testCheckoutCreateDualByDefaultCheckbox' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testCheckoutCreateDualByDefaultCheckbox();
        },
            'DeliveryAddressPurposeFlags::testCheckoutCreateCheckoutOnlyWhenUnchecked' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testCheckoutCreateCheckoutOnlyWhenUnchecked();
        },
            'DeliveryAddressPurposeFlags::testUpdateDoesNotUntagReceivingWhenUnchecked' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testUpdateDoesNotUntagReceivingWhenUnchecked();
        },
            'DeliveryAddressPurposeFlags::testReceivingCreateDualWhenAlsoCheckoutChecked' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testReceivingCreateDualWhenAlsoCheckoutChecked();
        },
            'DeliveryAddressPurposeFlags::testReceivingCreateReceivingOnlyWhenUnchecked' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testReceivingCreateReceivingOnlyWhenUnchecked();
        },
            'DeliveryAddressPurposeFlags::testCheckoutWidgetExposesAlsoUseReceivingCheckbox' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testCheckoutWidgetExposesAlsoUseReceivingCheckbox();
        },
            'DeliveryAddressPurposeFlags::testAccountDeliveryFormExposesAlsoUseCheckoutCheckbox' => function () {
            $this->assert_DeliveryAddressPurposeFlags_testAccountDeliveryFormExposesAlsoUseCheckoutCheckbox();
        },
            'EmbargoReasonLocalModel::testLocalDescriptionExtendsLocalModelAndMapsParent' => function () {
            $this->assert_EmbargoReasonLocalModel_testLocalDescriptionExtendsLocalModelAndMapsParent();
        },
            'EmbargoReasonLocalModel::testCatalogDiscoversEmbargoReasonLocal' => function () {
            $this->assert_EmbargoReasonLocalModel_testCatalogDiscoversEmbargoReasonLocal();
        },
            'EmbargoReasonLocalModel::testAdminServiceSeedsAndUiContracts' => function () {
            $this->assert_EmbargoReasonLocalModel_testAdminServiceSeedsAndUiContracts();
        },
            'EmbargoService::testEvaluateAgainstRulesUsesUnionAndReportsProvinceLevel' => function () {
            $this->assert_EmbargoService_testEvaluateAgainstRulesUsesUnionAndReportsProvinceLevel();
        },
            'EmbargoService::testRegionProviderExposesEmbargoOperations' => function () {
            $this->assert_EmbargoService_testRegionProviderExposesEmbargoOperations();
        },
            'EmbargoSubnationalSelect::testEmbargoServiceExposesActiveSubnationalRules' => function () {
            $this->assert_EmbargoSubnationalSelect_testEmbargoServiceExposesActiveSubnationalRules();
        },
            'EmbargoSubnationalSelect::testRegionControllerServesEmbargoRegionsMode' => function () {
            $this->assert_EmbargoSubnationalSelect_testRegionControllerServesEmbargoRegionsMode();
        },
            'EmbargoSubnationalSelect::testAddressJsMarksAndBlocksSubnationalEmbargo' => function () {
            $this->assert_EmbargoSubnationalSelect_testAddressJsMarksAndBlocksSubnationalEmbargo();
        },
            'EmbargoSubnationalSelect::testAddressLoaderBustsSubnationalEmbargoCache' => function () {
            $this->assert_EmbargoSubnationalSelect_testAddressLoaderBustsSubnationalEmbargoCache();
        },
            'EmbargoSubnationalSelect::testModuleVersionsBumped' => function () {
            $this->assert_EmbargoSubnationalSelect_testModuleVersionsBumped();
        },
            'EmbargoSubnationalSelect::testRegionJsonWritesFrameworkResponseHeaders' => function () {
            $this->assert_EmbargoSubnationalSelect_testRegionJsonWritesFrameworkResponseHeaders();
        },
            'FreeShippingConditionTypeLocalModel::testLocalDescriptionExtendsLocalModelAndMapsParent' => function () {
            $this->assert_FreeShippingConditionTypeLocalModel_testLocalDescriptionExtendsLocalModelAndMapsParent();
        },
            'FreeShippingConditionTypeLocalModel::testCatalogDiscoversConditionTypeLocal' => function () {
            $this->assert_FreeShippingConditionTypeLocalModel_testCatalogDiscoversConditionTypeLocal();
        },
            'FreeShippingConditionTypeLocalModel::testAdminServiceSeedsAndUiContracts' => function () {
            $this->assert_FreeShippingConditionTypeLocalModel_testAdminServiceSeedsAndUiContracts();
        },
            'FreeShippingRuleSeed::testModelDeclaresOriginSeedConstants' => function () {
            $this->assert_FreeShippingRuleSeed_testModelDeclaresOriginSeedConstants();
        },
            'FreeShippingRuleSeed::testDefaultSeedsAreInternationalThresholdTemplates' => function () {
            $this->assert_FreeShippingRuleSeed_testDefaultSeedsAreInternationalThresholdTemplates();
        },
            'FreeShippingRuleSeed::testAdminDeleteGateAndUiContracts' => function () {
            $this->assert_FreeShippingRuleSeed_testAdminDeleteGateAndUiContracts();
        },
            'G20RegionPack::testAddressCatalogProvincesExistForCoveredCountries' => function () {
            $this->assert_G20RegionPack_testAddressCatalogProvincesExistForCoveredCountries();
        },
            'G20RegionPack::testGeonamesFilledCountriesHaveProvinces' => function () {
            $this->assert_G20RegionPack_testGeonamesFilledCountriesHaveProvinces();
        },
            'G20RegionPack::testProfilesOpenDistrictForNestedG20Only' => function () {
            $this->assert_G20RegionPack_testProfilesOpenDistrictForNestedG20Only();
        },
            'GatesSurchargeChapter2::testDefaultPointTypesExcludePobox' => function () {
            $this->assert_GatesSurchargeChapter2_testDefaultPointTypesExcludePobox();
        },
            'GatesSurchargeChapter2::testEmptyAcceptedHazardsRejectLineHazards' => function () {
            $this->assert_GatesSurchargeChapter2_testEmptyAcceptedHazardsRejectLineHazards();
        },
            'GatesSurchargeChapter2::testCollectLineHazardsFromMetadata' => function () {
            $this->assert_GatesSurchargeChapter2_testCollectLineHazardsFromMetadata();
        },
            'GatesSurchargeChapter2::testSeedSurchargeCodesMatchPlan' => function () {
            $this->assert_GatesSurchargeChapter2_testSeedSurchargeCodesMatchPlan();
        },
            'GatesSurchargeChapter2::testLocalPricingWiresSurchargeService' => function () {
            $this->assert_GatesSurchargeChapter2_testLocalPricingWiresSurchargeService();
        },
            'GatesSurchargeChapter2::testQuoteRatesAppliesCapabilityGate' => function () {
            $this->assert_GatesSurchargeChapter2_testQuoteRatesAppliesCapabilityGate();
        },
            'GatesSurchargeChapter2::testSurchargeSumAndFixedMinorMath' => function () {
            $this->assert_GatesSurchargeChapter2_testSurchargeSumAndFixedMinorMath();
        },
            'GeonamesCascadeCatalog::testBuildScriptExists' => function () {
            $this->assert_GeonamesCascadeCatalog_testBuildScriptExists();
        },
            'GeonamesCascadeCatalog::testEgyptAndParaguayHaveProvinceAndCityLayers' => function () {
            $this->assert_GeonamesCascadeCatalog_testEgyptAndParaguayHaveProvinceAndCityLayers();
        },
            'GeonamesCascadeCatalog::testCatalogProvinceCoverageIsNearGlobal' => function () {
            $this->assert_GeonamesCascadeCatalog_testCatalogProvinceCoverageIsNearGlobal();
        },
            'GeonamesCascadeCatalog::testCatalogCityCoverageIsGlobalWhenProvincesExist' => function () {
            $this->assert_GeonamesCascadeCatalog_testCatalogCityCoverageIsGlobalWhenProvincesExist();
        },
            'GeonamesCascadeCatalog::testSyntheticNationwideExistsForCityStates' => function () {
            $this->assert_GeonamesCascadeCatalog_testSyntheticNationwideExistsForCityStates();
        },
            'GeonamesCascadeCatalog::testAfghanistanBadakhshanHasCitiesInCatalog' => function () {
            $this->assert_GeonamesCascadeCatalog_testAfghanistanBadakhshanHasCitiesInCatalog();
        },
            'MultiWarehouseSplitShipping::testOriginInterfaceAndServiceExist' => function () {
            $this->assert_MultiWarehouseSplitShipping_testOriginInterfaceAndServiceExist();
        },
            'MultiWarehouseSplitShipping::testSplitQuoteHashCoversPackagesNotOnlyTotal' => function () {
            $this->assert_MultiWarehouseSplitShipping_testSplitQuoteHashCoversPackagesNotOnlyTotal();
        },
            'MultiWarehouseSplitShipping::testSplitServiceAndLaneCopyRegisteredInModule' => function () {
            $this->assert_MultiWarehouseSplitShipping_testSplitServiceAndLaneCopyRegisteredInModule();
        },
            'MultiWarehouseSplitShipping::testAdminCopyAndBindSurfacesExist' => function () {
            $this->assert_MultiWarehouseSplitShipping_testAdminCopyAndBindSurfacesExist();
        },
            'OrderShipmentTrackingQueryService::testServiceExposesShipmentScopedQueryApi' => function () {
            $this->assert_OrderShipmentTrackingQueryService_testServiceExposesShipmentScopedQueryApi();
        },
            'OrderShipmentTrackingQueryService::testTrackingControllerExposesQueryAction' => function () {
            $this->assert_OrderShipmentTrackingQueryService_testTrackingControllerExposesQueryAction();
        },
            'PackAddonChapter3::testPackingSplitterSplitsByWeight' => function () {
            $this->assert_PackAddonChapter3_testPackingSplitterSplitsByWeight();
        },
            'PackAddonChapter3::testPackingSplitterKeepsUnderLimitInOneBox' => function () {
            $this->assert_PackAddonChapter3_testPackingSplitterKeepsUnderLimitInOneBox();
        },
            'PackAddonChapter3::testLocalPricingWiresPackSeasonalAddon' => function () {
            $this->assert_PackAddonChapter3_testLocalPricingWiresPackSeasonalAddon();
        },
            'PackAddonChapter3::testSeedHasPackingAndSeasonalDefaultsOff' => function () {
            $this->assert_PackAddonChapter3_testSeedHasPackingAndSeasonalDefaultsOff();
        },
            'PackAddonChapter3::testConfigVersionIncludesChapter3Facts' => function () {
            $this->assert_PackAddonChapter3_testConfigVersionIncludesChapter3Facts();
        },
            'PackAddonChapter3::testModuleVersionIsAtLeast280' => function () {
            $this->assert_PackAddonChapter3_testModuleVersionIsAtLeast280();
        },
            'PackAddonChapter3::testAddonAndSeasonalSumMinor' => function () {
            $this->assert_PackAddonChapter3_testAddonAndSeasonalSumMinor();
        },
            'PostalCountriesEmbargo::testPostalCountriesReturnsPlaceNameAndEmbargoed' => function () {
            $this->assert_PostalCountriesEmbargo_testPostalCountriesReturnsPlaceNameAndEmbargoed();
        },
            'PostalCountriesEmbargo::testNormalizePostalStripsInvisibleFormatChars' => function () {
            $this->assert_PostalCountriesEmbargo_testNormalizePostalStripsInvisibleFormatChars();
        },
            'PostalCountriesEmbargo::testPostalLookupNormsLetterFallbackAndNumericNoFallback' => function () {
            $this->assert_PostalCountriesEmbargo_testPostalLookupNormsLetterFallbackAndNumericNoFallback();
        },
            'PostalCountriesEmbargo::testPostalLookupUsesLookupNormsCandidates' => function () {
            $this->assert_PostalCountriesEmbargo_testPostalLookupUsesLookupNormsCandidates();
        },
            'PostalCountriesEmbargo::testDefaultMarketsTsvDeclaresSortOrderAndUsBeforeKr' => function () {
            $this->assert_PostalCountriesEmbargo_testDefaultMarketsTsvDeclaresSortOrderAndUsBeforeKr();
        },
            'PostalCountriesEmbargo::testPostalCountriesRanksUsNewYorkAheadOfDzFor10001' => function () {
            $this->assert_PostalCountriesEmbargo_testPostalCountriesRanksUsNewYorkAheadOfDzFor10001();
        },
            'PostalCountriesEmbargo::testCnPostalCatalogIncludesPudongAlias200100' => function () {
            $this->assert_PostalCountriesEmbargo_testCnPostalCatalogIncludesPudongAlias200100();
        },
            'PostalCountriesEmbargo::testShippingModuleVersionBumpedForPostalEmbargo' => function () {
            $this->assert_PostalCountriesEmbargo_testShippingModuleVersionBumpedForPostalEmbargo();
        },
            'PostalCountriesEmbargo::testRegionBackendExposesCountrySortEditor' => function () {
            $this->assert_PostalCountriesEmbargo_testRegionBackendExposesCountrySortEditor();
        },
            'ProductDeliveryModeLightOrigin::testDeliveryModeWidgetUsesDefaultOriginNotPlanPackages' => function () {
            $this->assert_ProductDeliveryModeLightOrigin_testDeliveryModeWidgetUsesDefaultOriginNotPlanPackages();
        },
            'QuoteConfigVersionStability::testActiveQuoteConfigVersionAcceptsContextAndMemosByLayer' => function () {
            $this->assert_QuoteConfigVersionStability_testActiveQuoteConfigVersionAcceptsContextAndMemosByLayer();
        },
            'QuoteConfigVersionStability::testReachabilityFactsUseFreshModelsAndScopeChain' => function () {
            $this->assert_QuoteConfigVersionStability_testReachabilityFactsUseFreshModelsAndScopeChain();
        },
            'QuoteConfigVersionStability::testQuoteRatesAndAvailableServicesThreadContext' => function () {
            $this->assert_QuoteConfigVersionStability_testQuoteRatesAndAvailableServicesThreadContext();
        },
            'QuoteConfigVersionStability::testScopedQuotePassesRequestScopeIntoVersionAndRates' => function () {
            $this->assert_QuoteConfigVersionStability_testScopedQuotePassesRequestScopeIntoVersionAndRates();
        },
            'QuoteConfigVersionStability::testShippingInfoProviderNormalizesScopeIntoRequest' => function () {
            $this->assert_QuoteConfigVersionStability_testShippingInfoProviderNormalizesScopeIntoRequest();
        },
            'QuoteFxDiagnostics::testQuoteRatesExposeFxSkippedDiagnostics' => function () {
            $this->assert_QuoteFxDiagnostics_testQuoteRatesExposeFxSkippedDiagnostics();
        },
            'QuoteFxDiagnostics::testListQuoteOptionsReturnsDiagnostics' => function () {
            $this->assert_QuoteFxDiagnostics_testListQuoteOptionsReturnsDiagnostics();
        },
            'RateTableChapter1::testSeedAmericasBracketsMatchPlan' => function () {
            $this->assert_RateTableChapter1_testSeedAmericasBracketsMatchPlan();
        },
            'RateTableChapter1::testAmericas2kgHits115Bracket' => function () {
            $this->assert_RateTableChapter1_testAmericas2kgHits115Bracket();
        },
            'RateTableChapter1::testWeightTableQuotesAmericas2kgAndRefusesOverMax' => function () {
            $this->assert_RateTableChapter1_testWeightTableQuotesAmericas2kgAndRefusesOverMax();
        },
            'RateTableChapter1::testBracketValidatorRejectsGaps' => function () {
            $this->assert_RateTableChapter1_testBracketValidatorRejectsGaps();
        },
            'RateTableChapter1::testChargeableWeightUsesVolumetric' => function () {
            $this->assert_RateTableChapter1_testChargeableWeightUsesVolumetric();
        },
            'RateTableChapter1::testMissingWeightAndDimsFlags' => function () {
            $this->assert_RateTableChapter1_testMissingWeightAndDimsFlags();
        },
            'RateTemplateBaseCurrency::testAdminServiceForcesBaseCurrencyAndSupportsUpdate' => function () {
            $this->assert_RateTemplateBaseCurrency_testAdminServiceForcesBaseCurrencyAndSupportsUpdate();
        },
            'RateTemplateBaseCurrency::testControllerSaveBranchesCreateAndUpdate' => function () {
            $this->assert_RateTemplateBaseCurrency_testControllerSaveBranchesCreateAndUpdate();
        },
            'RateTemplateBaseCurrency::testQuoteRatesConvertsViaCurrencyRateService' => function () {
            $this->assert_RateTemplateBaseCurrency_testQuoteRatesConvertsViaCurrencyRateService();
        },
            'RateTemplateBaseCurrency::testModuleRequiresCurrency' => function () {
            $this->assert_RateTemplateBaseCurrency_testModuleRequiresCurrency();
        },
            'RateTemplateDelete::testSeedCodeDetection' => function () {
            $this->assert_RateTemplateDelete_testSeedCodeDetection();
        },
            'RateTemplateDelete::testAdminServiceExposesDeleteRateTemplate' => function () {
            $this->assert_RateTemplateDelete_testAdminServiceExposesDeleteRateTemplate();
        },
            'RateTemplateDelete::testControllerExposesRemoveAction' => function () {
            $this->assert_RateTemplateDelete_testControllerExposesRemoveAction();
        },
            'RateTemplateSeedCompleteness::testExpectedNineSeedTemplateCodes' => function () {
            $this->assert_RateTemplateSeedCompleteness_testExpectedNineSeedTemplateCodes();
        },
            'RateTemplateSeedCompleteness::testSeedServiceAlwaysUpsertsTemplatesAndWritesRates' => function () {
            $this->assert_RateTemplateSeedCompleteness_testSeedServiceAlwaysUpsertsTemplatesAndWritesRates();
        },
            'RateTemplateSeedCompleteness::testListUiHumanizesCalcTypeAndSeedBadge' => function () {
            $this->assert_RateTemplateSeedCompleteness_testListUiHumanizesCalcTypeAndSeedBadge();
        },
            'RegionGlobalCatalog::testRegionServiceSupportsGlobalCountryCatalog' => function () {
            $this->assert_RegionGlobalCatalog_testRegionServiceSupportsGlobalCountryCatalog();
        },
            'RegionGlobalCatalog::testRegionQueryProviderExposesCatalogParam' => function () {
            $this->assert_RegionGlobalCatalog_testRegionQueryProviderExposesCatalogParam();
        },
            'RegionGlobalCatalog::testFrontendRegionControllerAcceptsCatalogParam' => function () {
            $this->assert_RegionGlobalCatalog_testFrontendRegionControllerAcceptsCatalogParam();
        },
            'RegionGlobalCatalog::testAddressJsUsesHttpFetchForGlobalCatalog' => function () {
            $this->assert_RegionGlobalCatalog_testAddressJsUsesHttpFetchForGlobalCatalog();
        },
            'RegionListLocalizationBatch::testRegionListUsesBatchLocalizedNameLookup' => function () {
            $this->assert_RegionListLocalizationBatch_testRegionListUsesBatchLocalizedNameLookup();
        },
            'ScopedShippingQuoteEmbargoGate::testQuoteAndListOptionsAssertDestinationAllowed' => function () {
            $this->assert_ScopedShippingQuoteEmbargoGate_testQuoteAndListOptionsAssertDestinationAllowed();
        },
            'SeedLaneOriginWarehouse::testSeedLanesBindCurrentWarehouseShippingAddress' => function () {
            $this->assert_SeedLaneOriginWarehouse_testSeedLanesBindCurrentWarehouseShippingAddress();
        },
            'ServiceLaneRelativeQuote::testShippingServiceDeclaresOriginField' => function () {
            $this->assert_ServiceLaneRelativeQuote_testShippingServiceDeclaresOriginField();
        },
            'ServiceLaneRelativeQuote::testServiceRegionModelAndUpgradeRegistered' => function () {
            $this->assert_ServiceLaneRelativeQuote_testServiceRegionModelAndUpgradeRegistered();
        },
            'ServiceLaneRelativeQuote::testQuotePathUsesLaneFilter' => function () {
            $this->assert_ServiceLaneRelativeQuote_testQuotePathUsesLaneFilter();
        },
            'ServiceLaneRelativeQuote::testAdminCreateShippingServicePersistsLaneFields' => function () {
            $this->assert_ServiceLaneRelativeQuote_testAdminCreateShippingServicePersistsLaneFields();
        },
            'ServiceLaneRelativeQuote::testBackendLaneUiContracts' => function () {
            $this->assert_ServiceLaneRelativeQuote_testBackendLaneUiContracts();
        },
            'ServiceLaneRelativeQuote::testLaneMatchSpecificityOrderingLogicPresent' => function () {
            $this->assert_ServiceLaneRelativeQuote_testLaneMatchSpecificityOrderingLogicPresent();
        },
            'ShippingProcessCache::testHotPathServicesOwnProcessBags' => function () {
            $this->assert_ShippingProcessCache_testHotPathServicesOwnProcessBags();
        },
            'ShippingProcessCache::testProcessCacheResetterIsRegistered' => function () {
            $this->assert_ShippingProcessCache_testProcessCacheResetterIsRegistered();
        },
            'ShippingScopeAndSeed::testModelsDeclareScopeFields' => function () {
            $this->assert_ShippingScopeAndSeed_testModelsDeclareScopeFields();
        },
            'ShippingScopeAndSeed::testAdminAndQuoteUseScopeService' => function () {
            $this->assert_ShippingScopeAndSeed_testAdminAndQuoteUseScopeService();
        },
            'ShippingScopeAndSeed::testScopeToolbarPresentOnConfigPages' => function () {
            $this->assert_ShippingScopeAndSeed_testScopeToolbarPresentOnConfigPages();
        },
            'ShippingScopeAndSeed::testDefaultMarketsSeedAssets' => function () {
            $this->assert_ShippingScopeAndSeed_testDefaultMarketsSeedAssets();
        },
            'ShippingScopeAndSeed::testQuoteLayerChainOrder' => function () {
            $this->assert_ShippingScopeAndSeed_testQuoteLayerChainOrder();
        },
            'ShippingScopeAndSeed::testNearestServiceLayerFallsBackToWebsiteZeroSeed' => function () {
            $this->assert_ShippingScopeAndSeed_testNearestServiceLayerFallsBackToWebsiteZeroSeed();
        },
            'ShippingShellProvider::testShellDocsAndInterfaceExist' => function () {
            $this->assert_ShippingShellProvider_testShellDocsAndInterfaceExist();
        },
            'ShippingShellProvider::testRequirementMentionsShell' => function () {
            $this->assert_ShippingShellProvider_testRequirementMentionsShell();
        },
            'ShippingShellProvider::testInterfaceDeclaresFullCapabilitySurface' => function () {
            $this->assert_ShippingShellProvider_testInterfaceDeclaresFullCapabilitySurface();
        },
            'ShippingShellProvider::testAbstractImplementsInterface' => function () {
            $this->assert_ShippingShellProvider_testAbstractImplementsInterface();
        },
            'ShippingShellProvider::testBuiltinProvidersExtendAbstract' => function () {
            $this->assert_ShippingShellProvider_testBuiltinProvidersExtendAbstract();
        },
            'ShippingShellProvider::testQuoteRatesDelegatesToProviderWithoutVendorBranch' => function () {
            $this->assert_ShippingShellProvider_testQuoteRatesDelegatesToProviderWithoutVendorBranch();
        },
            'ShippingShellProvider::testShellPhpHasNoVendorLiterals' => function () {
            $this->assert_ShippingShellProvider_testShellPhpHasNoVendorLiterals();
        },
            'ShippingShellProvider::testTrackingRoutesViaFacade' => function () {
            $this->assert_ShippingShellProvider_testTrackingRoutesViaFacade();
        },
            'ShippingShellProvider::testCarrierHasProviderCode' => function () {
            $this->assert_ShippingShellProvider_testCarrierHasProviderCode();
        },
            'ShippingShellProvider::testModuleVersion250' => function () {
            $this->assert_ShippingShellProvider_testModuleVersion250();
        },
            'SystemEmbargoAdminService::testModelDeclaresNaturalUniqueAndSystemScope' => function () {
            $this->assert_SystemEmbargoAdminService_testModelDeclaresNaturalUniqueAndSystemScope();
        },
            'SystemEmbargoAdminService::testReplaceForScopeRejectsSystem' => function () {
            $this->assert_SystemEmbargoAdminService_testReplaceForScopeRejectsSystem();
        },
            'SystemEmbargoAdminService::testAdminServiceHasNoDeleteApi' => function () {
            $this->assert_SystemEmbargoAdminService_testAdminServiceHasNoDeleteApi();
        },
            'SystemEmbargoAdminService::testSeedFileHasNoDomesticSurchargeProvinces' => function () {
            $this->assert_SystemEmbargoAdminService_testSeedFileHasNoDomesticSurchargeProvinces();
        },
            'SystemEmbargoAdminService::testManagerTabsIncludeSystemEmbargo' => function () {
            $this->assert_SystemEmbargoAdminService_testManagerTabsIncludeSystemEmbargo();
        },
            'SystemEmbargoAdminService::testDeactivateConfirmMentionsSiteWide' => function () {
            $this->assert_SystemEmbargoAdminService_testDeactivateConfirmMentionsSiteWide();
        },
            'SystemEmbargoAdminService::testEmbargoServiceMessageForSystem' => function () {
            $this->assert_SystemEmbargoAdminService_testEmbargoServiceMessageForSystem();
        },
            'SystemEmbargoAdminService::testConfigVersionIncludesReachability' => function () {
            $this->assert_SystemEmbargoAdminService_testConfigVersionIncludesReachability();
        },
            'SystemEmbargoAdminService::testEmbargoAdminServiceClassExists' => function () {
            $this->assert_SystemEmbargoAdminService_testEmbargoAdminServiceClassExists();
        },
            'WorldMarketSeed::testDefaultMarketsTsvCoversUsAndWorldScale' => function () {
            $this->assert_WorldMarketSeed_testDefaultMarketsTsvCoversUsAndWorldScale();
        },
            'WorldMarketSeed::testSeedMergesDestinationAndCarrierCoverageWhenExpanding' => function () {
            $this->assert_WorldMarketSeed_testSeedMergesDestinationAndCarrierCoverageWhenExpanding();
        },
            'YanwenDestinationCoverage::testCoverageIsCountryLevelOnly' => function () {
            $this->assert_YanwenDestinationCoverage_testCoverageIsCountryLevelOnly();
        },
            'YanwenDestinationCoverage::testIncludesUsExcludesCnAndProvinces' => function () {
            $this->assert_YanwenDestinationCoverage_testIncludesUsExcludesCnAndProvinces();
        },
            'YanwenDestinationCoverage::testProviderExposesSameCatalog' => function () {
            $this->assert_YanwenDestinationCoverage_testProviderExposesSameCatalog();
        },
            'YanwenDestinationCoverage::testRegistryPrefersProviderCode' => function () {
            $this->assert_YanwenDestinationCoverage_testRegistryPrefersProviderCode();
        },
        ];
    }

private function assert_AddressSoleDefault_testDeliveryServiceEnsuresSoleAddressIsDefault(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DeliveryAddressService.php');
    $this->assertStringContainsString('ensureSoleDefaultByCustomer', $source);
    $this->assertStringContainsString('countByCustomer', $source);
    $this->assertStringContainsString('$this->ensureSoleDefaultByCustomer($customerId);', $source);
    $this->assertStringContainsString('if ($this->countByCustomer($customerId) === 0) {', $source);
    $this->assertStringContainsString('$data[DeliveryAddress::schema_fields_IS_DEFAULT] = 1;', $source);
    $this->assertStringContainsString('$this->ensureSoleDefaultByCustomer($ownerId);', $source);
    $this->assertMatchesRegularExpression('/if \(count\(\$items\) !== 1\) \{\s*return;/s', $source);
}

private function assert_AddressSoleDefault_testShippingServiceEnsuresSoleAddressIsDefault(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingAddressService.php');
    $this->assertStringContainsString('ensureSoleDefault', $source);
    $this->assertStringContainsString('countAll', $source);
    $this->assertStringContainsString('$this->ensureSoleDefault();', $source);
    $this->assertStringContainsString('if ($this->countAll() === 0) {', $source);
    $this->assertStringContainsString('$data[ShippingAddress::schema_fields_IS_DEFAULT] = 1;', $source);
    $this->assertMatchesRegularExpression('/if \(count\(\$items\) !== 1\) \{\s*return;/s', $source);
}

private function assert_CarrierCoverageAddressMeta_testQuoteRatesPassesAddressMetaWithRegionIds(): void
{
    $manager = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    $match = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/CarrierCoverageMatchService.php');
    self::assertStringContainsString('?array $addressMeta = null', $manager);
    self::assertStringContainsString('$destAddress,', $manager);
    self::assertStringContainsString("'province_region_id'", $manager);
    self::assertStringContainsString('?array $addressMeta = null', $match);
    self::assertStringContainsString("evaluateAddress(\$address, \$context)", $match);
    self::assertStringContainsString("'province_region_id', 'city_region_id', 'district_region_id'", $match);
}

private function assert_CarrierCoverage_testDefaultProviderReturnsChinaCountry(): void
{
    $provider = new DefaultCarrierCoverageProvider();
    self::assertSame('default', $provider->providerCode());
    $rows = $provider->defaultCoverage();
    self::assertNotEmpty($rows);
    self::assertSame('country', $rows[0]['region_type']);
    self::assertSame('CN', $rows[0]['country_code']);
}

private function assert_CarrierCoverage_testNormalizeRowsAcceptsMultiSelectionShape(): void
{
    $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
    $registry = new CarrierCoverageProviderRegistry($om, null);
    $admin = new CarrierCoverageAdminService($om, $registry);
    $rows = $admin->normalizeRows([['region_type' => 'country', 'country_code' => 'cn'], ['region_type' => 'province', 'country_code' => 'CN', 'region_id' => 15860, 'region_code' => 'SC', 'region_name' => '四川省'], ['region_type' => 'province', 'country_code' => 'CN', 'region_id' => 15860, 'region_code' => 'SC']]);
    self::assertCount(2, $rows);
    self::assertSame('CN', $rows[0]['country_code']);
    self::assertSame('country', $rows[0]['region_type']);
    self::assertSame(15860, $rows[1]['region_id']);
}

private function assert_CarrierCoverage_testFormatRowLabelsIsHumanReadable(): void
{
    $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
    $registry = new CarrierCoverageProviderRegistry($om, null);
    $admin = new CarrierCoverageAdminService($om, $registry);
    $labels = $admin->formatRowLabels([['region_type' => 'country', 'country_code' => 'CN'], ['region_type' => 'province', 'country_code' => 'CN', 'region_id' => 15860, 'region_code' => 'SC', 'region_name' => '四川省']]);
    self::assertCount(2, $labels);
    self::assertStringContainsString('CN', $labels[0] . $labels[1]);
    self::assertStringNotContainsString('{', $labels[0]);
    self::assertStringContainsString('四川省', $labels[1]);
}

private function assert_CarrierCoverage_testCoverageRuleMatcherCountryCoversProvinceAddress(): void
{
    $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
    $regionService = $this->getMockBuilder(\Weline\Shipping\Service\RegionService::class)->disableOriginalConstructor()->onlyMethods(['findByLocation'])->getMock();
    $regionService->method('findByLocation')->willReturn(null);
    $matcher = new CoverageRuleMatcher($om, $regionService);
    $ok = $matcher->addressMatchesAnyRule(['country_code' => 'CN', 'province' => '四川省', 'city' => '成都市', 'district' => '新都区'], [['region_type' => 'country', 'country_code' => 'CN', 'region_id' => 0, 'region_code' => 'CN']]);
    self::assertTrue($ok);
}

private function assert_CarrierCoverage_testCoverageRuleMatcherRejectsOtherCountry(): void
{
    $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
    $regionService = $this->getMockBuilder(\Weline\Shipping\Service\RegionService::class)->disableOriginalConstructor()->onlyMethods(['findByLocation'])->getMock();
    $regionService->method('findByLocation')->willReturn(null);
    $matcher = new CoverageRuleMatcher($om, $regionService);
    $ok = $matcher->addressMatchesAnyRule(['country_code' => 'CN', 'province' => '四川省'], [['region_type' => 'country', 'country_code' => 'NG', 'region_id' => 0, 'region_code' => 'NG']]);
    self::assertFalse($ok);
}

private function assert_ChinaRegionPack_testCnAddressCatalogExistsAndJsonPackRemoved(): void
{
    $service = new RegionCascadeEnsureService();
    self::assertTrue($service->hasCatalogCoverage('CN'));
    self::assertNull($service->loadPack('CN'));
    $cnDir = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/data/address-catalog/CN';
    self::assertFileExists($cnDir . '/provinces.tsv.gz');
    self::assertFileExists($cnDir . '/cities.tsv.gz');
    self::assertFileExists($cnDir . '/districts.tsv.gz');
}

private function assert_ChinaRegionPack_testRegionQueryProviderDeclaresSuggest(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Framework/Query/RegionQueryProvider.php';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString("'suggest'", $content);
    self::assertStringContainsString("'format_suggestion'", $content);
    self::assertStringContainsString("'country_profile'", $content);
}

private function assert_CommerceEdgesChapter4_testIncotermDutyNoticeDoesNotChangeSemanticsOfAmount(): void
{
    $svc = new ShippingIncotermService();
    self::assertSame('ddp', $svc->normalize('DDP'));
    self::assertSame('ddu', $svc->normalize(''));
    self::assertNotSame('', $svc->dutyNotice('ddp'));
    self::assertNotSame($svc->dutyNotice('ddp'), $svc->dutyNotice('ddu'));
    self::assertSame(ShippingIncotermService::NOTICE_DDU, $svc->dutyNotice('ddu'));
    self::assertStringNotContainsString('_', $svc->labelForDutyNoticeCode(ShippingIncotermService::NOTICE_DDU));
    self::assertStringContainsString('关税', $svc->labelForDutyNoticeCode(ShippingIncotermService::NOTICE_DDU));
    self::assertNotSame(ShippingIncotermService::NOTICE_DDU, $svc->labelForDutyNoticeCode(ShippingIncotermService::NOTICE_DDU));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/Provider/LocalTemplatePricingService.php');
    self::assertStringContainsString("'incoterm'", $src);
    self::assertStringContainsString("'duty_notice'", $src);
    self::assertStringContainsString('ShippingIncotermService', $src);
}

private function assert_CommerceEdgesChapter4_testSplitFirstOnlySecondShipmentIsZero(): void
{
    $om = $this->createMock(ObjectManager::class);
    $commerce = new ShippingCommercePolicyService($om);
    $rates = $this->createMock(RateCalculationService::class);
    $svc = new SplitShipmentShippingService($commerce, $rates);
    $snap = ['split_shipment_shipping' => 'first_only', 'outbound_shipping_minor' => 11500];
    $second = $svc->quoteSubsequentShipment(2, [['qty_minor' => 1, 'weight_minor' => 1000]], $snap);
    self::assertSame(0, $second['amount_minor']);
    self::assertSame('first_only', $second['strategy']);
    $first = $svc->quoteSubsequentShipment(1, [], $snap);
    self::assertSame(11500, $first['amount_minor']);
}

private function assert_CommerceEdgesChapter4_testSplitEachShipmentRecalculatesPositive(): void
{
    $om = $this->createMock(ObjectManager::class);
    $commerce = new ShippingCommercePolicyService($om);
    $rates = $this->createMock(RateCalculationService::class);
    $rates->method('calculateTemplateMinor')->willReturn(4200);
    $tpl = $this->createMock(RateTemplate::class);
    $svc = new SplitShipmentShippingService($commerce, $rates);
    $snap = ['split_shipment_shipping' => 'each_shipment', 'outbound_shipping_minor' => 11500];
    $second = $svc->quoteSubsequentShipment(2, [['requires_shipping' => true, 'qty_minor' => 1, 'weight_minor' => 2000]], $snap, $tpl);
    self::assertSame(4200, $second['amount_minor']);
    self::assertGreaterThan(0, $second['amount_minor']);
    self::assertSame('each_shipment', $second['strategy']);
}

private function assert_CommerceEdgesChapter4_testReturnSellerPaysZeroAndBuyerTemplateWired(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ReturnShippingQuoteService.php');
    self::assertStringContainsString('seller_pays', $src);
    self::assertStringContainsString('SEED_TPL_RETURN_DOMESTIC', $src);
    self::assertStringNotContainsString('freeShipping', $src);
    $seed = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('ensureCommerceChapter4Seeds', $seed);
    self::assertStringContainsString('ReturnShippingQuoteService::TPL_DOMESTIC', $seed);
    self::assertStringContainsString('ReturnShippingQuoteService::TPL_INTL', $seed);
}

private function assert_CommerceEdgesChapter4_testConfigVersionAndModule290(): void
{
    $mgr = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('commerceConfigFacts', $mgr);
    $module = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php');
    self::assertStringContainsString("'2.9.0'", $module);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('ShippingCommercePolicy', $upgrade);
}

private function assert_CommerceEdgesChapter4_testCodFeeCalculatorIntoGrandTotalContract(): void
{
    $calcSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Payment/Service/CodFeeCalculator.php');
    self::assertStringContainsString('fromConfig', $calcSrc);
    $moneySrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Order/Api/Data/MoneySnapshot.php');
    self::assertStringContainsString('codFeeAmountMinor', $moneySrc);
    self::assertStringContainsString('cod_fee_amount_minor', $moneySrc);
    $facadeSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Order/Service/OrderFacade.php');
    self::assertStringContainsString('resolveCodFeeMinor', $facadeSrc);
    self::assertStringContainsString('split_shipment_shipping', $facadeSrc);
    $om = $this->createMock(ObjectManager::class);
    $calc = new \Weline\Payment\Service\CodFeeCalculator($om);
    self::assertSame(500, $calc->fromConfig(['fee' => 5.0], 10000, 2));
    $baseline = 11500;
    $fee = $calc->fromConfig(['fee' => 5.0], $baseline, 2);
    self::assertSame(500, $fee);
    self::assertSame(12000, $baseline + $fee);
}

private function assert_CommerceEdgesChapter4_testAdminLaneIncotermEditable(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/ShippingService/index.phtml');
    self::assertStringContainsString('name="incoterm"', $tpl);
    self::assertStringContainsString('saveIncoterm', $tpl);
    $admin = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigurationAdminService.php');
    self::assertStringContainsString('updateShippingServiceIncoterm', $admin);
    self::assertStringContainsString('schema_fields_INCOTERM', $admin);
}

private function assert_CountryNameDbOnly_testAddressRegionEmbargoForbidSymfonyCountries(): void
{
    $paths = [\BP . 'app/code/Weline/Shipping/Service/AddressFormatter.php', \BP . 'app/code/Weline/Shipping/Service/RegionService.php', \BP . 'app/code/Weline/Shipping/Service/SystemEmbargoAdminService.php'];
    foreach ($paths as $path) {
        $src = (string) \file_get_contents($path);
        self::assertStringNotContainsString('Symfony\Component\Intl', $src, $path . ' must not reference Symfony Intl');
        self::assertStringContainsString('Weline\I18n\Model\I18n', $src, $path . ' must resolve country names via I18n');
        self::assertStringContainsString('getCountries', $src);
    }
}

private function assert_DefaultOriginFallback_testResolveDefaultOriginFallsBackBeyondIsDefaultFlag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ServiceLaneMatchService.php');
    self::assertStringContainsString('function resolveDefaultOriginId', $src);
    self::assertStringContainsString('WarehouseShippingOriginInterface', $src);
    self::assertStringContainsString('findShippingAddressId', $src);
    self::assertStringContainsString('schema_fields_IS_ENABLED', $src);
    self::assertStringContainsString('无 is_default', $src);
}

private function assert_DefaultOriginFallback_testSeedPromotesPickedAddressToDefault(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('setDefault($pickedId)', $src);
    self::assertStringContainsString('提升为默认', $src);
}

private function assert_DeliveryAddressPurposeFlags_testModelDeclaresPurposeColumns(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Model/DeliveryAddress.php');
    self::assertStringContainsString("schema_fields_PURPOSE_CHECKOUT = 'purpose_checkout'", $src);
    self::assertStringContainsString("schema_fields_PURPOSE_RECEIVING = 'purpose_receiving'", $src);
}

private function assert_DeliveryAddressPurposeFlags_testUpgradeMigratesPurposeFlags(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('migrateDeliveryAddressPurposeFlags', $src);
    self::assertStringContainsString('DeliveryAddress::class', $src);
}

private function assert_DeliveryAddressPurposeFlags_testCheckoutCreateDualByDefaultCheckbox(): void
{
    $flags = DeliveryAddressService::resolvePurposeWriteFlags(['purpose_source' => 'checkout', 'also_use_receiving' => 1], true, null);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
}

private function assert_DeliveryAddressPurposeFlags_testCheckoutCreateCheckoutOnlyWhenUnchecked(): void
{
    $flags = DeliveryAddressService::resolvePurposeWriteFlags(['purpose_source' => 'checkout', 'also_use_receiving' => 0], true, null);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
    self::assertSame(0, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
}

private function assert_DeliveryAddressPurposeFlags_testUpdateDoesNotUntagReceivingWhenUnchecked(): void
{
    $existing = $this->createMock(DeliveryAddress::class);
    $existing->method('hasPurposeCheckout')->willReturn(true);
    $existing->method('hasPurposeReceiving')->willReturn(true);
    $flags = DeliveryAddressService::resolvePurposeWriteFlags(['purpose_source' => 'checkout', 'also_use_receiving' => 0], false, $existing);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
}

private function assert_DeliveryAddressPurposeFlags_testReceivingCreateDualWhenAlsoCheckoutChecked(): void
{
    $flags = DeliveryAddressService::resolvePurposeWriteFlags(['purpose_source' => 'receiving', 'also_use_checkout' => 1], true, null);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
}

private function assert_DeliveryAddressPurposeFlags_testReceivingCreateReceivingOnlyWhenUnchecked(): void
{
    $flags = DeliveryAddressService::resolvePurposeWriteFlags(['purpose_source' => 'receiving', 'also_use_checkout' => 0], true, null);
    self::assertSame(0, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
    self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
}

private function assert_DeliveryAddressPurposeFlags_testCheckoutWidgetExposesAlsoUseReceivingCheckbox(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/checkout-shipping-address.phtml');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/js/widgets/checkout-shipping-address.js');
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('data-also-use-receiving', $tpl);
    self::assertStringContainsString('同时用作收货地址', $tpl);
    self::assertStringContainsString("address_purpose: 'checkout'", $js);
    self::assertStringContainsString('purpose_source: \'checkout\'', $js);
    self::assertStringContainsString('20260916-purpose-tags2', $modules);
}

private function assert_DeliveryAddressPurposeFlags_testAccountDeliveryFormExposesAlsoUseCheckoutCheckbox(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/hooks/account.sidebar.content.phtml');
    self::assertStringContainsString('purpose_receiving', $tpl);
    self::assertStringContainsString('data-also-use-checkout', $tpl);
    self::assertStringContainsString('同时用作结账地址', $tpl);
    self::assertStringContainsString("purpose_source\" value=\"receiving\"", $tpl);
    // 收货新建默认不打结账标（禁止 checked）。
    self::assertDoesNotMatchRegularExpression('/name="also_use_checkout"[^>]*\bchecked\b/', $tpl);
}

private function assert_EmbargoReasonLocalModel_testLocalDescriptionExtendsLocalModelAndMapsParent(): void
{
    self::assertTrue(is_subclass_of(EmbargoReasonLocalDescription::class, LocalModel::class));
    self::assertSame(EmbargoReason::schema_fields_ID, EmbargoReasonLocalDescription::schema_fields_ID);
    self::assertSame(EmbargoReason::schema_fields_REASON_NAME, EmbargoReasonLocalDescription::schema_fields_REASON_NAME);
    self::assertSame(EmbargoReason::schema_fields_REASON_NAME, EmbargoReasonLocalDescription::schema_fields_name);
    self::assertSame('w_shipping_embargo_reason_local', EmbargoReasonLocalDescription::schema_table);
}

private function assert_EmbargoReasonLocalModel_testCatalogDiscoversEmbargoReasonLocal(): void
{
    require_once '/Users/weline/Project/Official/框架/app/code/Weline/Shipping/Test/Unit' . '/bootstrap.php';
    $catalog = new LocalModelTranslationCatalog();
    $byClass = [];
    foreach ($catalog->descriptors() as $descriptor) {
        $byClass[(string) $descriptor['local_model']] = $descriptor;
    }
    self::assertArrayHasKey(EmbargoReasonLocalDescription::class, $byClass);
    self::assertSame(EmbargoReason::class, $byClass[EmbargoReasonLocalDescription::class]['parent_model']);
    self::assertContains(EmbargoReason::schema_fields_REASON_NAME, $byClass[EmbargoReasonLocalDescription::class]['fields']);
}

private function assert_EmbargoReasonLocalModel_testAdminServiceSeedsAndUiContracts(): void
{
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/EmbargoReasonAdminService.php');
    self::assertStringContainsString('DEFAULT_SEEDS', $svc);
    self::assertStringContainsString('territory', $svc);
    self::assertStringContainsString('no_commerce', $svc);
    self::assertStringContainsString('LocalModelTranslationQueueService', $svc);
    self::assertTrue(method_exists(EmbargoReasonAdminService::class, 'seedDefaults'));
    self::assertTrue(method_exists(EmbargoReasonAdminService::class, 'listActiveOptions'));
    self::assertTrue(method_exists(EmbargoReasonAdminService::class, 'labelForCode'));
    $ctl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/SystemEmbargo.php');
    self::assertStringContainsString('function reasonSave', $ctl);
    self::assertStringContainsString('function reasonRemove', $ctl);
    self::assertStringContainsString('reason_options', $ctl);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/SystemEmbargo/index.phtml');
    self::assertStringContainsString('system-embargo-reason-card', $tpl);
    self::assertStringContainsString('system-embargo-tabs', $tpl);
    self::assertStringContainsString('system-embargo-tab-reasons', $tpl);
    self::assertStringContainsString('Weline\Shipping\Model\EmbargoReason\LocalDescription', $tpl);
    self::assertStringContainsString('shipping/backend/systemembargo/reasonSave', $tpl);
    self::assertStringContainsString('shipping/backend/systemembargo/reasonRemove', $tpl);
    self::assertStringContainsString('reason_options', $tpl);
    self::assertStringContainsString("['tab' => 'reasons']", $ctl);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('EmbargoReason::class', $upgrade);
    self::assertStringContainsString('seedEmbargoReasons', $upgrade);
}

private function assert_EmbargoService_testEvaluateAgainstRulesUsesUnionAndReportsProvinceLevel(): void
{
    /** @var EmbargoService $service */
    $service = (new \ReflectionClass(EmbargoService::class))->newInstanceWithoutConstructor();
    $rules = [['scope_type' => 'website', 'region_type' => 'province', 'country_code' => 'AU', 'region_id' => 0, 'region_code' => 'AU-NSW', 'street_id' => 0], ['scope_type' => 'channel', 'region_type' => 'country', 'country_code' => 'NZ', 'region_id' => 0, 'region_code' => 'NZ', 'street_id' => 0]];
    $provinceHit = $service->evaluateAgainstRules(['country_code' => 'AU', 'province_code' => 'AU-NSW'], $rules);
    self::assertTrue($provinceHit['blocked']);
    self::assertSame('province', $provinceHit['level']);
    self::assertNotSame('', (string) $provinceHit['message']);
    $otherProvince = $service->evaluateAgainstRules(['country_code' => 'AU', 'province_code' => 'AU-VIC'], $rules);
    self::assertFalse($otherProvince['blocked']);
    $countryHit = $service->evaluateAgainstRules(['country_code' => 'NZ'], $rules);
    self::assertTrue($countryHit['blocked']);
    self::assertSame('country', $countryHit['level']);
    self::assertSame('channel', $countryHit['scope_type']);
}

private function assert_EmbargoService_testRegionProviderExposesEmbargoOperations(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Framework/Query/RegionQueryProvider.php');
    self::assertStringContainsString("'embargo_evaluate'", $src);
    self::assertStringContainsString("'embargo_countries'", $src);
    self::assertStringContainsString("'name' => 'embargo_regions'", $src);
    self::assertStringContainsString('Active subnational embargo rules for address option marking', $src);
    self::assertStringContainsString('Country codes blocked by shipping embargo union', $src);
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Frontend/Region.php');
    self::assertStringContainsString('embargo_evaluate', $controller);
    $addressJs = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/js/address.js');
    self::assertStringContainsString('evaluateEmbargo', $addressJs);
    self::assertStringContainsString('is-embargoed', $addressJs);
    self::assertStringContainsString('embargoCoversControl', $addressJs);
    self::assertStringContainsString('markChildrenInheritedEmbargo', $addressJs);
}

private function assert_EmbargoSubnationalSelect_testEmbargoServiceExposesActiveSubnationalRules(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/EmbargoService.php');
    self::assertStringContainsString('function activeSubnationalRules', $src);
    self::assertStringContainsString('TYPE_COUNTRY', $src);
}

private function assert_EmbargoSubnationalSelect_testRegionControllerServesEmbargoRegionsMode(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Frontend/Region.php');
    self::assertStringContainsString("mode === 'embargo_regions'", $src);
    self::assertStringContainsString('activeSubnationalRules', $src);
}

private function assert_EmbargoSubnationalSelect_testAddressJsMarksAndBlocksSubnationalEmbargo(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/js/address.js');
    self::assertStringContainsString('function embargoedSubnationalRules', $js);
    self::assertStringContainsString('function markChildrenSubnationalEmbargo', $js);
    self::assertStringContainsString("callRegion('embargo_regions', {})", $js);
    self::assertStringContainsString('SUBNATIONAL_EMBARGO_CACHE_KEY', $js);
    self::assertStringContainsString('function ensurePools', $js);
    self::assertStringContainsString('wAddressLazyArmed', $js);
    self::assertStringContainsString('data-address-lazy', $js);
    self::assertStringContainsString('if (hit && hit.region && hit.region.embargoed)', $js);
    self::assertStringContainsString('paintMarked', $js);
    self::assertStringContainsString('markHitsForMenu', $js);
}

private function assert_EmbargoSubnationalSelect_testAddressLoaderBustsSubnationalEmbargoCache(): void
{
    $loader = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/js/address-loader.js');
    self::assertStringContainsString('20260921-keep-postal2', $loader);
}

private function assert_EmbargoSubnationalSelect_testModuleVersionsBumped(): void
{
    $shipping = include '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php';
    $theme = include '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/etc/module.php';
    self::assertSame('2.9.22', (string) ($shipping['version'] ?? ''));
    self::assertSame('2.2.530', (string) ($theme['version'] ?? ''));
}

private function assert_EmbargoSubnationalSelect_testRegionJsonWritesFrameworkResponseHeaders(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Frontend/Region.php');
    self::assertStringContainsString("setHeader('Content-Type', 'application/json; charset=utf-8')", $src);
    self::assertStringContainsString("setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')", $src);
    self::assertStringNotContainsString("header('Content-Type: application/json; charset=utf-8')", $src);
}

private function assert_FreeShippingConditionTypeLocalModel_testLocalDescriptionExtendsLocalModelAndMapsParent(): void
{
    self::assertTrue(is_subclass_of(FreeShippingConditionTypeLocalDescription::class, LocalModel::class));
    self::assertSame(FreeShippingConditionType::schema_fields_ID, FreeShippingConditionTypeLocalDescription::schema_fields_ID);
    self::assertSame(FreeShippingConditionType::schema_fields_CONDITION_NAME, FreeShippingConditionTypeLocalDescription::schema_fields_CONDITION_NAME);
    self::assertSame(FreeShippingConditionType::schema_fields_CONDITION_NAME, FreeShippingConditionTypeLocalDescription::schema_fields_name);
    self::assertSame('w_shipping_free_condition_type_local', FreeShippingConditionTypeLocalDescription::schema_table);
}

private function assert_FreeShippingConditionTypeLocalModel_testCatalogDiscoversConditionTypeLocal(): void
{
    // 轻量契约：完整 LocalModelTranslationCatalog 扫描依赖 APP_* 常量；无宿主 bootstrap 时退回结构断言。
    require_once '/Users/weline/Project/Official/框架/app/code/Weline/Shipping/Test/Unit' . '/bootstrap.php';
    if (!\defined('APP_CODE_PATH') || !\defined('APP_ETC_PATH')) {
        self::assertTrue(class_exists(FreeShippingConditionTypeLocalDescription::class));
        self::assertTrue(is_subclass_of(FreeShippingConditionTypeLocalDescription::class, LocalModel::class));
        self::assertSame(FreeShippingConditionType::schema_fields_ID, FreeShippingConditionTypeLocalDescription::schema_primary_key);
        return;
    }
    $catalog = new LocalModelTranslationCatalog();
    $byClass = [];
    foreach ($catalog->descriptors() as $descriptor) {
        $byClass[(string) $descriptor['local_model']] = $descriptor;
    }
    self::assertArrayHasKey(FreeShippingConditionTypeLocalDescription::class, $byClass);
    self::assertSame(FreeShippingConditionType::class, $byClass[FreeShippingConditionTypeLocalDescription::class]['parent_model']);
    self::assertContains(FreeShippingConditionType::schema_fields_CONDITION_NAME, $byClass[FreeShippingConditionTypeLocalDescription::class]['fields']);
}

private function assert_FreeShippingConditionTypeLocalModel_testAdminServiceSeedsAndUiContracts(): void
{
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/FreeShippingConditionTypeAdminService.php');
    self::assertStringContainsString('DEFAULT_SEEDS', $svc);
    self::assertStringContainsString('CONDITION_ORDER_AMOUNT', $svc);
    self::assertStringContainsString('CONDITION_REGION', $svc);
    self::assertStringContainsString('CONDITION_MIXED', $svc);
    self::assertStringContainsString('订单金额', $svc);
    self::assertStringContainsString('LocalModelTranslationQueueService', $svc);
    self::assertTrue(method_exists(FreeShippingConditionTypeAdminService::class, 'seedDefaults'));
    self::assertTrue(method_exists(FreeShippingConditionTypeAdminService::class, 'mapByCode'));
    self::assertTrue(method_exists(FreeShippingConditionTypeAdminService::class, 'labelForCode'));
    $ctl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/FreeShippingRule.php');
    self::assertStringContainsString('FreeShippingConditionTypeAdminService', $ctl);
    self::assertStringContainsString('page_section', $ctl);
    self::assertStringContainsString('condition_types_by_code', $ctl);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/FreeShippingRule/index.phtml');
    self::assertStringContainsString('Weline\Shipping\Model\FreeShippingConditionType\LocalDescription', $tpl);
    self::assertStringContainsString('field="condition_name"', $tpl);
    self::assertStringContainsString('bindRuntimeRecordId', $tpl);
    self::assertStringContainsString('shipping-free-condition-type-card', $tpl);
    self::assertStringContainsString('shipping-free-rule-page-tabs', $tpl);
    self::assertStringContainsString('shipping-free-rule-tab-conditions', $tpl);
    self::assertStringContainsString('shipping-free-rule-condition-type', $tpl);
    self::assertStringNotContainsString('getData(FreeShippingRule::schema_fields_CONDITION_TYPE)) ?></td>', $tpl);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('FreeShippingConditionType::class', $upgrade);
    self::assertStringContainsString('seedFreeShippingConditionTypes', $upgrade);
}

private function assert_FreeShippingRuleSeed_testModelDeclaresOriginSeedConstants(): void
{
    self::assertSame('origin', FreeShippingRule::schema_fields_ORIGIN);
    self::assertSame('seed', FreeShippingRule::ORIGIN_SEED);
    self::assertSame('manual', FreeShippingRule::ORIGIN_MANUAL);
}

private function assert_FreeShippingRuleSeed_testDefaultSeedsAreInternationalThresholdTemplates(): void
{
    self::assertTrue(class_exists(FreeShippingRuleSeedService::class));
    self::assertTrue(method_exists(FreeShippingRuleSeedService::class, 'seedDefaults'));
    self::assertTrue(method_exists(FreeShippingRuleSeedService::class, 'canonicalCodes'));
    $codes = [];
    $activeCodes = [];
    foreach (FreeShippingRuleSeedService::DEFAULT_SEEDS as $seed) {
        $code = (string) ($seed['rule_code'] ?? '');
        self::assertNotSame('', $code);
        self::assertSame(FreeShippingRule::CONDITION_ORDER_AMOUNT, $seed['condition_type'] ?? null);
        $codes[] = $code;
        if (!empty($seed['is_active'])) {
            $activeCodes[] = $code;
        }
    }
    foreach (['SEED_FREE_49', 'SEED_FREE_99', 'SEED_FREE_149', 'SEED_FREE_199', 'SEED_FREE_299', 'SEED_FREE_499'] as $expected) {
        self::assertContains($expected, $codes);
    }
    self::assertSame(['SEED_FREE_49'], $activeCodes);
    self::assertSame($codes, FreeShippingRuleSeedService::canonicalCodes());
}

private function assert_FreeShippingRuleSeed_testAdminDeleteGateAndUiContracts(): void
{
    $admin = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigurationAdminService.php');
    self::assertStringContainsString('deleteFreeShippingRule', $admin);
    self::assertStringContainsString('setFreeShippingRuleActive', $admin);
    self::assertStringContainsString('ORIGIN_SEED', $admin);
    self::assertStringContainsString('ORIGIN_MANUAL', $admin);
    self::assertStringContainsString('系统种子不可删除', $admin);
    self::assertMatchesRegularExpression('/function createFreeShippingRule[\s\S]+ORIGIN_MANUAL/', $admin);
    $ctl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/FreeShippingRule.php');
    self::assertStringContainsString('function remove', $ctl);
    self::assertStringContainsString('function activate', $ctl);
    self::assertStringContainsString('function deactivate', $ctl);
    self::assertStringContainsString('FreeShippingRuleSeedService', $ctl);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/FreeShippingRule/index.phtml');
    self::assertStringContainsString('free-shipping-origin-seed', $tpl);
    self::assertStringContainsString('系统种子不可删除', $tpl);
    self::assertStringContainsString('shipping/backend/freeshippingrule/remove', $tpl);
    self::assertStringContainsString('shipping/backend/freeshippingrule/activate', $tpl);
    self::assertStringContainsString('shipping/backend/freeshippingrule/deactivate', $tpl);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('alignHomepageWave2Threshold49', $upgrade);
    self::assertStringContainsString('seedFreeShippingRules', $upgrade);
    self::assertStringContainsString('FreeShippingRuleSeedService', $upgrade);
    $lane = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('FreeShippingRuleSeedService', $lane);
    self::assertStringContainsString('seedDefaults', $lane);
}

/** @return list<string> */
private function catalogCountries_G20RegionPack(): array
{
    return ['AR', 'AU', 'BR', 'CA', 'FR', 'DE', 'IN', 'ID', 'IT', 'JP', 'KR', 'MX', 'RU', 'SA', 'ZA', 'TR', 'GB', 'US', 'CN', 'HK', 'MO', 'TW', 'NZ'];
}

private function assert_G20RegionPack_testAddressCatalogProvincesExistForCoveredCountries(): void
{
    $service = new RegionCascadeEnsureService();
    foreach ($this->catalogCountries_G20RegionPack() as $cc) {
        self::assertTrue($service->hasCatalogCoverage($cc), $cc . ' should have address-catalog/provinces.tsv.gz');
        self::assertNull($service->loadPack($cc), 'JSON packs removed');
    }
}

private function assert_G20RegionPack_testGeonamesFilledCountriesHaveProvinces(): void
{
    $service = new RegionCascadeEnsureService();
    foreach (['PY', 'EG', 'AI'] as $cc) {
        self::assertTrue($service->hasCatalogCoverage($cc), $cc . ' should be filled from GeoNames ADM1');
    }
    self::assertFalse($service->hasCatalogCoverage('ZZ'), 'ZZ is not a real catalog country');
}

private function assert_G20RegionPack_testProfilesOpenDistrictForNestedG20Only(): void
{
    $profiles = new AddressCountryProfileService();
    self::assertContains('district', $profiles->profileFor('FR')['levels']);
    self::assertContains('district', $profiles->profileFor('GB')['levels']);
    self::assertContains('district', $profiles->profileFor('IT')['levels']);
    self::assertNotContains('district', $profiles->profileFor('US')['levels']);
    self::assertNotContains('district', $profiles->profileFor('JP')['levels']);
}

private function assert_GatesSurchargeChapter2_testDefaultPointTypesExcludePobox(): void
{
    $gate = new ShippingCapabilityGate();
    self::assertTrue($gate->allowsPointType(ShippingCapabilityGate::DEFAULT_ALLOWED_POINTS, 'residential'));
    self::assertFalse($gate->allowsPointType(ShippingCapabilityGate::DEFAULT_ALLOWED_POINTS, 'pobox'));
}

private function assert_GatesSurchargeChapter2_testEmptyAcceptedHazardsRejectLineHazards(): void
{
    $gate = new ShippingCapabilityGate();
    self::assertTrue($gate->allowsHazards('', []));
    self::assertFalse($gate->allowsHazards('', ['battery_lithium']));
    self::assertTrue($gate->allowsHazards('battery_lithium,liquid', ['battery_lithium']));
}

private function assert_GatesSurchargeChapter2_testCollectLineHazardsFromMetadata(): void
{
    $gate = new ShippingCapabilityGate();
    $classes = $gate->collectLineHazards([['requires_shipping' => true, 'fulfillment_metadata' => ['shipping_hazard_class' => 'liquid']], ['requires_shipping' => false, 'shipping_hazard_class' => 'battery_lithium']]);
    self::assertSame(['liquid'], $classes);
}

private function assert_GatesSurchargeChapter2_testSeedSurchargeCodesMatchPlan(): void
{
    $codes = DefaultShippingLaneSeedService::expectedSeedSurchargeCodes();
    self::assertCount(6, $codes);
    self::assertContains('SEED_SURCHARGE_CN_XJ', $codes);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('ensureRemoteSurchargeSeeds', $src);
    self::assertStringContainsString('SEED_SURCHARGE_CN_XZ', $src);
}

private function assert_GatesSurchargeChapter2_testLocalPricingWiresSurchargeService(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/Provider/LocalTemplatePricingService.php');
    self::assertStringContainsString('ShippingSurchargeService', $src);
    self::assertStringContainsString('surcharges', $src);
    self::assertStringContainsString('free_reason', $src);
}

private function assert_GatesSurchargeChapter2_testQuoteRatesAppliesCapabilityGate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('ShippingCapabilityGate', $src);
    self::assertStringContainsString('delivery_point_type', $src);
    self::assertStringContainsString('allowsHazards', $src);
    self::assertStringContainsString('surchargeConfigFacts', $src);
}

private function assert_GatesSurchargeChapter2_testSurchargeSumAndFixedMinorMath(): void
{
    $svc = new ShippingSurchargeService($this->createMock(\Weline\Framework\Manager\ObjectManager::class));
    self::assertSame(2500, $svc->sumMinor([['amount_minor' => 2500], ['amount_minor' => 0]]));
    // 固定 25.00 @ precision 2 → 2500 minor（与 SEED_SURCHARGE_CN_XJ 一致）
    $scale = 10 ** 2;
    self::assertSame(2500, (int) round(25.0 * $scale));
}

private function catalogRoot_GeonamesCascadeCatalog(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/data/address-catalog';
}

private function assert_GeonamesCascadeCatalog_testBuildScriptExists(): void
{
    $script = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/scripts/build-global-cascade-from-geonames.php';
    self::assertFileExists($script);
    $src = (string) file_get_contents($script);
    self::assertStringContainsString('--fill-missing', $src);
    self::assertStringContainsString('--fill-empty-cities', $src);
    self::assertStringContainsString('admin1CodesASCII', $src);
}

private function assert_GeonamesCascadeCatalog_testEgyptAndParaguayHaveProvinceAndCityLayers(): void
{
    foreach (['EG', 'PY'] as $cc) {
        $prov = $this->catalogRoot_GeonamesCascadeCatalog() . '/' . $cc . '/provinces.tsv.gz';
        $city = $this->catalogRoot_GeonamesCascadeCatalog() . '/' . $cc . '/cities.tsv.gz';
        self::assertFileExists($prov, $cc . ' provinces');
        self::assertFileExists($city, $cc . ' cities');
        $provLines = $this->gzLineCount_GeonamesCascadeCatalog($prov);
        $cityLines = $this->gzLineCount_GeonamesCascadeCatalog($city);
        self::assertGreaterThan(5, $provLines, $cc . ' provinces rows');
        self::assertGreaterThan(10, $cityLines, $cc . ' cities rows');
    }
}

private function assert_GeonamesCascadeCatalog_testCatalogProvinceCoverageIsNearGlobal(): void
{
    $root = $this->catalogRoot_GeonamesCascadeCatalog();
    $dirs = glob($root . '/*/provinces.tsv.gz') ?: [];
    self::assertGreaterThanOrEqual(249, count($dirs), 'expect all countries.tsv entries to have provinces (incl. synthetic)');
}

private function assert_GeonamesCascadeCatalog_testCatalogCityCoverageIsGlobalWhenProvincesExist(): void
{
    $root = $this->catalogRoot_GeonamesCascadeCatalog();
    $empty = [];
    foreach (glob($root . '/*/provinces.tsv.gz') ?: [] as $prov) {
        $cc = basename(dirname($prov));
        $city = $root . '/' . $cc . '/cities.tsv.gz';
        if (!is_file($city) || $this->gzLineCount_GeonamesCascadeCatalog($city) <= 0) {
            $empty[] = $cc;
        }
    }
    self::assertSame([], $empty, 'every country with provinces must have at least one city row (ADM2 or synthetic)');
}

private function assert_GeonamesCascadeCatalog_testSyntheticNationwideExistsForCityStates(): void
{
    foreach (['SG', 'GI', 'VA'] as $cc) {
        $path = $this->catalogRoot_GeonamesCascadeCatalog() . '/' . $cc . '/provinces.tsv.gz';
        self::assertFileExists($path);
        $fh = gzopen($path, 'rb');
        self::assertNotFalse($fh);
        $body = stream_get_contents($fh);
        gzclose($fh);
        self::assertStringContainsString($cc . '-00', (string) $body);
        $cityPath = $this->catalogRoot_GeonamesCascadeCatalog() . '/' . $cc . '/cities.tsv.gz';
        self::assertGreaterThan(0, $this->gzLineCount_GeonamesCascadeCatalog($cityPath), $cc . ' synthetic city');
        $cfh = gzopen($cityPath, 'rb');
        self::assertNotFalse($cfh);
        $cityBody = (string) stream_get_contents($cfh);
        gzclose($cfh);
        self::assertStringContainsString($cc . '-00-CITY', $cityBody);
    }
}

private function assert_GeonamesCascadeCatalog_testAfghanistanBadakhshanHasCitiesInCatalog(): void
{
    $city = $this->catalogRoot_GeonamesCascadeCatalog() . '/AF/cities.tsv.gz';
    self::assertFileExists($city);
    $fh = gzopen($city, 'rb');
    self::assertNotFalse($fh);
    $body = (string) stream_get_contents($fh);
    gzclose($fh);
    self::assertStringContainsString("\tAF-01\t", $body);
    self::assertStringContainsString('Faizabad', $body);
}

private function gzLineCount_GeonamesCascadeCatalog(string $path): int
{
    $fh = gzopen($path, 'rb');
    self::assertNotFalse($fh);
    $n = 0;
    while (gzgets($fh) !== false) {
        $n++;
    }
    gzclose($fh);
    return max(0, $n - 1);
}

private function assert_MultiWarehouseSplitShipping_testOriginInterfaceAndServiceExist(): void
{
    self::assertTrue(interface_exists(WarehouseShippingOriginInterface::class));
    self::assertTrue(class_exists(WarehouseShippingOriginService::class));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/WarehouseShippingOriginService.php');
    self::assertStringContainsString('requireShippingAddressId', $src);
    self::assertStringContainsString('bind(', $src);
    self::assertStringContainsString('website:0', $src);
    self::assertStringContainsString('$candidates[] = 0', $src);
}

private function assert_MultiWarehouseSplitShipping_testSplitQuoteHashCoversPackagesNotOnlyTotal(): void
{
    $req = new ShippingQuoteRequest(scope: ['website_id' => 1, 'store_id' => 1], address: ['country_code' => 'CN', 'province' => 'Zhejiang', 'city' => 'Hangzhou'], lines: [['line_uuid' => 'a', 'sku' => 's1', 'qty_minor' => 1000, 'unit_price_minor' => 100]], currency: 'CNY');
    $packagesA = [['split_key' => 'wh:1', 'warehouse_id' => 1, 'service_code' => 'STD', 'amount_minor' => 500, 'lines' => [['line_uuid' => 'a', 'sku' => 's1']]]];
    $packagesB = [['split_key' => 'wh:2', 'warehouse_id' => 2, 'service_code' => 'STD', 'amount_minor' => 500, 'lines' => [['line_uuid' => 'a', 'sku' => 's1']]]];
    $h1 = SplitShippingQuote::buildRequestHash($req, 'WLS_STD', $packagesA, 500);
    $h2 = SplitShippingQuote::buildRequestHash($req, 'WLS_STD', $packagesB, 500);
    self::assertNotSame($h1, $h2);
}

private function assert_MultiWarehouseSplitShipping_testSplitServiceAndLaneCopyRegisteredInModule(): void
{
    $module = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php');
    self::assertStringContainsString('SplitShippingQuoteServiceInterface', $module);
    self::assertStringContainsString('WarehouseShippingOriginInterface', $module);
    self::assertStringContainsString('2.4.89', $module);
    self::assertTrue(class_exists(SplitShippingQuoteService::class));
    self::assertTrue(class_exists(WarehouseLaneCopyService::class));
    $mgr = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('originShippingAddressId', $mgr);
    self::assertStringContainsString('shipping_profile_conflict', $mgr);
}

private function assert_MultiWarehouseSplitShipping_testAdminCopyAndBindSurfacesExist(): void
{
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/ShippingService.php');
    self::assertStringContainsString('bindWarehouseOrigin', $ctrl);
    self::assertStringContainsString('copyDefaultLanes', $ctrl);
    $view = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/ShippingService/index.phtml');
    self::assertStringContainsString('copy-default-lanes-btn', $view);
    self::assertStringContainsString('仓 ↔ 发货地址绑定', $view);
    $rate = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/RateTemplate/index.phtml');
    self::assertStringContainsString('按距离', $rate);
    self::assertStringContainsString('相加', $rate);
}

private function assert_OrderShipmentTrackingQueryService_testServiceExposesShipmentScopedQueryApi(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/OrderShipmentTrackingQueryService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('queryByShipmentId', $src);
    self::assertStringContainsString('ShippingFacade', $src);
    self::assertStringContainsString('queryTracking', $src);
    self::assertStringContainsString('buildProgressLines', $src);
    self::assertStringContainsString('tracking_url_template', $src);
}

private function assert_OrderShipmentTrackingQueryService_testTrackingControllerExposesQueryAction(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/Tracking.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function query', $src);
    self::assertStringContainsString('OrderShipmentTrackingQueryService', $src);
    self::assertStringContainsString('application/json', $src);
}

private function assert_PackAddonChapter3_testPackingSplitterSplitsByWeight(): void
{
    $splitter = new PackingSplitter();
    $boxes = $splitter->splitBoxes([[
        'requires_shipping' => true,
        'qty_minor' => 2,
        'weight_minor' => 20000,
        // 20kg each
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]], 30.0, 120000.0);
    self::assertCount(2, $boxes);
    self::assertCount(1, $boxes[0]);
    self::assertCount(1, $boxes[1]);
}

private function assert_PackAddonChapter3_testPackingSplitterKeepsUnderLimitInOneBox(): void
{
    $splitter = new PackingSplitter();
    $boxes = $splitter->splitBoxes([['requires_shipping' => true, 'qty_minor' => 1, 'weight_minor' => 5000, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]], 30.0, 120000.0);
    self::assertCount(1, $boxes);
}

private function assert_PackAddonChapter3_testLocalPricingWiresPackSeasonalAddon(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/Provider/LocalTemplatePricingService.php');
    self::assertStringContainsString('PackingSplitter', $src);
    self::assertStringContainsString('ShippingSeasonalSurchargeService', $src);
    self::assertStringContainsString('ShippingCheckoutAddonService', $src);
    self::assertStringContainsString('package_count', $src);
}

private function assert_PackAddonChapter3_testSeedHasPackingAndSeasonalDefaultsOff(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('ensurePackingAndAddonSeeds', $src);
    self::assertStringContainsString('ShippingPackingPolicy::SEED_CODE', $src);
    self::assertStringContainsString('ShippingSeasonalRule::SEED_PEAK', $src);
    self::assertStringContainsString('ShippingCheckoutAddon::SEED_SIGNATURE', $src);
    self::assertStringContainsString('schema_fields_IS_ACTIVE => 0', $src);
}

private function assert_PackAddonChapter3_testConfigVersionIncludesChapter3Facts(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('packingConfigFacts', $src);
    self::assertStringContainsString('seasonalConfigFacts', $src);
    self::assertStringContainsString('checkoutAddonConfigFacts', $src);
}

private function assert_PackAddonChapter3_testModuleVersionIsAtLeast280(): void
{
    $module = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php');
    self::assertMatchesRegularExpression("/'2\\.(8|9)\\.\\d+'/", $module);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('ShippingPackingPolicy', $upgrade);
    self::assertStringContainsString('ShippingCheckoutAddon', $upgrade);
    self::assertStringContainsString('ShippingSeasonalRule', $upgrade);
}

private function assert_PackAddonChapter3_testAddonAndSeasonalSumMinor(): void
{
    $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
    $addon = new ShippingCheckoutAddonService($om);
    $seasonal = new ShippingSeasonalSurchargeService($om);
    self::assertSame(500, $addon->sumMinor([['amount_minor' => 500]]));
    self::assertSame(575, $seasonal->sumMinor([['amount_minor' => 500], ['amount_minor' => 75]]));
    // 签名固定 5.00 → 500；燃油 5% of 11500 → 575
    self::assertSame(500, (int) round(5.0 * 100));
    self::assertSame(575, (int) round(11500 * 5.0 / 100));
}

private function assert_PostalCountriesEmbargo_testPostalCountriesReturnsPlaceNameAndEmbargoed(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/RegionService.php');
    self::assertStringContainsString('function postalCountries', $src);
    self::assertStringContainsString("'place_name' => \$placeName", $src);
    self::assertStringContainsString("'embargoed' => \$isEmbargoed", $src);
    self::assertStringContainsString('evaluateAddress', $src);
    self::assertStringContainsString('EmbargoService::class', $src);
    self::assertStringContainsString('countrySortRanks', $src);
    self::assertStringContainsString('seedCountrySortFromDefaultMarkets', $src);
    self::assertStringContainsString('sort_order', $src);
}

private function assert_PostalCountriesEmbargo_testNormalizePostalStripsInvisibleFormatChars(): void
{
    $zwnj = "10001‌";
    self::assertSame('10001', TsvGzReader::normalizePostal($zwnj));
    self::assertSame('10001', TsvGzReader::normalizePostal("10001​"));
    self::assertSame('10001', TsvGzReader::normalizePostal(" 100 01 "));
}

private function assert_PostalCountriesEmbargo_testPostalLookupNormsLetterFallbackAndNumericNoFallback(): void
{
    self::assertSame(['SW1A1AA', 'SW1A', 'SW1'], TsvGzReader::postalLookupNorms('SW1A 1AA'));
    self::assertSame(['M5V2T6', 'M5V'], TsvGzReader::postalLookupNorms('M5V 2T6'));
    self::assertSame(['A65F4E2', 'A65'], TsvGzReader::postalLookupNorms('A65 F4E2'));
    self::assertSame(['100001'], TsvGzReader::postalLookupNorms('100001'));
    self::assertSame(['10001'], TsvGzReader::postalLookupNorms('10001'));
    self::assertSame(['SW1A'], TsvGzReader::postalLookupNorms('SW1A'));
}

private function assert_PostalCountriesEmbargo_testPostalLookupUsesLookupNormsCandidates(): void
{
    $region = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/RegionService.php');
    $reader = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/AddressCatalog/TsvGzReader.php');
    self::assertStringContainsString('function postalLookupNorms', $reader);
    self::assertStringContainsString('postalLookupNorms', $region);
    self::assertStringContainsString("POSTAL_CODE_NORM, \$norms, 'in'", $region);
}

private function assert_PostalCountriesEmbargo_testDefaultMarketsTsvDeclaresSortOrderAndUsBeforeKr(): void
{
    $tsv = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/data/default-markets/countries.tsv';
    self::assertFileExists($tsv);
    $body = (string) file_get_contents($tsv);
    self::assertStringContainsString('sort_order', $body);
    self::assertMatchesRegularExpression('/^US\tamericas\t50\s*$/m', $body);
    self::assertMatchesRegularExpression('/^KR\tasia_pacific\t120\s*$/m', $body);
}

private function assert_PostalCountriesEmbargo_testPostalCountriesRanksUsNewYorkAheadOfDzFor10001(): void
{
    /** @var RegionService $svc */
    $svc = ObjectManager::getInstance()->getInstance(RegionService::class);
    try {
        $svc->seedCountrySortFromDefaultMarkets(true);
        $rows = $svc->postalCountries('10001');
    } catch (\Throwable $e) {
        self::markTestSkipped('Shipping region tables unavailable in this PHPUnit DB: ' . $e->getMessage());
    }
    self::assertNotEmpty($rows);
    $codes = array_map(static fn(array $row): string => (string) ($row['country_code'] ?? ''), $rows);
    self::assertContains('US', $codes);
    $usPos = array_search('US', $codes, true);
    $dzPos = array_search('DZ', $codes, true);
    $krPos = array_search('KR', $codes, true);
    self::assertNotFalse($usPos);
    if ($dzPos !== false) {
        self::assertLessThan($dzPos, $usPos, 'US must rank above DZ for 10001');
    }
    if ($krPos !== false) {
        self::assertLessThan($krPos, $usPos, 'US hot sort must rank above KR for 10001');
    }
    self::assertSame('New York', (string) ($rows[$usPos]['place_name'] ?? ''));
}

private function assert_PostalCountriesEmbargo_testCnPostalCatalogIncludesPudongAlias200100(): void
{
    $gz = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/data/address-catalog/CN/postal.tsv.gz';
    self::assertFileExists($gz);
    $fh = gzopen($gz, 'rb');
    self::assertNotFalse($fh);
    $found = false;
    while (($line = gzgets($fh)) !== false) {
        if (str_starts_with($line, "CN\t200100\t")) {
            self::assertStringContainsString('浦东新区', $line);
            self::assertStringContainsString("\t31\t3101\t310115", $line);
            $found = true;
            break;
        }
    }
    gzclose($fh);
    self::assertTrue($found, 'CN postal catalog must include 200100 → 浦东新区');
}

private function assert_PostalCountriesEmbargo_testShippingModuleVersionBumpedForPostalEmbargo(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php';
    self::assertIsArray($module);
    self::assertSame('2.9.21', (string) ($module['version'] ?? ''));
}

private function assert_PostalCountriesEmbargo_testRegionBackendExposesCountrySortEditor(): void
{
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/Region.php');
    self::assertStringContainsString('saveCountrySort', $ctrl);
    self::assertStringContainsString('country_sort_rows', $ctrl);
    $view = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/Region/index.phtml');
    self::assertStringContainsString('shipping-country-sort-card', $view);
    self::assertStringContainsString('热门国家排序', $view);
    $admin = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigurationAdminService.php');
    self::assertStringContainsString('updateCountrySortOrders', $admin);
}

private function assert_ProductDeliveryModeLightOrigin_testDeliveryModeWidgetUsesDefaultOriginNotPlanPackages(): void
{
    $widget = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/frontend/widgets/product-delivery-mode.phtml');
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/StorefrontOfferOriginCountryService.php');
    self::assertStringContainsString('@widget.cache {300}', $widget);
    self::assertStringNotContainsString('@widget.cache_ttl', $widget);
    self::assertStringContainsString('resolveDefaultOriginCountry', $widget);
    self::assertStringNotContainsString('resolveForOffer(', $widget);
    self::assertStringContainsString('function resolveDefaultOriginCountry', $service);
    self::assertStringContainsString('defaultWarehouseId', $service);
    self::assertStringNotContainsString('planPackages($websiteId, $storeId', substr($service, (int) strpos($service, 'function resolveDefaultOriginCountry'), 800));
}

private function managerSrc_QuoteConfigVersionStability(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
}

private function scopedSrc_QuoteConfigVersionStability(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ScopedShippingQuoteService.php');
}

private function assert_QuoteConfigVersionStability_testActiveQuoteConfigVersionAcceptsContextAndMemosByLayer(): void
{
    $src = $this->managerSrc_QuoteConfigVersionStability();
    self::assertMatchesRegularExpression('/function\s+activeQuoteConfigVersion\s*\(\s*\?array\s+\$context\s*=\s*null\s*\)/', $src);
    self::assertStringContainsString('resolveNearestServiceLayer', $src);
    self::assertStringContainsString('quoteConfigVersionMemo', $src);
    self::assertStringContainsString('pickFactFields', $src);
    self::assertStringNotContainsString("'service' => \$this->canonical((array)\$service->getData())", $src);
}

private function assert_QuoteConfigVersionStability_testReachabilityFactsUseFreshModelsAndScopeChain(): void
{
    $src = $this->managerSrc_QuoteConfigVersionStability();
    self::assertStringContainsString('EmbargoRegion::class, [], false', $src);
    self::assertStringContainsString('quoteLayerChain', $src);
    self::assertStringContainsString('SCOPE_SYSTEM', $src);
    self::assertStringContainsString('service_lanes', $src);
    self::assertStringContainsString('serviceIds', $src);
}

private function assert_QuoteConfigVersionStability_testQuoteRatesAndAvailableServicesThreadContext(): void
{
    $src = $this->managerSrc_QuoteConfigVersionStability();
    self::assertMatchesRegularExpression('/function\s+quoteRates\s*\([^)]*\?array\s+\$context\s*=\s*null/', $src);
    self::assertMatchesRegularExpression('/function\s+getAvailableServices\s*\([^)]*\?array\s+\$context\s*=\s*null/', $src);
    $avail = strstr($src, 'function getAvailableServices');
    self::assertNotFalse($avail);
    $chunk = substr((string) $avail, 0, 1200);
    self::assertStringContainsString('coverageMatch()->getAvailableServices', $chunk);
    self::assertStringContainsString('$context', $chunk);
}

private function assert_QuoteConfigVersionStability_testScopedQuotePassesRequestScopeIntoVersionAndRates(): void
{
    $src = $this->scopedSrc_QuoteConfigVersionStability();
    self::assertStringContainsString('quoteContext', $src);
    self::assertStringContainsString('activeQuoteConfigVersion(', $src);
    self::assertStringContainsString('quoteRates(', $src);
    self::assertStringContainsString('$request->scope', $src);
    self::assertStringContainsString('website_id', $src);
    self::assertStringContainsString('store_id', $src);
    self::assertStringContainsString('channel_id', $src);
}

private function assert_QuoteConfigVersionStability_testShippingInfoProviderNormalizesScopeIntoRequest(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Framework/Query/ShippingInfoQueryProvider.php');
    self::assertStringContainsString('normalizeQuoteScope', $src);
    self::assertStringContainsString('website_id', $src);
}

private function assert_QuoteFxDiagnostics_testQuoteRatesExposeFxSkippedDiagnostics(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('getLastQuoteDiagnostics', $src);
    self::assertStringContainsString('fx_skipped', $src);
    self::assertStringContainsString('$fxSkipped[] = $skipped', $src);
    self::assertStringContainsString("'missing_weight'", $src);
}

private function assert_QuoteFxDiagnostics_testListQuoteOptionsReturnsDiagnostics(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Framework/Query/ShippingInfoQueryProvider.php');
    self::assertStringContainsString('quote_diagnostics', $src);
    self::assertStringContainsString('getLastQuoteDiagnostics', $src);
}

private function assert_RateTableChapter1_testSeedAmericasBracketsMatchPlan(): void
{
    $brackets = SeedWeightBracketFactory::fromLinear(45, 14, SeedWeightBracketFactory::GENERAL_BOUNDS);
    self::assertCount(7, $brackets);
    self::assertSame('52.00', $brackets[0]['price']);
    self::assertSame('465.00', $brackets[6]['price']);
    self::assertSame(30.0, $brackets[6]['max']);
}

private function assert_RateTableChapter1_testAmericas2kgHits115Bracket(): void
{
    $brackets = SeedWeightBracketFactory::fromLinear(45, 14, SeedWeightBracketFactory::GENERAL_BOUNDS);
    // 2kg ∈ [2,5) → price at max=5 → 45+14*5=115
    self::assertSame('115.00', $brackets[3]['price']);
    self::assertSame(2.0, $brackets[3]['min']);
    self::assertSame(5.0, $brackets[3]['max']);
}

private function assert_RateTableChapter1_testWeightTableQuotesAmericas2kgAndRefusesOverMax(): void
{
    $tpl = new RateTemplate();
    $tpl->setData([RateTemplate::schema_fields_IS_ACTIVE => 1, RateTemplate::schema_fields_CALCULATION_TYPE => RateTemplate::CALC_TYPE_WEIGHT_TABLE, RateTemplate::schema_fields_MAX_WEIGHT_KG => 30, RateTemplate::schema_fields_RATE_BRACKETS => json_encode(SeedWeightBracketFactory::fromLinear(45, 14, SeedWeightBracketFactory::GENERAL_BOUNDS), JSON_UNESCAPED_UNICODE)]);
    $calc = new RateCalculationService($this->createMock(\Weline\Framework\Manager\ObjectManager::class));
    $minor = $calc->calculateTemplateMinor($tpl, [['requires_shipping' => true, 'qty_minor' => 1, 'weight_minor' => 2000, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]], 2);
    self::assertSame(11500, $minor);
    $this->expectException(ShippingRateUnavailableException::class);
    $calc->calculateTemplateMinor($tpl, [['requires_shipping' => true, 'qty_minor' => 1, 'weight_minor' => 35000, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20]], 2);
}

private function assert_RateTableChapter1_testBracketValidatorRejectsGaps(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new RateBracketValidator())->normalizeAndValidate([['min' => 0, 'max' => 1, 'price' => '10'], ['min' => 2, 'max' => 3, 'price' => '20']], 3.0);
}

private function assert_RateTableChapter1_testChargeableWeightUsesVolumetric(): void
{
    $summary = (new ChargeableWeightService())->summarize([[
        'requires_shipping' => true,
        'qty_minor' => 1,
        'weight_minor' => 500,
        // 0.5kg
        'length_cm' => 50,
        'width_cm' => 40,
        'height_cm' => 30,
    ]]);
    self::assertEqualsWithDelta(12.0, $summary['weight_kg'], 0.01);
    self::assertFalse($summary['missing_weight']);
    self::assertFalse($summary['missing_dims']);
}

private function assert_RateTableChapter1_testMissingWeightAndDimsFlags(): void
{
    $svc = new ChargeableWeightService();
    $noWeight = $svc->summarize([['requires_shipping' => true, 'qty_minor' => 1, 'weight_minor' => 0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]]);
    self::assertTrue($noWeight['missing_weight']);
    $noDims = $svc->summarize([['requires_shipping' => true, 'qty_minor' => 1, 'weight_minor' => 2000, 'length_cm' => 0, 'width_cm' => 0, 'height_cm' => 0]]);
    self::assertTrue($noDims['missing_dims']);
}

private function assert_RateTemplateBaseCurrency_testAdminServiceForcesBaseCurrencyAndSupportsUpdate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigurationAdminService.php');
    self::assertStringContainsString('function createRateTemplate', $src);
    self::assertStringContainsString('function updateRateTemplate', $src);
    self::assertStringContainsString('CurrencyRateService', $src);
    self::assertStringContainsString('getBaseCurrency', $src);
    self::assertDoesNotMatchRegularExpression('/CURRENCY_CODE\s*=>\s*strtoupper\(trim\(\(string\)\(\$data\[[\'"]currency_code[\'"]/', $src);
}

private function assert_RateTemplateBaseCurrency_testControllerSaveBranchesCreateAndUpdate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/RateTemplate.php');
    self::assertStringContainsString('updateRateTemplate', $src);
    self::assertStringContainsString('template_id', $src);
    self::assertStringContainsString('editing_template', $src);
}

private function assert_RateTemplateBaseCurrency_testQuoteRatesConvertsViaCurrencyRateService(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('tryConvert', $src);
    self::assertStringContainsString('CurrencyRateService', $src);
    self::assertStringNotContainsString('if ($templateCurrency === \'\' || $templateCurrency !== $currency)', $src);
    self::assertStringContainsString('convertShippingMinor', $src);
}

private function assert_RateTemplateBaseCurrency_testModuleRequiresCurrency(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php';
    self::assertIsArray($module);
    self::assertArrayHasKey('Weline_Currency', $module['requires'] ?? []);
}

private function assert_RateTemplateDelete_testSeedCodeDetection(): void
{
    self::assertTrue(RateTemplate::isSeedTemplateCode('SEED_TPL_DOMESTIC'));
    self::assertTrue(RateTemplate::isSeedTemplateCode('seed_tpl_europe'));
    self::assertFalse(RateTemplate::isSeedTemplateCode('TEST'));
    self::assertFalse(RateTemplate::isSeedTemplateCode('r43store_c1d7adba892e'));
}

private function assert_RateTemplateDelete_testAdminServiceExposesDeleteRateTemplate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigurationAdminService.php');
    self::assertStringContainsString('function deleteRateTemplate', $src);
    self::assertStringContainsString('系统种子不可删除', $src);
    self::assertStringContainsString('RATE_TEMPLATE_ID', $src);
    self::assertStringContainsString('仍有配送服务引用该费用模板', $src);
}

private function assert_RateTemplateDelete_testControllerExposesRemoveAction(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/RateTemplate.php');
    self::assertStringContainsString('function remove', $src);
    self::assertStringContainsString('rate_template_remove', $src);
    self::assertStringContainsString('deleteRateTemplate', $src);
}

private function assert_RateTemplateSeedCompleteness_testExpectedNineSeedTemplateCodes(): void
{
    $codes = DefaultShippingLaneSeedService::expectedSeedTemplateCodes();
    self::assertCount(11, $codes);
    foreach (['SEED_TPL_DOMESTIC', 'SEED_TPL_GREATER_CHINA', 'SEED_TPL_ASIA_PACIFIC', 'SEED_TPL_AMERICAS', 'SEED_TPL_EUROPE', 'SEED_TPL_OCEANIA', 'SEED_TPL_LATAM', 'SEED_TPL_MIDDLE_EAST_AFRICA', 'SEED_TPL_OTHER', 'SEED_TPL_HEAVY_DOMESTIC', 'SEED_TPL_HEAVY_INTERNATIONAL'] as $code) {
        self::assertContains($code, $codes);
    }
}

private function assert_RateTemplateSeedCompleteness_testSeedServiceAlwaysUpsertsTemplatesAndWritesRates(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('expectedSeedTemplateCodes', $src);
    self::assertStringContainsString('CALC_TYPE_WEIGHT_TABLE', $src);
    self::assertStringContainsString('SEED_GENERAL', $src);
    self::assertStringContainsString('SEED_HEAVY', $src);
    self::assertStringContainsString('ensureProfile', $src);
    self::assertStringContainsString('九档模板始终落库', $src);
}

private function assert_RateTemplateSeedCompleteness_testListUiHumanizesCalcTypeAndSeedBadge(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/RateTemplate/index.phtml');
    self::assertStringContainsString('shipping-rate-template-origin-seed', $tpl);
    self::assertStringContainsString('按重量阶梯', $tpl);
    self::assertStringContainsString('固定费用', $tpl);
    self::assertStringContainsString('rate_brackets', $tpl);
    self::assertStringContainsString('max_weight_kg', $tpl);
}

private function assert_RegionGlobalCatalog_testRegionServiceSupportsGlobalCountryCatalog(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/RegionService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("string \$countryCatalog = 'installed'", $src);
    self::assertStringContainsString('getGlobalCountriesAsRegions', $src);
    self::assertStringContainsString('applyGlobalCountryCatalog', $src);
}

private function assert_RegionGlobalCatalog_testRegionQueryProviderExposesCatalogParam(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Framework/Query/RegionQueryProvider.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("'catalog'", $src);
    self::assertStringContainsString("'global'", $src);
}

private function assert_RegionGlobalCatalog_testFrontendRegionControllerAcceptsCatalogParam(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Frontend/Region.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("getParam('catalog'", $src);
    self::assertStringContainsString("'global'", $src);
}

private function assert_RegionGlobalCatalog_testAddressJsUsesHttpFetchForGlobalCatalog(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/js/address.js';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('fetchRegionsFromSource', $src);
    self::assertStringContainsString("catalog === 'global'", $src);
    self::assertStringContainsString('catalog=global', $src);
}

private function assert_RegionListLocalizationBatch_testRegionListUsesBatchLocalizedNameLookup(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/RegionService.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('namesByRegionIds($regionIds', $source);
}

private function assert_ScopedShippingQuoteEmbargoGate_testQuoteAndListOptionsAssertDestinationAllowed(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ScopedShippingQuoteService.php');
    self::assertStringContainsString('public const ERROR_EMBARGO = \'shipping_destination_embargoed\';', $src);
    self::assertStringContainsString('private function assertDestinationAllowed(ShippingQuoteRequest $request): void', $src);
    self::assertStringContainsString('EmbargoService::class', $src);
    self::assertStringContainsString('assertAllowed($request->address', $src);
    self::assertMatchesRegularExpression('/if \(\$this->shippableCount\(\$request\) === 0\) \{\s*return \[\];\s*\}\s*\$this->assertDestinationAllowed\(\$request\);/s', $src);
    self::assertMatchesRegularExpression('/freeReason:\s*\'virtual_only\'[\s\S]*?\$this->assertDestinationAllowed\(\$request\);/', $src);
    self::assertStringContainsString('if ($this->useMemory || ShippingQuoteHarnessCatalog::load() !== null)', $src);
    self::assertStringContainsString("(string)__((string)(\$rate['label'] ?? \$code))", $src);
}

private function assert_SeedLaneOriginWarehouse_testSeedLanesBindCurrentWarehouseShippingAddress(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('ORIGIN_SHIPPING_ADDRESS_ID', $src);
    self::assertStringContainsString('resolveCurrentWarehouseOriginId', $src);
    self::assertStringContainsString('WarehouseShippingOriginInterface', $src);
    self::assertStringContainsString('ensureSeedShippingAddressFromWarehouse', $src);
    self::assertStringContainsString('resolveCurrentWarehouseContext', $src);
    // 禁止继续以 0/空 origin 通配异地发货
    self::assertStringNotContainsString("schema_fields_ORIGIN_SHIPPING_ADDRESS_ID => 0", $src);
    self::assertTrue(class_exists(DefaultShippingLaneSeedService::class));
}

private function assert_ServiceLaneRelativeQuote_testShippingServiceDeclaresOriginField(): void
{
    self::assertSame('origin_shipping_address_id', ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Model/ShippingService.php');
    self::assertStringContainsString('ORIGIN_SHIPPING_ADDRESS_ID', $src);
}

private function assert_ServiceLaneRelativeQuote_testServiceRegionModelAndUpgradeRegistered(): void
{
    self::assertSame('w_shipping_service_regions', ServiceRegion::schema_table);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('ServiceRegion::class', $upgrade);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Model/ServiceRegion.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ServiceLaneMatchService.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ServiceLaneAdminService.php');
}

private function assert_ServiceLaneRelativeQuote_testQuotePathUsesLaneFilter(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('laneMatch()', $src);
    self::assertStringContainsString('filterByOriginAndDest', $src);
    self::assertStringContainsString('destMatchesFreeShippingRegions', $src);
    self::assertStringContainsString('CONDITION_REGION', $src);
    self::assertStringContainsString('CONDITION_MIXED', $src);
    self::assertStringContainsString('service_lanes', $src);
}

private function assert_ServiceLaneRelativeQuote_testAdminCreateShippingServicePersistsLaneFields(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigurationAdminService.php');
    self::assertStringContainsString('ORIGIN_SHIPPING_ADDRESS_ID', $src);
    self::assertStringContainsString('lane_dest_selection', $src);
    self::assertStringContainsString('weight_rate', $src);
    self::assertStringContainsString('setRegionIds', $src);
}

private function assert_ServiceLaneRelativeQuote_testBackendLaneUiContracts(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/ShippingService/index.phtml');
    self::assertStringContainsString('origin_shipping_address_id', $tpl);
    self::assertStringContainsString('lane_dest_selection', $tpl);
    self::assertStringContainsString('theme:address', $tpl);
    self::assertStringContainsString('shipping-service-lane-table', $tpl);
    $free = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/FreeShippingRule/index.phtml');
    self::assertStringContainsString('value="region"', $free);
    self::assertStringContainsString('value="mixed"', $free);
    self::assertStringContainsString('free_region_selection', $free);
    $rate = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/RateTemplate/index.phtml');
    self::assertStringContainsString('weight_rate', $rate);
    self::assertStringContainsString('volume_rate', $rate);
    self::assertStringContainsString('quantity_rate', $rate);
    self::assertStringContainsString('勿在模板里配地区矩阵', $rate);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/prototypes/lane-relative-quote-ui.html');
}

private function assert_ServiceLaneRelativeQuote_testLaneMatchSpecificityOrderingLogicPresent(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ServiceLaneMatchService.php');
    self::assertStringContainsString('SPEC_PROVINCE', $src);
    self::assertStringContainsString('SPEC_COUNTRY', $src);
    self::assertStringContainsString('resolveDefaultOriginId', $src);
    self::assertTrue(class_exists(ServiceLaneMatchService::class));
    self::assertTrue(class_exists(ShippingServiceManager::class));
}

private function assert_ShippingProcessCache_testHotPathServicesOwnProcessBags(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/';
    foreach (['EmbargoService.php' => '$processRulesByScope', 'DestinationService.php' => '$processRulesByScope', 'CarrierCoverageMatchService.php' => '$processActiveCarriers', 'WarehouseShippingOriginService.php' => '$processOriginByKey', 'RegionLocalNameResolver.php' => '$processRegionCache'] as $file => $needle) {
        $src = (string) file_get_contents($base . $file);
        self::assertStringContainsString($needle, $src, $file);
        self::assertStringContainsString('function clearProcessCache', $src, $file);
    }
}

private function assert_ShippingProcessCache_testProcessCacheResetterIsRegistered(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/module.php';
    self::assertSame(\Weline\Shipping\Api\Runtime\ProcessCacheResetter::class, $module['provides']['process_cache_resetter.Weline_Shipping'] ?? null);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('EmbargoService::clearProcessCache()', $src);
    self::assertStringContainsString('RegionLocalNameResolver::clearProcessCache()', $src);
    $embargo = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/EmbargoService.php');
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $embargo);
    self::assertStringContainsString('ScopeIdentity::fromLayerIds', $embargo);
    self::assertStringContainsString("'shipping.embargo'", $embargo);
}

private function assert_ShippingScopeAndSeed_testModelsDeclareScopeFields(): void
{
    self::assertSame('scope_type', ShippingService::schema_fields_SCOPE_TYPE);
    self::assertSame('scope_id', ShippingService::schema_fields_SCOPE_ID);
    self::assertSame('scope_type', RateTemplate::schema_fields_SCOPE_TYPE);
    self::assertSame('scope_id', FreeShippingRule::schema_fields_SCOPE_ID);
}

private function assert_ShippingScopeAndSeed_testAdminAndQuoteUseScopeService(): void
{
    $coverage = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/CarrierCoverageMatchService.php');
    self::assertStringContainsString('ShippingConfigScopeService', $coverage);
    self::assertStringContainsString('resolveNearestServiceLayer', $coverage);
    $admin = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigurationAdminService.php');
    self::assertStringContainsString('normalizeScopePayload', $admin);
    self::assertStringContainsString('assertSameScope', $admin);
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/ShippingService.php');
    self::assertStringContainsString('ShippingBackendScopeTrait', $ctrl);
    self::assertStringContainsString('assignShippingWorkScope', $ctrl);
}

private function assert_ShippingScopeAndSeed_testScopeToolbarPresentOnConfigPages(): void
{
    foreach (['ShippingService', 'RateTemplate', 'FreeShippingRule'] as $page) {
        $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/' . $page . '/index.phtml');
        self::assertStringContainsString('scope-toolbar.phtml', $tpl);
        self::assertStringContainsString('name="scope_type"', $tpl);
        self::assertStringContainsString('name="target_scope"', $tpl);
    }
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/partials/scope-toolbar.phtml');
    $toolbar = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/partials/scope-toolbar.phtml');
    self::assertStringContainsString('shipping-work-scope.js', $toolbar);
    self::assertStringContainsString('shipping-work-scope-i18n', $toolbar);
    self::assertStringNotContainsString('function navigateFromNode', $toolbar);
    self::assertStringNotContainsString('function decodeSegment', $toolbar);
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/statics/js/backend/shipping-work-scope.js';
    self::assertFileExists($js);
    $jsBody = (string) file_get_contents($js);
    self::assertStringContainsString('navigateFromNode', $jsBody);
    self::assertStringContainsString('decodeSegment', $jsBody);
    self::assertStringContainsString("getElementById('shipping-work-scope')", $jsBody);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigScopeService.php');
}

private function assert_ShippingScopeAndSeed_testDefaultMarketsSeedAssets(): void
{
    $tsv = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/data/default-markets/countries.tsv';
    self::assertFileExists($tsv);
    $body = (string) file_get_contents($tsv);
    self::assertStringContainsString("CN\tdomestic", $body);
    self::assertStringContainsString("US\tamericas", $body);
    self::assertStringContainsString("DE\teurope", $body);
    self::assertStringContainsString('sort_order', $body);
    self::assertStringNotContainsString("\nKP\t", $body);
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultCarrierCoverageProvider.php');
    self::assertStringContainsString('default-markets/countries.tsv', $provider);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Setup/Upgrade.php');
    self::assertStringContainsString('seedDefaultLanes', $upgrade);
    self::assertStringContainsString('DefaultShippingLaneSeedService', $upgrade);
    self::assertStringContainsString('migrateConfigScopeColumns', $upgrade);
    self::assertTrue(class_exists(DefaultShippingLaneSeedService::class));
    self::assertTrue(class_exists(DefaultCarrierCoverageProvider::class));
    self::assertTrue(class_exists(ShippingConfigScopeService::class));
}

private function assert_ShippingScopeAndSeed_testQuoteLayerChainOrder(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigScopeService.php');
    self::assertStringContainsString('function quoteLayerChain', $src);
    $fn = strstr($src, 'function quoteLayerChain');
    self::assertNotFalse($fn);
    $channelPos = strpos((string) $fn, 'SCOPE_CHANNEL');
    $storePos = strpos((string) $fn, 'SCOPE_STORE');
    $websitePos = strpos((string) $fn, 'SCOPE_WEBSITE');
    self::assertNotFalse($channelPos);
    self::assertNotFalse($storePos);
    self::assertNotFalse($websitePos);
    self::assertLessThan((int) $storePos, (int) $channelPos);
    self::assertLessThan((int) $websitePos, (int) $storePos);
}

private function assert_ShippingScopeAndSeed_testNearestServiceLayerFallsBackToWebsiteZeroSeed(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingConfigScopeService.php');
    self::assertStringContainsString('website:0 种子层', $src);
    $fn = strstr($src, 'function resolveNearestServiceLayer');
    self::assertNotFalse($fn);
    self::assertStringContainsString("'scope_id' => 0", (string) $fn);
    self::assertStringContainsString('$chain[] = $seed', (string) $fn);
    $profile = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingProfileResolver.php');
    self::assertStringContainsString('profileScopeCandidates', $profile);
    self::assertStringContainsString('SCOPE_WEBSITE, 0', $profile);
}

private function moduleRoot_ShippingShellProvider(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Shipping';
}

private function assert_ShippingShellProvider_testShellDocsAndInterfaceExist(): void
{
    $root = $this->moduleRoot_ShippingShellProvider();
    self::assertFileExists($root . '/doc/shipping-shell.md');
    self::assertFileExists($root . '/doc/provider-development.md');
    self::assertFileExists($root . '/Interface/ShippingProviderInterface.php');
    self::assertFileExists($root . '/Service/Provider/AbstractShippingProvider.php');
    self::assertFileExists($root . '/Service/ShippingProviderScanner.php');
    self::assertFileExists($root . '/Service/ShippingProviderManager.php');
    self::assertFileExists($root . '/Service/ShippingFacade.php');
}

private function assert_ShippingShellProvider_testRequirementMentionsShell(): void
{
    $req = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/doc/需求.md');
    self::assertStringContainsString('SHIP-SHELL-001', $req);
    self::assertStringContainsString('AbstractShippingProvider', $req);
    self::assertMatchesRegularExpression('/~~不做承运商 API 实时询价~~|已由 SHIP-SHELL-001/', $req);
}

private function assert_ShippingShellProvider_testInterfaceDeclaresFullCapabilitySurface(): void
{
    $src = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/Interface/ShippingProviderInterface.php');
    foreach (['getCode', 'getProviderCode', 'getProviderApiVersion', 'getCapabilities', 'getDisplayMetadata', 'getConfigSchema', 'getDynamicFormSchema', 'cspDirectives', 'checkAvailability', 'defaultCoverageRegions', 'quote', 'createShipment', 'cancelShipment', 'confirmShipment', 'getLabel', 'queryTracking', 'verifyCallback', 'parseCallback', 'testConnection', 'normalizeError'] as $method) {
        self::assertMatchesRegularExpression('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src);
    }
}

private function assert_ShippingShellProvider_testAbstractImplementsInterface(): void
{
    $src = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/Service/Provider/AbstractShippingProvider.php');
    self::assertStringContainsString('implements ShippingProviderInterface', $src);
    self::assertStringContainsString('ShippingQuoteResult::unsupported', $src);
}

private function assert_ShippingShellProvider_testBuiltinProvidersExtendAbstract(): void
{
    $local = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/extends/module/Weline_Shipping/ShippingProvider/LocalRateTemplateProvider.php');
    $yanwen = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/extends/module/Weline_Shipping/ShippingProvider/YanwenProvider.php');
    self::assertStringContainsString('extends AbstractShippingProvider', $local);
    self::assertStringContainsString("return 'local'", $local);
    self::assertStringContainsString('extends AbstractShippingProvider', $yanwen);
    self::assertStringContainsString("return 'yanwen'", $yanwen);
    self::assertFileExists($this->moduleRoot_ShippingShellProvider() . '/extends/module/Weline_SystemConfig/Config/backend/yanwen.phtml');
}

private function assert_ShippingShellProvider_testQuoteRatesDelegatesToProviderWithoutVendorBranch(): void
{
    $src = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('ShippingProviderManager', $src);
    self::assertStringContainsString('->quote(', $src);
    self::assertStringContainsString('checkAvailability', $src);
    self::assertDoesNotMatchRegularExpression('/if\s*\([^)]*[\'"]yanwen[\'"]/', $src);
    self::assertDoesNotMatchRegularExpression('/if\s*\([^)]*[\'"]local[\'"].*quote/i', $src);
}

private function assert_ShippingShellProvider_testShellPhpHasNoVendorLiterals(): void
{
    $root = $this->moduleRoot_ShippingShellProvider();
    $paths = [$root . '/Service/ShippingServiceManager.php', $root . '/Service/ShippingFacade.php', $root . '/Service/ShippingProviderManager.php', $root . '/Service/TrackingService.php', $root . '/Service/ShippingProviderScanner.php'];
    foreach ($paths as $path) {
        $src = (string) file_get_contents($path);
        self::assertDoesNotMatchRegularExpression('/yanwen|yw56|Yanwen/i', $src, 'Shell must not hardcode vendor: ' . basename($path));
    }
}

private function assert_ShippingShellProvider_testTrackingRoutesViaFacade(): void
{
    $src = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/Service/TrackingService.php');
    self::assertStringContainsString('ShippingFacade', $src);
    self::assertStringContainsString('queryTracking', $src);
    self::assertStringNotContainsString('北京分拨中心', $src);
}

private function assert_ShippingShellProvider_testCarrierHasProviderCode(): void
{
    $src = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/Model/Carrier.php');
    self::assertStringContainsString('PROVIDER_CODE', $src);
    self::assertStringContainsString('provider_code', $src);
}

private function assert_ShippingShellProvider_testModuleVersion250(): void
{
    $module = (string) file_get_contents($this->moduleRoot_ShippingShellProvider() . '/etc/module.php');
    self::assertStringContainsString("'2.5.2'", $module);
}

private function assert_SystemEmbargoAdminService_testModelDeclaresNaturalUniqueAndSystemScope(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Model/EmbargoRegion.php');
    self::assertStringContainsString('uk_embargo_natural', $src);
    self::assertStringContainsString("SCOPE_SYSTEM = 'system'", $src);
    self::assertStringContainsString('reason_code', $src);
    self::assertStringContainsString('disabled_by', $src);
    self::assertStringContainsString('disabled_at', $src);
}

private function assert_SystemEmbargoAdminService_testReplaceForScopeRejectsSystem(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/EmbargoAdminService.php');
    self::assertStringContainsString('ERROR_REPLACE_FORBIDDEN', $src);
    self::assertStringContainsString('SCOPE_SYSTEM', $src);
    self::assertSame('system_embargo_replace_forbidden', SystemEmbargoAdminService::ERROR_REPLACE_FORBIDDEN);
    self::assertSame('system_embargo_delete_forbidden', SystemEmbargoAdminService::ERROR_DELETE_FORBIDDEN);
}

private function assert_SystemEmbargoAdminService_testAdminServiceHasNoDeleteApi(): void
{
    $ref = new \ReflectionClass(SystemEmbargoAdminService::class);
    self::assertTrue($ref->hasMethod('listAll'));
    self::assertTrue($ref->hasMethod('addCountry'));
    self::assertTrue($ref->hasMethod('addRegion'));
    self::assertTrue($ref->hasMethod('delete'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/SystemEmbargoAdminService.php');
    self::assertStringContainsString("'already_active'", $src);
    self::assertStringContainsString("'reactivated'", $src);
    self::assertStringContainsString("'created'", $src);
    $ctl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/SystemEmbargo.php');
    self::assertStringContainsString('无需重复新增', $ctl);
    self::assertStringContainsString('function remove', $ctl);
    self::assertStringNotContainsString('public function delete()', $ctl);
    self::assertStringNotContainsString('function postDelete', $ctl);
    self::assertTrue($ref->hasMethod('deactivate'));
    self::assertTrue($ref->hasMethod('activate'));
    self::assertTrue($ref->hasMethod('seedFromRows'));
    self::assertTrue($ref->hasMethod('purgeNonCanonicalSeeds'));
    self::assertTrue($ref->hasMethod('loadCanonicalSeedCountries'));
    self::assertFalse($ref->hasMethod('replaceForScope'));
    self::assertStringContainsString('shipping.system_embargo.deactivate', $src);
    self::assertStringContainsString('shipping.system_embargo.activate', $src);
    self::assertStringContainsString('reason_label', $src);
    self::assertStringContainsString('country_name', $src);
    self::assertStringContainsString('EmbargoReasonAdminService', $src);
    self::assertStringContainsString('purge_non_canonical_seed', $src);
    $publisher = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/SystemEmbargoResourceChangePublisher.php');
    self::assertStringContainsString("RESOURCE_TYPE = 'shipping_embargo'", $publisher);
    self::assertStringContainsString('w_changed($change)', $publisher);
    self::assertStringContainsString("global('shipping', ['embargo'])", $publisher);
}

private function assert_SystemEmbargoAdminService_testSeedFileHasNoDomesticSurchargeProvinces(): void
{
    $tsv = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/data/system-embargo/countries.tsv');
    self::assertStringContainsString("AQ\t", $tsv);
    self::assertStringContainsString("KP\t", $tsv);
    self::assertStringNotContainsString("CN\t", $tsv);
    self::assertDoesNotMatchRegularExpression('/^\s*CN\b/m', $tsv);
    self::assertStringNotContainsString('新疆', $tsv);
    self::assertStringNotContainsString('西藏', $tsv);
    self::assertStringNotContainsString('Xinjiang', $tsv);
    self::assertDoesNotMatchRegularExpression('/\bCN-XJ\b|\bCN-XZ\b/i', $tsv);
}

private function assert_SystemEmbargoAdminService_testManagerTabsIncludeSystemEmbargo(): void
{
    $manager = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/Manager.php');
    self::assertStringContainsString("'systemembargo'", $manager);
    self::assertStringContainsString('shipping/backend/systemembargo', $manager);
    self::assertStringContainsString('return $this->redirect', $manager);
    self::assertStringNotContainsString('<iframe', $manager);
    $tabs = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/partials/manager-tabs.phtml');
    self::assertStringContainsString('systemembargo', $tabs);
    self::assertStringContainsString('shipping/backend/systemembargo', $tabs);
    self::assertStringContainsString('w-tabs__tab', $tabs);
    self::assertStringNotContainsString('<iframe', $tabs);
    $managerTpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/Manager/index.phtml');
    self::assertStringNotContainsString('<iframe', $managerTpl);
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/SystemEmbargo.php');
    self::assertStringContainsString('ShippingBackendEmbedTrait', $controller);
    self::assertStringContainsString('getMessageManager()', $controller);
    self::assertStringContainsString('addSuccess', $controller);
    self::assertStringContainsString('addError', $controller);
    self::assertDoesNotMatchRegularExpression('/\$this->getMessage\s*\(/', $controller);
    self::assertStringNotContainsString('backendUrl(', $controller);
    $menu = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/etc/backend/menu.xml');
    self::assertStringContainsString('Weline_Shipping::system_embargo', $menu);
    self::assertStringContainsString('shipping/backend/systemembargo/index', $menu);
    self::assertStringContainsString('title="系统禁运"', $menu);
    $embargoTpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/SystemEmbargo/index.phtml');
    self::assertStringContainsString('manager-tabs.phtml', $embargoTpl);
    self::assertStringContainsString("'systemembargo'", $embargoTpl);
}

private function assert_SystemEmbargoAdminService_testDeactivateConfirmMentionsSiteWide(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/view/templates/backend/SystemEmbargo/index.phtml');
    self::assertStringContainsString('全站生效', $tpl);
    self::assertStringContainsString('系统种子不可删除', $tpl);
    self::assertStringContainsString('system-embargo-delete', $tpl);
    self::assertStringContainsString('system-embargo-origin-seed', $tpl);
    self::assertStringContainsString('shipping/backend/systemembargo/remove', $tpl);
    self::assertStringNotContainsString('shipping/backend/systemembargo/delete', $tpl);
    self::assertStringNotContainsString('>不允许删除条目', $tpl);
    self::assertStringContainsString('<w:theme:address', $tpl);
    self::assertStringContainsString('levels="country|province"', $tpl);
    self::assertStringContainsString('cascade="true"', $tpl);
    self::assertStringContainsString('district="false"', $tpl);
    self::assertStringContainsString('system-embargo-country', $tpl);
    self::assertStringContainsString('province_region_id', $tpl);
    self::assertStringContainsString('新增禁运', $tpl);
    self::assertStringNotContainsString('system-embargo-cc', $tpl);
    self::assertStringNotContainsString('ISO 国家码', $tpl);
    self::assertStringContainsString('system-embargo-add-dialog', $tpl);
    self::assertStringContainsString('data-w-action="dialog.open"', $tpl);
    self::assertStringContainsString('system-embargo-open-add', $tpl);
    self::assertStringContainsString('w-dialog', $tpl);
    self::assertStringContainsString('system-embargo-region-label', $tpl);
    self::assertStringContainsString('system-embargo-reason-label', $tpl);
    self::assertStringContainsString('reason_label', $tpl);
    self::assertStringContainsString('system-embargo-reason-card', $tpl);
    self::assertStringContainsString('EmbargoReason\LocalDescription', $tpl);
    self::assertStringContainsString('reason_options', $tpl);
    self::assertStringContainsString('systemembargo/reasonSave', $tpl);
}

private function assert_SystemEmbargoAdminService_testEmbargoServiceMessageForSystem(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/EmbargoService.php');
    self::assertStringContainsString('SCOPE_SYSTEM', $src);
    self::assertStringContainsString("'系统'", $src);
}

private function assert_SystemEmbargoAdminService_testConfigVersionIncludesReachability(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/ShippingServiceManager.php');
    self::assertStringContainsString('reachabilityConfigFacts', $src);
    self::assertStringContainsString('preferredShippingProfileCodes', $src);
    self::assertStringContainsString("'reachability'", $src);
}

private function assert_SystemEmbargoAdminService_testEmbargoAdminServiceClassExists(): void
{
    self::assertTrue(class_exists(EmbargoAdminService::class));
    self::assertTrue(class_exists(EmbargoRegion::class));
}

private function assert_WorldMarketSeed_testDefaultMarketsTsvCoversUsAndWorldScale(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/data/default-markets/countries.tsv';
    self::assertFileExists($path);
    $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    self::assertIsArray($raw);
    $rows = [];
    $usLane = null;
    foreach ($raw as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'country_code')) {
            continue;
        }
        $parts = preg_split("/\t|,\\s*/", $line) ?: [];
        $cc = strtoupper(trim((string) ($parts[0] ?? '')));
        $lane = strtolower(trim((string) ($parts[1] ?? '')));
        if ($cc === '') {
            continue;
        }
        $rows[$cc] = $lane;
        if ($cc === 'US') {
            $usLane = $lane;
        }
    }
    self::assertSame('americas', $usLane);
    self::assertGreaterThanOrEqual(200, count($rows));
    self::assertArrayNotHasKey('KP', $rows);
    self::assertArrayNotHasKey('AQ', $rows);
}

private function assert_WorldMarketSeed_testSeedMergesDestinationAndCarrierCoverageWhenExpanding(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/DefaultShippingLaneSeedService.php');
    self::assertStringContainsString('marketCountryRows', $src);
    self::assertStringContainsString('仅并入种子市场缺失国', $src);
    self::assertStringContainsString('中国大陆仓发货', $src);
    self::assertStringContainsString("'fee' => 45.00", $src);
    self::assertStringContainsString("'weight_rate' => 14.00", $src);
}

private function assert_YanwenDestinationCoverage_testCoverageIsCountryLevelOnly(): void
{
    $rows = YanwenDestinationCoverage::defaultCoverageRegions();
    self::assertNotEmpty($rows);
    foreach ($rows as $row) {
        self::assertSame(CarrierRegion::TYPE_COUNTRY, $row['region_type']);
        self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $row['country_code']);
        self::assertNull($row['region_id']);
        self::assertNull($row['street_id']);
    }
}

private function assert_YanwenDestinationCoverage_testIncludesUsExcludesCnAndProvinces(): void
{
    $codes = YanwenDestinationCoverage::countryCodes();
    self::assertContains('US', $codes);
    self::assertContains('GB', $codes);
    self::assertContains('HK', $codes);
    self::assertNotContains('CN', $codes);
    self::assertTrue(YanwenDestinationCoverage::coversCountry('us'));
    self::assertFalse(YanwenDestinationCoverage::coversCountry('CN'));
}

private function assert_YanwenDestinationCoverage_testProviderExposesSameCatalog(): void
{
    $providerSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Shipping/ShippingProvider/YanwenProvider.php');
    self::assertStringContainsString('defaultCoverageRegions', $providerSrc);
    self::assertStringContainsString('YanwenDestinationCoverage', $providerSrc);
    self::assertStringContainsString('destination_not_covered', $providerSrc);
}

private function assert_YanwenDestinationCoverage_testRegistryPrefersProviderCode(): void
{
    $registrySrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Service/CarrierCoverageProviderRegistry.php');
    self::assertStringContainsString('defaultCoverageForCode', $registrySrc);
    self::assertStringContainsString('defaultCoverageRegions', $registrySrc);
}
}
