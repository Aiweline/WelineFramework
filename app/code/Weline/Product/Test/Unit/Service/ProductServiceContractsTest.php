<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Context;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Model\ProductQuoteRequest;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\HomepageShelfOffersLocaleCacheWarmer;
use Weline\Product\Service\HomepageShelfStagger;
use Weline\Product\Service\PlpFilterOffersLightCacheWarmer;
use Weline\Product\Service\ProductAdminBulkService;
use Weline\Product\Service\ProductAttributeMetadataCatalog;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Product\Service\ProductCatalogEavBootstrap;
use Weline\Product\Service\ProductQuoteMailReplySlotService;
use Weline\Product\Service\ProductQuoteRequestStateMachine;
use Weline\Product\Service\ProductSearchProjectionMutationCoordinator;
use Weline\Product\Service\ProductShardSchemaCatalog;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\SearchProjectionScopeCacheWarmer;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontCatalogShellUrlBuilder;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontHotCacheBagSeeder;
use Weline\Product\Service\StorefrontListingPager;
use Weline\Product\Service\StorefrontPdpShelfDeferral;
use Weline\Product\Service\StorefrontProductDetailProjector;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

/**
 * Aggregated contract suite (merged from 37 *ContractTest files).
 * Observe one overall pass/fail via testAllProductServiceContractsPass().
 */
final class ProductServiceContractsTest extends TestCase
{
    public function testAllProductServiceContractsPass(): void
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
            'AttributeValueShardLookupIndex::testSchemaVersionBumpedForLookupIndexes' => function () {
            $this->assert_AttributeValueShardLookupIndex_testSchemaVersionBumpedForLookupIndexes();
        },
            'AttributeValueShardLookupIndex::testEntityLookupIndexLeadsWithEntityColumns' => function () {
            $this->assert_AttributeValueShardLookupIndex_testEntityLookupIndexLeadsWithEntityColumns();
        },
            'AttributeValueShardLookupIndex::testAttributeReverseLookupIndexExcludesUnboundedText' => function () {
            $this->assert_AttributeValueShardLookupIndex_testAttributeReverseLookupIndexExcludesUnboundedText();
        },
            'AttributeValueShardLookupIndex::testNoIndexCoversUnboundedTextColumns' => function () {
            $this->assert_AttributeValueShardLookupIndex_testNoIndexCoversUnboundedTextColumns();
        },
            'AttributeValueShardLookupIndex::testUniqueKeyUnchanged' => function () {
            $this->assert_AttributeValueShardLookupIndex_testUniqueKeyUnchanged();
        },
            'CategoryMenuPrefetch::testNavTreePrefetchesCategoryMenuPolicyKey' => function () {
            $this->assert_CategoryMenuPrefetch_testNavTreePrefetchesCategoryMenuPolicyKey();
        },
            'FilterPanelProcessL1Policy::testFilterPanelAndFacetCountsSkipProcessL1' => function () {
            $this->assert_FilterPanelProcessL1Policy_testFilterPanelAndFacetCountsSkipProcessL1();
        },
            'HomepageShelfOffersLocaleCacheWarmer::testModuleProvidesHomepageShelfOffersLocaleWarmer' => function () {
            $this->assert_HomepageShelfOffersLocaleCacheWarmer_testModuleProvidesHomepageShelfOffersLocaleWarmer();
        },
            'HomepageShelfOffersLocaleCacheWarmer::testWarmerTargetsProductPoolAfterSearchProjection' => function () {
            $this->assert_HomepageShelfOffersLocaleCacheWarmer_testWarmerTargetsProductPoolAfterSearchProjection();
        },
            'HomepageShelfOffersLocaleCacheWarmer::testClearWarmsProductPoolWithoutProductFqcn' => function () {
            $this->assert_HomepageShelfOffersLocaleCacheWarmer_testClearWarmsProductPoolWithoutProductFqcn();
        },
            'HomepageShelfOffersLocaleCacheWarmer::testWarmerUsesWebsiteEnabledLocalesAndLightSummaryOnly' => function () {
            $this->assert_HomepageShelfOffersLocaleCacheWarmer_testWarmerUsesWebsiteEnabledLocalesAndLightSummaryOnly();
        },
            'HomepageShelfOffersLocaleCacheWarmer::testWarmerDoesNotHardcodeAcceptanceLocaleCatalogAsSoleSource' => function () {
            $this->assert_HomepageShelfOffersLocaleCacheWarmer_testWarmerDoesNotHardcodeAcceptanceLocaleCatalogAsSoleSource();
        },
            'HomepageShelfStagger::testTopFourPairwiseDisjointAndFeaturedDiffersFromHotTopEight' => function () {
            $this->assert_HomepageShelfStagger_testTopFourPairwiseDisjointAndFeaturedDiffersFromHotTopEight();
        },
            'HomepageShelfStagger::testDealsDoNotBorrowFromOtherShelvesWhenPoolIsShort' => function () {
            $this->assert_HomepageShelfStagger_testDealsDoNotBorrowFromOtherShelvesWhenPoolIsShort();
        },
            'HomepageShelfStagger::testLimitedDealFlagQualifiesWithoutHighDiscount' => function () {
            $this->assert_HomepageShelfStagger_testLimitedDealFlagQualifiesWithoutHighDiscount();
        },
            'HomepageShelfStagger::testThinCatalogKeepsFeaturedDisjointFromHot' => function () {
            $this->assert_HomepageShelfStagger_testThinCatalogKeepsFeaturedDisjointFromHot();
        },
            'HomepageShelfStagger::testZeroPriceCardsAreExcludedFromAllShelves' => function () {
            $this->assert_HomepageShelfStagger_testZeroPriceCardsAreExcludedFromAllShelves();
        },
            'ListingSummarySlugUrl::testSnapshotAllowlistIncludesSlugAttributes' => function () {
            $this->assert_ListingSummarySlugUrl_testSnapshotAllowlistIncludesSlugAttributes();
        },
            'ListingSummarySlugUrl::testCatalogRowMapsSnapshotSlugAndBustsSummaryCache' => function () {
            $this->assert_ListingSummarySlugUrl_testCatalogRowMapsSnapshotSlugAndBustsSummaryCache();
        },
            'ListingSummarySlugUrl::testCartItemSnapshotExposesSlugInToArray' => function () {
            $this->assert_ListingSummarySlugUrl_testCartItemSnapshotExposesSlugInToArray();
        },
            'ListingSummarySlugUrl::testCardRendererPrefersSlugPathOverNumericId' => function () {
            $this->assert_ListingSummarySlugUrl_testCardRendererPrefersSlugPathOverNumericId();
        },
            'ListingSummarySlugUrl::testRecentlyViewedCardsPreferSlugPathOverNumericId' => function () {
            $this->assert_ListingSummarySlugUrl_testRecentlyViewedCardsPreferSlugPathOverNumericId();
        },
            'OfferTaxClassBinding::testOfferSchemaIncludesTaxClassCode' => function () {
            $this->assert_OfferTaxClassBinding_testOfferSchemaIncludesTaxClassCode();
        },
            'OfferTaxClassBinding::testAdminAndCartSurfacesWireTaxClass' => function () {
            $this->assert_OfferTaxClassBinding_testAdminAndCartSurfacesWireTaxClass();
        },
            'ProductAdminMutationService::testCreateOfferPersistsSku' => function () {
            $this->assert_ProductAdminMutationService_testCreateOfferPersistsSku();
        },
            'PlpFilterOffersLightCacheWarmer::testModuleProvidesPlpLightWarmer' => function () {
            $this->assert_PlpFilterOffersLightCacheWarmer_testModuleProvidesPlpLightWarmer();
        },
            'PlpFilterOffersLightCacheWarmer::testWarmerTargetsProductPoolAfterShelf' => function () {
            $this->assert_PlpFilterOffersLightCacheWarmer_testWarmerTargetsProductPoolAfterShelf();
        },
            'PlpFilterOffersLightCacheWarmer::testClearDiscoversViaProductPoolWithoutFqcn' => function () {
            $this->assert_PlpFilterOffersLightCacheWarmer_testClearDiscoversViaProductPoolWithoutFqcn();
        },
            'PlpFilterOffersLightCacheWarmer::testWarmerUsesLightPathsOnly' => function () {
            $this->assert_PlpFilterOffersLightCacheWarmer_testWarmerUsesLightPathsOnly();
        },
            'PlpFilterOffersLightCacheWarmer::testWarmerSeedsSoftSameFacetKeyAndShopperTipFirst' => function () {
            $this->assert_PlpFilterOffersLightCacheWarmer_testWarmerSeedsSoftSameFacetKeyAndShopperTipFirst();
        },
            'ProductAdminArchiveTransition::testCommandServiceChainsPublishedArchiveAndNoopsArchived' => function () {
            $this->assert_ProductAdminArchiveTransition_testCommandServiceChainsPublishedArchiveAndNoopsArchived();
        },
            'ProductAdminArchiveTransition::testBulkArchiveJsSurfacesFailedCounts' => function () {
            $this->assert_ProductAdminArchiveTransition_testBulkArchiveJsSurfacesFailedCounts();
        },
            'ProductAdminBulkInvalidUuidPurge::testBulkServicePurgesInvalidUuidViaPhysicalDelete' => function () {
            $this->assert_ProductAdminBulkInvalidUuidPurge_testBulkServicePurgesInvalidUuidViaPhysicalDelete();
        },
            'ProductAdminBulkInvalidUuidPurge::testExecuteSkipsCommandForInvalidUuidAndRequiresProductId' => function () {
            $this->assert_ProductAdminBulkInvalidUuidPurge_testExecuteSkipsCommandForInvalidUuidAndRequiresProductId();
        },
            'ProductAdminBulkInvalidUuidPurge::testExecuteStillDispatchesValidUuidToCommand' => function () {
            $this->assert_ProductAdminBulkInvalidUuidPurge_testExecuteStillDispatchesValidUuidToCommand();
        },
            'ProductAdminReadServiceCategoryCatalog::testTaxonomyCatalogUsesCatalogQueryConsumer' => function () {
            $this->assert_ProductAdminReadServiceCategoryCatalog_testTaxonomyCatalogUsesCatalogQueryConsumer();
        },
            'ProductCardBatchRender::testProjectFromOffersIsPublicBatchEntry' => function () {
            $this->assert_ProductCardBatchRender_testProjectFromOffersIsPublicBatchEntry();
        },
            'ProductCardBatchRender::testBatchShellIncludesCompiledCardWithoutPerCardFetch' => function () {
            $this->assert_ProductCardBatchRender_testBatchShellIncludesCompiledCardWithoutPerCardFetch();
        },
            'ProductCardBatchRender::testVideoCarouselPassesIdsOnlyToProductCardTaglib' => function () {
            $this->assert_ProductCardBatchRender_testVideoCarouselPassesIdsOnlyToProductCardTaglib();
        },
            'ProductCardBatchRender::testFragmentKeyIgnoresCardIndexWhileStampOwnsEagerBudget' => function () {
            $this->assert_ProductCardBatchRender_testFragmentKeyIgnoresCardIndexWhileStampOwnsEagerBudget();
        },
            'ProductCardBatchRender::testListingTemplatesUseBatchProjectionNotPerCardTaglib' => function () {
            $this->assert_ProductCardBatchRender_testListingTemplatesUseBatchProjectionNotPerCardTaglib();
        },
            'ProductCategoryAdminServiceDedupe::testFindSiblingAndDedupeUseEavLocalizedNames' => function () {
            $this->assert_ProductCategoryAdminServiceDedupe_testFindSiblingAndDedupeUseEavLocalizedNames();
        },
            'ProductCategoryAdminServiceEavPath::testSaveDoesNotCallAttributeRepositoryWriteExplicitDirectly' => function () {
            $this->assert_ProductCategoryAdminServiceEavPath_testSaveDoesNotCallAttributeRepositoryWriteExplicitDirectly();
        },
            'ProductCategoryDeleteProducts::testDeletePipelineUsesAnyStoreRecomputeAndTransaction' => function () {
            $this->assert_ProductCategoryDeleteProducts_testDeletePipelineUsesAnyStoreRecomputeAndTransaction();
        },
            'ProductIdentityCutoverRequestMemo::testCurrentUsesRequestContextRemember' => function () {
            $this->assert_ProductIdentityCutoverRequestMemo_testCurrentUsesRequestContextRemember();
        },
            'ProductQuoteMailReplySlot::testSourceConstantAndServiceSurface' => function () {
            $this->assert_ProductQuoteMailReplySlot_testSourceConstantAndServiceSurface();
        },
            'ProductQuoteMailReplySlot::testAdminTemplateHasMailReplySlot' => function () {
            $this->assert_ProductQuoteMailReplySlot_testAdminTemplateHasMailReplySlot();
        },
            'ProductQuoteMailReplySlot::testControllerAttachesMailReplySlots' => function () {
            $this->assert_ProductQuoteMailReplySlot_testControllerAttachesMailReplySlots();
        },
            'ProductQuoteMailReplySlot::testObserverAndEventXmlWireMailMessageSent' => function () {
            $this->assert_ProductQuoteMailReplySlot_testObserverAndEventXmlWireMailMessageSent();
        },
            'ProductQuoteMailReplySlot::testAccountQuotesOpensComposer' => function () {
            $this->assert_ProductQuoteMailReplySlot_testAccountQuotesOpensComposer();
        },
            'ProductQuoteMailReplySlot::testModuleOptionalMailDependency' => function () {
            $this->assert_ProductQuoteMailReplySlot_testModuleOptionalMailDependency();
        },
            'ProductQuoteRequestCaptchaGuardLazy::testGuardRendersLazyHostInsteadOfChallengeHtml' => function () {
            $this->assert_ProductQuoteRequestCaptchaGuardLazy_testGuardRendersLazyHostInsteadOfChallengeHtml();
        },
            'ProductQuoteRequestNative::testModelAndServiceSurfaceExist' => function () {
            $this->assert_ProductQuoteRequestNative_testModelAndServiceSurfaceExist();
        },
            'ProductQuoteRequestNative::testStorefrontQueryExposesSubmitQuoteRequest' => function () {
            $this->assert_ProductQuoteRequestNative_testStorefrontQueryExposesSubmitQuoteRequest();
        },
            'ProductQuoteRequestNative::testAdminMenuAndAclExist' => function () {
            $this->assert_ProductQuoteRequestNative_testAdminMenuAndAclExist();
        },
            'ProductQuoteRequestNative::testPdpQuoteOnlyIsSelectableAndOwnsSubmitModal' => function () {
            $this->assert_ProductQuoteRequestNative_testPdpQuoteOnlyIsSelectableAndOwnsSubmitModal();
        },
            'ProductQuoteRequestNative::testCartAddToCartSkipsQuoteOnly' => function () {
            $this->assert_ProductQuoteRequestNative_testCartAddToCartSkipsQuoteOnly();
        },
            'ProductQuoteRequestNative::testModuleProvidesSubmitInterfaceAndOptionalCaptcha' => function () {
            $this->assert_ProductQuoteRequestNative_testModuleProvidesSubmitInterfaceAndOptionalCaptcha();
        },
            'ProductQuoteRequestStateMachine::testStatusesAndTransitionMap' => function () {
            $this->assert_ProductQuoteRequestStateMachine_testStatusesAndTransitionMap();
        },
            'ProductQuoteRequestStateMachine::testEventsRegisteredInEventPhp' => function () {
            $this->assert_ProductQuoteRequestStateMachine_testEventsRegisteredInEventPhp();
        },
            'ProductQuoteRequestStateMachine::testServiceDelegatesMarkProcessedToStateMachine' => function () {
            $this->assert_ProductQuoteRequestStateMachine_testServiceDelegatesMarkProcessedToStateMachine();
        },
            'ProductQuoteRequestStateMachine::testAdminControllerUsesIndexTemplateAndTransitionAction' => function () {
            $this->assert_ProductQuoteRequestStateMachine_testAdminControllerUsesIndexTemplateAndTransitionAction();
        },
            'ProductQuoteRequestStateMachine::testAvailableTransitionsMap' => function () {
            $this->assert_ProductQuoteRequestStateMachine_testAvailableTransitionsMap();
        },
            'ProductScopedOption::testMetadataCatalogEnsuresScopedOptionsApi' => function () {
            $this->assert_ProductScopedOption_testMetadataCatalogEnsuresScopedOptionsApi();
        },
            'ProductScopedOption::testCommandServiceCreatesConfigurableAfterProductId' => function () {
            $this->assert_ProductScopedOption_testCommandServiceCreatesConfigurableAfterProductId();
        },
            'ProductScopedOption::testSearchProjectionIndexesPrivateOptionLabels' => function () {
            $this->assert_ProductScopedOption_testSearchProjectionIndexesPrivateOptionLabels();
        },
            'ProductScopedOption::testBootstrapImportDoesNotPersistProductOptionsGlobally' => function () {
            $this->assert_ProductScopedOption_testBootstrapImportDoesNotPersistProductOptionsGlobally();
        },
            'ProductScopedOption::testImportAllowsUnresolvedOptionsForPrivateEnsure' => function () {
            $this->assert_ProductScopedOption_testImportAllowsUnresolvedOptionsForPrivateEnsure();
        },
            'ProductScopedOption::testSingletonColorRemediationScriptExists' => function () {
            $this->assert_ProductScopedOption_testSingletonColorRemediationScriptExists();
        },
            'ProductScopedOption::testSpecialAxisPrivatizationScriptExists' => function () {
            $this->assert_ProductScopedOption_testSpecialAxisPrivatizationScriptExists();
        },
            'ProductScopedOption::testMisfiledColorToAxesScriptExists' => function () {
            $this->assert_ProductScopedOption_testMisfiledColorToAxesScriptExists();
        },
            'ProductSearchProjectionTextMap::testCollectLocalizedAttributeTextsUsesTextMapAndBoundedChunk' => function () {
            $this->assert_ProductSearchProjectionTextMap_testCollectLocalizedAttributeTextsUsesTextMapAndBoundedChunk();
        },
            'SearchProjectionScopeCacheWarmer::testModuleProvidesSearchProjectionScopeWarmer' => function () {
            $this->assert_SearchProjectionScopeCacheWarmer_testModuleProvidesSearchProjectionScopeWarmer();
        },
            'SearchProjectionScopeCacheWarmer::testWarmerTargetsProductPool' => function () {
            $this->assert_SearchProjectionScopeCacheWarmer_testWarmerTargetsProductPool();
        },
            'SearchProjectionScopeCacheWarmer::testClearWarmsProductPoolWithoutProductFqcn' => function () {
            $this->assert_SearchProjectionScopeCacheWarmer_testClearWarmsProductPoolWithoutProductFqcn();
        },
            'SearchProjectionScopeCacheWarmer::testBagSeederPrimesSearchProjectionScope' => function () {
            $this->assert_SearchProjectionScopeCacheWarmer_testBagSeederPrimesSearchProjectionScope();
        },
            'SearchProjectionScopeCacheWarmer::testWarmerDoesNotCallSnapshotWebsite' => function () {
            $this->assert_SearchProjectionScopeCacheWarmer_testWarmerDoesNotCallSnapshotWebsite();
        },
            'ShippingProfileBinding::testOfferSchemaIncludesShippingProfileCode' => function () {
            $this->assert_ShippingProfileBinding_testOfferSchemaIncludesShippingProfileCode();
        },
            'ShippingProfileBinding::testAdminAndPdpSurfacesExist' => function () {
            $this->assert_ShippingProfileBinding_testAdminAndPdpSurfacesExist();
        },
            'ShippingProfileBinding::testSpiRegistryIsEmptySafe' => function () {
            $this->assert_ShippingProfileBinding_testSpiRegistryIsEmptySafe();
        },
            'StorefrontCardSurfaceProjection::testOfferRepositoryExposesCardColumnAllowlist' => function () {
            $this->assert_StorefrontCardSurfaceProjection_testOfferRepositoryExposesCardColumnAllowlist();
        },
            'StorefrontCardSurfaceProjection::testSummaryCacheGenerationIsCard3' => function () {
            $this->assert_StorefrontCardSurfaceProjection_testSummaryCacheGenerationIsCard3();
        },
            'StorefrontCardSurfaceProjection::testListingSummaryDoesNotReattachDescriptionMeta' => function () {
            $this->assert_StorefrontCardSurfaceProjection_testListingSummaryDoesNotReattachDescriptionMeta();
        },
            'StorefrontCardSurfaceProjection::testHomepageShelfPlanUsesBoundedPools' => function () {
            $this->assert_StorefrontCardSurfaceProjection_testHomepageShelfPlanUsesBoundedPools();
        },
            'StorefrontCatalogFilteredOffers::testPublishedOffersForProductIdsUsesFilteredBuildPath' => function () {
            $this->assert_StorefrontCatalogFilteredOffers_testPublishedOffersForProductIdsUsesFilteredBuildPath();
        },
            'StorefrontCatalogFilteredOffers::testTargetedProjectionUsesChannelPolicyAndOrderIndependentKey' => function () {
            $this->assert_StorefrontCatalogFilteredOffers_testTargetedProjectionUsesChannelPolicyAndOrderIndependentKey();
        },
            'StorefrontCatalogFilteredOffers::testBoundedSummaryProjectionUsesDedicatedPolicyAndBuildLimit' => function () {
            $this->assert_StorefrontCatalogFilteredOffers_testBoundedSummaryProjectionUsesDedicatedPolicyAndBuildLimit();
        },
            'StorefrontCatalogProductVisibility::testProductIdGateUsesCatalogPublishedOffers' => function () {
            $this->assert_StorefrontCatalogProductVisibility_testProductIdGateUsesCatalogPublishedOffers();
        },
            'StorefrontCatalogShellUrlBuilder::testFixedShellsExcludeSearchAndIncludeListingRails' => function () {
            $this->assert_StorefrontCatalogShellUrlBuilder_testFixedShellsExcludeSearchAndIncludeListingRails();
        },
            'StorefrontCatalogShellUrlBuilder::testCoordinatorUsesShellBuilderForListingImpact' => function () {
            $this->assert_StorefrontCatalogShellUrlBuilder_testCoordinatorUsesShellBuilderForListingImpact();
        },
            'StorefrontCatalogShellUrlBuilder::testInvalidatorNeverCallsPurgeAll' => function () {
            $this->assert_StorefrontCatalogShellUrlBuilder_testInvalidatorNeverCallsPurgeAll();
        },
            'StorefrontCatalogShellUrlBuilder::testSitemapDelegatesGlobalListingToShellBuilder' => function () {
            $this->assert_StorefrontCatalogShellUrlBuilder_testSitemapDelegatesGlobalListingToShellBuilder();
        },
            'StorefrontCatalogSummaryBagReuse::testPublishedOfferSummariesStoresAndReusesLargerSameRequestBag' => function () {
            $this->assert_StorefrontCatalogSummaryBagReuse_testPublishedOfferSummariesStoresAndReusesLargerSameRequestBag();
        },
            'StorefrontCatalogSummaryBagReuse::testCanonicalSummaryLogicalKeyDropsLimitForShelfWindows' => function () {
            $this->assert_StorefrontCatalogSummaryBagReuse_testCanonicalSummaryLogicalKeyDropsLimitForShelfWindows();
        },
            'StorefrontCatalogSummaryBagReuse::testHomepageShelfPlanPrefetchesCanonicalSummaryBeforePools' => function () {
            $this->assert_StorefrontCatalogSummaryBagReuse_testHomepageShelfPlanPrefetchesCanonicalSummaryBeforePools();
        },
            'StorefrontCatalogSummaryBagReuse::testSummarySkeletonIsWebsiteScopedSharedAcrossCurrencies' => function () {
            $this->assert_StorefrontCatalogSummaryBagReuse_testSummarySkeletonIsWebsiteScopedSharedAcrossCurrencies();
        },
            'StorefrontCatalogSummaryBagReuse::testSummaryLangSurfaceSharedAcrossCurrenciesForSameLang' => function () {
            $this->assert_StorefrontCatalogSummaryBagReuse_testSummaryLangSurfaceSharedAcrossCurrenciesForSameLang();
        },
            'StorefrontCatalogVisibilityFilter::testViewServiceRoutesAllPublicExitsThroughUnifiedPostCacheFilters' => function () {
            $this->assert_StorefrontCatalogVisibilityFilter_testViewServiceRoutesAllPublicExitsThroughUnifiedPostCacheFilters();
        },
            'StorefrontCategoryTreeIndexLocalizedName::testForWebsiteAppliesEavLocalizedNamesAfterCacheLoad' => function () {
            $this->assert_StorefrontCategoryTreeIndexLocalizedName_testForWebsiteAppliesEavLocalizedNamesAfterCacheLoad();
        },
            'StorefrontCategoryViewService::testCategoryPageCollectsLinksFromCategoryDescendantsInOneBatch' => function () {
            $this->assert_StorefrontCategoryViewService_testCategoryPageCollectsLinksFromCategoryDescendantsInOneBatch();
        },
            'StorefrontGroceryCatalogSeed::testProductModuleNoLongerHostsGrocerySeedOpeners' => function () {
            $this->assert_StorefrontGroceryCatalogSeed_testProductModuleNoLongerHostsGrocerySeedOpeners();
        },
            'StorefrontGroceryCatalogSeed::testDesignThemeCategorySeedRefusesDefaultAndRequiresGroceryCode' => function () {
            $this->assert_StorefrontGroceryCatalogSeed_testDesignThemeCategorySeedRefusesDefaultAndRequiresGroceryCode();
        },
            'StorefrontGroceryCatalogSeed::testDesignThemeCatalogSeederRefusesDefaultWebsiteAndUsesGrocerySkus' => function () {
            $this->assert_StorefrontGroceryCatalogSeed_testDesignThemeCatalogSeederRefusesDefaultWebsiteAndUsesGrocerySkus();
        },
            'StorefrontGroceryCatalogSeed::testDesignThemeCliSeedScriptOnlyAcceptsGrocery' => function () {
            $this->assert_StorefrontGroceryCatalogSeed_testDesignThemeCliSeedScriptOnlyAcceptsGrocery();
        },
            'StorefrontListingPagerLocale::testPagerKeepsChineseSourceWithoutControllerTranslate' => function () {
            $this->assert_StorefrontListingPagerLocale_testPagerKeepsChineseSourceWithoutControllerTranslate();
        },
            'StorefrontListingPagerLocale::testPagerPrevNextLabelsAreChineseSource' => function () {
            $this->assert_StorefrontListingPagerLocale_testPagerPrevNextLabelsAreChineseSource();
        },
            'StorefrontListingPagerLocale::testCategoryAndCatalogTemplatesReResolvePrevNextAtRender' => function () {
            $this->assert_StorefrontListingPagerLocale_testCategoryAndCatalogTemplatesReResolvePrevNextAtRender();
        },
            'StorefrontListingShellPathOnly::testShellOwnsSharedFilterPipelineAndIgnoresVisitorQueryOnNeutralApi' => function () {
            $this->assert_StorefrontListingShellPathOnly_testShellOwnsSharedFilterPipelineAndIgnoresVisitorQueryOnNeutralApi();
        },
            'StorefrontListingShellPathOnly::testCatalogPresentationHydrateMergesSummaryNamesAfterMedia' => function () {
            $this->assert_StorefrontListingShellPathOnly_testCatalogPresentationHydrateMergesSummaryNamesAfterMedia();
        },
            'StorefrontListingShellPathOnly::testCatalogAndCategoryControllersDelegateToShellNeutral' => function () {
            $this->assert_StorefrontListingShellPathOnly_testCatalogAndCategoryControllersDelegateToShellNeutral();
        },
            'StorefrontListingShellPathOnly::testListingTemplatesBindJsModuleWithoutFullPageSortNav' => function () {
            $this->assert_StorefrontListingShellPathOnly_testListingTemplatesBindJsModuleWithoutFullPageSortNav();
        },
            'StorefrontListingShellPathOnly::testListingJsPaintsFacetCountsWithoutTouchingWelineApi' => function () {
            $this->assert_StorefrontListingShellPathOnly_testListingJsPaintsFacetCountsWithoutTouchingWelineApi();
        },
            'StorefrontPdpShelfDeferral::testEnableGatesDeferralOnlyWhenNotPreview' => function () {
            $this->setUp_StorefrontPdpShelfDeferral();
            try {
                $this->assert_StorefrontPdpShelfDeferral_testEnableGatesDeferralOnlyWhenNotPreview();
            } finally {
                $this->tearDown_StorefrontPdpShelfDeferral();
            }
        },
            'StorefrontPdpShelfDeferral::testDetailControllerEnablesDeferralAfterSeedRemember' => function () {
            $this->setUp_StorefrontPdpShelfDeferral();
            try {
                $this->assert_StorefrontPdpShelfDeferral_testDetailControllerEnablesDeferralAfterSeedRemember();
            } finally {
                $this->tearDown_StorefrontPdpShelfDeferral();
            }
        },
            'StorefrontPdpShelfDeferral::testShelfTemplatesEmitHydrateShellWhenDeferred' => function () {
            $this->setUp_StorefrontPdpShelfDeferral();
            try {
                $this->assert_StorefrontPdpShelfDeferral_testShelfTemplatesEmitHydrateShellWhenDeferred();
            } finally {
                $this->tearDown_StorefrontPdpShelfDeferral();
            }
        },
            'StorefrontProductWidgetCatalogReuse::testNewArrivalSharedKeysSeparateCutoffDatesAndCandidatePages' => function () {
            $this->assert_StorefrontProductWidgetCatalogReuse_testNewArrivalSharedKeysSeparateCutoffDatesAndCandidatePages();
        },
            'StorefrontProductWidgetCatalogReuse::testCardsPreferSummaryOffersWhenNotListingProjection' => function () {
            $this->assert_StorefrontProductWidgetCatalogReuse_testCardsPreferSummaryOffersWhenNotListingProjection();
        },
            'StorefrontProductWidgetCatalogReuse::testCoordinatorExposesNewArrivalAndPresentationPolicies' => function () {
            $this->assert_StorefrontProductWidgetCatalogReuse_testCoordinatorExposesNewArrivalAndPresentationPolicies();
        },
            'StorefrontProductWidgetCatalogReuse::testHomepageShelfPlanUsesSharedHotCacheNotRequestOnly' => function () {
            $this->assert_StorefrontProductWidgetCatalogReuse_testHomepageShelfPlanUsesSharedHotCacheNotRequestOnly();
        },
            'StorefrontProductWidgetCatalogReuse::testNewArrivalCardsReuseRequestBagAndCanonicalCandidatePage' => function () {
            $this->assert_StorefrontProductWidgetCatalogReuse_testNewArrivalCardsReuseRequestBagAndCanonicalCandidatePage();
        },
            'StorefrontProductWidgetCatalogReuse::testRepositoryExposesBoundedRecentPublishedApi' => function () {
            $this->assert_StorefrontProductWidgetCatalogReuse_testRepositoryExposesBoundedRecentPublishedApi();
        },
            'StorefrontProductWidgetCatalogReuse::testOfferResolverReadsAssignedOfferThenContextIdentity' => function () {
            $this->assert_StorefrontProductWidgetCatalogReuse_testOfferResolverReadsAssignedOfferThenContextIdentity();
        },
        ];
    }

private function assert_AttributeValueShardLookupIndex_testSchemaVersionBumpedForLookupIndexes(): void
{
    self::assertSame('4.13.0', ProductShardSchemaCatalog::SCHEMA_VERSION);
}

/**
 * AttributeValueRepository::loadRows()/purgeEntity() filter by entity without store_id;
 * the unique key leads with store_id (single value per shard in practice) and cannot serve them.
 */
private function assert_AttributeValueShardLookupIndex_testEntityLookupIndexLeadsWithEntityColumns(): void
{
    $columns = $this->indexColumns_AttributeValueShardLookupIndex('idx_entity_attr');
    self::assertSame(['entity_type', 'entity_id', 'attribute_code'], $columns);
}

/**
 * findEntityIdsByAttributeValues() narrows by attribute_code (IN) before comparing value_text.
 */
private function assert_AttributeValueShardLookupIndex_testAttributeReverseLookupIndexExcludesUnboundedText(): void
{
    $columns = $this->indexColumns_AttributeValueShardLookupIndex('idx_attr_store');
    self::assertSame(['entity_type', 'attribute_code', 'store_id'], $columns);
}

private function assert_AttributeValueShardLookupIndex_testNoIndexCoversUnboundedTextColumns(): void
{
    foreach ($this->attributeValueSchema_AttributeValueShardLookupIndex()->indexes as $index) {
        foreach (['value_text', 'value_string', 'value_json'] as $textColumn) {
            self::assertNotContains($textColumn, $index->columns, $index->name);
        }
    }
}

private function assert_AttributeValueShardLookupIndex_testUniqueKeyUnchanged(): void
{
    self::assertSame(['store_id', 'entity_type', 'entity_id', 'attribute_code', 'locale'], $this->indexColumns_AttributeValueShardLookupIndex('uk_attr_store_locale'));
}

/** @return list<string> */
private function indexColumns_AttributeValueShardLookupIndex(string $name): array
{
    foreach ($this->attributeValueSchema_AttributeValueShardLookupIndex()->indexes as $index) {
        if ($index->name === $name) {
            return $index->columns;
        }
    }
    self::fail('attribute_value index missing: ' . $name);
}

private function attributeValueSchema_AttributeValueShardLookupIndex(): TableSchema
{
    foreach ((new ProductShardSchemaCatalog())->schemasForShard('0') as $schema) {
        if (str_ends_with($schema->tableName, '_attribute_value')) {
            return $schema;
        }
    }
    self::fail('attribute_value schema missing');
}

private function assert_CategoryMenuPrefetch_testNavTreePrefetchesCategoryMenuPolicyKey(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontAllMenuCategoryTreeService.php');
    self::assertStringContainsString('prefetchPolicy(', $src);
    self::assertStringContainsString('categoryMenuPolicy()', $src);
    self::assertStringContainsString('rememberPolicy(', $src);
    $prefetchPos = \strpos($src, 'prefetchPolicy(');
    $rememberPos = \strpos($src, 'rememberPolicy(');
    self::assertNotFalse($prefetchPos);
    self::assertNotFalse($rememberPos);
    self::assertLessThan($rememberPos, $prefetchPos);
    self::assertTrue(class_exists(StorefrontAllMenuCategoryTreeService::class));
}

private function assert_FilterPanelProcessL1Policy_testFilterPanelAndFacetCountsSkipProcessL1(): void
{
    self::assertFalse(StorefrontCatalogCacheCoordinator::filterPanelPolicy()->storeProcessL1);
    self::assertFalse(StorefrontCatalogCacheCoordinator::catalogFacetCountsPolicy()->storeProcessL1);
}

private function assert_HomepageShelfOffersLocaleCacheWarmer_testModuleProvidesHomepageShelfOffersLocaleWarmer(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/etc/module.php';
    $provides = $module['provides'] ?? [];
    self::assertSame(HomepageShelfOffersLocaleCacheWarmer::class, $provides['cache.warmer.product.homepage_shelf_offers_locale'] ?? null);
    self::assertSame('1.0.390', (string) ($module['version'] ?? ''));
}

private function assert_HomepageShelfOffersLocaleCacheWarmer_testWarmerTargetsProductPoolAfterSearchProjection(): void
{
    $warmer = new HomepageShelfOffersLocaleCacheWarmer();
    self::assertSame(StorefrontCatalogViewService::cachePool(), $warmer->getTargetPool());
    self::assertSame('product.homepage_shelf_offers_locale', $warmer->getName());
    self::assertSame(230, $warmer->getPriority());
    self::assertGreaterThan(220, $warmer->getPriority());
}

private function assert_HomepageShelfOffersLocaleCacheWarmer_testClearWarmsProductPoolWithoutProductFqcn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/Console/Cache/Clear.php');
    self::assertStringContainsString("warmUp('product')", $src);
    self::assertStringNotContainsString('HomepageShelfOffersLocaleCacheWarmer', $src);
    self::assertStringNotContainsString('StorefrontHotCacheBagSeeder', $src);
}

private function assert_HomepageShelfOffersLocaleCacheWarmer_testWarmerUsesWebsiteEnabledLocalesAndLightSummaryOnly(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/HomepageShelfOffersLocaleCacheWarmer.php');
    self::assertStringContainsString('getWebsiteLanguageCodes', $src);
    self::assertStringContainsString('homepageFeaturedCards', $src);
    self::assertStringContainsString('publishedOfferSummaries', $src);
    self::assertStringContainsString('homepageShelfPlanPolicy', $src);
    self::assertStringContainsString('catalogSummaryOffersPolicy', $src);
    self::assertStringContainsString('catalogSummaryLangSurfacePolicy', $src);
    self::assertStringContainsString('peekPolicy', $src);
    self::assertStringContainsString('purgeProcessCacheForLogicalKey', $src);
    self::assertStringContainsString('warmed_l2_hit', $src);
    self::assertStringContainsString("'warmed' => \\max(0, \$warmedL2Hit)", $src);
    self::assertStringContainsString('remaining', $src);
    self::assertStringContainsString('BUDGET_MS', $src);
    self::assertStringContainsString("MEMORY_FLOOR = '512M'", $src);
    self::assertStringContainsString("warmUp('product')", (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/Console/Cache/Clear.php'));
    self::assertStringContainsString('reportWarmUpDetails', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/Console/Cache/Clear.php'));
    self::assertStringNotContainsString('->publishedOffers(', $src);
    self::assertStringNotContainsString('::publishedOffers(', $src);
    self::assertStringNotContainsString('publishedOffers(1000', $src);
    self::assertStringNotContainsString('seed_sharded', $src);
    self::assertStringNotContainsString('renderPartials', $src);
    self::assertStringNotContainsString('storefront_chrome', $src);
    self::assertStringNotContainsString('hanfu', $src);
}

private function assert_HomepageShelfOffersLocaleCacheWarmer_testWarmerDoesNotHardcodeAcceptanceLocaleCatalogAsSoleSource(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/HomepageShelfOffersLocaleCacheWarmer.php');
    // Priority order among enabled codes is OK; inventing tip whitelist as sole source is not.
    self::assertStringContainsString('getWebsiteLanguageCodes', $src);
    self::assertStringNotContainsString("['ar_SA', 'de_DE', 'fr_FR']", $src);
    self::assertStringNotContainsString('HEADER_TIP_QUERIES', $src);
}

private function assert_HomepageShelfStagger_testTopFourPairwiseDisjointAndFeaturedDiffersFromHotTopEight(): void
{
    $featuredPool = [];
    $dealsPool = [];
    $hotPool = [];
    // Shared IDs 1–12 would collide if shelves reused the same cards() order.
    for ($id = 1; $id <= 24; $id++) {
        $featuredPool[] = $this->card_HomepageShelfStagger($id, 100.0, 100.0);
        $hotPool[] = $this->card_HomepageShelfStagger($id, 100.0, 100.0, reviewCount: 100 - $id);
    }
    // Deals: deepest discounts on low IDs (overlap trap with featured/hot).
    for ($id = 1; $id <= 8; $id++) {
        $dealsPool[] = $this->card_HomepageShelfStagger($id, 70.0, 100.0);
    }
    for ($id = 25; $id <= 32; $id++) {
        $dealsPool[] = $this->card_HomepageShelfStagger($id, 80.0, 100.0);
    }
    $plan = HomepageShelfStagger::select($featuredPool, $dealsPool, $hotPool, 8, 4, 8);
    $featuredTop4 = $this->ids_HomepageShelfStagger(array_slice($plan['featured'], 0, 4));
    $dealsTop4 = $this->ids_HomepageShelfStagger(array_slice($plan['deals'], 0, 4));
    $hotTop4 = $this->ids_HomepageShelfStagger(array_slice($plan['hot'], 0, 4));
    $featuredTop8 = $this->ids_HomepageShelfStagger(array_slice($plan['featured'], 0, 8));
    $hotTop8 = $this->ids_HomepageShelfStagger(array_slice($plan['hot'], 0, 8));
    self::assertSame([], array_values(array_intersect($featuredTop4, $dealsTop4)));
    self::assertSame([], array_values(array_intersect($featuredTop4, $hotTop4)));
    self::assertSame([], array_values(array_intersect($dealsTop4, $hotTop4)));
    self::assertNotSame($featuredTop8, $hotTop8);
    self::assertSame([], array_values(array_intersect(array_intersect($featuredTop8, $dealsTop4), $hotTop8)), '禁止同 SKU 三刷');
}

private function assert_HomepageShelfStagger_testDealsDoNotBorrowFromOtherShelvesWhenPoolIsShort(): void
{
    $featured = [$this->card_HomepageShelfStagger(1), $this->card_HomepageShelfStagger(2), $this->card_HomepageShelfStagger(3), $this->card_HomepageShelfStagger(4)];
    $hot = [$this->card_HomepageShelfStagger(5), $this->card_HomepageShelfStagger(6), $this->card_HomepageShelfStagger(7), $this->card_HomepageShelfStagger(8)];
    // Only two real deals (≥15%); must not pad from featured/hot.
    $deals = [$this->card_HomepageShelfStagger(10, 70.0, 100.0), $this->card_HomepageShelfStagger(11, 80.0, 100.0), $this->card_HomepageShelfStagger(12, 95.0, 100.0)];
    $plan = HomepageShelfStagger::select($featured, $deals, $hot, 4, 4, 4);
    self::assertCount(2, $plan['deals']);
    self::assertSame([10, 11], $this->ids_HomepageShelfStagger($plan['deals']));
    self::assertNotContains(1, $this->ids_HomepageShelfStagger($plan['deals']));
    self::assertNotContains(5, $this->ids_HomepageShelfStagger($plan['deals']));
}

private function assert_HomepageShelfStagger_testLimitedDealFlagQualifiesWithoutHighDiscount(): void
{
    $deals = [array_merge($this->card_HomepageShelfStagger(99, 95.0, 100.0), ['is_limited_deal' => true])];
    $plan = HomepageShelfStagger::select([$this->card_HomepageShelfStagger(1)], $deals, [$this->card_HomepageShelfStagger(2)], 1, 1, 1);
    self::assertSame([99], $this->ids_HomepageShelfStagger($plan['deals']));
}

private function assert_HomepageShelfStagger_testThinCatalogKeepsFeaturedDisjointFromHot(): void
{
    $pool = [];
    for ($id = 1; $id <= 8; $id++) {
        $pool[] = $this->card_HomepageShelfStagger($id, 100.0 + $id, 100.0 + $id, reviewCount: 20 - $id);
    }
    $plan = HomepageShelfStagger::select($pool, [], $pool, 8, 4, 8);
    self::assertNotSame([], $plan['featured']);
    self::assertNotSame([], $plan['hot']);
    self::assertSame([], array_values(array_intersect($this->ids_HomepageShelfStagger($plan['featured']), $this->ids_HomepageShelfStagger($plan['hot']))));
    self::assertSame([], $plan['deals']);
    self::assertCount(8, array_unique(array_merge($this->ids_HomepageShelfStagger($plan['featured']), $this->ids_HomepageShelfStagger($plan['hot']))));
}

private function assert_HomepageShelfStagger_testZeroPriceCardsAreExcludedFromAllShelves(): void
{
    $featured = [$this->card_HomepageShelfStagger(1, 0.0, 0.0), $this->card_HomepageShelfStagger(2, 88.0, 88.0), $this->card_HomepageShelfStagger(3, 0.0, 120.0), $this->card_HomepageShelfStagger(4, 66.0, 66.0)];
    $deals = [$this->card_HomepageShelfStagger(10, 0.0, 100.0), $this->card_HomepageShelfStagger(11, 70.0, 100.0)];
    $hot = [$this->card_HomepageShelfStagger(20, 0.0, 0.0), $this->card_HomepageShelfStagger(21, 55.0, 55.0)];
    $plan = HomepageShelfStagger::select($featured, $deals, $hot, 4, 4, 4);
    self::assertSame([2, 4], $this->ids_HomepageShelfStagger($plan['featured']));
    self::assertSame([11], $this->ids_HomepageShelfStagger($plan['deals']));
    self::assertSame([21], $this->ids_HomepageShelfStagger($plan['hot']));
    foreach (array_merge($plan['featured'], $plan['deals'], $plan['hot']) as $card) {
        self::assertGreaterThan(0.0, (float) ($card['price'] ?? 0));
    }
}

/**
 * @return array<string, mixed>
 */
private function card_HomepageShelfStagger(int $id, float $price = 100.0, float $original = 100.0, int $reviewCount = 0): array
{
    return ['id' => $id, 'product_id' => $id, 'name' => 'P' . $id, 'price' => $price, 'original_price' => $original, 'review_count' => $reviewCount, 'rating' => 4.0];
}

/**
 * @param list<array<string, mixed>> $cards
 * @return list<int>
 */
private function ids_HomepageShelfStagger(array $cards): array
{
    return array_values(array_map(static fn(array $card): int => (int) ($card['product_id'] ?? $card['id'] ?? 0), $cards));
}

private function assert_ListingSummarySlugUrl_testSnapshotAllowlistIncludesSlugAttributes(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php');
    self::assertNotSame('', $source);
    self::assertStringContainsString("in_array(\$code, ['name', 'product_type', 'quote_only', 'slug', 'source_slug'], true)", $source);
    self::assertStringContainsString("slug: (string)(\$facts['slug'] ?? '')", $source);
    self::assertStringContainsString("\$attributeRowsByProductAndCode[\$productId]['source_slug']", $source);
}

private function assert_ListingSummarySlugUrl_testCatalogRowMapsSnapshotSlugAndBustsSummaryCache(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString("'slug' => \$this->resolvePublicCatalogSlug(", $source);
    self::assertStringContainsString("'summary-card3'", $source);
    self::assertStringContainsString('publicSlugFromSku', $source);
}

private function assert_ListingSummarySlugUrl_testCartItemSnapshotExposesSlugInToArray(): void
{
    $snapshotSource = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Cart/Api/Data/CartItemSnapshot.php');
    self::assertStringContainsString('public readonly string $slug = \'\'', $snapshotSource);
    self::assertStringContainsString("'slug' => trim(\$this->slug)", $snapshotSource);
    $snapshot = new CartItemSnapshot(offer: new OfferIdentity('product', 'offer-slug-113', 113), name: 'Demo', slug: 'demo-product-slug');
    $row = $snapshot->toArray();
    self::assertSame('demo-product-slug', $row['slug'] ?? null);
}

private function assert_ListingSummarySlugUrl_testCardRendererPrefersSlugPathOverNumericId(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCardRenderer.php');
    self::assertStringContainsString("\$urlPath = trim((string)(\$product['url_path'] ?? ''))", $source);
    self::assertStringContainsString("\$route === '' && \$urlPath !== ''", $source);
    self::assertStringContainsString("\$slug = strtolower(trim((string)(\$product['slug'] ?? '')))", $source);
    self::assertStringContainsString("'product/' . ltrim(\$slug, '/')", $source);
    self::assertStringContainsString("'product/' . \$productId", $source);
}

private function assert_ListingSummarySlugUrl_testRecentlyViewedCardsPreferSlugPathOverNumericId(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/RecentlyViewed/Service/RecentlyViewedService.php');
    self::assertStringContainsString("\$offer['slug'] ?? \$offer['source_slug']", $source);
    self::assertStringContainsString("'product/' . \$slug", $source);
    self::assertStringContainsString("'product/' . \$productId", $source);
    self::assertStringContainsString("'slug' => \$slug", $source);
}

private function assert_OfferTaxClassBinding_testOfferSchemaIncludesTaxClassCode(): void
{
    self::assertSame('4.13.0', ProductShardSchemaCatalog::SCHEMA_VERSION);
    self::assertSame('tax_class_code', Offer::schema_fields_TAX_CLASS_CODE);
    $catalog = new ProductShardSchemaCatalog();
    $schemas = $catalog->schemasForShard('0');
    $offer = null;
    foreach ($schemas as $schema) {
        if (str_ends_with($schema->tableName, '_offer')) {
            $offer = $schema;
            break;
        }
    }
    self::assertNotNull($offer);
    $cols = array_map(static fn($c) => $c->name, $offer->columns);
    self::assertContains('tax_class_code', $cols);
}

private function assert_OfferTaxClassBinding_testAdminAndCartSurfacesWireTaxClass(): void
{
    $edit = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/backend/catalog/edit.phtml');
    $create = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/backend/catalog/index.phtml');
    $cmd = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAdminCommandService.php');
    $snap = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php');
    self::assertStringContainsString('product-edit-tax-class', $edit);
    self::assertStringContainsString('name="tax_class_code"', $edit);
    self::assertStringContainsString('销售税税类', $edit);
    self::assertStringContainsString('product-create-tax-class', $create);
    self::assertStringContainsString('normalizeTaxClassCode', $cmd);
    self::assertStringContainsString('writeOfferTaxClassCode', $cmd);
    self::assertStringContainsString('TAX_CLASS_CODE', $snap);
    self::assertStringContainsString('taxClassCode:', $snap);
}

private function assert_ProductAdminMutationService_testCreateOfferPersistsSku(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAdminMutationService.php');
    self::assertStringContainsString('Offer::schema_fields_SKU => $identity->sku', $src);
    self::assertMatchesRegularExpression(
        '/function createOffer\([\s\S]*?Offer::schema_fields_SKU\s*=>\s*\$identity->sku/s',
        $src,
    );
}

private function assert_PlpFilterOffersLightCacheWarmer_testModuleProvidesPlpLightWarmer(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/etc/module.php';
    $provides = $module['provides'] ?? [];
    self::assertSame(PlpFilterOffersLightCacheWarmer::class, $provides['cache.warmer.product.plp_light'] ?? null);
    self::assertSame('1.1.22', (string) ($module['version'] ?? ''));
}

private function assert_PlpFilterOffersLightCacheWarmer_testWarmerTargetsProductPoolAfterShelf(): void
{
    $warmer = new PlpFilterOffersLightCacheWarmer();
    self::assertSame(StorefrontCatalogViewService::cachePool(), $warmer->getTargetPool());
    self::assertSame('product.plp_light', $warmer->getName());
    self::assertSame(235, $warmer->getPriority());
    self::assertGreaterThan(230, $warmer->getPriority());
}

private function assert_PlpFilterOffersLightCacheWarmer_testClearDiscoversViaProductPoolWithoutFqcn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/Console/Cache/Clear.php');
    self::assertStringContainsString("warmUp('product')", $src);
    self::assertStringNotContainsString('PlpFilterOffersLightCacheWarmer', $src);
}

private function assert_PlpFilterOffersLightCacheWarmer_testWarmerUsesLightPathsOnly(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/PlpFilterOffersLightCacheWarmer.php');
    self::assertStringContainsString('publishedListingCandidates', $src);
    self::assertStringContainsString('publishedOfferSummaries', $src);
    self::assertStringContainsString('facetCountsForProductIds', $src);
    self::assertStringContainsString('buildPanel', $src);
    self::assertStringContainsString('StorefrontFilterPanelService', $src);
    self::assertStringContainsString('TIP_SCOPES', $src);
    self::assertStringContainsString('BUDGET_MS', $src);
    self::assertStringContainsString('FACET_LISTING_LIMIT', $src);
    self::assertStringNotContainsString('publishedOffers(1000', $src);
    self::assertDoesNotMatchRegularExpression('/->publishedOffers\s*\(/', $src);
    self::assertStringNotContainsString('renderPartials', $src);
    self::assertStringNotContainsString("warmUp('weline_theme_storefront_chrome')", $src);
    self::assertStringNotContainsString('Partials::', $src);
}

/**
 * contracts plp-filter-soft-cold A: zh×USD first; seed soft-same key max1000+facet true.
 */
private function assert_PlpFilterOffersLightCacheWarmer_testWarmerSeedsSoftSameFacetKeyAndShopperTipFirst(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/PlpFilterOffersLightCacheWarmer.php');
    self::assertMatchesRegularExpression(
        "/TIP_SCOPES\s*=\s*\[\s*\[\s*'lang'\s*=>\s*'zh_Hans_CN'\s*,\s*'currency'\s*=>\s*'USD'\s*\]/s",
        $src,
    );
    self::assertStringContainsString("'lang' => 'de_DE'", $src);
    self::assertStringContainsString("'lang' => 'ja_JP'", $src);
    self::assertMatchesRegularExpression('/FACET_LISTING_LIMIT\s*=\s*1000/', $src);
    self::assertMatchesRegularExpression(
        '/publishedListingCandidates\s*\(\s*self::FACET_LISTING_LIMIT\s*,\s*true\s*\)/',
        $src,
    );
    self::assertDoesNotMatchRegularExpression(
        '/publishedListingCandidates\s*\(\s*self::(?:LISTING_LIMIT|FACET_LISTING_LIMIT)\s*,\s*false\s*\)/',
        $src,
    );
    self::assertStringNotContainsString('LISTING_LIMIT = 120', $src);
    self::assertStringContainsString('facet_listing_details=1', $src);
}

private function assert_ProductAdminArchiveTransition_testCommandServiceChainsPublishedArchiveAndNoopsArchived(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAdminCommandService.php');
    self::assertStringContainsString('lifecycleStepsToward', $source);
    self::assertStringContainsString("STATUS_ARCHIVED", $source);
    self::assertStringContainsString('ACTION_RESTORE', $source);
    self::assertMatchesRegularExpression('/lifecycleStepsToward.*?published.*?disabled.*?archived/s', $source);
    self::assertMatchesRegularExpression("/'archived'\\s*=>\\s*\\['draft'\\]/", $source);
    self::assertMatchesRegularExpression('/private function transition\(.*?STATUS_ARCHIVED.*?noop|already.?archiv|已归档/s', $source);
}

private function assert_ProductAdminArchiveTransition_testBulkArchiveJsSurfacesFailedCounts(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/backend/product-admin.js');
    self::assertStringContainsString('function bulkArchive', $script);
    self::assertMatchesRegularExpression('/function bulkArchive\([\s\S]*?data\.failed[\s\S]*?succeeded/m', $script);
}

private function assert_ProductAdminBulkInvalidUuidPurge_testBulkServicePurgesInvalidUuidViaPhysicalDelete(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAdminBulkService.php');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/backend/product-admin.js');
    self::assertStringContainsString('isValidProductUuid', $source);
    self::assertStringContainsString('ProductPhysicalDeleteService', $source);
    self::assertStringContainsString('deleteByIds', $source);
    self::assertStringContainsString('ACTION_ARCHIVE', $source);
    self::assertMatchesRegularExpression('/function bulkArchive\([\s\S]*?product_id:\s*item\.product_id/m', $js);
}

private function assert_ProductAdminBulkInvalidUuidPurge_testExecuteSkipsCommandForInvalidUuidAndRequiresProductId(): void
{
    if (!function_exists('__')) {
        eval('function __(string $text, array $args = []): string { return $text; }');
    }
    $commands = $this->createMock(ProductAdminCommandInterface::class);
    $commands->expects(self::never())->method('execute');
    $service = new ProductAdminBulkService($commands, null);
    $result = $service->execute(0, 'archive', hash('sha256', 'bulk-invalid-uuid-no-id'), [['global_product_uuid' => 'e2e-delprod-1789143448634', 'product_id' => 0, 'expected_version' => 0, 'local_version' => 1]]);
    self::assertTrue($result['success']);
    self::assertSame(0, $result['data']['succeeded']);
    self::assertSame(1, $result['data']['failed']);
    self::assertSame('product_admin_product_uuid_invalid', $result['data']['items'][0]['error_code']);
}

private function assert_ProductAdminBulkInvalidUuidPurge_testExecuteStillDispatchesValidUuidToCommand(): void
{
    if (!function_exists('__')) {
        eval('function __(string $text, array $args = []): string { return $text; }');
    }
    $commands = $this->createMock(ProductAdminCommandInterface::class);
    $commands->expects(self::once())->method('execute')->willReturn(ProductAdminResult::ok([], 'ok'));
    $service = new ProductAdminBulkService($commands, null);
    $result = $service->execute(0, 'archive', hash('sha256', 'bulk-valid-uuid'), [['global_product_uuid' => '10000000-0000-4000-8000-000000000001', 'product_id' => 10, 'expected_version' => 1, 'local_version' => 1]]);
    self::assertTrue($result['success']);
    self::assertSame(1, $result['data']['succeeded']);
}

private function assert_ProductAdminReadServiceCategoryCatalog_testTaxonomyCatalogUsesCatalogQueryConsumer(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAdminReadService.php');
    self::assertStringContainsString('ProductCatalogQueryConsumer', $source);
    self::assertStringContainsString('catalogConsumer->flatRows', $source);
    self::assertStringNotContainsString('$this->categories->listAll', $source);
    self::assertStringContainsString("'categories' => \$this->categoryCatalog", $source);
}

private function assert_ProductCardBatchRender_testProjectFromOffersIsPublicBatchEntry(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCardRenderer.php');
    self::assertStringContainsString('public static function projectFromOffers(', $src);
    self::assertStringContainsString('public static function projectFromIds(', $src);
    self::assertStringContainsString('public static function projectFromProducts(', $src);
    self::assertStringContainsString('public static function projectHtmlByProductId(', $src);
    self::assertStringContainsString('renderCachedBody', $src);
    self::assertStringContainsString('buildCardsBatchHtml', $src);
    self::assertStringContainsString('product-cards-batch.phtml', $src);
    self::assertStringContainsString('card_batch', $src);
    self::assertStringContainsString('product-card-shelf.phtml', $src);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-card-shelf.phtml');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-cards-batch.phtml');
    self::assertStringContainsString('StorefrontProductCardFragmentCache', $src);
    self::assertStringContainsString("'batch' => true", $src);
    self::assertStringContainsString('hydrateReviewAggregates', $src);
    self::assertStringContainsString('aggregatesForExternalUuids', $src);
    self::assertStringNotContainsString('static $cardHtml', $src);
    // Batch path must not loop renderCachedBody (single-card Taglib keeps fragment).
    self::assertSame(1, \preg_match('/private static function renderNormalizedProductsHtml\((?:[^}]|\}(?!\;))*?\n    \}/s', $src, $fnMatch));
    self::assertStringNotContainsString('renderCachedBody', (string) ($fnMatch[0] ?? ''));
    self::assertStringContainsString('buildCardsBatchHtml', (string) ($fnMatch[0] ?? ''));
}

private function assert_ProductCardBatchRender_testBatchShellIncludesCompiledCardWithoutPerCardFetch(): void
{
    $batch = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-cards-batch.phtml');
    self::assertStringContainsString('compiled_card_file', $batch);
    self::assertStringContainsString('include $compiledCard', $batch);
    self::assertStringNotContainsString('$this->fetch(', $batch);
}

private function assert_ProductCardBatchRender_testVideoCarouselPassesIdsOnlyToProductCardTaglib(): void
{
    // Product/Test/Unit/Service → Weline/Theme/...
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets/video/video-carousel/default.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('<w:product:card ids="relatedProductIds"', $source);
    self::assertStringNotContainsString('StorefrontProductWidgetCatalog', $source);
    self::assertStringNotContainsString('projectHtmlByProductId', $source);
    self::assertStringNotContainsString('cardsByIds', $source);
}

private function assert_ProductCardBatchRender_testFragmentKeyIgnoresCardIndexWhileStampOwnsEagerBudget(): void
{
    \Weline\Framework\Runtime\RequestContext::init();
    $cache = (new \ReflectionClass(\Weline\Theme\Service\StorefrontProductCardFragmentCache::class))->newInstanceWithoutConstructor();
    $flags = ['density' => 'shelf', 'show_price' => true];
    $base = ['id' => 1, 'name' => 'X', 'price' => 10.0, 'currency' => 'CNY'];
    self::assertSame($cache->logicalKey($base + ['card_index' => 0], $flags), $cache->logicalKey($base + ['card_index' => 39], $flags));
    $lazy = '<img class="wpc-image" loading="lazy">';
    for ($i = 0; $i < 8; ++$i) {
        $out = ProductCardRenderer::stampImageLoadingAttributes($lazy, $i);
        self::assertStringContainsString('loading="eager"', $out, 'budget slot ' . $i);
    }
    $afterBudget = ProductCardRenderer::stampImageLoadingAttributes($lazy, 0);
    self::assertStringContainsString('loading="lazy"', $afterBudget);
}

private function assert_ProductCardBatchRender_testListingTemplatesUseBatchProjectionNotPerCardTaglib(): void
{
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/catalog/index.phtml');
    $category = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/category/index.phtml');
    foreach ([$catalog, $category] as $tpl) {
        self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $tpl);
        self::assertStringNotContainsString('<w:product:card', $tpl);
        self::assertStringNotContainsString('fromStorefrontOffer(', $tpl);
    }
}

/**
 * 简单网格：声明式 ids 批渲（Taglib → projectFromIds），禁止模板内 projectFromProducts。
 *
 * @return iterable<string, array{0: string, 1: string}>
 */
private function recommendationTaglibBatchSurfaces_ProductCardBatchRender(): iterable
{
    $productRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $themeRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme';
    yield 'product-related' => [$productRoot . '/view/templates/frontend/widgets/related-products.phtml', 'ids="products"'];
    yield 'product-recommended' => [$productRoot . '/view/templates/frontend/widgets/recommended-products.phtml', 'ids="products"'];
    yield 'product-you-may-like' => [$productRoot . '/view/templates/frontend/widgets/you-may-like.phtml', 'ids="products"'];
    yield 'theme-related' => [$themeRoot . '/view/theme/frontend/widgets/product/related-products/default.phtml', 'ids="batchProducts"'];
    yield 'theme-up-sell' => [$themeRoot . '/view/theme/frontend/widgets/product/up-sell/default.phtml', 'ids="batchProducts"'];
}

/**
 * 按 id 分桶接线（轮播/交叉销售等）：仍用 projectHtmlByProductId。
 *
 * @return iterable<string, array{0: string}>
 */
private function keyedBatchSurfaces_ProductCardBatchRender(): iterable
{
    $productRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $themeRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme';
    yield 'product-best-sellers' => [$productRoot . '/view/templates/frontend/best-sellers/index.phtml'];
    yield 'theme-cross-sell' => [$themeRoot . '/view/theme/frontend/widgets/product/cross-sell/default.phtml'];
    yield 'theme-carousel' => [$themeRoot . '/view/theme/frontend/widgets/carousel/product-carousel/default.phtml'];
}

private function assert_ProductCategoryAdminServiceDedupe_testFindSiblingAndDedupeUseEavLocalizedNames(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCategoryAdminService.php');
    self::assertStringContainsString('findSiblingIdByLocalizedName', $source);
    self::assertStringContainsString('dedupeSiblingsByLocalizedName', $source);
    self::assertStringContainsString('readNameMap', $source);
}

private function assert_ProductCategoryAdminServiceEavPath_testSaveDoesNotCallAttributeRepositoryWriteExplicitDirectly(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCategoryAdminService.php');
    self::assertStringContainsString('ProductCategoryAttributeService', $source);
    self::assertStringContainsString('$this->categoryAttributes->writeName', $source);
    self::assertStringContainsString('$this->categoryAttributes->writeCode', $source);
    self::assertStringContainsString('$this->categoryAttributes->purge', $source);
    self::assertStringNotContainsString('$this->attributes->writeExplicit', $source);
}

private function assert_ProductCategoryDeleteProducts_testDeletePipelineUsesAnyStoreRecomputeAndTransaction(): void
{
    $admin = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCategoryAdminService.php');
    $physical = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductPhysicalDeleteService.php');
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Catalog/Space/ProductCatalogSpaceProvider.php');
    self::assertStringContainsString('listByCategoryIdsAnyStore', $admin);
    self::assertStringContainsString('listByProductIdsAnyStore', $admin);
    self::assertStringContainsString('ProductCategoryExclusiveClassifier::intersectSelected', $admin);
    self::assertStringContainsString('ProductCategoryExclusiveClassifier::classify', $admin);
    self::assertStringContainsString('withWebsiteTransaction', $admin);
    self::assertStringContainsString('getQuery()', $admin);
    self::assertStringContainsString('beginTransaction', $admin);
    self::assertStringContainsString('ProductPhysicalDeleteService', $admin);
    self::assertStringContainsString('DELETE_PRODUCT_LIST_LIMIT = 200', $admin);
    self::assertStringContainsString("purgeOfferIds", $physical);
    self::assertStringContainsString('purgeCatalogOffers', $physical);
    self::assertStringContainsString('purgeProductIds', $physical);
    self::assertStringContainsString('deleteByProductIds', $physical);
    self::assertStringContainsString('inventoryModuleEnabled', $physical);
    self::assertStringContainsString('product_physical_delete_inventory_unavailable', $physical);
    self::assertStringContainsString('listProductsForDelete', $provider);
    self::assertStringContainsString("options['product_ids']", $provider);
}

private function assert_ProductIdentityCutoverRequestMemo_testCurrentUsesRequestContextRemember(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductIdentityCutoverService.php');
    self::assertStringContainsString('REQUEST_SNAPSHOT_KEY', $src);
    self::assertStringContainsString('product.identity_cutover.snapshot.v1', $src);
    self::assertStringContainsString('RequestContext::remember', $src);
    self::assertStringContainsString('publishRequestSnapshot', $src);
}

private function assert_ProductQuoteMailReplySlot_testSourceConstantAndServiceSurface(): void
{
    self::assertSame('product_quote', ProductQuoteMailReplySlotService::SOURCE);
    $src = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteMailReplySlotService.php');
    self::assertStringContainsString('resolveLocalMailboxByEmail', $src);
    self::assertStringContainsString('listLocalMailboxes', $src);
    self::assertStringContainsString('Weline_Mail::mail_send_as', $src);
    self::assertStringContainsString('buildComposerPayload', $src);
    self::assertStringContainsString('mailto:', $src);
    self::assertStringNotContainsString('getBackendUrl(\'weline_mail/backend\'', $src);
}

private function assert_ProductQuoteMailReplySlot_testAdminTemplateHasMailReplySlot(): void
{
    $tpl = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/backend/QuoteRequest/index.phtml');
    self::assertStringContainsString('mail_reply', $tpl);
    self::assertStringContainsString('企业邮箱回复', $tpl);
    self::assertStringContainsString('外部邮件客户端', $tpl);
    self::assertStringContainsString('data-quote-mail-open', $tpl);
    self::assertStringContainsString('<w:mail-composer', $tpl);
    self::assertStringContainsString('WelineMailComposer', $tpl);
    self::assertStringNotContainsString('compose=1', $tpl);
}

private function assert_ProductQuoteMailReplySlot_testControllerAttachesMailReplySlots(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php');
    self::assertStringContainsString('ProductQuoteMailReplySlotService', $controller);
    self::assertStringContainsString('attachToQuotes', $controller);
}

private function assert_ProductQuoteMailReplySlot_testObserverAndEventXmlWireMailMessageSent(): void
{
    self::assertFileExists(BP . 'app/code/Weline/Product/Observer/QuoteRequestMailMessageSentObserver.php');
    $observer = (string) file_get_contents(BP . 'app/code/Weline/Product/Observer/QuoteRequestMailMessageSentObserver.php');
    self::assertStringContainsString('ProductQuoteMailReplySlotService::SOURCE', $observer);
    self::assertStringContainsString('STATUS_REPLIED', $observer);
    $xml = (string) file_get_contents(BP . 'app/code/Weline/Product/etc/event.xml');
    self::assertStringContainsString('Weline_Mail::mail_message_sent', $xml);
    self::assertStringContainsString('QuoteRequestMailMessageSentObserver', $xml);
}

private function assert_ProductQuoteMailReplySlot_testAccountQuotesOpensComposer(): void
{
    $hook = (string) file_get_contents(BP . 'app/code/Weline/Product/view/hooks/account.sidebar.content.phtml');
    self::assertStringContainsString('data-quote-thread-open', $hook);
    self::assertStringContainsString('<w:mail-composer', $hook);
    self::assertStringContainsString('product_quote', $hook);
}

private function assert_ProductQuoteMailReplySlot_testModuleOptionalMailDependency(): void
{
    $module = include BP . 'app/code/Weline/Product/etc/module.php';
    self::assertSame('1.0.140', $module['version'] ?? null);
    self::assertArrayHasKey('Weline_Mail', $module['optional'] ?? []);
}

private function assert_ProductQuoteRequestCaptchaGuardLazy_testGuardRendersLazyHostInsteadOfChallengeHtml(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductQuoteRequestCaptchaGuard.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('LazyCaptchaClientRuntime', $source);
    self::assertStringContainsString('LazyCaptchaClientRuntime::hostMarkup', $source);
    self::assertStringContainsString('self::FORM_ID', $source);
    self::assertStringContainsString('self::INTENT', $source);
    self::assertStringNotContainsString('->renderChallenge(', $source);
}

private function assert_ProductQuoteRequestNative_testModelAndServiceSurfaceExist(): void
{
    $model = BP . 'app/code/Weline/Product/Model/ProductQuoteRequest.php';
    $service = BP . 'app/code/Weline/Product/Service/ProductQuoteRequestService.php';
    $api = BP . 'app/code/Weline/Product/Api/ProductQuoteRequestSubmitInterface.php';
    $frontend = BP . 'app/code/Weline/Product/Controller/Frontend/Api/QuoteRequest.php';
    $backend = BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php';
    foreach ([$model, $service, $api, $frontend, $backend] as $path) {
        self::assertFileExists($path);
    }
    $modelSrc = (string) file_get_contents($model);
    self::assertStringContainsString('weline_product_quote_request', $modelSrc);
    self::assertStringContainsString('idempotency_key', $modelSrc);
    self::assertStringContainsString('address_json', $modelSrc);
    $serviceSrc = (string) file_get_contents($service);
    self::assertStringContainsString('function submit(', $serviceSrc);
    self::assertStringContainsString('idempotency_key', $serviceSrc);
    self::assertStringContainsString('publishedOffersForProduct', $serviceSrc);
    self::assertStringContainsString('仅询价', $serviceSrc);
    self::assertStringContainsString('company_website', $serviceSrc);
    self::assertStringNotContainsString('Weline\Inquiry', $serviceSrc);
    self::assertStringNotContainsString('Weline_Inquiry', $serviceSrc);
}

private function assert_ProductQuoteRequestNative_testStorefrontQueryExposesSubmitQuoteRequest(): void
{
    $path = BP . 'app/code/Weline/Product/extends/module/Weline_Framework/Query/ProductStorefrontQueryProvider.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("'submitQuoteRequest'", $src);
    self::assertStringContainsString('ProductQuoteRequestSubmitInterface', $src);
    self::assertStringContainsString("'frontend' => true", $src);
    self::assertStringContainsString("'mode' => 'write'", $src);
}

private function assert_ProductQuoteRequestNative_testAdminMenuAndAclExist(): void
{
    $menu = (string) file_get_contents(BP . 'app/code/Weline/Product/etc/backend/menu.xml');
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php');
    $template = BP . 'app/code/Weline/Product/view/templates/backend/quote-request/index.phtml';
    self::assertFileExists($template);
    self::assertStringContainsString('Weline_Product::commerce:catalog:quote-requests', $menu);
    self::assertStringContainsString('weline_product/backend/quote-request/index', $menu);
    self::assertStringContainsString("Acl('Weline_Product::commerce:catalog:quote-requests'", $controller);
    self::assertStringContainsString('function markProcessed', $controller);
    self::assertStringContainsString('标记已处理', (string) file_get_contents($template));
}

private function assert_ProductQuoteRequestNative_testPdpQuoteOnlyIsSelectableAndOwnsSubmitModal(): void
{
    $pdp = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('data-action="open-product-quote"', $pdp);
    self::assertStringContainsString('data-product-quote-modal', $pdp);
    self::assertStringContainsString('data-testid="product-submit-quote"', $pdp);
    // Fail-closed: quote CTA + modal only when $quoteOnly (no theatre for purchasable / digital packs).
    self::assertMatchesRegularExpression('/if \(\$quoteOnly\):\s*.*?data-testid="product-submit-quote"/s', $pdp);
    self::assertMatchesRegularExpression('/if \(\$quoteOnly\):\s*.*?data-testid="product-quote-modal"/s', $pdp);
    self::assertMatchesRegularExpression('/if \(\$quoteOnly\) \{\s*try \{\s*\$quoteCaptchaHtml = \(string\)ObjectManager::getInstance\(ProductQuoteRequestCaptchaGuard::class\)->renderChallenge\(\);/s', $pdp);
    self::assertStringContainsString('data-quote-submit-url', $pdp);
    self::assertStringContainsString('<w:theme:address', $pdp);
    self::assertStringContainsString('postal-lookup="true"', $pdp);
    self::assertStringContainsString('code="product-quote-address"', $pdp);
    self::assertStringNotContainsString('w:inquiry', $pdp);
    self::assertStringNotContainsString('Weline_Inquiry', $pdp);
    self::assertStringNotContainsString('<w:inquiry', $pdp);
    $quoteJs = (string) file_get_contents(BP . 'app/code/Weline/Product/view/statics/js/widgets/widget-product-info-1.js');
    self::assertStringContainsString("params.get('quote') === '1'", $quoteJs);
    self::assertStringContainsString("=== '#quote'", $quoteJs);
    self::assertStringContainsString('idempotencyKey', $quoteJs);
    // Deep-link open only when quote CTA is present (SSR already gated by $quoteOnly).
    self::assertStringContainsString('wantsQuote && !openButton.disabled && !openButton.hidden', $quoteJs);
    $infoJs = (string) file_get_contents(BP . 'app/code/Weline/Product/view/statics/js/widgets/widget-product-info-0.js');
    self::assertStringContainsString('function isOfferPurchasable', $infoJs);
    self::assertStringContainsString("params.set('quote', '1')", $infoJs);
    self::assertStringContainsString("toggle('is-disabled', !exists)", $infoJs);
    self::assertStringContainsString("toggle('is-out-of-stock', exists && !stocked && !quoteOnlyOption)", $infoJs);
    self::assertStringContainsString('syncQuoteButtonContext', $infoJs);
}

private function assert_ProductQuoteRequestNative_testCartAddToCartSkipsQuoteOnly(): void
{
    $cart = (string) file_get_contents(BP . 'app/code/Weline/Cart/view/templates/frontend/widgets/product-add-to-cart.phtml');
    self::assertStringContainsString('$quoteOnly = !empty($offer[\'quote_only\'])', $cart);
    self::assertStringContainsString('// Product owns the quote CTA', $cart);
    self::assertMatchesRegularExpression('/if \(\$quoteOnly\) \{\s*\/\/ Product owns the quote CTA[^\n]*\s*return;/s', $cart);
}

private function assert_ProductQuoteRequestNative_testModuleProvidesSubmitInterfaceAndOptionalCaptcha(): void
{
    $module = include BP . 'app/code/Weline/Product/etc/module.php';
    self::assertSame('1.0.393', $module['version'] ?? null);
    self::assertArrayHasKey(\Weline\Product\Api\ProductQuoteRequestSubmitInterface::class, $module['provides'] ?? []);
    self::assertSame('*', $module['optional']['Weline_Captcha'] ?? null);
    self::assertFileExists(BP . 'app/code/Weline/Product/doc/product-quote-request.md');
}

private function assert_ProductQuoteRequestStateMachine_testStatusesAndTransitionMap(): void
{
    self::assertContains(ProductQuoteRequest::STATUS_PROCESSING, ProductQuoteRequest::STATUSES);
    self::assertContains(ProductQuoteRequest::STATUS_REPLIED, ProductQuoteRequest::STATUSES);
    self::assertContains(ProductQuoteRequest::STATUS_CANCELLED, ProductQuoteRequest::STATUSES);
    $src = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteRequestStateMachine.php');
    self::assertStringContainsString('EVENT_CAN_TRANSITION', $src);
    self::assertStringContainsString('EVENT_CHANGE_BEFORE', $src);
    self::assertStringContainsString('EVENT_CHANGED', $src);
    self::assertStringContainsString('Weline_Product::quote_request_status_can_transition', $src);
    self::assertStringContainsString('Weline_Product::quote_request_status_change_before', $src);
    self::assertStringContainsString('Weline_Product::quote_request_status_changed', $src);
    self::assertStringContainsString('STATUS_REPLIED', $src);
    self::assertStringContainsString('ADMIN_REPLY_AT', $src);
}

private function assert_ProductQuoteRequestStateMachine_testEventsRegisteredInEventPhp(): void
{
    $events = include BP . 'app/code/Weline/Product/event.php';
    self::assertIsArray($events);
    foreach (['Weline_Product::quote_request_submitted', 'Weline_Product::quote_request_status_can_transition', 'Weline_Product::quote_request_status_change_before', 'Weline_Product::quote_request_status_changed'] as $name) {
        self::assertArrayHasKey($name, $events);
    }
    self::assertFileExists(BP . 'app/code/Weline/Product/doc/event/quote_request_status_changed.md');
}

private function assert_ProductQuoteRequestStateMachine_testServiceDelegatesMarkProcessedToStateMachine(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteRequestService.php');
    self::assertStringContainsString('ProductQuoteRequestStateMachine', $src);
    self::assertStringContainsString('transitionStatus', $src);
    self::assertStringContainsString('quote_request_submitted', $src);
    self::assertStringContainsString("STATUS_PROCESSED", $src);
}

private function assert_ProductQuoteRequestStateMachine_testAdminControllerUsesIndexTemplateAndTransitionAction(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php');
    self::assertStringContainsString("fetch('index')", $controller);
    self::assertStringContainsString('function transition', $controller);
    self::assertStringContainsString('transitionStatus', $controller);
    self::assertFileExists(BP . 'app/code/Weline/Product/view/templates/backend/QuoteRequest/index.phtml');
    $tpl = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/backend/QuoteRequest/index.phtml');
    self::assertStringContainsString('available_transitions', $tpl);
    self::assertStringContainsString('quote-request/transition', $tpl);
    self::assertStringContainsString('w:form', $tpl);
    self::assertStringContainsString('csrf="auto"', $tpl);
    self::assertStringNotContainsString('fetch(\'backend/quote-request/index\')', $controller);
}

private function assert_ProductQuoteRequestStateMachine_testAvailableTransitionsMap(): void
{
    $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
    $events = $this->createMock(\Weline\Framework\Event\EventsManager::class);
    $machine = new ProductQuoteRequestStateMachine($om, $events);
    self::assertSame([ProductQuoteRequest::STATUS_PROCESSING, ProductQuoteRequest::STATUS_REPLIED, ProductQuoteRequest::STATUS_PROCESSED, ProductQuoteRequest::STATUS_CANCELLED], $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_NEW));
    self::assertSame([ProductQuoteRequest::STATUS_REPLIED, ProductQuoteRequest::STATUS_PROCESSED, ProductQuoteRequest::STATUS_CANCELLED], $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_PROCESSING));
    self::assertSame([], $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_PROCESSED));
    self::assertSame([], $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_CANCELLED));
}

private function assert_ProductScopedOption_testMetadataCatalogEnsuresScopedOptionsApi(): void
{
    self::assertTrue(method_exists(ProductAttributeMetadataCatalog::class, 'ensureAndCanonicalizeVariantAxes'));
    self::assertTrue(method_exists(ProductAttributeMetadataCatalog::class, 'ensureVariantAxisOptions'));
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAttributeMetadataCatalog.php');
    self::assertIsString($source);
    self::assertStringContainsString('AttributeOptionStoreInterface', $source);
    self::assertStringContainsString('ensureInScope(', $source);
    self::assertStringContainsString('metadataIndex($productId)', $source);
}

private function assert_ProductScopedOption_testCommandServiceCreatesConfigurableAfterProductId(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAdminCommandService.php');
    self::assertIsString($source);
    self::assertStringContainsString('ensureAndCanonicalizeVariantAxes', $source);
    self::assertStringContainsString('canonicalizeOfferMatrix($matrixPayload, $productId)', $source);
    self::assertStringContainsString('normalizeRows($rows, $productId)', $source);
}

private function assert_ProductScopedOption_testSearchProjectionIndexesPrivateOptionLabels(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductSearchProjectionService.php');
    self::assertIsString($source);
    self::assertStringContainsString('collectPrivateOptionLabelsByProduct', $source);
    self::assertStringContainsString('Option::schema_fields_scope_instance_id', $source);
}

private function assert_ProductScopedOption_testBootstrapImportDoesNotPersistProductOptionsGlobally(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCatalogEavBootstrap.php');
    self::assertIsString($source);
    self::assertStringContainsString('bool $persistOptions = true', $source);
    self::assertStringContainsString('Option::schema_fields_scope_instance_id, Option::SCOPE_SHARED', $source);
    self::assertTrue(class_exists(ProductCatalogEavBootstrap::class));
}

private function assert_ProductScopedOption_testImportAllowsUnresolvedOptionsForPrivateEnsure(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/scripts/import-1688-hanfu-catalog.php');
    self::assertIsString($source);
    self::assertStringContainsString('Instance-private options are materialized during Product create/save', $source);
    self::assertStringContainsString('ensured as instance-private rows on Product create/save', $source);
}

private function assert_ProductScopedOption_testSingletonColorRemediationScriptExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/scripts/remediate-singleton-color-options-to-private.php';
    self::assertFileExists($path);
    $source = file_get_contents($path);
    self::assertIsString($source);
    self::assertStringContainsString('scope_instance_id', $source);
    self::assertStringContainsString('attribute_code', $source);
    self::assertStringContainsString('--dry-run', $source);
    self::assertStringContainsString('--apply', $source);
}

private function assert_ProductScopedOption_testSpecialAxisPrivatizationScriptExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/scripts/remediate-special-axis-options-to-private.php';
    self::assertFileExists($path);
    $source = file_get_contents($path);
    self::assertIsString($source);
    self::assertStringContainsString('character', $source);
    self::assertStringContainsString('look_ref', $source);
    self::assertStringContainsString('style_type', $source);
    self::assertStringContainsString('combination_key', $source);
    self::assertStringContainsString('scope_instance_id', $source);
    self::assertStringContainsString('--dry-run', $source);
    self::assertStringContainsString('--apply', $source);
}

private function assert_ProductScopedOption_testMisfiledColorToAxesScriptExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/scripts/remediate-misfiled-color-options-to-axes.php';
    self::assertFileExists($path);
    $source = file_get_contents($path);
    self::assertIsString($source);
    self::assertStringContainsString('ColorAxisClassifier', $source);
    self::assertStringContainsString("attrIds['prop']", $source);
    self::assertStringContainsString('combination_key', $source);
    self::assertStringContainsString('--dry-run', $source);
    self::assertStringContainsString('--apply', $source);
    self::assertStringContainsString('targetAxes', $source);
}

private function assert_ProductSearchProjectionTextMap_testCollectLocalizedAttributeTextsUsesTextMapAndBoundedChunk(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductSearchProjectionService.php');
    self::assertStringContainsString('listLocalizedTextMap(', $source);
    self::assertStringContainsString('boundedEntityChunkSize(', $source);
    self::assertStringNotContainsString('array_chunk($entityIds, 48)', $source);
    self::assertDoesNotMatchRegularExpression('/collectLocalizedAttributeTexts\([\s\S]*?listExplicitRows\(/', $source);
    self::assertSame(100, AttributeValueRepository::boundedEntityChunkSize(4));
}

private function assert_SearchProjectionScopeCacheWarmer_testModuleProvidesSearchProjectionScopeWarmer(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/etc/module.php';
    $provides = $module['provides'] ?? [];
    self::assertSame(SearchProjectionScopeCacheWarmer::class, $provides['cache.warmer.product.search_projection_scope'] ?? null);
}

private function assert_SearchProjectionScopeCacheWarmer_testWarmerTargetsProductPool(): void
{
    $warmer = new SearchProjectionScopeCacheWarmer();
    self::assertSame(StorefrontCatalogViewService::cachePool(), $warmer->getTargetPool());
    self::assertSame('product.search_projection_scope', $warmer->getName());
}

private function assert_SearchProjectionScopeCacheWarmer_testClearWarmsProductPoolWithoutProductFqcn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/Console/Cache/Clear.php');
    self::assertStringContainsString("warmUp('product')", $src);
    self::assertStringNotContainsString('SearchProjectionScopeCacheWarmer', $src);
}

private function assert_SearchProjectionScopeCacheWarmer_testBagSeederPrimesSearchProjectionScope(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontHotCacheBagSeeder.php');
    self::assertStringContainsString('primeSearchProjectionScope', $src);
    self::assertStringContainsString('product.search_projection_scope', $src);
    self::assertTrue(method_exists(StorefrontHotCacheBagSeeder::class, 'prime'));
}

private function assert_SearchProjectionScopeCacheWarmer_testWarmerDoesNotCallSnapshotWebsite(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/SearchProjectionScopeCacheWarmer.php');
    self::assertStringContainsString('snapshotScope', $src);
    self::assertStringNotContainsString('->snapshotWebsite(', $src);
    self::assertStringNotContainsString('::snapshotWebsite(', $src);
}

private function assert_ShippingProfileBinding_testOfferSchemaIncludesShippingProfileCode(): void
{
    self::assertSame('4.13.0', ProductShardSchemaCatalog::SCHEMA_VERSION);
    self::assertSame('shipping_profile_code', Offer::schema_fields_SHIPPING_PROFILE_CODE);
    self::assertSame('shipping_hazard_class', Offer::schema_fields_SHIPPING_HAZARD_CLASS);
    self::assertSame('is_free_shipping', Offer::schema_fields_IS_FREE_SHIPPING);
    self::assertSame('free_shipping_min_amount', Offer::schema_fields_FREE_SHIPPING_MIN_AMOUNT);
    $catalog = new ProductShardSchemaCatalog();
    $schemas = $catalog->schemasForShard('0');
    $offer = null;
    foreach ($schemas as $schema) {
        if (str_ends_with($schema->tableName, '_offer')) {
            $offer = $schema;
            break;
        }
    }
    self::assertNotNull($offer);
    $cols = array_map(static fn($c) => $c->name, $offer->columns);
    self::assertContains('shipping_profile_code', $cols);
    self::assertContains('shipping_hazard_class', $cols);
    self::assertContains('is_free_shipping', $cols);
    self::assertContains('free_shipping_min_amount', $cols);
}

private function assert_ShippingProfileBinding_testAdminAndPdpSurfacesExist(): void
{
    $create = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/backend/catalog/index.phtml');
    $edit = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/backend/catalog/edit.phtml');
    $pdp = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml');
    $cmd = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductAdminCommandService.php');
    $snap = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php');
    self::assertStringContainsString('product-create-shipping-profile', $create);
    self::assertStringContainsString('product-create-shipping-hazard', $create);
    self::assertStringContainsString('product-create-is-free-shipping', $create);
    self::assertStringContainsString('product-create-free-shipping-min-amount', $create);
    self::assertStringContainsString('配送方案', $create);
    self::assertStringContainsString('product-edit-shipping-profile', $edit);
    self::assertStringContainsString('product-edit-shipping-hazard', $edit);
    self::assertStringContainsString('product-edit-is-free-shipping', $edit);
    self::assertStringContainsString('product-edit-free-shipping-min-amount', $edit);
    self::assertStringContainsString('本品免邮额度', $edit);
    self::assertStringContainsString('product-shipping-hint', $pdp);
    self::assertStringContainsString('previewHint', $pdp);
    self::assertStringContainsString('WidgetI18n::label((string)$shippingHint[\'note\'])', $pdp);
    self::assertStringContainsString('WidgetI18n::label((string)$shippingHint[\'badge\'])', $pdp);
    self::assertStringContainsString('normalizeShippingProfileCode', $cmd);
    self::assertStringContainsString('normalizeShippingHazardClass', $cmd);
    self::assertStringContainsString('shipping_profile_code', $snap);
    self::assertStringContainsString('shipping_hazard_class', $snap);
    self::assertStringContainsString('is_free_shipping', $snap);
    self::assertStringContainsString('free_shipping_min_amount', $snap);
}

private function assert_ShippingProfileBinding_testSpiRegistryIsEmptySafe(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/Storefront/StorefrontShippingProfileCatalogProviderRegistry.php');
    self::assertStringContainsString('forTesting', $src);
    self::assertStringContainsString('?StorefrontShippingProfileCatalogProviderInterface', $src);
}

private function assert_StorefrontCardSurfaceProjection_testOfferRepositoryExposesCardColumnAllowlist(): void
{
    $fields = OfferRepository::storefrontCardOfferFields();
    self::assertContains('offer_id', $fields);
    self::assertContains('product_id', $fields);
    self::assertContains('global_offer_uuid', $fields);
    self::assertContains('sku', $fields);
    self::assertNotContains('type_config_json', $fields);
    self::assertNotContains('cas_token', $fields);
    $repoSource = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Repository/OfferRepository.php');
    self::assertStringContainsString('->fields(self::storefrontCardOfferFields())', $repoSource);
}

private function assert_StorefrontCardSurfaceProjection_testSummaryCacheGenerationIsCard3(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString("'summary-card3'", $source);
    self::assertStringContainsString('toCardSurfaceRow', $source);
    self::assertStringContainsString('// Cold path: page size tracks remaining need', $source);
}

private function assert_StorefrontCardSurfaceProjection_testListingSummaryDoesNotReattachDescriptionMeta(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductDetailProjector.php');
    $methodPos = strpos($source, 'function projectListingSummary');
    self::assertNotFalse($methodPos);
    $chunk = substr($source, $methodPos, 2200);
    self::assertStringContainsString('Cold card surface', $chunk);
    self::assertStringNotContainsString("'description' =>", $chunk);
    self::assertStringNotContainsString("'meta_description' =>", $chunk);
}

private function assert_StorefrontCardSurfaceProjection_testHomepageShelfPlanUsesBoundedPools(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('buildFeaturedCandidateCards(16)', $source);
    self::assertStringContainsString('buildDealCandidateCards(12)', $source);
    self::assertStringContainsString('bestSellerCards(16, false)', $source);
}

private function assert_StorefrontCatalogFilteredOffers_testPublishedOffersForProductIdsUsesFilteredBuildPath(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    $projectorSource = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductDetailProjector.php');
    self::assertStringContainsString('product.catalog.resolve_filtered', $source);
    self::assertStringContainsString('publishedFacetListingForProductIds', $source);
    self::assertStringContainsString('product.catalog.build_facet_listing', $source);
    self::assertStringContainsString('buildPublishedOffers(', $source);
    self::assertStringContainsString('$filterIds', $source);
    self::assertStringContainsString('$includeListingDetails', $source);
    self::assertStringContainsString('Targeted hydration must not rebuild', $source);
    self::assertStringContainsString('product.catalog.live_request', $source);
    self::assertStringContainsString("'product.live_offers'", $source);
    self::assertStringContainsString("'product.published_offers_by_slug'", $source);
    self::assertStringContainsString('projectMany(', $source);
    self::assertStringContainsString('product.catalog.projection.bulk', $source);
    self::assertStringNotContainsString('product.catalog.full_rows.request', $source);
    self::assertStringNotContainsString('product.catalog.filtered_offers.request', $source);
    self::assertStringNotContainsString('product.catalog.offers.request', $source);
    self::assertStringContainsString('catalogTargetedOffersPolicy()', $source);
    self::assertStringContainsString('catalogTargetedOffersLogicalKey', $source);
    self::assertStringContainsString('buildTargetedPublishedOffers(', $source);
    self::assertStringContainsString('product.catalog.targeted_reuse', $source);
    self::assertStringContainsString('peekPolicy(', $source);
    self::assertStringContainsString('sliceTargetedOffersFromWarmCatalog(', $source);
    self::assertStringContainsString('public function facetCountsForProductIds(', $source);
    self::assertStringNotContainsString('product.catalog.facet_counts.request', $source);
    self::assertStringContainsString('product.catalog.facet_counts', $source);
    self::assertStringContainsString('product.catalog.attribute_rows.request', $source);
    self::assertStringContainsString('CatalogOverlayResolver', $source);
    self::assertStringContainsString('rememberPolicy(', $source);
    self::assertStringContainsString('rememberForRequest(', $source);
    // Soft facet bags (!includeMedia) skip metadata prefetch — media+details only.
    self::assertStringContainsString('if ($includeListingDetails && $includeMedia && count($projectionProductIds) > 1)', $source);
    self::assertStringContainsString('public function projectMany(', $projectorSource);
    // Summary listings seed null; full listings load shard attributes once via request memo.
    self::assertStringContainsString('$attributeRows = null;', $source);
    self::assertMatchesRegularExpression('/\$attributeRows = null;\s*if \(\$includeListingDetails\) \{\s*\$attributeStartedAt = hrtime\(true\);.*?\$attributeRows = \$this->requestAttributeRows\(/s', $source);
    self::assertStringContainsString('$attributeCodes = (!$includeMedia) ? $this->resolveShopperFacetAttributeCodes() : null;', $source);
    self::assertStringNotContainsString("\$attributeRows = [];\n        if (\$includeListingDetails) {", $source);
    $snapshotSource = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php');
    self::assertStringContainsString('if ($attributeRows === []) {', $snapshotSource);
    self::assertStringContainsString('$attributeRows = null;', $snapshotSource);
    $filteredBlockStart = strpos($source, 'if ($filterIds !== [])');
    self::assertNotFalse($filteredBlockStart);
    $returnPos = strpos($source, 'return \array_slice($rows, 0, $limit);', (int) $filteredBlockStart);
    self::assertNotFalse($returnPos);
    $filteredBlock = substr($source, (int) $filteredBlockStart, (int) $returnPos - (int) $filteredBlockStart + 40);
    self::assertStringContainsString('buildTargetedPublishedOffers', $filteredBlock);
    self::assertStringContainsString('product.catalog.resolve_filtered', $filteredBlock);
    self::assertStringNotContainsString('rememberPublishedOffers()', $filteredBlock);
}

private function assert_StorefrontCatalogFilteredOffers_testTargetedProjectionUsesChannelPolicyAndOrderIndependentKey(): void
{
    $policy = StorefrontCatalogCacheCoordinator::catalogTargetedOffersPolicy();
    self::assertSame('product.catalog_offers_targeted', $policy->resource);
    self::assertSame('channel', $policy->scope);
    self::assertSame(['currency', 'lang'], $policy->vary);
    self::assertSame(['catalog', 'config', 'global/i18n', 'price'], $policy->dependencies);
    self::assertSame(1200, $policy->singleFlightWaitMs);
    $service = (new \ReflectionClass(StorefrontCatalogCacheCoordinator::class))->newInstanceWithoutConstructor();
    $first = $service->catalogTargetedOffersLogicalKey(0, [3, 1, 2], false);
    $second = $service->catalogTargetedOffersLogicalKey(0, [2, 3, 1], false);
    $full = $service->catalogTargetedOffersLogicalKey(0, [1, 2, 3], true);
    self::assertSame($first, $second);
    self::assertNotSame($first, $full);
    self::assertStringStartsWith('product.catalog_offers.targeted.v2.0.summary.', $first);
}

private function assert_StorefrontCatalogFilteredOffers_testBoundedSummaryProjectionUsesDedicatedPolicyAndBuildLimit(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString('public function publishedOfferSummaries(', $source);
    self::assertStringContainsString('catalogSummaryOffersPolicy()', $source);
    self::assertStringContainsString('catalogSummaryOffersLogicalKey', $source);
    self::assertStringContainsString("'product.catalog.build_summary'", $source);
    self::assertStringContainsString('$maxRows', $source);
    self::assertStringContainsString('if ($maxRows !== null && count($candidateOffers) >= $maxRows)', $source);
    $coordinator = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogCacheCoordinator.php');
    self::assertStringContainsString('public static function catalogSummaryOffersPolicy()', $coordinator);
    self::assertStringContainsString('public static function catalogSummaryOffersLogicalKey(', $coordinator);
    self::assertStringContainsString('catalogSummaryOffersLogicalKey($websiteId, 48)', $coordinator);
}

private function assert_StorefrontCatalogProductVisibility_testProductIdGateUsesCatalogPublishedOffers(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogProductVisibility.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function filterSellableProductIds(', $source);
    self::assertStringContainsString('function isProductSellable(', $source);
    self::assertStringContainsString('publishedOffersForProductIds(', $source);
    self::assertStringContainsString('StorefrontCatalogVisibilityFilterInterface', $source);
}

private function assert_StorefrontCatalogShellUrlBuilder_testFixedShellsExcludeSearchAndIncludeListingRails(): void
{
    $fixed = StorefrontCatalogShellUrlBuilder::FIXED_SHELL_PATHS;
    self::assertContains('/', $fixed);
    self::assertContains('/products', $fixed);
    self::assertContains('/new-arrivals', $fixed);
    self::assertContains('/best-sellers', $fixed);
    self::assertNotContains('/search', $fixed);
}

private function assert_StorefrontCatalogShellUrlBuilder_testCoordinatorUsesShellBuilderForListingImpact(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductSearchProjectionMutationCoordinator.php');
    self::assertStringContainsString('StorefrontCatalogShellUrlBuilder', $src);
    self::assertStringContainsString('relativePathsForProduct', $src);
    self::assertStringContainsString('notifyCatalogChanged', $src);
    self::assertStringContainsString('afterCommit', $src);
    self::assertStringNotContainsString("listing' => ['/', '/products']", $src);
    self::assertStringNotContainsString('ProductStorefrontCacheInvalidator', $src);
    self::assertSame('product_search_projection', ProductSearchProjectionMutationCoordinator::RESOURCE_TYPE);
}

private function assert_StorefrontCatalogShellUrlBuilder_testInvalidatorNeverCallsPurgeAll(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductStorefrontCacheInvalidator.php');
    self::assertStringContainsString('function purgeForUrls', $src);
    self::assertStringContainsString('FullPageCacheCoordinator::purgeUrls', $src);
    self::assertStringContainsString('absoluteFixedShellsForAllWebsites', $src);
    self::assertStringNotContainsString('FpcHtmlDiskStore::purgeAll', $src);
    self::assertStringNotContainsString('::purgeAll(', $src);
    self::assertStringNotContainsString('rotateToEmptyGeneration', $src);
    self::assertTrue(method_exists(ProductStorefrontCacheInvalidator::class, 'purgeForUrls'));
    self::assertTrue(method_exists(ProductStorefrontCacheInvalidator::class, 'clearForCatalogChange'));
}

private function assert_StorefrontCatalogShellUrlBuilder_testSitemapDelegatesGlobalListingToShellBuilder(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductSitemapUrlService.php');
    self::assertStringContainsString('StorefrontCatalogShellUrlBuilder', $src);
    self::assertStringContainsString('absoluteListingUrlsForGlobalProduct', $src);
}

private function assert_StorefrontCatalogSummaryBagReuse_testPublishedOfferSummariesStoresAndReusesLargerSameRequestBag(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString('CANONICAL_SUMMARY_LIMIT = 48', $source);
    self::assertStringNotContainsString('REQUEST_SUMMARY_BAG_KEY', $source);
    self::assertStringNotContainsString('product.catalog.summary.bag.request', $source);
    self::assertStringNotContainsString('product.catalog.full_rows.request', $source);
    self::assertMatchesRegularExpression('/function publishedOfferSummaries[\s\S]*rememberPolicy\(/', $source);
    self::assertMatchesRegularExpression('/\$buildLimit = \$limit <= self::CANONICAL_SUMMARY_LIMIT/', $source);
}

private function assert_StorefrontCatalogSummaryBagReuse_testCanonicalSummaryLogicalKeyDropsLimitForShelfWindows(): void
{
    $k24 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 24);
    $k32 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 32);
    $k48 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 48);
    $k64 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 64);
    self::assertSame($k24, $k32);
    self::assertSame($k32, $k48);
    self::assertStringContainsString('summary.v4.', $k48);
    self::assertStringNotContainsString('.48', $k48);
    self::assertNotSame($k48, $k64);
    self::assertSame(48, StorefrontCatalogViewService::CANONICAL_SUMMARY_LIMIT);
}

private function assert_StorefrontCatalogSummaryBagReuse_testHomepageShelfPlanPrefetchesCanonicalSummaryBeforePools(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertMatchesRegularExpression('/function \(\): array \{[\s\S]*publishedOfferSummaries\(48\)[\s\S]*buildFeaturedCandidateCards\(16\)/', $source);
}

private function assert_StorefrontCatalogSummaryBagReuse_testSummarySkeletonIsWebsiteScopedSharedAcrossCurrencies(): void
{
    $policy = StorefrontCatalogCacheCoordinator::catalogSummarySkeletonPolicy();
    self::assertSame('product.catalog_offers_summary_skeleton', $policy->resource);
    self::assertSame('website', $policy->scope);
    self::assertSame([], $policy->vary);
    self::assertGreaterThanOrEqual(5000, $policy->singleFlightWaitMs);
    $k48 = StorefrontCatalogCacheCoordinator::catalogSummarySkeletonLogicalKey(0, 48);
    $k24 = StorefrontCatalogCacheCoordinator::catalogSummarySkeletonLogicalKey(0, 24);
    self::assertSame($k48, $k24);
    self::assertStringContainsString('summary_skeleton.v1.', $k48);
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString('buildPublishedOfferSummariesFromSkeleton', $source);
    self::assertStringContainsString('rememberSummarySkeletonOffers', $source);
    self::assertStringContainsString('product.catalog.build_summary_skeleton', $source);
}

private function assert_StorefrontCatalogSummaryBagReuse_testSummaryLangSurfaceSharedAcrossCurrenciesForSameLang(): void
{
    $policy = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfacePolicy();
    self::assertSame('product.catalog_offers_summary_lang', $policy->resource);
    self::assertSame('website', $policy->scope);
    self::assertEqualsCanonicalizing(['lang'], $policy->vary);
    $a = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfaceLogicalKey(0, 'es_MX', 24);
    $b = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfaceLogicalKey(0, 'es_MX', 48);
    $c = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfaceLogicalKey(0, 'en_US', 48);
    self::assertSame($a, $b);
    self::assertNotSame($b, $c);
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString('build_summary_lang_surface', $source);
    self::assertStringContainsString('applyStorefrontPricing = true', $source);
    self::assertStringContainsString('RequestContext::getWelineUserCurrency()', $source);
}

private function assert_StorefrontCatalogVisibilityFilter_testViewServiceRoutesAllPublicExitsThroughUnifiedPostCacheFilters(): void
{
    $service = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php';
    $interface = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Api/StorefrontCatalogVisibilityFilterInterface.php';
    self::assertFileExists($service);
    self::assertFileExists($interface);
    $source = (string) file_get_contents($service);
    $api = (string) file_get_contents($interface);
    self::assertStringContainsString('filterSellableOfferIds', $api);
    self::assertStringContainsString('isOfferSellable', $api);
    self::assertStringContainsString('function applyPostCacheOfferFilters', $source);
    self::assertStringContainsString('function applyCatalogVisibilityOfferFilter', $source);
    self::assertStringContainsString('applyPostCacheOfferFilters(', $source);
    self::assertGreaterThanOrEqual(4, substr_count($source, 'applyPostCacheOfferFilters('), 'Expected multiple public exits to share the unified post-cache filter');
    self::assertMatchesRegularExpression('/function publishedListingCandidates[\s\S]*applyPostCacheOfferFilters\(/', $source);
    self::assertMatchesRegularExpression('/function livePublishedOffersForProductIds[\s\S]*applyPostCacheOfferFilters\(/', $source);
    self::assertMatchesRegularExpression('/function livePublishedOffersForProduct\([\s\S]*applyPostCacheOfferFilters\(/', $source);
    self::assertMatchesRegularExpression('/function publishedOfferSummaries[\s\S]*applyPostCacheOfferFilters\(/', $source, 'Summaries must still apply post-cache visibility filters');
    self::assertStringNotContainsString('REQUEST_FULL_ROWS_KEY', $source);
}

private function assert_StorefrontCategoryTreeIndexLocalizedName_testForWebsiteAppliesEavLocalizedNamesAfterCacheLoad(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCategoryTreeIndex.php');
    self::assertStringContainsString('ProductCategoryAttributeService', $source);
    self::assertStringContainsString('applyLocalizedNames', $source);
    self::assertStringContainsString('State::getLangLocal()', $source);
    self::assertStringContainsString('rememberForRequest', $source);
    self::assertStringContainsString("'storefront.category_tree.urls'", $source);
    self::assertStringContainsString("['categories' => \\count(\$index['by_id'])]", $source);
    self::assertStringContainsString('categoryUrlWithoutLookup', $source);
    self::assertStringNotContainsString('function categoryUrl(string $path)', $source);
    self::assertStringNotContainsString('getFrontendUrl(', $source);
}

private function assert_StorefrontCategoryViewService_testCategoryPageCollectsLinksFromCategoryDescendantsInOneBatch(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCategoryViewService.php');
    self::assertStringContainsString('collectCategoryProductIds', $source);
    self::assertStringContainsString('$this->tree->forWebsite($websiteId)', $source);
    self::assertStringContainsString('$this->categoryLinks->listByCategoryIds($websiteId, $categoryIds)', $source);
    self::assertStringContainsString('$queue = [$categoryId];', $source);
    self::assertStringContainsString("'product_ids' => \$productIds", $source);
}

private function assert_StorefrontGroceryCatalogSeed_testProductModuleNoLongerHostsGrocerySeedOpeners(): void
{
    $productRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    self::assertFileDoesNotExist($productRoot . '/data/seed-grocery-categories.php');
    self::assertFileDoesNotExist($productRoot . '/scripts/seed-grocery-catalog.php');
    self::assertFileDoesNotExist($productRoot . '/Service/StorefrontGroceryCatalogSeeder.php');
}

private function assert_StorefrontGroceryCatalogSeed_testDesignThemeCategorySeedRefusesDefaultAndRequiresGroceryCode(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/grocery/data/seed-categories.php');
    self::assertStringContainsString("Refuse: Grocery seed must target website code grocery", $source);
    self::assertStringContainsString("Refuse: Grocery seed only accepts code=grocery", $source);
    self::assertStringContainsString('grocery-produce', $source);
    self::assertStringContainsString('deactivateForeignCategories', $source);
    self::assertStringContainsString('Website::ID_DEFAULT', $source);
}

private function assert_StorefrontGroceryCatalogSeed_testDesignThemeCatalogSeederRefusesDefaultWebsiteAndUsesGrocerySkus(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/grocery/scripts/GroceryCatalogSeeder.php');
    self::assertStringContainsString('namespace Weline\Design\Grocery', $source);
    self::assertStringContainsString('class GroceryCatalogSeeder', $source);
    self::assertStringContainsString('Grocery catalog seed refuses website_id=0', $source);
    self::assertStringContainsString('GROCERY-TOMATO-500G', $source);
    self::assertStringContainsString('grocery-produce', $source);
    self::assertStringContainsString('notifyCatalogChanged', $source);
    self::assertStringContainsString('disableForeignPublishedProducts', $source);
    self::assertStringContainsString("str_starts_with(\$sku, 'GROCERY-')", $source);
    self::assertStringContainsString("str_starts_with(\$sku, 'DS-CJ')", $source);
    self::assertStringNotContainsString('is_demo', $source);
}

private function assert_StorefrontGroceryCatalogSeed_testDesignThemeCliSeedScriptOnlyAcceptsGrocery(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/grocery/scripts/seed-catalog.php');
    self::assertStringContainsString("only website code grocery is accepted", $source);
    self::assertStringContainsString('GroceryCatalogSeeder', $source);
    self::assertStringContainsString('seed-categories.php', $source);
    self::assertStringContainsString('app/design/Weline/grocery/scripts/seed-catalog.php', $source);
}

private function assert_StorefrontListingPagerLocale_testPagerKeepsChineseSourceWithoutControllerTranslate(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontListingPager.php');
    self::assertStringContainsString("'label' => '上一页'", $source);
    self::assertStringContainsString("'label' => '下一页'", $source);
    self::assertStringNotContainsString("__('上一页')", $source);
    self::assertStringNotContainsString("__('下一页')", $source);
    self::assertStringNotContainsString('use Weline\Theme\Helper\WidgetI18n;', $source);
}

private function assert_StorefrontListingPagerLocale_testPagerPrevNextLabelsAreChineseSource(): void
{
    $pager = new StorefrontListingPager();
    $options = $pager->buildPageOptions('/products', 2, 5, [], 5, true);
    self::assertSame('prev', $options[0]['type']);
    self::assertSame('上一页', $options[0]['label']);
    $last = $options[array_key_last($options)];
    self::assertSame('next', $last['type']);
    self::assertSame('下一页', $last['label']);
}

private function assert_StorefrontListingPagerLocale_testCategoryAndCatalogTemplatesReResolvePrevNextAtRender(): void
{
    $category = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/category/index.phtml');
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/catalog/index.phtml');
    foreach ([$category, $catalog] as $source) {
        self::assertStringContainsString('use Weline\Theme\Helper\WidgetI18n;', $source);
        self::assertStringContainsString("WidgetI18n::label('上一页')", $source);
        self::assertStringContainsString("WidgetI18n::label('下一页')", $source);
    }
}

private function assert_StorefrontListingShellPathOnly_testShellOwnsSharedFilterPipelineAndIgnoresVisitorQueryOnNeutralApi(): void
{
    $shell = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontListingShell.php');
    self::assertStringContainsString('function projectNeutral(', $shell);
    self::assertStringContainsString('function projectFiltered(', $shell);
    self::assertStringContainsString('Weline_Product::storefront_offers_filter', $shell);
    self::assertStringContainsString('normalizeQuery(', $shell);
    self::assertStringContainsString("str_starts_with(\$key, 'af_')", $shell);
    // Soft PLP: page bag presentation-complete before projectFromOffers;
    // releaseCallerCatalog may stay true while hydrateMedia is true.
    self::assertStringContainsString('hydrateListingCardPresentation', $shell);
    self::assertStringContainsString('?bool $releaseCallerCatalog = null', $shell);
    self::assertStringContainsString('releaseCallerCatalog: $releaseCallerCatalog ?? !$hydrateMedia', $shell);
}

private function assert_StorefrontListingShellPathOnly_testCatalogPresentationHydrateMergesSummaryNamesAfterMedia(): void
{
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString('function hydrateListingCardPresentation(', $catalog);
    self::assertStringContainsString('hydrateListingMedia($offers)', $catalog);
    self::assertStringContainsString('publishedOffersForProductIds(', $catalog);
    self::assertStringContainsString('product.catalog.listing_card_names', $catalog);
    // Must not invent a second FE card renderer — shell still uses ProductCardRenderer.
    $shell = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontListingShell.php');
    self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $shell);
}

private function assert_StorefrontListingShellPathOnly_testCatalogAndCategoryControllersDelegateToShellNeutral(): void
{
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Catalog.php');
    $category = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Category.php');
    self::assertStringContainsString('StorefrontListingShell', $catalog);
    self::assertStringContainsString('projectNeutral(', $catalog);
    self::assertStringContainsString('projectFiltered(', $catalog);
    self::assertStringContainsString('StorefrontListingFpcQueryKeys', $catalog);
    self::assertStringContainsString('StorefrontListingShell', $category);
    self::assertStringContainsString('projectNeutral(', $category);
    self::assertStringContainsString('projectFiltered(', $category);
    self::assertStringContainsString('StorefrontListingFpcQueryKeys', $category);
    $csv = \Weline\Product\Service\StorefrontListingFpcQueryKeys::csv();
    self::assertStringContainsString('query_keys=' . $csv, $catalog);
    self::assertStringContainsString('query_keys=' . $csv, $category);
    self::assertLessThanOrEqual(
        \Weline\Framework\Extends\Module\Weline_Framework\Extra\Type\FpcExtraType::MAX_QUERY_KEYS,
        \count(\Weline\Product\Service\StorefrontListingFpcQueryKeys::all()),
    );
    self::assertStringNotContainsString("str_starts_with(\$queryKey, 'af_')", $catalog);
    self::assertStringNotContainsString("str_starts_with(\$queryKey, 'af_')", $category);
}

private function assert_StorefrontListingShellPathOnly_testListingTemplatesBindJsModuleWithoutFullPageSortNav(): void
{
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/catalog/index.phtml');
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/frontend/weline.modules.js');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/storefront-listing.js');
    self::assertStringContainsString('data-weline-load="storefrontListing"', $catalog);
    self::assertStringContainsString('storefrontListing', $modules);
    self::assertStringContainsString('filteredListingPage', $js);
    self::assertStringContainsString('product_storefront', $js);
    self::assertStringContainsString('DEBOUNCE_MS', $js);
    self::assertStringContainsString('scheduleRefresh', $js);
    self::assertStringContainsString('is-listing-loading', $js);
    self::assertStringContainsString('data-listing-loading', $js);
    self::assertStringContainsString('committed', $js);
    self::assertStringContainsString('revertToCommitted', $js);
    self::assertStringContainsString('failStreak', $js);
    self::assertStringContainsString('paintFacetCounts', $js);
    self::assertStringContainsString('facet_counts', $js);
    self::assertStringContainsString('hasSoftListingQuery', $js);
    self::assertStringContainsString('hydrateStateFromLocation', $js);
    self::assertStringContainsString("data-listing-ssr-filtered') === '1'", $js);
    self::assertStringContainsString('data-listing-ssr-filtered', $catalog);
    $categoryTpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/category/index.phtml');
    self::assertStringContainsString('data-listing-ssr-filtered', $categoryTpl);
    $hanfuCategoryTpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/design/Weline/hanfu/Weline_Product/templates/frontend/category/index.phtml');
    self::assertStringContainsString('data-listing-ssr-filtered', $hanfuCategoryTpl);
    $categoryCtrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Category.php');
    $catalogCtrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Catalog.php');
    self::assertStringContainsString('storefront_listing_ssr_filtered', $categoryCtrl);
    self::assertStringContainsString('storefront_listing_ssr_filtered', $catalogCtrl);
    self::assertStringContainsString('data-listing-loading-label', $catalog);
    self::assertStringNotContainsString('window.location.href', $catalog);
}

private function assert_StorefrontListingShellPathOnly_testListingJsPaintsFacetCountsWithoutTouchingWelineApi(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/storefront-listing.js');
    $api = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline-api.js');
    self::assertStringContainsString('function paintFacetCounts', $js);
    self::assertStringContainsString('payload.facet_counts', $js);
    self::assertStringContainsString('[data-listing-filter="price"][data-filter-value]', $js);
    self::assertStringContainsString('[data-listing-filter="attr"][data-filter-code][data-filter-value]', $js);
    self::assertStringContainsString('.w-filters__count', $js);
    self::assertStringContainsString('row.hidden = count === 0 && !selected', $js);
    // FE-1 R3: omitted slim options → count 0 (never early-return leaving SSR residuals).
    self::assertSame(2, substr_count($js, 'item ? item.count : 0'));
    self::assertStringContainsString('Array.isArray(attrs)', $js);
    self::assertStringContainsString('committedFacetCounts', $js);
    self::assertStringContainsString('resetFacetVisibilitySsrNeutral', $js);
    self::assertStringContainsString('paintFacetCounts(state.committedFacetCounts, state)', $js);
    self::assertStringContainsString('compactVisibleAttributeFacets', $js);
    self::assertStringContainsString('FACET_PRIMARY_LIMIT = 6', $js);
    self::assertStringContainsString('w-filters__list--more', $js);
    self::assertStringContainsString('w-filters__more', $js);
    self::assertStringNotContainsString("if (!item) {\n                    return;\n                }", $js);
    self::assertStringNotContainsString("if (!item) {\n                        return;\n                    }", $js);
    self::assertStringNotContainsString('facet_counts', $api);
    self::assertStringNotContainsString('paintFacetCounts', $api);
    self::assertStringNotContainsString('refinement', $api);
}

private ?Context $previousContext_StorefrontPdpShelfDeferral = null;

private function setUp_StorefrontPdpShelfDeferral(): void
{
    $this->previousContext_StorefrontPdpShelfDeferral = Context::getCurrent();
    Context::enter(new Context());
}

private function tearDown_StorefrontPdpShelfDeferral(): void
{
    Context::leave();
    if ($this->previousContext_StorefrontPdpShelfDeferral !== null) {
        Context::enter($this->previousContext_StorefrontPdpShelfDeferral);
    }
}

private function assert_StorefrontPdpShelfDeferral_testEnableGatesDeferralOnlyWhenNotPreview(): void
{
    self::assertFalse(StorefrontPdpShelfDeferral::isEnabled());
    self::assertFalse(StorefrontPdpShelfDeferral::shouldDeferCardAssembly(false));
    StorefrontPdpShelfDeferral::enableForRequest();
    self::assertTrue(StorefrontPdpShelfDeferral::isEnabled());
    self::assertTrue(RequestContext::get(StorefrontPdpShelfDeferral::BAG_KEY));
    self::assertTrue(StorefrontPdpShelfDeferral::shouldDeferCardAssembly(false));
    self::assertFalse(StorefrontPdpShelfDeferral::shouldDeferCardAssembly(true));
}

private function assert_StorefrontPdpShelfDeferral_testDetailControllerEnablesDeferralAfterSeedRemember(): void
{
    $detail = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Detail.php');
    self::assertStringContainsString('StorefrontPdpShelfDeferral::enableForRequest()', $detail);
    self::assertStringContainsString('StorefrontOfferResolver::rememberResolvedOffer($displayOffer)', $detail);
    $rememberPos = strpos($detail, 'StorefrontOfferResolver::rememberResolvedOffer($displayOffer)');
    $deferPos = strpos($detail, 'StorefrontPdpShelfDeferral::enableForRequest()');
    self::assertNotFalse($rememberPos);
    self::assertNotFalse($deferPos);
    self::assertGreaterThan($rememberPos, $deferPos);
}

private function assert_StorefrontPdpShelfDeferral_testShelfTemplatesEmitHydrateShellWhenDeferred(): void
{
    $crossSell = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/cross-sell.phtml');
    self::assertStringContainsString('StorefrontPdpShelfDeferral::shouldDeferCardAssembly', $crossSell);
    self::assertStringContainsString('data-testid="cross-sell-deferred"', $crossSell);
    self::assertStringContainsString('data-hydrate-operation="bundleCards"', $crossSell);
    self::assertStringContainsString('data-weline-hydrate="1"', $crossSell);
    self::assertStringContainsString('if (count($products) < 2) {', $crossSell);
    self::assertStringContainsString('data-testid="cross-sell-empty"', $crossSell);
    $yml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/you-may-like.phtml');
    self::assertStringContainsString('data-testid="you-may-like-deferred"', $yml);
    self::assertStringContainsString('data-hydrate-operation="youMayLikeCards"', $yml);
    self::assertStringContainsString('youMayLikeCards', $yml);
}

private function assert_StorefrontProductWidgetCatalogReuse_testNewArrivalSharedKeysSeparateCutoffDatesAndCandidatePages(): void
{
    try {
        $policy = StorefrontCatalogCacheCoordinator::newArrivalCandidatesPolicy();
        $context = new \Weline\Framework\Cache\StorefrontCacheKeyContext(\Weline\Framework\Runtime\ScopeIdentity::channel(0, 'default', 'fixture', 'web', 'normal'), 'en_US', 'USD', hash('sha256', 'catalog'), hash('sha256', 'context'), true);
        $key = static fn(string $cutoff, int $offset): string => \Weline\Framework\Cache\KeyBuilder::policyKey($policy, StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, $cutoff, 48, $offset), 'same-catalog-generation', $context);
        self::assertNotSame($key('2026-08-08 00:00:00', 0), $key('2026-08-09 00:00:00', 0));
        self::assertNotSame($key('2026-08-09 00:00:00', 0), $key('2026-08-09 00:00:00', 48));
    } catch (\Throwable $error) {
        self::fail('New-arrival dates and pages must have distinct shared identities: ' . $error->getMessage());
    }
}

private function assert_StorefrontProductWidgetCatalogReuse_testCardsPreferSummaryOffersWhenNotListingProjection(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('publishedOfferSummaries($fetchLimit)', $source);
    self::assertStringContainsString('listRecentPublishedCreatedAt', $source);
    self::assertStringContainsString('newArrivalCandidatesPolicy', $source);
    self::assertStringContainsString('publishedOffersForProductIds([$seedProductId], 4, false)', $source);
    self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
    self::assertStringNotContainsString('listAll($websiteId)', $source);
}

private function assert_StorefrontProductWidgetCatalogReuse_testCoordinatorExposesNewArrivalAndPresentationPolicies(): void
{
    $presentation = StorefrontCatalogCacheCoordinator::categoryLocalizedPresentationPolicy();
    $candidates = StorefrontCatalogCacheCoordinator::newArrivalCandidatesPolicy();
    self::assertSame('product.category_presentation', $presentation->resource);
    self::assertSame('website', $presentation->scope);
    self::assertContains('lang', $presentation->vary);
    self::assertSame('product.new_arrival_candidates', $candidates->resource);
    self::assertSame('website', $candidates->scope);
}

private function assert_StorefrontProductWidgetCatalogReuse_testHomepageShelfPlanUsesSharedHotCacheNotRequestOnly(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('homepageShelfPlanPolicy', $source);
    self::assertStringContainsString('homepageShelfPlanLogicalKey', $source);
    self::assertStringContainsString('rememberPolicy(', $source);
    $policy = StorefrontCatalogCacheCoordinator::homepageShelfPlanPolicy();
    self::assertSame('product.homepage_shelf_id_plan', $policy->resource);
    self::assertSame('website', $policy->scope);
    self::assertSame([], $policy->vary);
    self::assertGreaterThanOrEqual(5000, $policy->singleFlightWaitMs);
    self::assertSame('product.homepage.shelf_id_plan.v2.0', StorefrontCatalogCacheCoordinator::homepageShelfPlanLogicalKey(0));
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('hydrateShelfCardsFromSummaries', $source);
    self::assertStringContainsString('shelfProductIds', $source);
}

private function assert_StorefrontProductWidgetCatalogReuse_testNewArrivalCardsReuseRequestBagAndCanonicalCandidatePage(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('NEW_ARRIVAL_CARDS_REQUEST_KEY', $source);
    self::assertStringContainsString('finishNewArrivalCards', $source);
    self::assertStringContainsString('CANONICAL_NEW_ARRIVAL_PAGE', $source);
    $summary = StorefrontCatalogCacheCoordinator::catalogSummaryOffersPolicy();
    self::assertGreaterThanOrEqual(3000, $summary->singleFlightWaitMs);
    $candidates = StorefrontCatalogCacheCoordinator::newArrivalCandidatesPolicy();
    self::assertGreaterThanOrEqual(3000, $candidates->singleFlightWaitMs);
    $k16 = StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, '2026-01-01 00:00:00', 16, 0);
    $k64 = StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, '2026-01-01 00:00:00', 64, 0);
    $k128 = StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, '2026-01-01 00:00:00', 128, 0);
    self::assertSame($k16, $k64);
    self::assertNotSame($k64, $k128);
    self::assertSame(64, StorefrontCatalogCacheCoordinator::CANONICAL_NEW_ARRIVAL_PAGE);
}

private function assert_StorefrontProductWidgetCatalogReuse_testRepositoryExposesBoundedRecentPublishedApi(): void
{
    self::assertTrue(method_exists(ProductRepository::class, 'listRecentPublishedCreatedAt'));
}

private function assert_StorefrontProductWidgetCatalogReuse_testOfferResolverReadsAssignedOfferThenContextIdentity(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Helper/StorefrontOfferResolver.php');
    self::assertStringContainsString("getData('storefront_offer')", $source);
    self::assertStringContainsString('Context::current()', $source);
    self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
    self::assertTrue(class_exists(StorefrontOfferResolver::class));
    self::assertTrue(class_exists(StorefrontProductWidgetCatalog::class));
}
}
