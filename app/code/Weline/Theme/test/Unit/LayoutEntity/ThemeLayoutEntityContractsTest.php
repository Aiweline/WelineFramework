<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Observer\ControllerFetchFileAfter;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\LayoutEntity\RequiredFilterSlotFallbackInjector;
use Weline\Theme\Service\LayoutEntity\StorefrontFloatLayerHost;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityDocumentShellBaker;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPageInputDigest;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityRequestSolidifyGate;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyCompilePipeline;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyLeaseStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyQueue;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifySerialKey;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyJobStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyStampStore;
use Weline\Theme\Service\LayoutEntity\WidgetAssetArtifactPublisher;
use Weline\Theme\Service\LayoutEntity\WidgetAssetOptimizer;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService;

/**
 * Aggregated contract suite (merged from 38 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeLayoutEntityContractsPass().
 */
final class ThemeLayoutEntityContractsTest extends TestCase
{
    public function testAllThemeLayoutEntityContractsPass(): void
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
            'CatalogLayoutSkeletonSolidify::testDocumentShellTrailingKeepsProductsLayoutStyles' => function () {
            $this->assert_CatalogLayoutSkeletonSolidify_testDocumentShellTrailingKeepsProductsLayoutStyles();
        },
            'CatalogLayoutSkeletonSolidify::testBakeProductsLayoutPostambleIncludesGridCss' => function () {
            $this->assert_CatalogLayoutSkeletonSolidify_testBakeProductsLayoutPostambleIncludesGridCss();
        },
            'CheckoutRequiredDefaultInjectionBake::testCheckoutIndexCompileInjectsRequiredWidgetsIntoSlots' => function () {
            $this->assert_CheckoutRequiredDefaultInjectionBake_testCheckoutIndexCompileInjectsRequiredWidgetsIntoSlots();
        },
            'DocumentShellBakedIntoShell::testBakeFromHomepageFixtureProducesSingleDocumentRoot' => function () {
            $this->assert_DocumentShellBakedIntoShell_testBakeFromHomepageFixtureProducesSingleDocumentRoot();
        },
            'DocumentShellBakedIntoShell::testBakeFromDefaultLayoutAndProductsLayout' => function () {
            $this->assert_DocumentShellBakedIntoShell_testBakeFromDefaultLayoutAndProductsLayout();
        },
            'DocumentShellBakedIntoShell::testAlgoVersionConstantStableForDigest' => function () {
            $this->assert_DocumentShellBakedIntoShell_testAlgoVersionConstantStableForDigest();
        },
            'EnsurePublishedChromeForScope::testLegacyMigrateBindingsCommandRemoved' => function () {
            $this->assert_EnsurePublishedChromeForScope_testLegacyMigrateBindingsCommandRemoved();
        },
            'EnsurePublishedChromeForScope::testEnsureCurrentPromotesOrphanScopeVersions' => function () {
            $this->assert_EnsurePublishedChromeForScope_testEnsureCurrentPromotesOrphanScopeVersions();
        },
            'InheritedLayoutRequiredInjection::testResolverInheritsAncestorSolidifyOnlyWhenChildLacksLayoutOverride' => function () {
            $this->assert_InheritedLayoutRequiredInjection_testResolverInheritsAncestorSolidifyOnlyWhenChildLacksLayoutOverride();
        },
            'InheritedLayoutRequiredInjection::testStarLayoutInjectionsApplyOnInheritedNonHomepageLayouts' => function () {
            $this->assert_InheritedLayoutRequiredInjection_testStarLayoutInjectionsApplyOnInheritedNonHomepageLayouts();
        },
            'InheritedLayoutRequiredInjection::testInheritedSolidifiedHtmlMissingFloatsStillGetsSafetyNetHosts' => function () {
            $this->assert_InheritedLayoutRequiredInjection_testInheritedSolidifiedHtmlMissingFloatsStillGetsSafetyNetHosts();
        },
            'InheritedLayoutRequiredInjection::testChildDesignFooterOverrideStillRestoredAtMaterialize' => function () {
            $this->assert_InheritedLayoutRequiredInjection_testChildDesignFooterOverrideStillRestoredAtMaterialize();
        },
            'InheritedLayoutRequiredInjection::testInjectinheritProbeThemeInheritsWithoutLayoutOverrides' => function () {
            $this->assert_InheritedLayoutRequiredInjection_testInjectinheritProbeThemeInheritsWithoutLayoutOverrides();
        },
            'InheritedLayoutRequiredInjection::testAppWidgetsKeepStarLayoutTypeForInheritedPages' => function () {
            $this->assert_InheritedLayoutRequiredInjection_testAppWidgetsKeepStarLayoutTypeForInheritedPages();
        },
            'InjectprobeFloatlessTheme::testDesignFooterOmitsFloatAndMaterializerRestoresSlots' => function () {
            $this->assert_InjectprobeFloatlessTheme_testDesignFooterOmitsFloatAndMaterializerRestoresSlots();
        },
            'InjectprobeFloatlessTheme::testHomepageOverrideDisabledSoParentShellIsUsed' => function () {
            $this->assert_InjectprobeFloatlessTheme_testHomepageOverrideDisabledSoParentShellIsUsed();
        },
            'LayoutEmbedRecoverProductInfo::testProductPageNormalizerKeepsLayoutEmbedRecoverProductInfo' => function () {
            $this->assert_LayoutEmbedRecoverProductInfo_testProductPageNormalizerKeepsLayoutEmbedRecoverProductInfo();
        },
            'LayoutEmbedRecoverProductInfo::testPolicySubLayoutsDeclarePolicyContentSlot' => function () {
            $this->assert_LayoutEmbedRecoverProductInfo_testPolicySubLayoutsDeclarePolicyContentSlot();
        },
            'LayoutRelationCompilerFilterPlaceholder::testCompileDropsListFiltersPlaceholderWhenInjectionPresent' => function () {
            $this->assert_LayoutRelationCompilerFilterPlaceholder_testCompileDropsListFiltersPlaceholderWhenInjectionPresent();
        },
            'LayoutSlotRendererColdPathPolicy::testRetiredPointersCannotReadDependenciesOrReuseStaleCache' => function () {
            $this->assert_LayoutSlotRendererColdPathPolicy_testRetiredPointersCannotReadDependenciesOrReuseStaleCache();
        },
            'LayoutSlotRendererColdPathPolicy::testRuntimeCleanerPurgesLayoutEntityProjectionPool' => function () {
            $this->assert_LayoutSlotRendererColdPathPolicy_testRuntimeCleanerPurgesLayoutEntityProjectionPool();
        },
            'LayoutSlotRendererColdPathPolicy::testPublishedLayoutSourceCandidatesPolicySharesProjectionPool' => function () {
            $this->assert_LayoutSlotRendererColdPathPolicy_testPublishedLayoutSourceCandidatesPolicySharesProjectionPool();
        },
            'LayoutSlotRendererColdPathPolicy::testCriticalChromePinsRejectStaleCommerceBag' => function () {
            $this->assert_LayoutSlotRendererColdPathPolicy_testCriticalChromePinsRejectStaleCommerceBag();
        },
            'LayoutSlotRendererColdPathPolicy::testHardCutFinalizeRecordsSkippedL3BudgetPhase' => function () {
            $this->assert_LayoutSlotRendererColdPathPolicy_testHardCutFinalizeRecordsSkippedL3BudgetPhase();
        },
            'MiniCartFooterExtrasNativeOwnerBake::testMatchedSavedNativeOwnerStillParentsFooterExtrasOrphans' => function () {
            $this->assert_MiniCartFooterExtrasNativeOwnerBake_testMatchedSavedNativeOwnerStillParentsFooterExtrasOrphans();
        },
            'MiniCartFooterExtrasNativeOwnerBake::testChildrenParentedElsewhereCloneUnderThisNativeOwner' => function () {
            $this->assert_MiniCartFooterExtrasNativeOwnerBake_testChildrenParentedElsewhereCloneUnderThisNativeOwner();
        },
            'PublishedCartCheckoutNestedSlotPreserve::testSparsePlacementKeepsCartAndCheckoutControllerContentAndNestedSlots' => function () {
            $this->assert_PublishedCartCheckoutNestedSlotPreserve_testSparsePlacementKeepsCartAndCheckoutControllerContentAndNestedSlots();
        },
            'PublishedPolicyContentPreserve::testSparseContentAdditionKeepsPolicyBodyAndHomepageDefaultTree' => function () {
            $this->assert_PublishedPolicyContentPreserve_testSparseContentAdditionKeepsPolicyBodyAndHomepageDefaultTree();
        },
            'PublishedPolicyContentPreserve::testPolicyLayoutsLinkCookieNotCookies' => function () {
            $this->assert_PublishedPolicyContentPreserve_testPolicyLayoutsLinkCookieNotCookies();
        },
            'PublishedStorefrontForcedZeroFill::testStripProducesZeroDataWslotOnSimulatedPublishedHtml' => function () {
            try {
                $this->assert_PublishedStorefrontForcedZeroFill_testStripProducesZeroDataWslotOnSimulatedPublishedHtml();
            } finally {
                $this->tearDown_PublishedStorefrontForcedZeroFill();
            }
        },
            'PublishedStorefrontForcedZeroFill::testProductsListingLayoutDefaultsChromeOn' => function () {
            try {
                $this->assert_PublishedStorefrontForcedZeroFill_testProductsListingLayoutDefaultsChromeOn();
            } finally {
                $this->tearDown_PublishedStorefrontForcedZeroFill();
            }
        },
            'PublishedStorefrontZeroDataWslot::testBoundaryStripRemovesDataWslotFromBakeDebris' => function () {
            $this->assert_PublishedStorefrontZeroDataWslot_testBoundaryStripRemovesDataWslotFromBakeDebris();
        },
            'PublishedStorefrontZeroDataWslot::testStripPromotesDataWslotToPublishedSlotIdentity' => function () {
            $this->assert_PublishedStorefrontZeroDataWslot_testStripPromotesDataWslotToPublishedSlotIdentity();
        },
            'PublishedStorefrontZeroDataWslot::testSlotBoundaryMarkersExposesStripReactiveApi' => function () {
            $this->assert_PublishedStorefrontZeroDataWslot_testSlotBoundaryMarkersExposesStripReactiveApi();
        },
            'RequiredDefaultInjectionBakeMerger::testClassAndMergeApiExist' => function () {
            $this->assert_RequiredDefaultInjectionBakeMerger_testClassAndMergeApiExist();
        },
            'RequiredDefaultInjectionBakeMerger::testContractMergeRelocatesWrongSlotInsteadOfDuplicating' => function () {
            $this->assert_RequiredDefaultInjectionBakeMerger_testContractMergeRelocatesWrongSlotInsteadOfDuplicating();
        },
            'RequiredDefaultInjectionBakeMerger::testMergeAddsRequiredNodeWhenSlotEmpty' => function () {
            $this->assert_RequiredDefaultInjectionBakeMerger_testMergeAddsRequiredNodeWhenSlotEmpty();
        },
            'RequiredDefaultInjectionBakeMerger::testMergeSkipsUserDeletedOmission' => function () {
            $this->assert_RequiredDefaultInjectionBakeMerger_testMergeSkipsUserDeletedOmission();
        },
            'RequiredDefaultInjectionBakeMerger::testMergeIntoNodesStampsSourceOnEmptyProvenanceSurvivor' => function () {
            $this->assert_RequiredDefaultInjectionBakeMerger_testMergeIntoNodesStampsSourceOnEmptyProvenanceSurvivor();
        },
            'RequiredDefaultInjectionBakeMerger::testMergeAddsNodeAlreadyCarriesDefaultInjectionSource' => function () {
            $this->assert_RequiredDefaultInjectionBakeMerger_testMergeAddsNodeAlreadyCarriesDefaultInjectionSource();
        },
            'RequiredDefaultInjection::testMissingRequiredInjectionIsMerged' => function () {
            $this->assert_RequiredDefaultInjection_testMissingRequiredInjectionIsMerged();
        },
            'RequiredDefaultInjection::testPresentWidgetIsNotDuplicated' => function () {
            $this->assert_RequiredDefaultInjection_testPresentWidgetIsNotDuplicated();
        },
            'RequiredDefaultInjection::testWrongSlotIdentityIsRelocatedNotDuplicated' => function () {
            $this->assert_RequiredDefaultInjection_testWrongSlotIdentityIsRelocatedNotDuplicated();
        },
            'RequiredDefaultInjection::testDualSlotSameIdentityCollapsesToDeclaredOnce' => function () {
            $this->assert_RequiredDefaultInjection_testDualSlotSameIdentityCollapsesToDeclaredOnce();
        },
            'RequiredDefaultInjection::testEmptyModuleSeedDoesNotSuppressRequiredModule' => function () {
            $this->assert_RequiredDefaultInjection_testEmptyModuleSeedDoesNotSuppressRequiredModule();
        },
            'RequiredDefaultInjection::testVersionUninstallSuppressesOnlyThatVersion' => function () {
            $this->assert_RequiredDefaultInjection_testVersionUninstallSuppressesOnlyThatVersion();
        },
            'RequiredDefaultInjection::testRecommendedInjectionIsNotPromoted' => function () {
            $this->assert_RequiredDefaultInjection_testRecommendedInjectionIsNotPromoted();
        },
            'RequiredDefaultInjection::testRequiredTargetsListsSlotModuleCode' => function () {
            $this->assert_RequiredDefaultInjection_testRequiredTargetsListsSlotModuleCode();
        },
            'RequiredDefaultInjection::testSlotInnerHasWidgetCodeRequiresExplicitMarkers' => function () {
            $this->assert_RequiredDefaultInjection_testSlotInnerHasWidgetCodeRequiresExplicitMarkers();
        },
            'RequiredDefaultInjection::testSlotHasWidgetPresentIgnoresSiblingSlots' => function () {
            $this->assert_RequiredDefaultInjection_testSlotHasWidgetPresentIgnoresSiblingSlots();
        },
            'RequiredDefaultInjection::testSlotHasWidgetPresentSeesSiblingWrapperClaimingSlot' => function () {
            $this->assert_RequiredDefaultInjection_testSlotHasWidgetPresentSeesSiblingWrapperClaimingSlot();
        },
            'RequiredDefaultInjection::testRequiredInjectionNodeStampsDefaultInjectionSource' => function () {
            $this->assert_RequiredDefaultInjection_testRequiredInjectionNodeStampsDefaultInjectionSource();
        },
            'RequiredDefaultInjection::testMergeKeepsSameIdentityOnMultipleDeclaredSlots' => function () {
            $this->assert_RequiredDefaultInjection_testMergeKeepsSameIdentityOnMultipleDeclaredSlots();
        },
            'RequiredDefaultInjection::testRequiredInjectionOmissionIsOnlyVersionedHumanUninstall' => function () {
            $this->assert_RequiredDefaultInjection_testRequiredInjectionOmissionIsOnlyVersionedHumanUninstall();
        },
            'RequiredDefaultInjection::testNonHomepageInheritsHomepageChromeExtensionInjections' => function () {
            $this->assert_RequiredDefaultInjection_testNonHomepageInheritsHomepageChromeExtensionInjections();
        },
            'RequiredDefaultInjection::testHomepageDoesNotDoubleCountChromeCarrierInjections' => function () {
            $this->assert_RequiredDefaultInjection_testHomepageDoesNotDoubleCountChromeCarrierInjections();
        },
            'RequiredDefaultInjection::testTargetThemeVersionDecisionDoesNotBorrowSourceVersion' => function () {
            $this->assert_RequiredDefaultInjection_testTargetThemeVersionDecisionDoesNotBorrowSourceVersion();
        },
            'RequiredDefaultInjectionRuntimeSafetyNet::testFloatHostSynthesizesWhenDesignFooterDroppedLayer' => function () {
            $this->assert_RequiredDefaultInjectionRuntimeSafetyNet_testFloatHostSynthesizesWhenDesignFooterDroppedLayer();
        },
            'RequiredDefaultInjectionRuntimeSafetyNet::testSourceTemplateRestoresWSlotFloatLayer' => function () {
            $this->assert_RequiredDefaultInjectionRuntimeSafetyNet_testSourceTemplateRestoresWSlotFloatLayer();
        },
            'RequiredDefaultInjectionRuntimeSafetyNet::testStorefrontFloatSlotsInheritOnNonHomepagePlans' => function () {
            $this->assert_RequiredDefaultInjectionRuntimeSafetyNet_testStorefrontFloatSlotsInheritOnNonHomepagePlans();
        },
            'RequiredDefaultInjectionRuntimeSafetyNet::testResolvePageTypeUsesUriMapForProductPdp' => function () {
            $this->assert_RequiredDefaultInjectionRuntimeSafetyNet_testResolvePageTypeUsesUriMapForProductPdp();
        },
            'RequiredDefaultInjectionRuntimeSafetyNet::testSafetyNetUsesSlotScopedPresenceNotPageOnce' => function () {
            $this->assert_RequiredDefaultInjectionRuntimeSafetyNet_testSafetyNetUsesSlotScopedPresenceNotPageOnce();
        },
            'RequiredDefaultInjectionRuntimeSafetyNet::testWireUpUsesRuntimeSafetyNetAfterStrip' => function () {
            $this->assert_RequiredDefaultInjectionRuntimeSafetyNet_testWireUpUsesRuntimeSafetyNetAfterStrip();
        },
            'RequiredDefaultInjectionRuntimeSafetyNet::testStoreMusicAndCsDeclareStarLayouts' => function () {
            $this->assert_RequiredDefaultInjectionRuntimeSafetyNet_testStoreMusicAndCsDeclareStarLayouts();
        },
            'RequiredFilterSlotFallbackInjector::testEnsureStripsPlaceholderWhenFiltersAlreadyPresent' => function () {
            $this->assert_RequiredFilterSlotFallbackInjector_testEnsureStripsPlaceholderWhenFiltersAlreadyPresent();
        },
            'RequiredFilterSlotFallbackInjector::testGateFingerprintAndCriticalPathCoverFiltersInventory' => function () {
            $this->assert_RequiredFilterSlotFallbackInjector_testGateFingerprintAndCriticalPathCoverFiltersInventory();
        },
            'RequiredFilterSlotFallbackInjector::testEnsureFillsEmptyPublishedListFiltersSlot' => function () {
            $this->assert_RequiredFilterSlotFallbackInjector_testEnsureFillsEmptyPublishedListFiltersSlot();
        },
            'RequiredFilterSlotFallbackInjector::testProductsSourcePlaceholderIsEditorOnly' => function () {
            $this->assert_RequiredFilterSlotFallbackInjector_testProductsSourcePlaceholderIsEditorOnly();
        },
            'SolidifiedControllerTemplate::testSourceSelectionContractInAppCode' => function () {
            $this->assert_SolidifiedControllerTemplate_testSourceSelectionContractInAppCode();
        },
            'SolidifyRelationOnlyHooks::testDocumentShellBakerDoesNotBakeHooksToPhp' => function () {
            $this->assert_SolidifyRelationOnlyHooks_testDocumentShellBakerDoesNotBakeHooksToPhp();
        },
            'SolidifyRelationOnlyHooks::testBakeFromSourceKeepsHookTags' => function () {
            $this->assert_SolidifyRelationOnlyHooks_testBakeFromSourceKeepsHookTags();
        },
            'ThemeLayoutEntityBakeCoordinator::testResourceReceiptRequiresRealOutputAndUsesContentDigest' => function () {
            $this->assert_ThemeLayoutEntityBakeCoordinator_testResourceReceiptRequiresRealOutputAndUsesContentDigest();
        },
            'ThemeLayoutEntityBakeCoordinator::testConfigurationCommandsDoNotRequireStructureGeneration' => function () {
            $this->assert_ThemeLayoutEntityBakeCoordinator_testConfigurationCommandsDoNotRequireStructureGeneration();
        },
            'ThemeLayoutEntityBakeCoordinator::testChromeBakeMergesMiniCartFooterExtrasAndKeepsMiniCartPageExtras' => function () {
            $this->assert_ThemeLayoutEntityBakeCoordinator_testChromeBakeMergesMiniCartFooterExtrasAndKeepsMiniCartPageExtras();
        },
            'ThemeLayoutEntityBakeCoordinator::testSolidifyScopeVersionEntryPointsWirePublishAndDraftReset' => function () {
            $this->assert_ThemeLayoutEntityBakeCoordinator_testSolidifyScopeVersionEntryPointsWirePublishAndDraftReset();
        },
            'ThemeLayoutEntityBakeCoordinator::testChromeBakeAndSolidifyPersistChromePayloadForStorefrontFill' => function () {
            $this->assert_ThemeLayoutEntityBakeCoordinator_testChromeBakeAndSolidifyPersistChromePayloadForStorefrontFill();
        },
            'ThemeLayoutEntityBakeCoordinator::testRebakeDedupesLayoutTargetsAndSkipsPageDependencies' => function () {
            $this->assert_ThemeLayoutEntityBakeCoordinator_testRebakeDedupesLayoutTargetsAndSkipsPageDependencies();
        },
            'ThemeLayoutEntityFormalCompileCandidates::testPinnedClosureCapturesCandidateMapNotBareCandidates' => function () {
            $this->assert_ThemeLayoutEntityFormalCompileCandidates_testPinnedClosureCapturesCandidateMapNotBareCandidates();
        },
            'ThemeLayoutEntityLocaleComPrewarm::testPublishGateUsesFormalLocaleCompileFailClosed' => function () {
            $this->assert_ThemeLayoutEntityLocaleComPrewarm_testPublishGateUsesFormalLocaleCompileFailClosed();
        },
            'ThemeLayoutEntityLocaleComPrewarm::testFormalCompilePinsWebsiteFromIdentityBeforeCompile' => function () {
            $this->assert_ThemeLayoutEntityLocaleComPrewarm_testFormalCompilePinsWebsiteFromIdentityBeforeCompile();
            $this->assert_ThemeLayoutEntityFormalScopedPurge_testDrainPathForbidsClearAll();
        },
            'ThemeLayoutEntityOwnerLockTimeout::testOwnerLockWaitIsAtLeastTwoMinutes' => function () {
            $this->assert_ThemeLayoutEntityOwnerLockTimeout_testOwnerLockWaitIsAtLeastTwoMinutes();
        },
            'ThemeLayoutEntityPageInputDigest::testMatchesStoredWhenOriginUnchanged' => function () {
            $this->setUp_ThemeLayoutEntityPageInputDigest();
            try {
                $this->assert_ThemeLayoutEntityPageInputDigest_testMatchesStoredWhenOriginUnchanged();
            } finally {
                $this->tearDown_ThemeLayoutEntityPageInputDigest();
            }
        },
            'ThemeLayoutEntityPageInputDigest::testMismatchWhenOriginBytesChange' => function () {
            $this->setUp_ThemeLayoutEntityPageInputDigest();
            try {
                $this->assert_ThemeLayoutEntityPageInputDigest_testMismatchWhenOriginBytesChange();
            } finally {
                $this->tearDown_ThemeLayoutEntityPageInputDigest();
            }
        },
            'ThemeLayoutEntityPageInputDigest::testEmptyStoredDigestIsStale' => function () {
            $this->setUp_ThemeLayoutEntityPageInputDigest();
            try {
                $this->assert_ThemeLayoutEntityPageInputDigest_testEmptyStoredDigestIsStale();
            } finally {
                $this->tearDown_ThemeLayoutEntityPageInputDigest();
            }
        },
            'ThemeLayoutEntityPageInputDigest::testMaterializerAndBakeAndGateShareHelper' => function () {
            $this->setUp_ThemeLayoutEntityPageInputDigest();
            try {
                $this->assert_ThemeLayoutEntityPageInputDigest_testMaterializerAndBakeAndGateShareHelper();
            } finally {
                $this->tearDown_ThemeLayoutEntityPageInputDigest();
            }
        },
            'ThemeLayoutEntityPaths::testDistinctScopesAndStoreModesCannotShareDirectories' => function () {
            $this->assert_ThemeLayoutEntityPaths_testDistinctScopesAndStoreModesCannotShareDirectories();
        },
            'ThemeLayoutEntityPaths::testLayoutOptionsTargetsAndNestedTypesRemainSeparate' => function () {
            $this->assert_ThemeLayoutEntityPaths_testLayoutOptionsTargetsAndNestedTypesRemainSeparate();
        },
            'ThemeLayoutEntityPaths::testPartialOptionsArePreserved' => function () {
            $this->assert_ThemeLayoutEntityPaths_testPartialOptionsArePreserved();
        },
            'ThemeLayoutEntityPaths::testLongestLegalScopeCanBeCreatedAndEnumerated' => function () {
            $this->assert_ThemeLayoutEntityPaths_testLongestLegalScopeCanBeCreatedAndEnumerated();
        },
            'ThemeLayoutEntityPathsStaging::testRootFollowsStagingEnv' => function () {
            try {
                $this->assert_ThemeLayoutEntityPathsStaging_testRootFollowsStagingEnv();
            } finally {
                $this->tearDown_ThemeLayoutEntityPathsStaging();
            }
        },
            'ThemeLayoutEntityPathsStaging::testSourceMentionsStagingSession' => function () {
            try {
                $this->assert_ThemeLayoutEntityPathsStaging_testSourceMentionsStagingSession();
            } finally {
                $this->tearDown_ThemeLayoutEntityPathsStaging();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testSerialKeyStableAndLayoutDimensionDiffers' => function () {
            $this->setUp_ThemeLayoutEntityRequestSolidifyGate();
            try {
                $this->assert_ThemeLayoutEntityRequestSolidifyGate_testSerialKeyStableAndLayoutDimensionDiffers();
            } finally {
                $this->tearDown_ThemeLayoutEntityRequestSolidifyGate();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testLeaseSameSlotAcrossVersionSharesFile' => function () {
            $this->setUp_ThemeLayoutEntityRequestSolidifyGate();
            try {
                $this->assert_ThemeLayoutEntityRequestSolidifyGate_testLeaseSameSlotAcrossVersionSharesFile();
            } finally {
                $this->tearDown_ThemeLayoutEntityRequestSolidifyGate();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testLeaseSameKeyCoalescesSecondAcquire' => function () {
            $this->setUp_ThemeLayoutEntityRequestSolidifyGate();
            try {
                $this->assert_ThemeLayoutEntityRequestSolidifyGate_testLeaseSameKeyCoalescesSecondAcquire();
            } finally {
                $this->tearDown_ThemeLayoutEntityRequestSolidifyGate();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testStampRoundTrip' => function () {
            $this->setUp_ThemeLayoutEntityRequestSolidifyGate();
            try {
                $this->assert_ThemeLayoutEntityRequestSolidifyGate_testStampRoundTrip();
            } finally {
                $this->tearDown_ThemeLayoutEntityRequestSolidifyGate();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testDeadLetterCountsConsecutiveFailuresAndClears' => function () {
            $this->setUp_ThemeLayoutEntityRequestSolidifyGate();
            try {
                $this->assert_ThemeLayoutEntityRequestSolidifyGate_testDeadLetterCountsConsecutiveFailuresAndClears();
            } finally {
                $this->tearDown_ThemeLayoutEntityRequestSolidifyGate();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testCircuitBreakerStopsForbidAndEnqueue' => function () {
            $this->setUp_ThemeLayoutEntityRequestSolidifyGate();
            try {
                $this->assert_ThemeLayoutEntityRequestSolidifyGate_testCircuitBreakerStopsForbidAndEnqueue();
            } finally {
                $this->tearDown_ThemeLayoutEntityRequestSolidifyGate();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testQueueClassesAndGateExist' => function () {
            $this->setUp_ThemeLayoutEntityRequestSolidifyGate();
            try {
                $this->assert_ThemeLayoutEntityRequestSolidifyGate_testQueueClassesAndGateExist();
            } finally {
                $this->tearDown_ThemeLayoutEntityRequestSolidifyGate();
            }
        },
            'ThemeLayoutEntityRequestSolidifyGate::testFiltersInventoryBakeVerdict' => function () {
            $this->assert_ThemeLayoutEntityRequestSolidifyGate_testFiltersInventoryBakeVerdict();
        },
            'ThemeLayoutEntitySolidifyCompilePipeline::testPipelineExposesDualPoolsAndWorkers' => function () {
            $this->assert_ThemeLayoutEntitySolidifyCompilePipeline_testPipelineExposesDualPoolsAndWorkers();
        },
            'ThemeLayoutEntitySolidifyCompilePipeline::testConcurrencyClampCeilingConstants' => function () {
            $this->assert_ThemeLayoutEntitySolidifyCompilePipeline_testConcurrencyClampCeilingConstants();
        },
            'ThemeLayoutEntitySolidifyCompilePipeline::testWorkerScriptsExist' => function () {
            $this->assert_ThemeLayoutEntitySolidifyCompilePipeline_testWorkerScriptsExist();
        },
            'ThemeLayoutEntitySolidifyCompilePipeline::testBatchPublisherCompileOptionAndPromoteSplit' => function () {
            $this->assert_ThemeLayoutEntitySolidifyCompilePipeline_testBatchPublisherCompileOptionAndPromoteSplit();
        },
            'ThemeLayoutEntitySolidifyCompilePipeline::testBakeCoordinatorDelegatesToPipeline' => function () {
            $this->assert_ThemeLayoutEntitySolidifyCompilePipeline_testBakeCoordinatorDelegatesToPipeline();
        },
            'ThemeLayoutEntityUpgradePurge::testPathsExposeGeneratedRootAndLegacyMigrate' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testPathsExposeGeneratedRootAndLegacyMigrate();
        },
            'ThemeLayoutEntityUpgradePurge::testDefaultRootIsUnderGenerated' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testDefaultRootIsUnderGenerated();
        },
            'ThemeLayoutEntityUpgradePurge::testUpgradeEventsRegisterSolidificationObserver' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testUpgradeEventsRegisterSolidificationObserver();
        },
            'ThemeLayoutEntityUpgradePurge::testThemeUpgradeCommandWiresLayoutSolidify' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testThemeUpgradeCommandWiresLayoutSolidify();
        },
            'ThemeLayoutEntityUpgradePurge::testCutoverOnlyFullClearsWhenExplicitlyRequested' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testCutoverOnlyFullClearsWhenExplicitlyRequested();
        },
            'ThemeLayoutEntityUpgradePurge::testDeployUpgradeAndCoreUpdateDispatchLifecyclePurgeEvents' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testDeployUpgradeAndCoreUpdateDispatchLifecyclePurgeEvents();
        },
            'ThemeLayoutEntityUpgradePurge::testOfflineGcHooksReuseThemeRuntimeCacheCleaner' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testOfflineGcHooksReuseThemeRuntimeCacheCleaner();
        },
            'ThemeLayoutEntityUpgradePurge::testListAndPurgeVersionModeDirectoryLeavesNeighborIntact' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testListAndPurgeVersionModeDirectoryLeavesNeighborIntact();
        },
            'ThemeLayoutEntityUpgradePurge::testPurgeEntityTreeRemovesSiblingThemeLayoutEntitiesUnderVar' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testPurgeEntityTreeRemovesSiblingThemeLayoutEntitiesUnderVar();
        },
            'ThemeLayoutEntityUpgradePurge::testAssertPurgeableEntityRootRejectsBp' => function () {
            $this->assert_ThemeLayoutEntityUpgradePurge_testAssertPurgeableEntityRootRejectsBp();
        },
            'ThemeLayoutEntityUpgradeSolidifyBoundThemes::testSolidifyAllThemesUsesApplicationBoundThemesNotAllInstalled' => function () {
            $this->assert_ThemeLayoutEntityUpgradeSolidifyBoundThemes_testSolidifyAllThemesUsesApplicationBoundThemesNotAllInstalled();
        },
            'ThemeLayoutEntityWidgetAssets::testCollectorDedupesAndSplitsBuckets' => function () {
            $this->assert_ThemeLayoutEntityWidgetAssets_testCollectorDedupesAndSplitsBuckets();
        },
            'ThemeLayoutEntityWidgetAssets::testMiniCartIconPromotesNestedMoneySummaryCssToLayoutBucket' => function () {
            $this->assert_ThemeLayoutEntityWidgetAssets_testMiniCartIconPromotesNestedMoneySummaryCssToLayoutBucket();
        },
            'ThemeLayoutEntityWidgetAssets::testEmitHtmlContainsMarkerAndTags' => function () {
            $this->assert_ThemeLayoutEntityWidgetAssets_testEmitHtmlContainsMarkerAndTags();
        },
            'ThemeLayoutEntityWidgetAssets::testTaglibDeclaresSourceAttrs' => function () {
            $this->assert_ThemeLayoutEntityWidgetAssets_testTaglibDeclaresSourceAttrs();
        },
            'ThemeLayoutEntityWidgetTypeFallback::test_catalog_exposes_module_code_fallback' => function () {
            $this->assert_ThemeLayoutEntityWidgetTypeFallback_test_catalog_exposes_module_code_fallback();
        },
            'ThemeLayoutEntityWidgetTypeFallback::test_renderer_falls_back_when_typed_find_misses' => function () {
            $this->assert_ThemeLayoutEntityWidgetTypeFallback_test_renderer_falls_back_when_typed_find_misses();
        },
            'ThemeLayoutEntityWidgetTypeFallback::test_order_notice_registry_type_is_content_not_form' => function () {
            $this->assert_ThemeLayoutEntityWidgetTypeFallback_test_order_notice_registry_type_is_content_not_form();
        },
            'ThemeLayoutNamespaceIsolation::testFormalCompilePinsThemeDataBeforeWarmAndCompile' => function () {
            $this->assert_ThemeLayoutNamespaceIsolation_testFormalCompilePinsThemeDataBeforeWarmAndCompile();
        },
            'ThemeLayoutNamespaceIsolation::testGatewayArtifactsRequireExplicitThemeNamespace' => function () {
            $this->assert_ThemeLayoutNamespaceIsolation_testGatewayArtifactsRequireExplicitThemeNamespace();
        },
            'ThemeLayoutNamespaceIsolation::testHeadPacksResolveThemeFromThemeDataCurrent' => function () {
            $this->assert_ThemeLayoutNamespaceIsolation_testHeadPacksResolveThemeFromThemeDataCurrent();
        },
            'ThemeLayoutNamespaceIsolation::testUpgradeCollectSkipsInheritWithoutCrossThemeReuse' => function () {
            $this->assert_ThemeLayoutNamespaceIsolation_testUpgradeCollectSkipsInheritWithoutCrossThemeReuse();
        },
            'ThemeLayoutOriginWatch::testCronFastSkipsNonDevAndRegistersExecuteName' => function () {
            $this->assert_ThemeLayoutOriginWatch_testCronFastSkipsNonDevAndRegistersExecuteName();
        },
            'ThemeLayoutOriginWatch::testWatchServiceCascadesWidgetsViaLedgerNotLayoutTextScan' => function () {
            $this->assert_ThemeLayoutOriginWatch_testWatchServiceCascadesWidgetsViaLedgerNotLayoutTextScan();
        },
            'ThemeLayoutOriginWatch::testBakeCoordinatorHonorsForcePagesPastDigestSkip' => function () {
            $this->assert_ThemeLayoutOriginWatch_testBakeCoordinatorHonorsForcePagesPastDigestSkip();
        },
            'ThemeLayoutOriginWatch::testUsageServiceExposesOwnSolidifyTargetsFromReferenceOwnerScope' => function () {
            $this->assert_ThemeLayoutOriginWatch_testUsageServiceExposesOwnSolidifyTargetsFromReferenceOwnerScope();
        },
            'ThemeScopeVersionChromeMemo::testModelHasChromeDecodeMemoAndDoesNotPersistArrayViaSetChromePayload' => function () {
            $this->assert_ThemeScopeVersionChromeMemo_testModelHasChromeDecodeMemoAndDoesNotPersistArrayViaSetChromePayload();
        },
            'ThemeScopeVersionChromeMemo::testCopyArrayTreeIsRecursivePhpNotJsonRoundtrip' => function () {
            $this->assert_ThemeScopeVersionChromeMemo_testCopyArrayTreeIsRecursivePhpNotJsonRoundtrip();
        },
            'ThemeScopeVersionChromeMemo::testCopyArrayTreeIsolatesNestedMutation' => function () {
            $this->assert_ThemeScopeVersionChromeMemo_testCopyArrayTreeIsolatesNestedMutation();
        },
            'ThemeScopeVersionChromeMemo::testInstanceMemoAvoidsSecondJsonDecode' => function () {
            $this->assert_ThemeScopeVersionChromeMemo_testInstanceMemoAvoidsSecondJsonDecode();
        },
            'ThemeScopeVersionChromeMemo::testPeekBorrowsMemoWhileGetChromePayloadStillIsolates' => function () {
            $this->assert_ThemeScopeVersionChromeMemo_testPeekBorrowsMemoWhileGetChromePayloadStillIsolates();
        },
            'ThemeScopeVersionModel::testModelHasChromePayloadAndNoPageType' => function () {
            $this->assert_ThemeScopeVersionModel_testModelHasChromePayloadAndNoPageType();
        },
            'ThemeScopeVersionModel::testSelectionAndSnapshotModelsExistWithOwnerKeys' => function () {
            $this->assert_ThemeScopeVersionModel_testSelectionAndSnapshotModelsExistWithOwnerKeys();
        },
            'ThemeScopeVersionModel::testWorkspaceUniqueKeyIncludesThemeVersionAndBindingExternal' => function () {
            $this->assert_ThemeScopeVersionModel_testWorkspaceUniqueKeyIncludesThemeVersionAndBindingExternal();
        },
            'WidgetAssetSourcePrefetch::testOptimizerPrefetchesSourcesBeforeTransformStreams' => function () {
            $this->assert_WidgetAssetSourcePrefetch_testOptimizerPrefetchesSourcesBeforeTransformStreams();
        },
            'WidgetAssetSourcePrefetch::testPublisherExposesPrefetchSourcesUsingWidgetAssetSourcePolicy' => function () {
            $this->assert_WidgetAssetSourcePrefetch_testPublisherExposesPrefetchSourcesUsingWidgetAssetSourcePolicy();
        },
        ];
    }

private function assert_CatalogLayoutSkeletonSolidify_testDocumentShellTrailingKeepsProductsLayoutStyles(): void
{
    $baker = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityDocumentShellBaker.php';
    self::assertFileExists($baker);
    $src = (string) \file_get_contents($baker);
    self::assertStringContainsString('<style', $src);
    self::assertStringContainsString('products-layout', $src);
    self::assertStringContainsString('layoutStyles', $src);
}

private function assert_CatalogLayoutSkeletonSolidify_testBakeProductsLayoutPostambleIncludesGridCss(): void
{
    $products = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/theme/frontend/layouts/products/default.phtml';
    self::assertFileExists($products);
    $baker = new ThemeLayoutEntityDocumentShellBaker();
    $segments = $baker->bakeFromSource((string) \file_get_contents($products), $products);
    self::assertStringContainsString('products-layout__container', $segments['postamble']);
    self::assertStringContainsString('grid-template-columns', $segments['postamble']);
}

private function assert_CheckoutRequiredDefaultInjectionBake_testCheckoutIndexCompileInjectsRequiredWidgetsIntoSlots(): void
{
    $index = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Checkout/view/frontend/checkout/index.phtml';
    self::assertFileExists($index);
    $source = (string) file_get_contents($index);
    $nodes = ['addr' => ['node_uid' => 'addr', 'widget_module' => 'Weline_Shipping', 'widget_code' => 'checkout-shipping-address', 'widget_type' => 'content', 'is_active' => true, 'slot_id' => 'checkout-shipping-address', 'config' => ['title' => '结账收货地址'], 'source' => 'default_injection', 'sort_order' => 10], 'express' => ['node_uid' => 'express', 'widget_module' => 'Weline_Payment', 'widget_code' => 'checkout-express-payment', 'widget_type' => 'content', 'is_active' => true, 'slot_id' => 'checkout-express-payment', 'config' => ['title' => '快捷支付'], 'source' => 'default_injection', 'sort_order' => 5], 'coupon' => ['node_uid' => 'coupon', 'widget_module' => 'Weline_Marketing', 'widget_code' => 'checkout-coupon', 'widget_type' => 'content', 'is_active' => true, 'slot_id' => 'checkout-summary-discount', 'config' => ['title' => '优惠券'], 'source' => 'default_injection', 'sort_order' => 20], 'note' => ['node_uid' => 'note', 'widget_module' => 'Weline_Order', 'widget_code' => 'order-notice', 'widget_type' => 'content', 'is_active' => true, 'slot_id' => 'checkout-summary-note', 'config' => ['title' => '订单留言'], 'source' => 'default_injection', 'sort_order' => 30]];
    $compiled = (new LayoutRelationCompiler())->compile($source, $nodes);
    self::assertGreaterThanOrEqual(4, substr_count($compiled, 'renderResolved'));
    $required = ['checkout-shipping-address' => 'checkout-shipping-address', 'checkout-express-payment' => 'checkout-express-payment', 'checkout-summary-discount' => 'checkout-coupon', 'checkout-summary-note' => 'order-notice'];
    foreach ($required as $slot => $code) {
        self::assertMatchesRegularExpression('/id="' . preg_quote($slot, '/') . '"[\s\S]{0,1600}?widget_code\'\s*=>\s*\'' . preg_quote($code, '/') . '\'/', $compiled, "compiled checkout index must inject {$code} into {$slot}");
    }
}

private function assert_DocumentShellBakedIntoShell_testBakeFromHomepageFixtureProducesSingleDocumentRoot(): void
{
    $homepage = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/layouts/homepage/default.phtml';
    self::assertFileExists($homepage);
    $source = (string) \file_get_contents($homepage);
    $baker = new ThemeLayoutEntityDocumentShellBaker();
    $segments = $baker->bakeFromSource($source, $homepage);
    $shell = $baker->wrap($segments['preamble'], "<?php\n\$__chromePath = '/tmp/chrome.phtml';\nif (\\is_string(\$__chromePath) && \$__chromePath !== '' && \\is_file(\$__chromePath)) {\n    include \$__chromePath;\n}\n?>", "<!--THEME-LAYOUT-ENTITY-SLOT:content-->\n<div class=\"theme-layout-entity-slot\" data-slot-id=\"content\"></div>\n", $segments['postamble']);
    self::assertMatchesRegularExpression('/<!DOCTYPE\s+html>/i', $shell);
    self::assertMatchesRegularExpression('/<html\b/i', $shell);
    self::assertMatchesRegularExpression('/<head\b/i', $shell);
    self::assertMatchesRegularExpression('/<\/head>/i', $shell);
    self::assertMatchesRegularExpression('/<body\b/i', $shell);
    self::assertMatchesRegularExpression('/<\/body>/i', $shell);
    self::assertMatchesRegularExpression('/<\/html>/i', $shell);
    // docshell.v4: hooks remain source Taglib (relation-only solidify).
    self::assertStringContainsString('$__chromePath', $shell);
    self::assertStringContainsString('chrome.phtml', $shell);
    self::assertDoesNotMatchRegularExpression('/include\s+\$__chromePath\s*;/', $shell);
    self::assertStringContainsString('theme-layout-entity-slot', $shell);
    self::assertStringContainsString('<w:hook>', $shell);
    self::assertStringNotContainsString('Template::class)->getHook(', $shell);
    self::assertSame(1, \preg_match_all('/<html\b/i', $shell));
    self::assertSame(1, \preg_match_all('/<!DOCTYPE\b/i', $shell));
    self::assertStringNotContainsString('<w:slot', $shell);
    self::assertStringNotContainsString('<w:widget', $shell);
    // wrap() may still interleave Partials header/footer PHP for chrome bind path;
    // source hooks in preamble/postamble must survive.
    self::assertStringContainsString('Weline_Theme::frontend::layouts::homepage::body-end', $shell);
    self::assertStringContainsString("->renderPartials('frontend', 'header', [], 'default')", $shell);
    self::assertStringContainsString("->renderPartials('frontend', 'footer', [], 'default')", $shell);
    $headerPos = \strpos($shell, "->renderPartials('frontend', 'header'");
    $slotPos = \strpos($shell, 'data-slot-id="content"');
    $footerPos = \strpos($shell, "->renderPartials('frontend', 'footer'");
    self::assertNotFalse($headerPos);
    self::assertNotFalse($slotPos);
    self::assertNotFalse($footerPos);
    self::assertLessThan($slotPos, $headerPos);
    self::assertLessThan($footerPos, $slotPos);
}

private function assert_DocumentShellBakedIntoShell_testBakeFromDefaultLayoutAndProductsLayout(): void
{
    $baker = new ThemeLayoutEntityDocumentShellBaker();
    $default = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/layouts/default/default.phtml';
    $products = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/theme/frontend/layouts/products/default.phtml';
    self::assertFileExists($default);
    self::assertFileExists($products);
    foreach ([$default, $products] as $path) {
        $segments = $baker->bakeFromSource((string) \file_get_contents($path), $path);
        $shell = $baker->wrap($segments['preamble'], "<?php\n\$__chromePath = '/tmp/chrome.phtml';\n?>", "slot-body\n", $segments['postamble']);
        self::assertMatchesRegularExpression('/<!DOCTYPE\b/i', $shell, $path);
        self::assertStringContainsString("->renderPartials('frontend', 'header', [], 'default')", $shell);
        self::assertStringContainsString("->renderPartials('frontend', 'footer', [], 'default')", $shell);
        self::assertDoesNotMatchRegularExpression('/include\s+\$__chromePath\s*;/', $shell);
        self::assertStringNotContainsString('<w:slot', $shell);
        self::assertStringNotContainsString('<w:widget', $shell);
    }
}

private function assert_DocumentShellBakedIntoShell_testAlgoVersionConstantStableForDigest(): void
{
    self::assertSame('docshell.v4', ThemeLayoutEntityDocumentShellBaker::ALGO_VERSION);
}

private function assert_EnsurePublishedChromeForScope_testLegacyMigrateBindingsCommandRemoved(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Layout/MigrateBindings.php';
    self::assertFileDoesNotExist($path);
    $convert = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/Layout/ConvertVersionArtifacts.php';
    self::assertFileExists($convert);
}

private function assert_EnsurePublishedChromeForScope_testEnsureCurrentPromotesOrphanScopeVersions(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeScopeVersionService.php');
    $start = \strpos($src, 'function ensureCurrent(');
    self::assertNotFalse($start);
    $end = \strpos($src, 'function getCurrent(', $start);
    self::assertNotFalse($end);
    $body = \substr($src, $start, $end - $start);
    self::assertStringContainsString('loadLatestForScope(', $body);
    self::assertStringContainsString('setIsCurrent(true)', $body);
}

private function assert_InheritedLayoutRequiredInjection_testResolverInheritsAncestorSolidifyOnlyWhenChildLacksLayoutOverride(): void
{
    $resolver = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/SolidifiedControllerTemplateResolver.php';
    $src = (string) file_get_contents($resolver);
    self::assertStringContainsString('resolvePublishedVersionAlongThemeChain', $src);
    self::assertStringContainsString('themeProvidesLayoutOverride', $src);
    self::assertStringContainsString('getParentId', $src);
    self::assertStringContainsString('Child design themes may bind package_defaults', $src, 'Inheritance comment must stay: package_defaults child reuses ancestor solidify');
    self::assertStringContainsString('Inherit ancestor published solidification only for layouts the child does', $src);
    // Order: load own → if child override use own → else walk parents → fallback own
    $ownPos = strpos($src, 'getPublished($themeId, $scope, $storeMode, $area)');
    $overridePos = strpos($src, 'themeProvidesLayoutOverride($themeId, $layoutType, $layoutOption, $area)');
    $parentWalkPos = strpos($src, 'getParentId()');
    self::assertNotFalse($ownPos);
    self::assertNotFalse($overridePos);
    self::assertNotFalse($parentWalkPos);
    self::assertLessThan($overridePos, $ownPos);
    self::assertLessThan($parentWalkPos, $overridePos);
    self::assertStringContainsString('Own published alone is not enough', $src, 'Child sealed version must still inherit parent identity for non-override layouts');
    self::assertStringContainsString('ThemeLayoutDesignOverride::provides', $src);
}

private function assert_InheritedLayoutRequiredInjection_testStarLayoutInjectionsApplyOnInheritedNonHomepageLayouts(): void
{
    // Application widgets declare layout_type=* so products/category/… inherit the same
    // required float injections as homepage when the child only inherits those layouts.
    $declarations = [['module' => 'Weline_StoreMusic', 'type' => 'content', 'code' => 'store-music', 'default_injections' => [['layout_type' => '*', 'slot' => 'storefront-float-start', 'required' => true]]], ['module' => 'Weline_CustomerService', 'type' => 'content', 'code' => 'customer-service-float', 'default_injections' => [['layout_type' => '*', 'slot' => 'storefront-float-end', 'required' => true]]], ['module' => 'Weline_Theme', 'type' => 'content', 'code' => 'filters', 'default_injections' => [['layout_type' => 'products', 'slot' => 'list-filters', 'required' => true]]]];
    $productSlots = array_column(RequiredDefaultInjectionContract::requiredTargets($declarations, 'products'), 'slot_id');
    self::assertContains('storefront-float-start', $productSlots);
    self::assertContains('storefront-float-end', $productSlots);
    self::assertContains('list-filters', $productSlots);
    $categorySlots = array_column(RequiredDefaultInjectionContract::requiredTargets($declarations, 'category'), 'slot_id');
    self::assertContains('storefront-float-start', $categorySlots);
    self::assertContains('storefront-float-end', $categorySlots);
    self::assertNotContains('list-filters', $categorySlots);
}

private function assert_InheritedLayoutRequiredInjection_testInheritedSolidifiedHtmlMissingFloatsStillGetsSafetyNetHosts(): void
{
    // Simulate child rendering an ancestor-solidified page whose chrome lost float slots
    // (parent design rewrite / incomplete bake) — inheritance must not skip required inject.
    $inherited = <<<'HTML'
    <!DOCTYPE html>
    <html><head><title>child inherits parent products layout</title></head>
    <body>
    <main data-layout-type="products" class="theme-layout-products">
      <w:slot id="list-filters" class="filters-slot"></w:slot>
      <div class="product-list">items</div>
    </main>
    </body></html>
    HTML;
    self::assertFalse(StorefrontFloatLayerHost::htmlHasFloatDestinations($inherited));
    $withHosts = StorefrontFloatLayerHost::ensureInHtml($inherited);
    self::assertTrue(StorefrontFloatLayerHost::htmlHasFloatDestinations($withHosts));
    self::assertStringContainsString('data-slot-id="storefront-float-start"', $withHosts);
    self::assertStringContainsString('data-slot-id="storefront-float-end"', $withHosts);
    self::assertStringContainsString('list-filters', $withHosts);
}

private function assert_InheritedLayoutRequiredInjection_testChildDesignFooterOverrideStillRestoredAtMaterialize(): void
{
    // Child may inherit most layouts but rewrite footer (like injectprobe) — bake must
    // restore float destinations so inherited *and* own layouts keep injection targets.
    $childFooter = "<?php\n?>\n<!-- child inherits chrome but rewrote footer without floats -->\n" . "<w:slot id=\"footer\" name=\"整体底部\"></w:slot>\n";
    $baked = StorefrontFloatLayerHost::ensureInSourceTemplate($childFooter);
    self::assertStringContainsString('<w:slot id="storefront-float-start"', $baked);
    self::assertStringContainsString('<w:slot id="storefront-float-end"', $baked);
    $materializer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';
    self::assertStringContainsString('StorefrontFloatLayerHost::ensureInSourceTemplate', (string) file_get_contents($materializer));
}

private function assert_InheritedLayoutRequiredInjection_testInjectinheritProbeThemeInheritsWithoutLayoutOverrides(): void
{
    $root = '/Users/weline/Project/Official/框架/app' . '/design/Weline/injectinherit';
    self::assertDirectoryExists($root);
    // Frontend area must exist (ThemeContextService::assertFrontendTheme) even with zero overrides.
    self::assertDirectoryExists($root . '/frontend');
    self::assertFileExists($root . '/frontend/.inherit-only');
    $register = (string) file_get_contents($root . '/register.php');
    self::assertStringContainsString("'parent'", $register);
    self::assertMatchesRegularExpression("/'parent'\\s*=>\\s*'injectprobe'/", $register);
    $layouts = $root . '/frontend/layouts';
    if (is_dir($layouts)) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($layouts));
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.phtml')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($layouts)));
            self::fail('injectinherit must not override layouts (found frontend/layouts' . $rel . ')');
        }
    }
    // Parent probe still stresses floatless footer; child inherits that stress via parent_id.
    $parentFooter = '/Users/weline/Project/Official/框架/app' . '/design/Weline/injectprobe/frontend/partials/footer/default.phtml';
    self::assertFileExists($parentFooter);
    $src = (string) file_get_contents($parentFooter);
    self::assertStringContainsString('injectprobe:floatless', $src);
    self::assertFalse(StorefrontFloatLayerHost::htmlHasFloatDestinations($src));
}

private function assert_InheritedLayoutRequiredInjection_testAppWidgetsKeepStarLayoutTypeForInheritedPages(): void
{
    $music = '/Users/weline/Project/Official/框架/app/code/Weline' . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
    if (!is_file($music)) {
        $music = '/Users/weline/Project/Official/框架/app/code' . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
    }
    $cs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
    if (!is_file($cs)) {
        $cs = '/Users/weline/Project/Official/框架/app/code' . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
    }
    self::assertFileExists($music);
    self::assertFileExists($cs);
    $musicTpl = 'Weline_StoreMusic::templates/frontend/widgets/store-music.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($music, $musicTpl));
    $musicSrc = (string) file_get_contents(\Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($musicTpl));
    self::assertStringContainsString('"layout_type":"*"', $musicSrc);
    self::assertStringContainsString('"required":true', $musicSrc);
    $csTpl = 'Weline_CustomerService::templates/frontend/widgets/customer-service-float.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($cs, $csTpl));
    $csView = \Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($csTpl);
    $csSrc = (string) file_get_contents($csView);
    self::assertStringContainsString('"layout_type":"*"', $csSrc);
    self::assertStringContainsString('"required":true', $csSrc);
}

private function assert_InjectprobeFloatlessTheme_testDesignFooterOmitsFloatAndMaterializerRestoresSlots(): void
{
    // __DIR__ = .../Theme/Test/Unit/LayoutEntity → 6 levels up = app/
    $design = '/Users/weline/Project/Official/框架/app' . '/design/Weline/injectprobe/frontend/partials/footer/default.phtml';
    self::assertFileExists($design);
    $src = (string) file_get_contents($design);
    self::assertStringContainsString('injectprobe:floatless', $src);
    self::assertFalse(StorefrontFloatLayerHost::htmlHasFloatDestinations($src), 'injectprobe design footer must omit storefront-float destinations');
    $restored = StorefrontFloatLayerHost::ensureInSourceTemplate($src);
    self::assertTrue(StorefrontFloatLayerHost::htmlHasFloatDestinations($restored));
    self::assertStringContainsString('<w:slot id="storefront-float-start"', $restored);
    self::assertStringContainsString('<w:slot id="storefront-float-end"', $restored);
    $materializer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';
    self::assertStringContainsString('StorefrontFloatLayerHost::ensureInSourceTemplate', (string) file_get_contents($materializer));
}

private function assert_InjectprobeFloatlessTheme_testHomepageOverrideDisabledSoParentShellIsUsed(): void
{
    $active = '/Users/weline/Project/Official/框架/app' . '/design/Weline/injectprobe/frontend/layouts/homepage/default.phtml';
    $disabled = '/Users/weline/Project/Official/框架/app' . '/design/Weline/injectprobe/frontend/layouts/_disabled/homepage/default.phtml';
    // Active homepage override must stay absent: a fragment <main> replaces
    // Weline_Theme shell and causes worker_scope_response_incomplete_html 503.
    self::assertFileDoesNotExist($active);
    self::assertFileExists($disabled);
    $src = (string) file_get_contents($disabled);
    self::assertStringContainsString('data-injectprobe-theme', $src);
}

private function assert_LayoutEmbedRecoverProductInfo_testProductPageNormalizerKeepsLayoutEmbedRecoverProductInfo(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ProductPageLayoutNormalizer.php');
    self::assertStringContainsString('layout_embed_recover', $src);
    self::assertStringContainsString('WIDGET_CODE_PRODUCT_INFO', $src);
    self::assertStringContainsString("\$source === 'layout_embed_recover'", $src);
}

private function assert_LayoutEmbedRecoverProductInfo_testPolicySubLayoutsDeclarePolicyContentSlot(): void
{
    $dir = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/layouts/policy';
    foreach (['privacy', 'cookie', 'shipping', 'refund', 'disclaimer', 'accessibility', 'term-condition'] as $opt) {
        $file = $dir . '/' . $opt . '.phtml';
        self::assertFileExists($file, $opt);
        $html = (string) \file_get_contents($file);
        self::assertStringContainsString('id="policy-content"', $html, $opt);
    }
}

private function assert_LayoutRelationCompilerFilterPlaceholder_testCompileDropsListFiltersPlaceholderWhenInjectionPresent(): void
{
    $source = <<<'HTML'
    <aside>
    <w:slot id="list-filters" name="筛选器区域" multiple="true" position="sidebar">
    <div class="products-layout__placeholder slot-placeholder" data-placeholder="list-filters">
    <span class="products-layout__placeholder-text">筛选器区域 - 由 Filters 部件默认注入</span>
    </div>
    </w:slot>
    </aside>
    HTML;
    $nodes = [['node_uid' => 'filters-1', 'widget_module' => 'Weline_Filters', 'widget_type' => 'sidebar', 'widget_code' => 'category-filters', 'slot_id' => 'list-filters', 'source' => 'default_injection', 'is_active' => true, 'sort_order' => 0, 'config' => []]];
    $out = (new LayoutRelationCompiler())->compile($source, $nodes);
    self::assertStringContainsString('renderResolved', $out);
    self::assertStringContainsString('category-filters', $out);
    self::assertStringNotContainsString('data-placeholder="list-filters"', $out);
    self::assertStringNotContainsString('筛选器区域 - 由 Filters 部件默认注入', $out);
}

private function assert_LayoutSlotRendererColdPathPolicy_testRetiredPointersCannotReadDependenciesOrReuseStaleCache(): void
{
    $resolver = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver::class))->newInstanceWithoutConstructor();
    $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(1, 'default.default.default', 'normal', 'frontend', 1, 'formal', 1);
    // Any dependency access fails: retired resolution must not consult
    // database versions, bindings, structure paths, or cached pointers.
    self::assertNull($resolver->resolvePublishedChrome(1, 'default.default.default'));
    self::assertNull($resolver->resolveCurrentChrome(1, 'default.default.default'));
    self::assertNull($resolver->resolvePageEntity($identity, \str_repeat('a', 64), \str_repeat('b', 64)));
    $runtime = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityRuntime::class))->newInstanceWithoutConstructor();
    self::assertNull($runtime->tryResolvePageLayoutPath(1, 'default.default.default', \str_repeat('a', 64), \str_repeat('b', 64)));
}

private function assert_LayoutSlotRendererColdPathPolicy_testRuntimeCleanerPurgesLayoutEntityProjectionPool(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeRuntimeCacheCleaner.php');
    self::assertStringContainsString('purgeLayoutEntityPublishedProjectionHotCachePool', $src);
    self::assertStringContainsString('LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL', $src);
    self::assertStringContainsString('layout_entity_published_projection_hot_cache', $src);
}

private function assert_LayoutSlotRendererColdPathPolicy_testPublishedLayoutSourceCandidatesPolicySharesProjectionPool(): void
{
    $policy = StorefrontThemeCacheCoordinator::publishedLayoutSourceCandidatesPolicy();
    self::assertSame('theme.layout_entity.source_candidates', $policy->resource);
    self::assertSame(StorefrontThemeCacheCoordinator::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL, $policy->pool);
    self::assertSame('channel', $policy->scope);
    self::assertSame([], $policy->vary);
    self::assertSame(['theme'], $policy->dependencies);
    self::assertTrue($policy->allowsEmptyResult);
    $snapshotSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutSourceSnapshot.php');
    self::assertStringContainsString('publishedLayoutSourceCandidatesPolicy', $snapshotSrc);
    self::assertStringContainsString('REQUEST_CAPTURE_MEMO_KEY', $snapshotSrc);
    self::assertStringContainsString('allowsPublishedSourceCandidatesHotCache', $snapshotSrc);
    self::assertStringContainsString('MODE_FORMAL', $snapshotSrc);
    self::assertStringContainsString('isPreviewMode', $snapshotSrc);
    self::assertStringContainsString('criticalChromePinsMatchDisk', $snapshotSrc);
    self::assertStringContainsString('purgePublishedProjectionPoolAfterEntityPromote', $snapshotSrc);
    $publisherSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php');
    self::assertStringContainsString('purgePublishedProjectionPoolAfterEntityPromote', $publisherSrc);
    self::assertStringContainsString('rollback_plan', $publisherSrc);
    $cleanerSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeRuntimeCacheCleaner.php');
    self::assertStringContainsString('public static function purgePublishedProjectionPoolAfterEntityPromote', $cleanerSrc);
}

/**
 * Stale source_candidates bag with commerce-like header bytes must fail chrome pin gate
 * when live derived disk already holds design SoT chrome.
 */
private function assert_LayoutSlotRendererColdPathPolicy_testCriticalChromePinsRejectStaleCommerceBag(): void
{
    $tmp = \sys_get_temp_dir() . '/weline-chrome-pin-gate-' . \bin2hex(\random_bytes(4));
    \mkdir($tmp, 0777, true);
    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths($tmp);
    $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        3999,
        'fixture.chrome.pin',
        'normal',
        'frontend',
        17,
        'formal',
        3,
    );
    $diskHeader = $paths->partialPhtml($identity, 'header', 'default');
    \mkdir(\dirname($diskHeader), 0777, true);

    $wrap = static function (string $origin, string $bodyHtml) use ($identity): string {
        $meta = [
            'origin' => $origin,
            'identity' => $identity->toArray(),
            'resource_type' => 'partial',
            'partial_type' => 'header',
            'partial_option' => 'default',
            'input_digest' => '',
        ];
        $encoded = \base64_encode((string)\json_encode($meta, JSON_THROW_ON_ERROR));
        $bytes = "<?php /* weline-source:{$encoded} */ ?>\n{$bodyHtml}\n";
        \file_put_contents($origin, $bytes);
        $digest = \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPageInputDigest::forOrigin($origin);
        $meta['input_digest'] = $digest;
        $encoded = \base64_encode((string)\json_encode($meta, JSON_THROW_ON_ERROR));

        return "<?php /* weline-source:{$encoded} */ ?>\n{$bodyHtml}\n";
    };

    $sotOrigin = $tmp . '/design-header-default.phtml';
    $commerceOrigin = $tmp . '/commerce-header-default.phtml';
    $sotDisk = $wrap($sotOrigin, '<header data-header-chrome="sot"></header>');
    $commercePin = $wrap($commerceOrigin, '<header data-header-mobile="supplier"></header>');
    \file_put_contents($diskHeader, $sotDisk);

    $stale = \Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot::fromCandidates(
        $identity,
        '',
        [$diskHeader => $commercePin],
    );
    $fresh = \Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot::fromCandidates(
        $identity,
        '',
        [$diskHeader => $sotDisk],
    );
    $ref = new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot::class);
    $method = $ref->getMethod('criticalChromePinsMatchDisk');
    $method->setAccessible(true);
    self::assertFalse(
        (bool)$method->invoke(null, $stale, $paths, $identity),
        'stale commerce pin against SoT disk must fail criticalChromePinsMatchDisk',
    );
    self::assertTrue(
        (bool)$method->invoke(null, $fresh, $paths, $identity),
        'matching SoT pin must pass criticalChromePinsMatchDisk',
    );

    foreach (new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    ) as $file) {
        if ($file->isDir()) {
            @\rmdir($file->getPathname());
        } else {
            @\unlink($file->getPathname());
        }
    }
    @\rmdir($tmp);
}

private function assert_LayoutSlotRendererColdPathPolicy_testHardCutFinalizeRecordsSkippedL3BudgetPhase(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/LayoutSlotRenderer.php');
    self::assertStringContainsString('ThemeLayoutBudgetPhases::L3_SLOTS', $src);
    self::assertStringContainsString("'reason' => 'storefront_hard_cut'", $src);
    self::assertStringContainsString('theme.layout.hard_cut_finalize', $src);
    self::assertStringContainsString('storefront hard-cut skips', $src);
}

private function assert_MiniCartFooterExtrasNativeOwnerBake_testMatchedSavedNativeOwnerStillParentsFooterExtrasOrphans(): void
{
    $registry = new class extends ThemePlaceableRegistry
    {
        public function __construct()
        {
        }
        public function find(string $module, string $type, string $code, ?\Weline\Theme\Model\WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
        {
            if ($code !== 'mini-cart-icon') {
                return null;
            }
            return new ThemeComponentDefinition(module: $module, type: $type, code: $code, name: $code, renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, templateContent: '<aside><w:slot id="footer-extras" multiple="true"></w:slot></aside>', slots: ['footer-extras']);
        }
    };
    $owner = [
        'node_uid' => 'saved-mini-cart',
        'widget_module' => 'Weline_Theme',
        'widget_type' => 'header',
        'widget_code' => 'mini-cart-icon',
        'slot_id' => 'user-area',
        'source' => 'template_inline',
        // Empty template_ref so discover matches this saved native owner (non-empty
        // mismatch would skip match and recreate — not the regression under test).
        'config' => [],
    ];
    $coupon = ['node_uid' => 'coupon', 'widget_module' => 'Weline_Marketing', 'widget_type' => 'content', 'widget_code' => 'checkout-coupon', 'slot_id' => 'footer-extras', 'source' => 'default_injection', 'config' => []];
    $notice = ['node_uid' => 'notice', 'widget_module' => 'Weline_Order', 'widget_type' => 'content', 'widget_code' => 'order-notice', 'slot_id' => 'footer-extras', 'source' => 'default_injection', 'config' => []];
    $source = '<w:slot id="user-area"><w:widget module="Weline_Theme" type="header" name="mini-cart-icon" /></w:slot>';
    $compiler = new LayoutRelationCompiler($registry);
    $nodes = $compiler->discoverNativeOwners($source, ['saved-mini-cart' => $owner, 'coupon' => $coupon, 'notice' => $notice]);
    self::assertSame('saved-mini-cart', (string) ($nodes['coupon']['parent_uid'] ?? ''));
    self::assertSame('saved-mini-cart', (string) ($nodes['notice']['parent_uid'] ?? ''));
    $again = $compiler->discoverNativeOwners($source, $nodes);
    self::assertSame('saved-mini-cart', (string) ($again['coupon']['parent_uid'] ?? ''));
    self::assertSame('saved-mini-cart', (string) ($again['notice']['parent_uid'] ?? ''));
    $compiled = $compiler->compile($source, ['saved-mini-cart' => $owner, 'coupon' => $coupon, 'notice' => $notice]);
    self::assertStringContainsString("'widget_code' => 'checkout-coupon'", $compiled);
    self::assertStringContainsString("'widget_code' => 'order-notice'", $compiled);
    self::assertStringContainsString("'footer-extras'", $compiled);
    self::assertGreaterThanOrEqual(3, substr_count($compiled, 'renderResolved'));
}

private function assert_MiniCartFooterExtrasNativeOwnerBake_testChildrenParentedElsewhereCloneUnderThisNativeOwner(): void
{
    $registry = new class extends ThemePlaceableRegistry
    {
        public function __construct()
        {
        }
        public function find(string $module, string $type, string $code, ?\Weline\Theme\Model\WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
        {
            if ($code !== 'mini-cart-icon') {
                return null;
            }
            return new ThemeComponentDefinition(module: $module, type: $type, code: $code, name: $code, renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, templateContent: '<aside><w:slot id="footer-extras" multiple="true"></w:slot></aside>', slots: ['footer-extras']);
        }
    };
    $coupon = ['node_uid' => 'coupon', 'widget_module' => 'Weline_Marketing', 'widget_type' => 'content', 'widget_code' => 'checkout-coupon', 'slot_id' => 'footer-extras', 'source' => 'default_injection', 'parent_uid' => 'other-header-mini-cart', 'config' => []];
    $source = '<w:slot id="user-area"><w:widget module="Weline_Theme" type="header" name="mini-cart-icon" /></w:slot>';
    $compiler = new LayoutRelationCompiler($registry);
    $nodes = $compiler->discoverNativeOwners($source, ['coupon' => $coupon]);
    $ownerUid = '';
    $cloneUid = '';
    foreach ($nodes as $uid => $node) {
        if (($node['widget_code'] ?? '') === 'mini-cart-icon') {
            $ownerUid = (string) $uid;
        }
        if (($node['widget_code'] ?? '') === 'checkout-coupon' && (string) ($node['parent_uid'] ?? '') !== 'other-header-mini-cart') {
            $cloneUid = (string) $uid;
        }
    }
    self::assertNotSame('', $ownerUid);
    self::assertNotSame('', $cloneUid);
    self::assertNotSame('coupon', $cloneUid);
    self::assertSame($ownerUid, (string) ($nodes[$cloneUid]['parent_uid'] ?? ''));
    self::assertSame('other-header-mini-cart', (string) ($nodes['coupon']['parent_uid'] ?? ''));
    $compiled = $compiler->compile($source, ['coupon' => $coupon]);
    self::assertStringContainsString("'widget_code' => 'checkout-coupon'", $compiled);
    self::assertStringContainsString("'footer-extras'", $compiled);
}

private function assert_PublishedCartCheckoutNestedSlotPreserve_testSparsePlacementKeepsCartAndCheckoutControllerContentAndNestedSlots(): void
{
    foreach (['cart' => ['cart-content', 'cart-recommendations'], 'checkout' => ['checkout-content', 'checkout-trust']] as $page => $slots) {
        $source = '<w:slot id="content"><section data-layout="' . $page . '"><div data-wslot="' . $slots[0] . '"><?= $this->getData("controller_body") ?></div><aside data-wslot="' . $slots[1] . '"></aside></section></w:slot>';
        $compiled = (new LayoutRelationCompiler($this->registry))->compile($source, [$this->node('a', 'content', 'SPARSE', 0)]);
        $html = (new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent($compiled, ['controller_body' => '<form>BUSINESS FORM</form>']);
        self::assertSame(1, substr_count($html, '<form>BUSINESS FORM</form>'));
        self::assertSame(1, substr_count($html, '<b>SPARSE</b>'));
        foreach ($slots as $slot) {
            self::assertStringContainsString('data-wslot="' . $slot . '"', $html);
        }
    }
}

private function assert_PublishedPolicyContentPreserve_testSparseContentAdditionKeepsPolicyBodyAndHomepageDefaultTree(): void
{
    foreach (['<div class="policy-main"><p><?= $this->getData("body") ?></p></div>', '<div data-wslot="homepage-hero"><h1>HERO</h1></div><div data-wslot="homepage-featured">FEATURED</div>'] as $body) {
        $source = '<w:slot id="content">' . $body . '</w:slot>';
        $compiled = (new LayoutRelationCompiler($this->registry))->compile($source, [$this->node('a', 'content', 'NEWSLETTER', 0)]);
        $html = (new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent($compiled, ['body' => 'PRIVACY TERMS']);
        self::assertSame(1, substr_count($html, '<b>NEWSLETTER</b>'));
        if (str_contains($body, 'policy-main')) {
            self::assertStringContainsString('<p>PRIVACY TERMS</p>', $html);
        } else {
            self::assertStringContainsString('<h1>HERO</h1>', $html);
            self::assertStringContainsString('FEATURED', $html);
        }
    }
}

private function assert_PublishedPolicyContentPreserve_testPolicyLayoutsLinkCookieNotCookies(): void
{
    // Defaults live in Helper after policy-document extraction (layouts no longer hardcode @url).
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/PolicyDocumentDefaults.php');
    self::assertStringNotContainsString("'url_key' => 'cookies'", $src);
    self::assertStringContainsString("'url_key' => 'cookie'", $src);
}

private function tearDown_PublishedStorefrontForcedZeroFill(): void
{
    RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE);
    RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS);
    RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED);
    RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON);
    RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_PRIME_TRANSIENT_ERROR);
    parent::tearDown();
}

/**
 * 2026-09-26 用户纠偏（严格档「有固化就完全不注」）：
 * `publishedSolidifiedArtifactLoaded()` 以 `CTX_FRAGMENTS.page_html` 非空为唯一判据。
 */
/**
 * wave9-9s5/9s6: complete chrome shells must skip heal even with empty delivery /
 * nested same-tag HTML that used to false-positive blank via naive (.*?).
 * Empty footer--shell is 缺壳 (missing_chrome_blank_header_or_footer), not
 * emptyCrit-while-chromePresent (that false-forced ~2s heal every request).
 */
private function assert_PublishedStorefrontForcedZeroFill_testStripProducesZeroDataWslotOnSimulatedPublishedHtml(): void
{
    $html = '<!--@weline-slot:content-->' . '<div data-wslot="content" data-wslot-name="主内容" class="x">' . '<div data-wslot="widget-hero" data-wslot-exclusive="true">hero</div>' . '</div>' . '<!--@/weline-slot:content-->' . '<div data-wslot="logo">L</div>';
    RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED, true);
    RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON, 'forced_ctx_unset+skip_fill_solidified');
    RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE, true);
    self::assertFalse(ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html));
    $out = SlotBoundaryMarkers::strip($html);
    self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $out));
    self::assertStringNotContainsString('@weline-slot', $out);
    self::assertStringContainsString('hero', $out);
    self::assertTrue(RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED) === true);
    self::assertSame('forced_ctx_unset+skip_fill_solidified', RequestContext::get(ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON));
}

private function assert_PublishedStorefrontForcedZeroFill_testProductsListingLayoutDefaultsChromeOn(): void
{
    $productLayout = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/theme/frontend/layouts/products/default.phtml';
    if (!\is_file($productLayout)) {
        $productLayout = '/Users/weline/Project/Official/框架/app/code' . '/Product/view/theme/frontend/layouts/products/default.phtml';
    }
    self::assertFileExists($productLayout);
    $src = (string) \file_get_contents($productLayout);
    self::assertStringContainsString('wave9-9s4', $src);
    self::assertStringContainsString("\$meta['showHeader'] = \$showHeader", $src);
    self::assertStringContainsString("\$meta['showFooter'] = \$showFooter", $src);
    self::assertStringContainsString("\$showHeader = \$meta['showHeader'] ?? \$this->getData('showHeader') ?? true", $src);
}

private function assert_PublishedStorefrontZeroDataWslot_testBoundaryStripRemovesDataWslotFromBakeDebris(): void
{
    $bake = '<!--@weline-slot:content-->' . '<div class="theme-layout-entity-slot" data-slot-id="content">' . '<div data-wslot="widget-hero" data-wslot-exclusive="true" class="slot-hero">hero</div>' . '</div>' . '<!--@/weline-slot:content-->';
    $sanitized = SlotBoundaryMarkers::stripReactiveSlotAttributes($bake);
    self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $sanitized));
    self::assertStringContainsString('data-slot-id="content"', $sanitized);
    self::assertStringContainsString('@weline-slot:content', $sanitized);
    $outbound = SlotBoundaryMarkers::strip($bake);
    self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $outbound));
    self::assertStringNotContainsString('@weline-slot', $outbound);
    self::assertStringContainsString('hero', $outbound);
}

private function assert_PublishedStorefrontZeroDataWslot_testStripPromotesDataWslotToPublishedSlotIdentity(): void
{
    $reactive = '<div class="w-auth-login__social-slot" data-wslot="account-login-social-providers"></div>';
    $outbound = SlotBoundaryMarkers::strip($reactive);
    self::assertSame(0, \preg_match_all('/\bdata-wslot\s*=/', $outbound));
    self::assertStringContainsString('data-slot-id="account-login-social-providers"', $outbound);
    self::assertStringContainsString('theme-published-slot', $outbound);
    self::assertStringContainsString('w-auth-login__social-slot', $outbound);
}

private function assert_PublishedStorefrontZeroDataWslot_testSlotBoundaryMarkersExposesStripReactiveApi(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/SlotBoundaryMarkers.php');
    self::assertStringContainsString('function stripReactiveSlotAttributes', $src);
    self::assertStringContainsString('wave8-8s3', $src);
    self::assertStringContainsString('stripReactiveSlotAttributes($html)', $src);
}

private function assert_RequiredDefaultInjectionBakeMerger_testClassAndMergeApiExist(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionBakeMerger.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('final class RequiredDefaultInjectionBakeMerger', $src);
    self::assertStringContainsString('function mergeIntoNodes', $src);
    self::assertStringContainsString('function involvedExactLayoutTypes', $src);
    self::assertStringContainsString('RequiredDefaultInjectionContract::merge', $src);
    self::assertStringContainsString('uninstalledInjectionsForVersion', $src);
    self::assertStringContainsString('DefaultInjectionPlanRepository', $src);
    self::assertStringContainsString('listDeclarations', $src);
    self::assertStringNotContainsString('ThemeComponentCatalog', $src);
    self::assertTrue(\class_exists(RequiredDefaultInjectionBakeMerger::class));
}

private function assert_RequiredDefaultInjectionBakeMerger_testContractMergeRelocatesWrongSlotInsteadOfDuplicating(): void
{
    $declarations = [['module' => 'Weline_Product', 'type' => 'product', 'code' => 'recommended-products', 'default_injections' => [['layout_type' => 'category', 'slot' => 'category-recommendations', 'required' => true, 'sort_order' => 0, 'area' => 'content']]]];
    $slots = ['content' => [['node_uid' => 'rec-drift', 'widget_module' => 'Weline_Product', 'widget_code' => 'recommended-products', 'slot_id' => 'content']], 'category-recommendations' => [['node_uid' => 'rec-correct', 'widget_module' => 'Weline_Product', 'widget_code' => 'recommended-products', 'slot_id' => 'category-recommendations']]];
    $merged = RequiredDefaultInjectionContract::merge($slots, 'category', $declarations, []);
    self::assertArrayNotHasKey('content', $merged);
    self::assertCount(1, $merged['category-recommendations'] ?? []);
    self::assertSame('rec-correct', $merged['category-recommendations'][0]['node_uid'] ?? '');
}

private function assert_RequiredDefaultInjectionBakeMerger_testMergeAddsRequiredNodeWhenSlotEmpty(): void
{
    $declarations = [['module' => 'Weline_Filters', 'type' => 'content', 'code' => 'filters', 'default_injections' => [['layout_type' => 'products', 'slot' => 'list-filters', 'required' => true, 'sort_order' => 10, 'area' => 'sidebar']]]];
    $merged = RequiredDefaultInjectionContract::merge([], 'products', $declarations, []);
    self::assertArrayHasKey('list-filters', $merged);
    self::assertSame('filters', $merged['list-filters'][0]['widget_code'] ?? '');
    self::assertSame('Weline_Filters', $merged['list-filters'][0]['widget_module'] ?? '');
}

private function assert_RequiredDefaultInjectionBakeMerger_testMergeSkipsUserDeletedOmission(): void
{
    $declarations = [['module' => 'Weline_Filters', 'type' => 'content', 'code' => 'filters', 'default_injections' => [['layout_type' => 'products', 'slot' => 'list-filters', 'required' => true]]]];
    $omissions = [['slot_id' => 'list-filters', 'widget_module' => 'Weline_Filters', 'widget_code' => 'filters']];
    $merged = RequiredDefaultInjectionContract::merge([], 'products', $declarations, $omissions);
    self::assertSame([], $merged['list-filters'] ?? []);
}

private function assert_RequiredDefaultInjectionBakeMerger_testMergeIntoNodesStampsSourceOnEmptyProvenanceSurvivor(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionBakeMerger.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('requiredKeys', $src);
    self::assertStringContainsString("\$node['source'] = 'default_injection'", $src);
    // Empty-provenance survivors of a required identity must be stamped — not only new uids.
    self::assertStringContainsString('isset($requiredKeys[$key])', $src);
    self::assertStringContainsString('empty-provenance survivors', $src);
}

private function assert_RequiredDefaultInjectionBakeMerger_testMergeAddsNodeAlreadyCarriesDefaultInjectionSource(): void
{
    $declarations = [['module' => 'Weline_Product', 'type' => 'product', 'code' => 'cross-sell', 'default_injections' => [['layout_type' => 'cart', 'slot' => 'cart-recommendations', 'required' => true]]]];
    $merged = RequiredDefaultInjectionContract::merge([], 'cart', $declarations, []);
    self::assertSame('default_injection', $merged['cart-recommendations'][0]['source'] ?? null);
}

private function assert_RequiredDefaultInjection_testMissingRequiredInjectionIsMerged(): void
{
    $slots = RequiredDefaultInjectionContract::merge([], 'products', [$this->filtersDeclaration_RequiredDefaultInjection()], []);
    self::assertArrayHasKey('list-filters', $slots);
    self::assertSame('category-filters', $slots['list-filters'][0]['widget_code']);
    self::assertSame('Weline_Filters', $slots['list-filters'][0]['widget_module']);
}

private function assert_RequiredDefaultInjection_testPresentWidgetIsNotDuplicated(): void
{
    $slots = ['list-filters' => [['widget_module' => 'Weline_Filters', 'widget_code' => 'category-filters', 'sort_order' => 0]]];
    $merged = RequiredDefaultInjectionContract::merge($slots, 'products', [$this->filtersDeclaration_RequiredDefaultInjection()], []);
    self::assertCount(1, $merged['list-filters']);
}

private function assert_RequiredDefaultInjection_testWrongSlotIdentityIsRelocatedNotDuplicated(): void
{
    $slots = ['content' => [['node_uid' => 'drifted-filters-uid', 'widget_module' => 'Weline_Filters', 'widget_code' => 'category-filters', 'slot_id' => 'content', 'sort_order' => 0]]];
    $merged = RequiredDefaultInjectionContract::merge($slots, 'category', [$this->filtersDeclaration_RequiredDefaultInjection()], []);
    self::assertArrayNotHasKey('content', $merged);
    self::assertCount(1, $merged['category-filters'] ?? []);
    self::assertSame('drifted-filters-uid', $merged['category-filters'][0]['node_uid'] ?? '');
    self::assertSame('category-filters', $merged['category-filters'][0]['slot_id'] ?? '');
}

private function assert_RequiredDefaultInjection_testDualSlotSameIdentityCollapsesToDeclaredOnce(): void
{
    $slots = ['content' => [['node_uid' => 'old-content-copy', 'widget_module' => 'Weline_Filters', 'widget_code' => 'category-filters', 'slot_id' => 'content']], 'category-filters' => [['node_uid' => 'bake-merger-copy', 'widget_module' => 'Weline_Filters', 'widget_code' => 'category-filters', 'slot_id' => 'category-filters']]];
    $merged = RequiredDefaultInjectionContract::merge($slots, 'category', [$this->filtersDeclaration_RequiredDefaultInjection()], []);
    self::assertArrayNotHasKey('content', $merged);
    self::assertCount(1, $merged['category-filters'] ?? []);
    self::assertSame('bake-merger-copy', $merged['category-filters'][0]['node_uid'] ?? '');
}

private function assert_RequiredDefaultInjection_testEmptyModuleSeedDoesNotSuppressRequiredModule(): void
{
    $slots = ['list-filters' => [['widget_module' => '', 'widget_code' => 'category-filters', 'sort_order' => 0]]];
    $merged = RequiredDefaultInjectionContract::merge($slots, 'products', [$this->filtersDeclaration_RequiredDefaultInjection()], []);
    self::assertCount(2, $merged['list-filters']);
    self::assertSame('Weline_Filters', $merged['list-filters'][1]['widget_module']);
}

private function assert_RequiredDefaultInjection_testVersionUninstallSuppressesOnlyThatVersion(): void
{
    self::assertSame('user_deleted@89', RequiredDefaultInjectionContract::userDeletedSource(89));
    self::assertTrue(RequiredDefaultInjectionContract::matchesVersionUninstall('user_deleted@89', 89));
    self::assertFalse(RequiredDefaultInjectionContract::matchesVersionUninstall('user_deleted@89', 90));
    self::assertFalse(RequiredDefaultInjectionContract::matchesVersionUninstall('user_deleted', 89));
    $omitted = RequiredDefaultInjectionContract::merge([], 'products', [$this->filtersDeclaration_RequiredDefaultInjection()], [['slot_id' => 'list-filters', 'widget_module' => 'Weline_Filters', 'widget_code' => 'category-filters']]);
    self::assertSame([], $omitted);
    $otherVersion = RequiredDefaultInjectionContract::merge([], 'products', [$this->filtersDeclaration_RequiredDefaultInjection()], []);
    self::assertArrayHasKey('list-filters', $otherVersion);
}

private function assert_RequiredDefaultInjection_testRecommendedInjectionIsNotPromoted(): void
{
    $declaration = $this->filtersDeclaration_RequiredDefaultInjection();
    $declaration['default_injections'][1]['required'] = false;
    $slots = RequiredDefaultInjectionContract::merge([], 'products', [$declaration], []);
    self::assertArrayNotHasKey('list-filters', $slots);
    self::assertArrayHasKey('category-filters', RequiredDefaultInjectionContract::merge([], 'category', [$declaration], []));
}

private function assert_RequiredDefaultInjection_testRequiredTargetsListsSlotModuleCode(): void
{
    $targets = RequiredDefaultInjectionContract::requiredTargets([$this->filtersDeclaration_RequiredDefaultInjection()], 'products');
    self::assertCount(1, $targets);
    self::assertSame('list-filters', $targets[0]['slot_id']);
    self::assertSame('Weline_Filters', $targets[0]['widget_module']);
    self::assertSame('category-filters', $targets[0]['widget_code']);
    self::assertArrayNotHasKey('node', $targets[0]);
    $items = RequiredDefaultInjectionContract::requiredInjections([$this->filtersDeclaration_RequiredDefaultInjection()], 'products');
    self::assertCount(1, $items);
    self::assertArrayHasKey('node', $items[0]);
    self::assertTrue(RequiredDefaultInjectionContract::isUninstalled([['slot_id' => 'list-filters', 'widget_module' => 'Weline_Filters', 'widget_code' => 'category-filters']], 'list-filters', 'Weline_Filters', 'category-filters'));
}

private function assert_RequiredDefaultInjection_testSlotInnerHasWidgetCodeRequiresExplicitMarkers(): void
{
    self::assertTrue(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<div data-widget-code="category-filters"></div>', 'Weline_Filters', 'category-filters'));
    self::assertTrue(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<button data-testid="product-add-to-cart">Add</button>', 'Weline_Cart', 'product-add-to-cart'));
    self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<button data-action="add">Add</button>', 'Weline_Cart', 'product-add-to-cart'));
    self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<button data-action="buy-now">Buy</button>', 'Weline_Checkout', 'product-buy-now'));
    self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<div class="w-payment-express"></div>', 'Weline_Payment', 'product-express-payment'));
    self::assertFalse(RequiredDefaultInjectionContract::slotInnerHasWidgetCode('<div class="empty"></div>', 'Weline_Cart', 'product-add-to-cart'));
}

private function assert_RequiredDefaultInjection_testSlotHasWidgetPresentIgnoresSiblingSlots(): void
{
    $html = <<<'HTML'
    <div data-slot-id="footer-extras">
      <div class="w-marketing-checkout-coupon" data-testid="checkout-coupon" data-widget-code="checkout-coupon"></div>
    </div>
    <div data-slot-id="checkout-summary-discount" class="theme-published-slot"> </div>
    HTML;
    self::assertTrue(RequiredDefaultInjectionContract::slotHasWidgetPresent($html, 'footer-extras', 'Weline_Marketing', 'checkout-coupon'));
    self::assertFalse(RequiredDefaultInjectionContract::slotHasWidgetPresent($html, 'checkout-summary-discount', 'Weline_Marketing', 'checkout-coupon'));
    self::assertTrue(RequiredDefaultInjectionContract::pageHasWidgetPresent($html, 'Weline_Marketing', 'checkout-coupon'));
}

private function assert_RequiredDefaultInjection_testSlotHasWidgetPresentSeesSiblingWrapperClaimingSlot(): void
{
    // Bake mis-nest: host empty, wrapper is a sibling but stamps data-slot-id.
    $html = <<<'HTML'
    <section data-slot-id="cart-recommendations" class="theme-published-slot"></section>
    <div class="widget-wrapper" data-widget-module="Weline_Product" data-widget-code="cross-sell" data-slot-id="cart-recommendations" data-widget-source="entity_node"></div>
    HTML;
    self::assertTrue(RequiredDefaultInjectionContract::slotHasWidgetPresent($html, 'cart-recommendations', 'Weline_Product', 'cross-sell'));
    self::assertTrue(RequiredDefaultInjectionContract::slotClaimedByWidgetWrapper($html, 'cart-recommendations', 'Weline_Product', 'cross-sell'));
    // Must not treat a different slot claim as present for this slot.
    self::assertFalse(RequiredDefaultInjectionContract::slotHasWidgetPresent($html, 'product-cross-sell', 'Weline_Product', 'cross-sell'));
}

private function assert_RequiredDefaultInjection_testRequiredInjectionNodeStampsDefaultInjectionSource(): void
{
    $items = RequiredDefaultInjectionContract::requiredInjections([['module' => 'Weline_Product', 'type' => 'product', 'code' => 'cross-sell', 'default_injections' => [['layout_type' => 'cart', 'slot' => 'cart-recommendations', 'required' => true]]]], 'cart');
    self::assertNotSame([], $items);
    self::assertSame('default_injection', $items[0]['node']['source'] ?? null);
}

private function assert_RequiredDefaultInjection_testMergeKeepsSameIdentityOnMultipleDeclaredSlots(): void
{
    $declaration = ['module' => 'Weline_Marketing', 'type' => 'content', 'code' => 'checkout-coupon', 'default_injections' => [['layout_type' => 'checkout', 'slot' => 'footer-extras', 'required' => true, 'sort_order' => 10], ['layout_type' => 'checkout', 'slot' => 'checkout-summary-discount', 'required' => true, 'sort_order' => 20]]];
    $slots = ['footer-extras' => [['node_uid' => 'mini-cart-coupon', 'widget_module' => 'Weline_Marketing', 'widget_code' => 'checkout-coupon', 'slot_id' => 'footer-extras']]];
    $merged = RequiredDefaultInjectionContract::merge($slots, 'checkout', [$declaration], []);
    self::assertCount(1, $merged['footer-extras'] ?? []);
    self::assertSame('mini-cart-coupon', $merged['footer-extras'][0]['node_uid'] ?? '');
    self::assertCount(1, $merged['checkout-summary-discount'] ?? []);
    self::assertSame('checkout-coupon', $merged['checkout-summary-discount'][0]['widget_code'] ?? '');
}

/**
 * 同部件 XOR：卸载仍走版本化 user_deleted；未填店面软跳过（见 Overlay soft-skip）。
 */
private function assert_RequiredDefaultInjection_testRequiredInjectionOmissionIsOnlyVersionedHumanUninstall(): void
{
    $contract = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionContract.php');
    self::assertStringContainsString('Identity XOR', $contract);
    self::assertStringContainsString('user_deleted@{versionId}', $contract);
    self::assertStringContainsString('missing fill must not 500', $contract);
    self::assertStringContainsString('must not be both layout-embedded', $contract);
    $planner = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionPlanner.php');
    self::assertStringContainsString('isUninstalled($omissions', $planner);
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/WidgetDefaultInjectionService.php');
    self::assertStringContainsString('function uninstalledInjectionsForVersion', $service);
    self::assertStringContainsString('userDeletedSource($versionId)', $service);
    self::assertStringContainsString('A plain `user_deleted` row without a version is not an uninstall of this version', $service);
    // Task 5: target ThemeScopeVersion V — never ThemeLayoutVersion page axis.
    self::assertStringContainsString('ThemeScopeVersionWidgetDecisionService', $service);
    self::assertStringContainsString('resolveTargetThemeVersionId', $service);
    self::assertStringNotContainsString('ThemeLayoutVersionService::class', $service);
    self::assertStringContainsString('listUninstallOmissions', $service);
    $with = RequiredDefaultInjectionContract::merge([], 'checkout', [['module' => 'Weline_Shipping', 'type' => 'content', 'code' => 'checkout-shipping-address', 'default_injections' => [['layout_type' => 'checkout', 'layout_option' => 'default', 'slot' => 'checkout-shipping-address', 'area' => 'content', 'sort_order' => 10, 'required' => true]]]], []);
    self::assertArrayHasKey('checkout-shipping-address', $with);
    self::assertSame('checkout-shipping-address', $with['checkout-shipping-address'][0]['widget_code']);
    $uninstalled = RequiredDefaultInjectionContract::merge([], 'checkout', [['module' => 'Weline_Shipping', 'type' => 'content', 'code' => 'checkout-shipping-address', 'default_injections' => [['layout_type' => 'checkout', 'layout_option' => 'default', 'slot' => 'checkout-shipping-address', 'area' => 'content', 'sort_order' => 10, 'required' => true]]]], [['slot_id' => 'checkout-shipping-address', 'widget_module' => 'Weline_Shipping', 'widget_code' => 'checkout-shipping-address']]);
    self::assertSame([], $uninstalled);
}

private function assert_RequiredDefaultInjection_testNonHomepageInheritsHomepageChromeExtensionInjections(): void
{
    self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer-payment-account-links'));
    self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer-help-links'));
    self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('header-nav-extensions'));
    self::assertFalse(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer'));
    self::assertFalse(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('list-filters'));
    $items = RequiredDefaultInjectionContract::requiredInjections([$this->filtersDeclaration_RequiredDefaultInjection(), $this->footerPaymentLinkDeclaration_RequiredDefaultInjection()], 'products');
    $codes = array_map(static fn(array $row): string => (string) $row['widget_code'], $items);
    self::assertContains('category-filters', $codes);
    self::assertContains('footer-payment-methods-link', $codes);
    $payment = null;
    foreach ($items as $row) {
        if (($row['widget_code'] ?? '') === 'footer-payment-methods-link') {
            $payment = $row;
            break;
        }
    }
    self::assertNotNull($payment);
    self::assertSame('footer-payment-account-links', $payment['slot_id']);
    self::assertSame('Weline_Payment', $payment['widget_module']);
}

private function assert_RequiredDefaultInjection_testHomepageDoesNotDoubleCountChromeCarrierInjections(): void
{
    $items = RequiredDefaultInjectionContract::requiredInjections([$this->footerPaymentLinkDeclaration_RequiredDefaultInjection()], 'homepage');
    self::assertCount(1, $items);
    self::assertSame('footer-payment-methods-link', $items[0]['widget_code']);
}

private function assert_RequiredDefaultInjection_testTargetThemeVersionDecisionDoesNotBorrowSourceVersion(): void
{
    $current = (new \ReflectionClass(ThemeScopeVersion::class))->newInstanceWithoutConstructor();
    $current->setData(['version_id' => 100, 'creation_source_version_id' => 7, 'parent_version_id' => 7]);
    $published = (clone $current)->setData('version_id', 90);
    $versions = new class($current, $published)
    {
        public function __construct(public ?ThemeScopeVersion $current, public ThemeScopeVersion $published)
        {
        }
        public function getCurrent(...$args): ?ThemeScopeVersion
        {
            return $this->current;
        }
        public function getPublished(...$args): ThemeScopeVersion
        {
            return $this->published;
        }
    };
    $previous = ObjectManager::_getInstance(ThemeScopeVersionService::class);
    ObjectManager::setInstance(ThemeScopeVersionService::class, $versions);
    try {
        $decisions = (new \ReflectionClass(ThemeScopeVersionWidgetDecisionService::class))->newInstanceWithoutConstructor();
        self::assertSame(100, $decisions->resolveTargetThemeVersionId(3, 'shop.store.channel'));
        $versions->current = null;
        self::assertSame(90, $decisions->resolveTargetThemeVersionId(3, 'shop.store.channel'));
    } finally {
        if ($previous === null) {
            ObjectManager::removeInstance(ThemeScopeVersionService::class);
        } else {
            ObjectManager::setInstance(ThemeScopeVersionService::class, $previous);
        }
    }
}

/**
 * @return array<string, mixed>
 */
private function filtersDeclaration_RequiredDefaultInjection(): array
{
    return ['module' => 'Weline_Filters', 'type' => 'sidebar', 'code' => 'category-filters', 'default_injections' => [['layout_type' => 'category', 'layout_option' => 'default', 'slot' => 'category-filters', 'area' => 'sidebar', 'sort_order' => 0, 'required' => true], ['layout_type' => 'products', 'layout_option' => 'default', 'slot' => 'list-filters', 'area' => 'sidebar', 'sort_order' => 0, 'required' => true]]];
}

/**
 * @return array<string, mixed>
 */
private function footerPaymentLinkDeclaration_RequiredDefaultInjection(): array
{
    return ['module' => 'Weline_Payment', 'type' => 'footer', 'code' => 'footer-payment-methods-link', 'default_injections' => [['layout_type' => 'homepage', 'slot' => 'footer-payment-account-links', 'area' => 'footer', 'sort_order' => 0, 'required' => true, 'config' => ['label' => '支付方式']]]];
}

private function assert_RequiredDefaultInjectionRuntimeSafetyNet_testFloatHostSynthesizesWhenDesignFooterDroppedLayer(): void
{
    $html = '<html><body><main>grocery homepage rewrite without floats</main></body></html>';
    self::assertFalse(StorefrontFloatLayerHost::htmlHasFloatDestinations($html));
    $out = StorefrontFloatLayerHost::ensureInHtml($html);
    self::assertTrue(StorefrontFloatLayerHost::htmlHasFloatDestinations($out));
    self::assertStringContainsString('data-slot-id="storefront-float-start"', $out);
    self::assertStringContainsString('data-slot-id="storefront-float-end"', $out);
    self::assertStringContainsString('</body>', $out);
}

private function assert_RequiredDefaultInjectionRuntimeSafetyNet_testSourceTemplateRestoresWSlotFloatLayer(): void
{
    $source = "<?php\n?>\n<w:slot id=\"footer\"></w:slot>\n";
    $out = StorefrontFloatLayerHost::ensureInSourceTemplate($source);
    self::assertStringContainsString('<w:slot id="storefront-float-start"', $out);
    self::assertStringContainsString('<w:slot id="storefront-float-end"', $out);
    // Idempotent
    self::assertSame($out, StorefrontFloatLayerHost::ensureInSourceTemplate($out));
}

private function assert_RequiredDefaultInjectionRuntimeSafetyNet_testStorefrontFloatSlotsInheritOnNonHomepagePlans(): void
{
    self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('storefront-float-start'));
    self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('storefront-float-end'));
    self::assertTrue(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer-help-links'));
    self::assertFalse(RequiredDefaultInjectionContract::isInheritedChromeCarrierSlot('footer'));
    $declarations = [['module' => 'Weline_StoreMusic', 'type' => 'content', 'code' => 'store-music', 'default_injections' => [['layout_type' => 'homepage', 'slot' => 'storefront-float-start', 'required' => true]]]];
    $targets = RequiredDefaultInjectionContract::requiredTargets($declarations, 'products');
    $slots = array_column($targets, 'slot_id');
    self::assertContains('storefront-float-start', $slots);
}

private function assert_RequiredDefaultInjectionRuntimeSafetyNet_testResolvePageTypeUsesUriMapForProductPdp(): void
{
    $net = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionRuntimeSafetyNet.php';
    $src = (string) file_get_contents($net);
    self::assertStringContainsString('resolvePageTypeFromUri', $src);
    self::assertStringContainsString('REQUEST_LAYOUT_NAME_KEY', $src);
    self::assertStringContainsString('product-native-detail', $src);
    // Must not rely only on /products|/category heuristics (PDP would fall through to homepage).
    $fnPos = strpos($src, 'private function resolvePageType()');
    self::assertNotFalse($fnPos);
    $layoutNamePos = strpos($src, 'REQUEST_LAYOUT_NAME_KEY', $fnPos);
    $uriPos = strpos($src, 'resolvePageTypeFromUri', $fnPos);
    self::assertNotFalse($layoutNamePos);
    self::assertNotFalse($uriPos);
    $productsPos = strpos($src, "str_contains(\$path, '/products')", $fnPos);
    self::assertNotFalse($productsPos);
    self::assertLessThan($uriPos, $layoutNamePos);
    self::assertLessThan($productsPos, $uriPos);
    $resolver = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/SolidifiedControllerTemplateResolver.php';
    $resolverSrc = (string) file_get_contents($resolver);
    $forIdentityPos = strpos($resolverSrc, 'function resolveForIdentity(');
    self::assertNotFalse($forIdentityPos);
    $setPos = strpos($resolverSrc, 'REQUEST_LAYOUT_NAME_KEY', $forIdentityPos);
    self::assertNotFalse($setPos);
}

private function assert_RequiredDefaultInjectionRuntimeSafetyNet_testSafetyNetUsesSlotScopedPresenceNotPageOnce(): void
{
    $net = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionRuntimeSafetyNet.php';
    $src = (string) file_get_contents($net);
    self::assertStringContainsString('slotHasWidgetPresent', $src);
    self::assertStringNotContainsString('pageHasWidgetPresent($html, $module, $code)', $src, 'page-level presence must not suppress other declared slots');
    $bake = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionBakeMerger.php';
    $bakeSrc = (string) file_get_contents($bake);
    self::assertStringNotContainsString('pageHasWidgetPresent', $bakeSrc);
    self::assertStringContainsString('multiple *declared* slots', $bakeSrc);
    self::assertStringNotContainsString('Page-level identity once', $bakeSrc);
}

private function assert_RequiredDefaultInjectionRuntimeSafetyNet_testWireUpUsesRuntimeSafetyNetAfterStrip(): void
{
    $obs = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/LayoutSlotRenderer.php';
    $obsSrc = (string) file_get_contents($obs);
    self::assertStringContainsString('StorefrontLayoutHtmlFinalizer', $obsSrc);
    $finalizer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/StorefrontLayoutHtmlFinalizer.php';
    $finalizerSrc = (string) file_get_contents($finalizer);
    self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $finalizerSrc);
    $stripPos = strpos($finalizerSrc, 'SlotBoundaryMarkers::strip($html)');
    $ensurePos = strpos($finalizerSrc, 'requiredNet->ensure($html)');
    self::assertNotFalse($stripPos);
    self::assertNotFalse($ensurePos);
    self::assertLessThan($ensurePos, $stripPos);
    $healer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/StorefrontSsrChromeHealer.php';
    $healerSrc = (string) file_get_contents($healer);
    self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $healerSrc);
    $materializer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';
    $matSrc = (string) file_get_contents($materializer);
    self::assertStringContainsString('StorefrontFloatLayerHost::ensureInSourceTemplate', $matSrc);
    $slotRenderer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/SlotRendererService.php';
    $srSrc = (string) file_get_contents($slotRenderer);
    self::assertStringContainsString('StorefrontFloatLayerHost::ensureInHtml', $srSrc);
}

private function assert_RequiredDefaultInjectionRuntimeSafetyNet_testStoreMusicAndCsDeclareStarLayouts(): void
{
    $music = '/Users/weline/Project/Official/框架/app/code/Weline' . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
    if (!is_file($music)) {
        $music = '/Users/weline/Project/Official/框架/app/code' . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
    }
    self::assertFileExists($music);
    $musicTpl = 'Weline_StoreMusic::templates/frontend/widgets/store-music.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($music, $musicTpl));
    $musicSrc = (string) file_get_contents(\Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($musicTpl));
    self::assertStringContainsString('"layout_type":"*"', $musicSrc);
    self::assertStringContainsString('"required":true', $musicSrc);
    $cs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
    if (!is_file($cs)) {
        $cs = '/Users/weline/Project/Official/框架/app/code' . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
    }
    self::assertFileExists($cs);
    $csTpl = 'Weline_CustomerService::templates/frontend/widgets/customer-service-float.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($cs, $csTpl));
    $csSrc = (string) file_get_contents(\Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($csTpl));
    self::assertStringContainsString('"layout_type":"*"', $csSrc);
    self::assertStringContainsString('"required":true', $csSrc);
}

private function assert_RequiredFilterSlotFallbackInjector_testEnsureStripsPlaceholderWhenFiltersAlreadyPresent(): void
{
    $html = '<aside class="products-layout__sidebar">' . '<div data-slot-id="list-filters" class="theme-published-slot">' . '<div class="products-layout__placeholder slot-placeholder" data-placeholder="list-filters">' . '<span class="products-layout__placeholder-text">筛选器区域 - 由 Filters 部件默认注入</span>' . '</div>' . '<div class="widget-wrapper" data-widget-code="category-filters" data-slot-id="list-filters">' . '<aside class="w-filters" data-testid="storefront-filters-panel">filters-ok</aside>' . '</div>' . '</div></aside>';
    $out = (new RequiredFilterSlotFallbackInjector())->ensure($html);
    self::assertStringContainsString('storefront-filters-panel', $out);
    self::assertStringContainsString('filters-ok', $out);
    self::assertStringNotContainsString('data-placeholder="list-filters"', $out);
    self::assertStringNotContainsString('由 Filters 部件默认注入', $out);
}

private function assert_RequiredFilterSlotFallbackInjector_testGateFingerprintAndCriticalPathCoverFiltersInventory(): void
{
    $gate = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityRequestSolidifyGate.php';
    self::assertFileExists($gate);
    $src = (string) file_get_contents($gate);
    self::assertStringContainsString('isFiltersCritical', $src);
    self::assertStringContainsString('filterInventoryBakeBroken', $src);
    self::assertStringContainsString('empty_filters_inventory_fallback_original', $src);
    self::assertStringContainsString("list-filters", $src);
    self::assertStringContainsString('category-filters', $src);
    $injector = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredFilterSlotFallbackInjector.php';
    self::assertFileExists($injector);
    $injSrc = (string) file_get_contents($injector);
    self::assertStringContainsString('data-wslot', $injSrc);
    // Must not early-return on editor canvas (theme-switch solidify acceptance).
    self::assertStringNotContainsString('if ($html === \'\' || $this->isEditorCanvas())', $injSrc);
    $net = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionRuntimeSafetyNet.php';
    $netEditor = (string) file_get_contents($net);
    self::assertStringContainsString('if ($this->isEditorCanvas())', $netEditor);
    self::assertStringContainsString('return $this->finishFiltersXor($html);', $netEditor);
    $obs = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/LayoutSlotRenderer.php';
    $obsSrc = (string) file_get_contents($obs);
    self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $obsSrc);
    // Reactive shells expose data-wslot until strip; ensure() must run after promote.
    $stripPos = strpos($obsSrc, 'SlotBoundaryMarkers::strip($html)');
    $ensurePos = strpos($obsSrc, 'requiredNet->ensure($html)');
    self::assertNotFalse($stripPos);
    self::assertNotFalse($ensurePos);
    self::assertLessThan($stripPos, $ensurePos, 'Required ensure must run after SlotBoundaryMarkers::strip');
    // Editor canvas finalizeFrontendHtml must run Filters XOR before editor assets.
    self::assertMatchesRegularExpression('/function finalizeFrontendHtml\([\s\S]*?' . 'if \(\$this->isEditorCanvasRequest\(\)\) \{[\s\S]*?' . 'requiredNet->ensure\(\$html\)[\s\S]*?' . 'EditorModeAssetInjector[\s\S]*?' . 'return \$injector->inject\(\$html\)/', $obsSrc, 'Editor canvas Filters ensure must run before EditorModeAssetInjector');
    $healer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/StorefrontSsrChromeHealer.php';
    $healerSrc = (string) file_get_contents($healer);
    self::assertStringContainsString('RequiredDefaultInjectionRuntimeSafetyNet', $healerSrc);
    $hStrip = strpos($healerSrc, 'SlotBoundaryMarkers::strip($html)');
    $hEnsure = strpos($healerSrc, 'requiredNet->ensure($html)');
    self::assertNotFalse($hStrip);
    self::assertNotFalse($hEnsure);
    self::assertLessThan($hStrip, $hEnsure, 'Healer required ensure must run after strip');
    $netSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/RequiredDefaultInjectionRuntimeSafetyNet.php');
    self::assertStringContainsString('RequiredFilterSlotFallbackInjector', $netSrc);
    self::assertTrue(class_exists(ThemeLayoutEntityRequestSolidifyGate::class));
}

private function assert_RequiredFilterSlotFallbackInjector_testEnsureFillsEmptyPublishedListFiltersSlot(): void
{
    $html = '<aside class="products-layout__sidebar">' . '<div data-slot-id="list-filters" class="theme-published-slot" data-area="frontend">' . '</div></aside>';
    // Without a live ThemeLayoutEntityWidgetRenderer stack, ensure() may strip-only
    // when render returns empty — contract is: never leave declaration placeholder text.
    $out = (new RequiredFilterSlotFallbackInjector())->ensure($html);
    self::assertStringNotContainsString('由 Filters 部件默认注入', $out);
    self::assertStringContainsString('data-slot-id="list-filters"', $out);
}

private function assert_RequiredFilterSlotFallbackInjector_testProductsSourcePlaceholderIsEditorOnly(): void
{
    $products = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/theme/frontend/layouts/products/default.phtml';
    if (!is_file($products)) {
        $products = '/Users/weline/Project/Official/框架/app/code' . '/Product/view/theme/frontend/layouts/products/default.phtml';
    }
    self::assertFileExists($products);
    $src = (string) file_get_contents($products);
    self::assertStringContainsString('$isEditorPreview', $src);
    self::assertStringContainsString('if ($isEditorPreview)', $src);
    self::assertStringContainsString('data-placeholder="list-filters"', $src);
    $category = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/theme/frontend/layouts/category/default.phtml';
    if (!is_file($category)) {
        $category = '/Users/weline/Project/Official/框架/app/code' . '/Product/view/theme/frontend/layouts/category/default.phtml';
    }
    self::assertFileExists($category);
    $catSrc = (string) file_get_contents($category);
    self::assertStringContainsString('$isEditorPreview', $catSrc);
    self::assertStringContainsString('if ($isEditorPreview)', $catSrc);
}

private function assert_SolidifiedControllerTemplate_testSourceSelectionContractInAppCode(): void
{
    // Prefer app/code path assertions: PHPUnit may load vendor/weline/module-theme first.
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/SolidifiedControllerTemplateResolver.php');
    self::assertIsString($source);
    self::assertStringContainsString('function resolveForIdentity', $source, 'Selection must use the complete version and resource identity.');
    self::assertStringContainsString('versionOwnerScope', $source, 'Published layout selection must honor ThemeApplicationContext version owner scope.');
    self::assertStringContainsString('ThemeApplicationContext', $source);
    self::assertStringContainsString('function resolveExecutableLayoutPath', $source);
    self::assertStringContainsString('resolvePublishedVersionAlongThemeChain', $source, 'Child package_defaults themes must inherit ancestor published solidified layouts (Filters default_injections).');
    self::assertStringContainsString('themeProvidesLayoutOverride', $source, 'Parent solidification must not clobber child design layout overrides (homepage brand content).');
    self::assertStringContainsString('getParentId', $source, 'Theme inheritance walk must use parent_id chain.');
    self::assertMatchesRegularExpression('/use\s+Weline\\\\Theme\\\\Model\\\\ThemeScopeVersion\s*;/', $source, 'ThemeScopeVersion return type must import Model namespace (not LayoutEntity).');
}

private function assert_SolidifyRelationOnlyHooks_testDocumentShellBakerDoesNotBakeHooksToPhp(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityDocumentShellBaker.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("ALGO_VERSION = 'docshell.v4'", $src);
    self::assertStringContainsString('Leave w:hook', $src);
    self::assertStringContainsString('document_shell_forbid_hook_bake', $src);
    // rewrite fringe must not convert hooks; phpGetHook must throw if called.
    $rewritePos = \strpos($src, 'function rewriteDocumentShellMarkup');
    $phpGetHookPos = \strpos($src, 'function phpGetHook');
    self::assertNotFalse($rewritePos);
    self::assertNotFalse($phpGetHookPos);
    $rewriteFn = \substr($src, $rewritePos, $phpGetHookPos - $rewritePos);
    self::assertStringNotContainsString('phpGetHook', $rewriteFn);
    self::assertStringContainsString('w:slot', $rewriteFn);
}

private function assert_SolidifyRelationOnlyHooks_testBakeFromSourceKeepsHookTags(): void
{
    $source = <<<'HTML'
    <!DOCTYPE html>
    <html lang="en"><head><title>t</title>
    <w:hook>Weline_Theme::frontend::layouts::homepage::head-after</w:hook>
    </head>
    <body id="top">
    <w:hook>Weline_Theme::frontend::layouts::homepage::body-start</w:hook>
    <main class="weline-main-content homepage-main" id="homepage-main">
    <w:slot id="homepage-hero" name="Hero"><w:widget type="banner" name="hero-slider" /></w:slot>
    </main>
    <w:hook>Weline_Theme::frontend::layouts::homepage::body-end</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::base::body-end</w:hook>
    </body></html>
    HTML;
    $baker = new ThemeLayoutEntityDocumentShellBaker();
    $segments = $baker->bakeFromSource($source, '(memory-homepage)');
    self::assertStringContainsString('<w:hook>Weline_Theme::frontend::layouts::homepage::head-after</w:hook>', $segments['preamble']);
    self::assertStringContainsString('<w:hook>Weline_Theme::frontend::layouts::homepage::body-end</w:hook>', $segments['postamble']);
    self::assertStringContainsString('<w:hook>Weline_Theme::frontend::layouts::base::body-end</w:hook>', $segments['postamble']);
    self::assertStringNotContainsString('Template::class)->getHook(', $segments['preamble']);
    self::assertStringNotContainsString('Template::class)->getHook(', $segments['postamble']);
    // Slots stripped from shell fringe (body owns them).
    self::assertStringNotContainsString('<w:slot', $segments['preamble']);
    self::assertStringNotContainsString('<w:slot', $segments['postamble']);
}

private function assert_ThemeLayoutEntityBakeCoordinator_testResourceReceiptRequiresRealOutputAndUsesContentDigest(): void
{
    $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($coordinator, 'resourceArtifactReceipt');
    $path = tempnam(sys_get_temp_dir(), 'theme-resource-receipt-');
    try {
        file_put_contents($path, '<section>solidified</section>');
        $receipt = $method->invoke($coordinator, 'page', $path);
        self::assertTrue($receipt['exists']);
        self::assertSame('page', $receipt['type']);
        self::assertSame(hash_file('sha256', $path), $receipt['artifact_id']);
        self::assertArrayNotHasKey('path', $receipt);
        unlink($path);
        $this->expectException(\RuntimeException::class);
        $method->invoke($coordinator, 'page', $path);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

private function assert_ThemeLayoutEntityBakeCoordinator_testConfigurationCommandsDoNotRequireStructureGeneration(): void
{
    $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
    self::assertFalse($coordinator->commandsAreStructural([['op' => 'SET', 'path' => '/nodes/example/config/title', 'value' => 'updated']]));
    foreach (['slot_id', 'sort_order', 'is_active', 'area'] as $field) {
        self::assertTrue($coordinator->commandsAreStructural([['op' => 'SET', 'path' => '/nodes/example/' . $field, 'value' => 'changed']]));
    }
    foreach (['ADD_NODE', 'REMOVE_NODE', 'MOVE_NODE'] as $op) {
        self::assertTrue($coordinator->commandsAreStructural([['op' => $op]]));
    }
}

private function assert_ThemeLayoutEntityBakeCoordinator_testChromeBakeMergesMiniCartFooterExtrasAndKeepsMiniCartPageExtras(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'mini-cart'", $source);
    self::assertStringContainsString('attachHeaderNativeMiniCartOwners', $source);
    self::assertStringContainsString('contentNodesForLayout', $source);
    self::assertStringContainsString('footer-extras', $source);
    self::assertMatchesRegularExpression("/mergeIntoNodes\\(\\s*\\\$nodes,\\s*\\\$version->getThemeId\\(\\),\\s*'mini-cart'/s", $source);
    $coordinator = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
    $content = new \ReflectionMethod($coordinator, 'contentNodesForLayout');
    $content->setAccessible(true);
    $nodes = ['coupon' => ['node_uid' => 'coupon', 'widget_code' => 'checkout-coupon', 'slot_id' => 'footer-extras', 'area' => 'footer'], 'page' => ['node_uid' => 'page', 'widget_code' => 'hero', 'slot_id' => 'content', 'area' => 'content']];
    $miniCart = $content->invoke($coordinator, 'mini-cart', $nodes);
    self::assertArrayHasKey('coupon', $miniCart);
    self::assertArrayHasKey('page', $miniCart);
    $homepage = $content->invoke($coordinator, 'homepage', $nodes);
    self::assertArrayNotHasKey('coupon', $homepage);
    self::assertArrayHasKey('page', $homepage);
}

private function assert_ThemeLayoutEntityBakeCoordinator_testSolidifyScopeVersionEntryPointsWirePublishAndDraftReset(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function solidifyScopeVersion', $source);
    self::assertStringContainsString('function solidifyCurrentScopeVersion', $source);
    self::assertStringContainsString('function bakePublishArtifactsForVersion', $source);
    self::assertStringContainsString("\$options['force_formal'] = true", $source);
    self::assertStringContainsString('theme_scope_version_unresolved', $source);
    self::assertStringContainsString('ensureCurrent(', $source);
    $solidifyCurrent = \strpos($source, 'function solidifyCurrentScopeVersion(');
    self::assertNotFalse($solidifyCurrent);
    $solidifySlice = \substr($source, $solidifyCurrent, 1200);
    self::assertStringContainsString('scopeVersions->ensureCurrent(', $solidifySlice);
    // Missing revision head (e.g. after draft reset) must bootstrap with initial=true.
    self::assertStringContainsString('$headMissing = $this->snapshotService()->head($version->toVersionIdentity()) === null', $source);
    self::assertStringContainsString('$initial = $version->getContentRevision() < 1 || $headMissing', $source);
    $reset = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeEditorDraftResetService.php');
    self::assertStringContainsString('solidifyCurrentScopeVersion(', $reset);
    $editor = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php');
    self::assertStringContainsString('->solidifyScopeVersion($context, (int)$result[\'theme_version_id\']', $editor);
    self::assertStringContainsString('theme_scope_draft_solidify_failed', $editor);
}

private function assert_ThemeLayoutEntityBakeCoordinator_testChromeBakeAndSolidifyPersistChromePayloadForStorefrontFill(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function resolveChromeNodesForBake', $source);
    self::assertStringContainsString('function persistChromePayload', $source);
    self::assertStringContainsString('$this->persistChromePayload($identity)', $source);
    self::assertStringNotContainsString('persistChromePayloadIfEmpty', $source);
    // theme:upgrade pipeline solidifyPendingIdentity must persist chrome (not only editor publish()).
    self::assertMatchesRegularExpression('/function solidifyPendingIdentity\b[\s\S]*?\$this->persistChromePayload\(\$identity\);/s', $source);
    self::assertMatchesRegularExpression('/function bakeChromeFromNodes.*?scopeVersions->setChromePayload\(\$version,/s', $source);
    // Must not leave chrome bake as in-memory setData only (storefront fill reads DB row).
    self::assertDoesNotMatchRegularExpression('/function bakeChromeFromNodes.*?\$version->setChromePayload\(\$this->slotTree->filterChromeNodes/s', $source);
}

private function assert_ThemeLayoutEntityBakeCoordinator_testRebakeDedupesLayoutTargetsAndSkipsPageDependencies(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function uniqueLayoutTargets', $source);
    self::assertStringContainsString('page_dependency', $source);
    self::assertStringContainsString('$expandCatalog = false', $source);
    self::assertStringContainsString('function generatedPageMatchesInputs', $source);
    self::assertStringContainsString('function layoutNeedsOwnSolidify', $source);
    self::assertStringContainsString('function pageDerivedWouldBeEmpty', $source);
    self::assertStringContainsString('function designOwnedPageTargets', $source);
    self::assertStringContainsString('$this->designOwnedPageTargets($identity)', $source);
    self::assertStringContainsString('skipped_inherit', $source);
    self::assertStringContainsString('skipped_unchanged', $source);
    self::assertStringContainsString('ThemeLayoutDesignOverride::provides', $source);
    // Design-owned pages must not be treated as empty (journal/faq bake member set).
    self::assertMatchesRegularExpression(
        '/function pageDerivedWouldBeEmpty\b[\s\S]*?ThemeLayoutDesignOverride::provides\(/s',
        $source,
    );
    self::assertMatchesRegularExpression(
        '/function pageCandidates\b[\s\S]*?\$designOwned\s*=\s*ThemeLayoutDesignOverride::provides\(/s',
        $source,
    );
    self::assertStringContainsString('function scopeOwnsSolidify', $source);
    self::assertStringContainsString('function hasOwnThemeApplication', $source);
    self::assertStringContainsString('$includeSelectedDraft = true', $source);
    self::assertStringContainsString("\$options['all_versions']", $source);
    self::assertStringContainsString("\$options['scope']", $source);
    self::assertStringContainsString("\$options['layout_type']", $source);
    self::assertStringContainsString("\$options['layout_option']", $source);
    self::assertStringContainsString('function applyLayoutCascadeFilter', $source);
    self::assertStringContainsString('function catalogLayoutOptions', $source);
    self::assertStringContainsString('!$allVersions && !$this->isCurrentlyEffectiveScopeVersion', $source);
    $upgrade = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
    $upgradeSrc = (string) file_get_contents($upgrade);
    // setup/theme upgrade without -t: only website-bound themes (not rebake(null) all themes).
    self::assertStringContainsString('themesForDefaultUpgrade', $upgradeSrc);
    self::assertStringContainsString('rebakeAfterInjectionCollect(', $upgradeSrc);
    self::assertStringNotContainsString('rebakeAfterInjectionCollect(null,', $upgradeSrc);
    self::assertStringContainsString("\$options['layout_type']", $upgradeSrc);
    self::assertStringContainsString("\$options['layout_option']", $upgradeSrc);
    $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
    $unique = new \ReflectionMethod($coordinator, 'uniqueLayoutTargets');
    $out = $unique->invoke($coordinator, [['layout_type' => 'checkout', 'layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0], ['layout_type' => 'checkout', 'layout_option' => 'default', 'resource_type' => 'page_dependency', 'target_id' => null], ['layout_type' => 'cart', 'layout_option' => 'default']]);
    self::assertCount(2, $out);
    self::assertSame('checkout', $out[0]['layout_type']);
    self::assertSame('cart', $out[1]['layout_type']);
    $identity = new ThemeVersionIdentity(1, 'default.default.default', 'live', 'frontend', 1, ThemeVersionIdentity::MODE_FORMAL, 1);
    $cascade = new \ReflectionMethod($coordinator, 'applyLayoutCascadeFilter');
    $cascade->setAccessible(true);
    $narrowed = $cascade->invoke($coordinator, [['layout_type' => 'checkout', 'layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0], ['layout_type' => 'cart', 'layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0], ['layout_type' => 'checkout', 'layout_option' => 'express', 'target_type' => 'global', 'target_id' => 0]], 'checkout', '', $identity);
    self::assertSame(['checkout'], array_values(array_unique(array_column($narrowed, 'layout_type'))));
    self::assertContains('default', array_column($narrowed, 'layout_option'));
    self::assertContains('express', array_column($narrowed, 'layout_option'));
    $one = $cascade->invoke($coordinator, [['layout_type' => 'checkout', 'layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0], ['layout_type' => 'checkout', 'layout_option' => 'express', 'target_type' => 'global', 'target_id' => 0]], 'checkout', 'express', $identity);
    self::assertCount(1, $one);
    self::assertSame('express', $one[0]['layout_option']);
}

private function assert_ThemeLayoutEntityFormalCompileCandidates_testPinnedClosureCapturesCandidateMapNotBareCandidates(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php');
    self::assertStringContainsString('$candidateMap = $candidates;', $src);
    self::assertMatchesRegularExpression('/runPinnedToIdentityWebsite\(\$identity,\s*function\s*\(\)\s*use\s*\([^)]*\$candidateMap[^)]*\)/s', $src);
    self::assertStringContainsString('snapshotFromPublishedCandidates(', $src);
    self::assertStringContainsString('?array $candidates', $src);
    self::assertStringContainsString('$candidates = is_array($candidates) ? $candidates : [];', $src);
}

private function assert_ThemeLayoutEntityLocaleComPrewarm_testPublishGateUsesFormalLocaleCompileFailClosed(): void
{
    $publisher = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php');
    $service = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php');
    self::assertIsString($publisher);
    self::assertIsString($service);
    self::assertStringContainsString('ThemeLayoutEntityFormalLocaleCompileService::class', $publisher);
    self::assertStringContainsString('compileAfterPromote', $publisher);
    self::assertStringContainsString("options['compile']", $publisher);
    self::assertStringContainsString('rollbackPromote', $publisher);
    self::assertStringContainsString('class ThemeLayoutEntityFormalLocaleCompileService', $service);
    self::assertStringContainsString('TemplateCompileService', $service);
    self::assertStringContainsString('主题布局发布', $service);
    self::assertStringContainsString('阶段1/2 Taglib编译', $service);
    self::assertStringContainsString('阶段2/2 资源合包预热', $service);
    self::assertStringContainsString('Taglib · 主题=', $service);
    self::assertStringContainsString('formatThemeProgressLabel', $service);
    self::assertStringContainsString('loadThemeForIdentity', $service);
    self::assertStringContainsString('theme#', $service);
    self::assertStringNotContainsString("resolveTheme('frontend'", $service, 'progress must not use process-global Default frontend theme');
    self::assertStringContainsString('progressBar', $service);
    self::assertStringContainsString('finishProgressLine', $service);
    self::assertStringContainsString('进程池', $service);
    self::assertStringContainsString('compileAfterPromoteFromPaths', $service);
    self::assertStringContainsString('loadPageCandidateSubset', $service);
    self::assertStringContainsString('stream_candidate_paths', $service);
    self::assertStringContainsString('indexPageLayoutsFromPaths', $service);
    self::assertStringContainsString('Parser::clearWorkerCaches', $service);
    self::assertStringContainsString('->uninstall(', $service);
    $compileSvc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/TemplateCompileService.php');
    self::assertStringContainsString('Parser::releaseLocaleWorkerCaches', $compileSvc);
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Template.php');
    self::assertStringContainsString('function clearPinnedSources', $template);
    self::assertStringContainsString('snapshotFromPublishedCandidates', $service);
    // Promote candidates must be closed over (PHP 8.4 typed arg ≠ silent null).
    self::assertStringContainsString('$candidateMap = $candidates', $service);
    self::assertTrue((bool) preg_match('/function\s*\(\s*\)\s*use\s*\([\s\S]*?\$candidateMap[\s\S]*?\)\s*:\s*void/m', $service), 'runPinnedToIdentityWebsite closure must use ($candidateMap)');
    // Prefer candidate snapshot before disk capture (avoid OwnerLock vs solidify WRITE).
    $loop = (string) preg_replace('/.*foreach \(\$pages as (?:\$pageIndex => )?\$page\) \{/s', 'foreach ($pages as $page) {', $service, 1);
    $preferPos = strpos($loop, 'snapshotFromPublishedCandidates');
    $capturePos = strpos($loop, 'ThemeLayoutSourceSnapshot::capture');
    self::assertNotFalse($preferPos);
    self::assertNotFalse($capturePos);
    self::assertLessThan($capturePos, $preferPos, 'candidate snapshot must run before disk capture');
    self::assertStringContainsString("'nested'", $service);
    self::assertStringContainsString('resolveConcurrency', $service);
    self::assertStringContainsString('getWebsiteLanguageCodes', $service);
    self::assertStringNotContainsString('w_log_warning', $publisher);
    self::assertStringNotContainsString('LocaleComPrewarm', $publisher);
}

private function assert_ThemeLayoutEntityLocaleComPrewarm_testFormalCompilePinsWebsiteFromIdentityBeforeCompile(): void
{
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php');
    self::assertStringContainsString('runPinnedToIdentityWebsite', $service);
    self::assertStringContainsString('runWithCompileTimeWebsitePin', $service);
    self::assertStringContainsString('WebsiteData::setWebsite', $service);
    self::assertStringContainsString('resolveWebsiteForCanonicalScope', $service);
    self::assertStringContainsString('compilePinnedSources', $service);
    self::assertStringContainsString('warmPublishedResourcePacks', $service);
    self::assertStringContainsString('ThemeHeadChromeCssPack', $service);
    self::assertStringContainsString('warmAreaPacks', $service);
    self::assertStringContainsString('ThemeRuntimeCacheCleaner', $service);
    self::assertStringContainsString('clearCachesAfterCompileWarm', $service);
    self::assertStringContainsString('ThemeStorefrontSlugFpcPublisher', $service);
    self::assertStringContainsString('purgeLayoutsForThemeTargets', $service);
    self::assertStringContainsString('theme_layout_formal_compile_scoped', $service);
    self::assertStringContainsString("cache_invalidation", $service);
    self::assertStringContainsString("'scoped'", $service);
    self::assertStringContainsString('clearAllThemeRelatedCaches', $service);
    self::assertStringContainsString('theme_layout_formal_compile_warm', $service);
    // clearAll only inside explicit cache_invalidation=all branch (cutover), never the default path.
    self::assertMatchesRegularExpression(
        '/if\s*\(\s*\$invalidation\s*===\s*[\'"]all[\'"]\s*\)\s*\{[^}]*clearAllThemeRelatedCaches\s*\(/s',
        $service,
    );
    self::assertStringNotContainsString('advancePublishedStorefrontGeneration', $service);
    $warmPos = strpos($service, 'warmPublishedResourcePacks($identity->area, $theme, $themeLabel, $identity->canonicalScope);');
    $clearPos = strpos($service, 'clearCachesAfterCompileWarm($theme, $identity, $pages, $invalidation);');
    self::assertNotFalse($warmPos);
    self::assertNotFalse($clearPos);
    self::assertGreaterThan($warmPos, $clearPos, 'post-warm cache clear must run after theme-head warm');
    self::assertMatchesRegularExpression('/runPinnedToIdentityWebsite\s*\(\s*\$identity\s*,/', $service);
    // Theme namespace isolation: Formal compile+warm must pin identity.themeId (not Default).
    self::assertStringContainsString('ThemeData::setCurrentTheme', $service);
    self::assertStringContainsString('sourceContextKey', $service);
    self::assertStringNotContainsString("'context_key' => \$snapshot->fingerprint()", $service, 'context_key must be per-source (stable across pages), not whole-snapshot fingerprint');
    self::assertMatchesRegularExpression('/warmPublishedResourcePacks\s*\(\s*\$identity->area\s*,\s*\$theme\s*,/', $service);
}

private function assert_ThemeLayoutEntityFormalScopedPurge_testDrainPathForbidsClearAll(): void
{
    $themeRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    $formal = (string) file_get_contents($themeRoot . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php');
    $publisher = (string) file_get_contents($themeRoot . '/Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php');
    $queue = (string) file_get_contents($themeRoot . '/Service/LayoutEntity/ThemeLayoutEntitySolidifyQueue.php');
    $pipeline = (string) file_get_contents($themeRoot . '/Service/LayoutEntity/ThemeLayoutEntitySolidifyCompilePipeline.php');
    // Drain / batch / pipeline must not force Formal full clear.
    self::assertStringNotContainsString('clearAllThemeRelatedCaches(', $publisher);
    self::assertStringNotContainsString('clearAllThemeRelatedCaches(', $queue);
    self::assertStringNotContainsString("cache_invalidation' => 'all'", $publisher);
    self::assertStringNotContainsString('cache_invalidation\' => \'all\'', $pipeline);
    self::assertStringNotContainsString('"cache_invalidation" => "all"', $pipeline);
    // Formal default is scoped purge API.
    self::assertStringContainsString("\$options['cache_invalidation'] ?? 'scoped'", $formal);
    self::assertStringContainsString('purgeLayoutsForThemeTargets', $formal);
    self::assertStringContainsString('theme_layout_formal_compile_scoped', $formal);
}

private function assert_ThemeLayoutEntityOwnerLockTimeout_testOwnerLockWaitIsAtLeastTwoMinutes(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityOwnerLock.php');
    self::assertStringContainsString('120_000_000_000', $src);
    self::assertStringNotContainsString('10_000_000_000', $src);
    self::assertStringContainsString('theme_layout_owner_lock_timeout', $src);
}

private string $origin_ThemeLayoutEntityPageInputDigest;

private function setUp_ThemeLayoutEntityPageInputDigest(): void
{
    parent::setUp();
    ThemeLayoutEntityPageInputDigest::resetRequestCache();
    $this->origin_ThemeLayoutEntityPageInputDigest = sys_get_temp_dir() . '/weline-page-input-digest-' . bin2hex(random_bytes(4)) . '.phtml';
    file_put_contents($this->origin_ThemeLayoutEntityPageInputDigest, "<!-- origin-a -->\n");
}

private function tearDown_ThemeLayoutEntityPageInputDigest(): void
{
    ThemeLayoutEntityPageInputDigest::resetRequestCache();
    if (is_file($this->origin_ThemeLayoutEntityPageInputDigest)) {
        @unlink($this->origin_ThemeLayoutEntityPageInputDigest);
    }
    parent::tearDown();
}

private function assert_ThemeLayoutEntityPageInputDigest_testMatchesStoredWhenOriginUnchanged(): void
{
    $digest = ThemeLayoutEntityPageInputDigest::forOrigin($this->origin_ThemeLayoutEntityPageInputDigest);
    self::assertNotSame('', $digest);
    self::assertTrue(ThemeLayoutEntityPageInputDigest::matchesStored($digest, $this->origin_ThemeLayoutEntityPageInputDigest));
}

private function assert_ThemeLayoutEntityPageInputDigest_testMismatchWhenOriginBytesChange(): void
{
    $digest = ThemeLayoutEntityPageInputDigest::forOrigin($this->origin_ThemeLayoutEntityPageInputDigest);
    file_put_contents($this->origin_ThemeLayoutEntityPageInputDigest, "<!-- origin-b -->\n");
    self::assertFalse(ThemeLayoutEntityPageInputDigest::matchesStored($digest, $this->origin_ThemeLayoutEntityPageInputDigest));
}

private function assert_ThemeLayoutEntityPageInputDigest_testEmptyStoredDigestIsStale(): void
{
    self::assertFalse(ThemeLayoutEntityPageInputDigest::matchesStored('', $this->origin_ThemeLayoutEntityPageInputDigest));
    self::assertFalse(ThemeLayoutEntityPageInputDigest::matchesStored('   ', $this->origin_ThemeLayoutEntityPageInputDigest));
}

private function assert_ThemeLayoutEntityPageInputDigest_testMaterializerAndBakeAndGateShareHelper(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity';
    $materializer = (string) file_get_contents($root . '/ThemeLayoutEntityMaterializer.php');
    $bake = (string) file_get_contents($root . '/ThemeLayoutEntityBakeCoordinator.php');
    $gate = (string) file_get_contents($root . '/ThemeLayoutEntityRequestSolidifyGate.php');
    self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::forOrigin', $materializer);
    self::assertStringContainsString("'input_digest' => ThemeLayoutEntityPageInputDigest::forOrigin(\$origin)", $materializer);
    self::assertGreaterThanOrEqual(2, substr_count($materializer, 'ThemeLayoutEntityPageInputDigest::forOrigin'), 'page + chrome (and deps) must write input_digest');
    self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $bake);
    self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::planDigest', $bake);
    self::assertStringContainsString('chromePartialDiskStaleOrRaw', $bake);
    self::assertStringContainsString('rematerializeChromeIfStale', $bake);
    self::assertStringContainsString('discoverStaleChromePartialPairs', $bake);
    self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $gate);
    self::assertStringContainsString('design_origin_stale_fallback_original', $gate);
    self::assertStringContainsString('design_chrome_origin_stale_fallback_original', $gate);
    $queue = (string) file_get_contents($root . '/ThemeLayoutEntitySolidifyQueue.php');
    self::assertStringContainsString('rematerializeChromeIfStale', $queue);
    $cli = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Console/Theme/LayoutSolidifyStatus.php';
    self::assertFileExists($cli);
    $cliSrc = (string) file_get_contents($cli);
    self::assertStringContainsString('theme:layout-solidify-status', $cliSrc);
    self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $cliSrc);
    // Debt #1: upgrade/pipeline must heal draft chrome even when page pending is empty / skipped.
    self::assertStringContainsString('rematerializeChromeIfStale', $bake);
    self::assertGreaterThanOrEqual(3, substr_count($bake, 'rematerializeChromeIfStale'), 'BakeCoordinator: method + solidifyPendingIdentity + empty-pending collect heal (+ pipeline post-pass)');
    self::assertStringContainsString('toVersionIdentity()', $bake);
    self::assertStringContainsString('Pages unchanged / inherit-skipped', $bake);
    self::assertStringContainsString("'/draft/'", $cliSrc);
    self::assertStringContainsString("'draft'", $cliSrc);
    self::assertStringContainsString("'formal'", $cliSrc);
}

private function assert_ThemeLayoutEntityPaths_testDistinctScopesAndStoreModesCannotShareDirectories(): void
{
    $paths = new ThemeLayoutEntityPaths('/tmp/theme-layout-entities-contract/');
    $owners = [['shop.eu.default', 'normal'], ['shop_eu.default', 'normal'], ['shop-a.default.default', 'normal'], ['shop_a.default.default', 'normal'], ['Shop.eu.default', 'normal'], ['shop.eu.default__test', 'normal'], ['shop.eu.default', 'test'], ['shop.eu.default', 'Test']];
    $directories = [];
    foreach ($owners as [$scope, $mode]) {
        $identity = new ThemeVersionIdentity(3, $scope, $mode, 'frontend', 10, 'formal', 2);
        $directories[] = strtolower($paths->ownerDir($identity));
    }
    self::assertCount(count($owners), array_unique($directories));
}

private function assert_ThemeLayoutEntityPaths_testLayoutOptionsTargetsAndNestedTypesRemainSeparate(): void
{
    $paths = new ThemeLayoutEntityPaths('/tmp/theme-layout-entities-contract/');
    $formal = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
    $draft = $formal->withVersion(10, 'draft', 2);
    $plain = $paths->pageLayoutPhtml($formal, 'account/login');
    self::assertStringEndsWith('/v10/pages/layouts/account/login/default.phtml', $plain);
    self::assertStringEndsWith('/draft/pages/layouts/account/login/default.phtml', $paths->pageLayoutPhtml($draft, 'account/login'));
    self::assertNotSame($plain, $paths->pageLayoutPhtml($formal, 'account/login', 'compact'));
    self::assertNotSame($plain, $paths->pageLayoutPhtml($formal, 'account/login', 'default', 'product', 12));
    self::assertNotSame($paths->pageLayoutPhtml($formal, 'account/login', 'default', 'product', 12), $paths->pageLayoutPhtml($formal, 'account/login', 'default', 'product', 13));
}

private function assert_ThemeLayoutEntityPaths_testPartialOptionsArePreserved(): void
{
    $paths = new ThemeLayoutEntityPaths('/tmp/theme-layout-entities-contract/');
    $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 2);
    self::assertTrue(method_exists($paths, 'partialPhtml'), 'The shared partial loader needs a type/option path.');
    self::assertStringEndsWith('/v10/theme/partials/header/compact.phtml', $paths->partialPhtml($identity, 'header', 'compact'));
    self::assertNotSame($paths->partialPhtml($identity, 'header'), $paths->partialPhtml($identity, 'header', 'compact'));
}

private function assert_ThemeLayoutEntityPaths_testLongestLegalScopeCanBeCreatedAndEnumerated(): void
{
    $root = sys_get_temp_dir() . '/weline-path-' . bin2hex(random_bytes(6)) . '/theme-layout-entities';
    $paths = new ThemeLayoutEntityPaths($root);
    $scope = str_repeat('W', 255) . '.' . str_repeat('s', 64) . '.' . str_repeat('c', 64);
    $identity = new ThemeVersionIdentity(3, $scope, 'normal', 'frontend', 10, 'formal', 2);
    $path = $paths->pageLayoutPhtml($identity, 'homepage');
    try {
        self::assertTrue(@mkdir(dirname($path), 0770, true), 'Legal scope must fit filesystem component limits.');
        self::assertSame(2, file_put_contents($path, 'ok'));
        self::assertSame('ok', file_get_contents($path));
        $versions = $paths->listVersionModeDirectories();
        self::assertCount(1, $versions);
        self::assertSame(10, $versions[0]['theme_version_id']);
        self::assertSame($scope, $versions[0]['canonical_scope']);
        self::assertSame('normal', $versions[0]['store_mode']);
    } finally {
        if (is_dir($root)) {
            $paths->purgeAllEntities();
            @rmdir(dirname($root));
        }
    }
}

private function tearDown_ThemeLayoutEntityPathsStaging(): void
{
    putenv(DeployStagingSession::ENV_ROOT);
    unset($_ENV[DeployStagingSession::ENV_ROOT], $_SERVER[DeployStagingSession::ENV_ROOT]);
    parent::tearDown();
}

private function assert_ThemeLayoutEntityPathsStaging_testRootFollowsStagingEnv(): void
{
    $root = rtrim(sys_get_temp_dir(), '/\\') . '/weline-theme-paths-staging-' . bin2hex(random_bytes(3));
    mkdir($root . '/theme-layout-entities', 0775, true);
    putenv(DeployStagingSession::ENV_ROOT . '=' . $root);
    $_ENV[DeployStagingSession::ENV_ROOT] = $root;
    $paths = new ThemeLayoutEntityPaths();
    self::assertStringStartsWith(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'theme-layout-entities', rtrim($paths->root(), '/\\'));
    @rmdir($root . '/theme-layout-entities');
    @rmdir($root);
}

private function assert_ThemeLayoutEntityPathsStaging_testSourceMentionsStagingSession(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php');
    self::assertStringContainsString('DeployStagingSession', $src);
    self::assertStringContainsString('themeLayoutEntitiesRoot', $src);
}

private string $root_ThemeLayoutEntityRequestSolidifyGate;

private function setUp_ThemeLayoutEntityRequestSolidifyGate(): void
{
    parent::setUp();
    $this->root_ThemeLayoutEntityRequestSolidifyGate = sys_get_temp_dir() . '/weline-solidify-gate-' . bin2hex(random_bytes(4));
    @mkdir($this->root_ThemeLayoutEntityRequestSolidifyGate, 0775, true);
    ThemeLayoutEntitySolidifyQueue::resetRequestEnqueued();
}

private function tearDown_ThemeLayoutEntityRequestSolidifyGate(): void
{
    ThemeLayoutEntitySolidifyQueue::resetRequestEnqueued();
    $this->purge_ThemeLayoutEntityRequestSolidifyGate($this->root_ThemeLayoutEntityRequestSolidifyGate);
    parent::tearDown();
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testSerialKeyStableAndLayoutDimensionDiffers(): void
{
    $a = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'default.default.default', 'normal', 'homepage', 'default', 1, 1);
    $b = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'default.default.default', 'normal', 'homepage', 'default', 1, 1);
    $c = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'default.default.default', 'normal', 'mini-cart', 'default', 1, 1);
    $d = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'default.default.default', 'normal', 'homepage', 'default', 9, 9);
    self::assertSame($a->toString(), $b->toString());
    self::assertSame($a->hash(), $b->hash());
    self::assertSame($a->slotHash(), $d->slotHash());
    self::assertSame($a->hash(), $d->hash());
    self::assertNotSame($a->toString(), $d->toString());
    self::assertNotSame($a->layoutDimension(), $c->layoutDimension());
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testLeaseSameSlotAcrossVersionSharesFile(): void
{
    $paths = new ThemeLayoutEntityPaths($this->root_ThemeLayoutEntityRequestSolidifyGate . DIRECTORY_SEPARATOR);
    $leases = new ThemeLayoutEntitySolidifyLeaseStore($paths);
    $v1 = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'homepage', 'default', 1, 1);
    $v2 = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'homepage', 'default', 2, 2);
    self::assertTrue($leases->tryAcquire($v1));
    self::assertFalse($leases->tryAcquire($v2));
    $leases->release($v1);
    self::assertTrue($leases->tryAcquire($v2));
    $leases->release($v2);
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testLeaseSameKeyCoalescesSecondAcquire(): void
{
    $paths = new ThemeLayoutEntityPaths($this->root_ThemeLayoutEntityRequestSolidifyGate . DIRECTORY_SEPARATOR);
    $leases = new ThemeLayoutEntitySolidifyLeaseStore($paths);
    $key = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'homepage', 'default', 0, 0);
    self::assertTrue($leases->tryAcquire($key));
    self::assertTrue($leases->isPending($key));
    self::assertFalse($leases->tryAcquire($key));
    $leases->release($key);
    self::assertFalse($leases->isPending($key));
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testStampRoundTrip(): void
{
    $paths = new ThemeLayoutEntityPaths($this->root_ThemeLayoutEntityRequestSolidifyGate . DIRECTORY_SEPARATOR);
    $stamps = new ThemeLayoutEntitySolidifyStampStore($paths);
    $key = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'homepage', 'default', 3, 2);
    self::assertNull($stamps->read($key));
    $stamps->write($key, str_repeat('a', 64));
    self::assertSame(str_repeat('a', 64), $stamps->read($key));
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testDeadLetterCountsConsecutiveFailuresAndClears(): void
{
    $paths = new ThemeLayoutEntityPaths($this->root_ThemeLayoutEntityRequestSolidifyGate . DIRECTORY_SEPARATOR);
    $jobs = new ThemeLayoutEntitySolidifyJobStore($paths);
    $key = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'category', 'default', 3, 2);
    self::assertNull($jobs->failureState($key));
    self::assertFalse($jobs->clearFailure($key));

    // Three consecutive drain failures accumulate on the same slot-scoped dead-letter file.
    for ($i = 1; $i <= 3; $i++) {
        $jobs->put($key, str_repeat('f', 64));
        self::assertTrue($jobs->moveToFailed($key, 'di_mismatch_' . $i));
        self::assertFalse($jobs->has($key), 'moveToFailed must remove the pending slot');
        $state = $jobs->failureState($key);
        self::assertIsArray($state);
        self::assertSame($i, $state['fail_count']);
        self::assertSame('di_mismatch_' . $i, $state['fail_reason']);
        self::assertGreaterThan(0, $state['failed_at']);
    }
    // A later version of the same slot shares the dead-letter record (slot hash, not V/R).
    $later = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'category', 'default', 9, 9);
    self::assertSame(3, $jobs->failureState($later)['fail_count'] ?? null);

    // Successful bake clears the record exactly once.
    self::assertTrue($jobs->clearFailure($later));
    self::assertNull($jobs->failureState($key));
    self::assertFalse($jobs->clearFailure($key));
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testCircuitBreakerStopsForbidAndEnqueue(): void
{
    $gate = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityRequestSolidifyGate.php';
    $queue = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntitySolidifyQueue.php';
    $gateSrc = (string) file_get_contents($gate);
    $queueSrc = (string) file_get_contents($queue);

    // Queue owns the breaker policy (env thresholds), Gate only consumes it.
    self::assertStringContainsString('public function circuitState(', $queueSrc);
    self::assertStringContainsString("theme.layout_entity.solidify_circuit_failures", $queueSrc);
    self::assertStringContainsString("theme.layout_entity.solidify_circuit_cooldown_seconds", $queueSrc);
    self::assertStringContainsString('$this->jobs->clearFailure($key)', $queueSrc);
    self::assertStringContainsString('invalidateScopeAfterRecovery', $queueSrc);
    self::assertStringContainsString("'theme_layout_solidify_circuit_recovered'", $queueSrc);
    // Bake must not save/markPublished an already sealed+published version on every run.
    self::assertStringContainsString('if ($needsSeal) {', $queueSrc);
    self::assertStringContainsString('if ($needsSeal || !$version->isPublished()) {', $queueSrc);

    self::assertStringContainsString('$this->queue->circuitState($key)', $gateSrc);
    self::assertStringContainsString("'solidify_circuit_open'", $gateSrc);
    self::assertStringContainsString('if ($needsJob && !$circuitOpen) {', $gateSrc);
    // forbid() must be guarded by the breaker: open circuit keeps shared FPC allowed.
    $forbidPos = strpos($gateSrc, "SharedResponseCachePolicy::forbid('theme_layout_solidify_fallback_original')");
    self::assertNotFalse($forbidPos);
    $guardPos = strrpos(substr($gateSrc, 0, $forbidPos), 'if (!$circuitOpen) {');
    self::assertNotFalse($guardPos, 'forbid() must sit inside the !$circuitOpen guard');
    self::assertLessThan(200, $forbidPos - $guardPos);
    self::assertStringContainsString("'circuit_open' => \$circuitOpen", $gateSrc);
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testFiltersInventoryBakeVerdict(): void
{
    $gateRef = new \ReflectionClass(ThemeLayoutEntityRequestSolidifyGate::class);
    $gate = $gateRef->newInstanceWithoutConstructor();
    $verdict = $gateRef->getMethod('filtersInventoryVerdict');
    $verdict->setAccessible(true);
    $judge = static fn(string $blob, string $origin): array => $verdict->invoke($gate, $blob, $origin);

    $design = BP . 'app/design/Weline/hanfu/frontend/layouts/';
    // Category design layout mounts NO Filters anchor at all; search mounts id="search-filters"
    // whose accept="" list merely mentions category-filters; products mounts <w:slot id="list-filters">.
    $categoryOrigin = $design . 'category/default.phtml';
    $searchOrigin = $design . 'search/default.phtml';
    $productsOrigin = $design . 'products/default.phtml';
    self::assertFileExists($categoryOrigin);
    self::assertFileExists($searchOrigin);
    self::assertFileExists($productsOrigin);

    // 1) No Filters slot mounted → judgement not applicable. This is the "job never completes"
    //    regression: it used to stay true forever, re-enqueueing on every request.
    self::assertSame(
        ['broken' => false, 'reason' => 'no_filters_slot'],
        $judge('<div class="forms-tiles">no filters</div>', $categoryOrigin),
    );
    // 2) Counterexample: accept="…,layout-category-filters,category-filters,…" must NOT count as a mount.
    self::assertStringContainsString(
        'accept="layout-search-filters,layout-category-filters,category-filters',
        (string) file_get_contents($searchOrigin),
    );
    self::assertSame(
        ['broken' => false, 'reason' => 'no_filters_slot'],
        $judge('<div class="search-shell">results</div>', $searchOrigin),
    );
    // 3) Layout mounts list-filters but bake produced no Filters → the safety net must stay ON.
    self::assertSame(
        ['broken' => true, 'reason' => 'widget_missing_mount_present'],
        $judge('<div class="products-grid">grid</div>', $productsOrigin),
    );
    // 4) Declaration placeholder survived the bake → broken.
    self::assertSame(
        ['broken' => true, 'reason' => 'placeholder_left'],
        $judge('<div data-placeholder="list-filters"></div>', $productsOrigin),
    );
    // 5) Healthy bake → not broken.
    self::assertSame(
        ['broken' => false, 'reason' => 'filters_baked'],
        $judge('Weline_Filters data-widget-code="category-filters" renderResolved(', $productsOrigin),
    );
    // 6) Unknown origin → conservative broken (logged + surfaced in decision['filter_inventory_bake']).
    self::assertSame(
        ['broken' => true, 'reason' => 'origin_unknown'],
        $judge('<div>x</div>', '/nonexistent/layouts/category/default.phtml'),
    );
    // 7) Empty derived page → broken.
    self::assertSame(
        ['broken' => true, 'reason' => 'derived_page_empty'],
        $judge('', $productsOrigin),
    );
    // 8) S1 counterexample: bare `id` anchor must NOT swallow *-id attributes
    //    (`data-widget-id="category-filters"` is a widget instance id, not a slot mount).
    $tmpOrigin = sys_get_temp_dir() . '/weline-filter-slot-anchor-' . bin2hex(random_bytes(4)) . '.phtml';
    try {
        file_put_contents(
            $tmpOrigin,
            '<div data-widget-id="category-filters"></div><div data-entity-id="list-filters"></div>',
        );
        self::assertSame(
            ['broken' => false, 'reason' => 'no_filters_slot'],
            $judge('<div>x</div>', $tmpOrigin),
        );
        // 9) A real plain `id="list-filters"` mount still counts.
        file_put_contents($tmpOrigin, '<div id="list-filters"></div>');
        self::assertSame(
            ['broken' => true, 'reason' => 'widget_missing_mount_present'],
            $judge('<div>x</div>', $tmpOrigin),
        );
    } finally {
        @unlink($tmpOrigin);
    }
}

private function assert_ThemeLayoutEntityRequestSolidifyGate_testQueueClassesAndGateExist(): void
{
    $gate = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityRequestSolidifyGate.php';
    $queue = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntitySolidifyQueue.php';
    $observer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/ControllerFetchFileBefore.php';
    self::assertFileExists($gate);
    self::assertFileExists($queue);
    $gateSrc = (string) file_get_contents($gate);
    $queueSrc = (string) file_get_contents($queue);
    $obsSrc = (string) file_get_contents($observer);
    self::assertStringContainsString('use_original_template', $gateSrc);
    self::assertStringContainsString('enqueued_fallback_original', $gateSrc);
    self::assertStringContainsString('stamp_fresh_source_template', $gateSrc);
    self::assertStringContainsString('stampFresh', $gateSrc);
    self::assertStringContainsString('($derivedMissing && !$stampFresh)', $gateSrc);
    self::assertStringContainsString('isFiltersCritical', $gateSrc);
    self::assertStringContainsString('filterInventoryBakeBroken', $gateSrc);
    self::assertStringContainsString('empty_filters_inventory_fallback_original', $gateSrc);
    self::assertStringContainsString('design_origin_stale_fallback_original', $gateSrc);
    self::assertStringContainsString('design_chrome_origin_stale_fallback_original', $gateSrc);
    self::assertStringContainsString('derivedPageOriginStale', $gateSrc);
    self::assertStringContainsString('derivedChromeOriginStale', $gateSrc);
    self::assertStringContainsString('CRITICAL_CHROME_PARTIALS', $gateSrc);
    self::assertStringContainsString('WELINE_THEME_SOLIDIFY_DEBUG', $gateSrc);
    self::assertStringContainsString('exportSolidifyDecisionObservability', $gateSrc);
    self::assertStringContainsString('X-Weline-Solidify-Decision', $gateSrc);
    $obsPos = strpos($gateSrc, 'function exportSolidifyDecisionObservability');
    self::assertNotFalse($obsPos);
    $headerPos = strpos($gateSrc, 'X-Weline-Solidify-Decision', $obsPos);
    $debugPos = strpos($gateSrc, "getenv('WELINE_THEME_SOLIDIFY_DEBUG')", $obsPos);
    self::assertNotFalse($headerPos);
    self::assertNotFalse($debugPos);
    // Fallback Decision header must emit before the debug-only log gate.
    self::assertLessThan($debugPos, $headerPos);
    self::assertStringContainsString('ThemeLayoutEntityPageInputDigest::matchesStored', $gateSrc);
    self::assertStringContainsString("SharedResponseCachePolicy::forbid('theme_layout_solidify_fallback_original')", $gateSrc);
    self::assertStringContainsString('CTX_DERIVED_PAGE_PATH_MEMO', $gateSrc);
    self::assertStringContainsString('evaluateRequestKey', $gateSrc);
    self::assertStringContainsString("'_eval_key'", $gateSrc);
    self::assertStringContainsString('RequestContext::get(self::CTX_DECISION)', $gateSrc);
    self::assertStringNotContainsString("setHeader('CDN-Cache-Control'", $gateSrc);
    self::assertStringNotContainsString("setHeader('Cloudflare-CDN-Cache-Control'", $gateSrc);
    self::assertStringContainsString('ThemeLayoutEntitySolidifyJobStore', $queueSrc);
    self::assertStringContainsString('drainPendingJobs', $queueSrc);
    self::assertStringContainsString('Runtime::isPersistent()', $queueSrc);
    self::assertStringContainsString('PostResponseTaskQueue::enqueue', $queueSrc);
    self::assertStringContainsString('runDistinctLayoutJobsConcurrently', $queueSrc);
    self::assertStringContainsString('ThemeLayoutEntityRequestSolidifyGate', $obsSrc);
    self::assertStringContainsString('forceOriginalSolidifyGate', $obsSrc);
    $cron = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Cron/LayoutSolidifyDrain.php';
    self::assertFileExists($cron);
    $cronSrc = (string) file_get_contents($cron);
    self::assertStringContainsString('theme_layout_solidify_drain', $cronSrc);
    self::assertStringContainsString('drainPendingJobs', $cronSrc);
}

private function purge_ThemeLayoutEntityRequestSolidifyGate(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

private function assert_ThemeLayoutEntitySolidifyCompilePipeline_testPipelineExposesDualPoolsAndWorkers(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntitySolidifyCompilePipeline.php';
    $src = file_get_contents($path);
    self::assertIsString($src);
    self::assertStringContainsString('WELINE_THEME_SOLIDIFY_CONCURRENCY', $src);
    self::assertStringContainsString('WELINE_THEME_COMPILE_CONCURRENCY', $src);
    self::assertStringContainsString('HostProcessPoolPolicy', $src);
    self::assertStringContainsString('resolveConcurrencyDecision', $src);
    self::assertStringContainsString('formatPoolDecisionNote', $src);
    self::assertStringContainsString('solidify_source=%s compile_source=%s', $src);
    self::assertStringContainsString('solidify-identity-job.php', $src);
    self::assertStringContainsString('compile-identity-job.php', $src);
    self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_NESTED', $src);
    self::assertStringContainsString('runSolidifyJob', $src);
    self::assertStringContainsString('runCompileJob', $src);
    self::assertStringContainsString('isInsideWlsWorker', $src);
    self::assertStringContainsString('rollbackPromote', $src);
    self::assertStringContainsString('findSolidifyQueueIndex', $src);
    self::assertStringContainsString('collectBusyOwnerHashes', $src);
    self::assertStringContainsString('flushPendingRollbacks', $src);
    self::assertStringContainsString('terminateRunningWorkers', $src);
    self::assertStringContainsString('terminated_by_parent', $src);
    self::assertStringContainsString('ownerHasRunningWorker', $src);
    self::assertStringContainsString('ownerHash', $src);
    self::assertStringContainsString('pendingRollbacks', $src);
    self::assertStringContainsString('deploy_staging_root', $src);
    self::assertStringContainsString('applyDeployStagingRootFromJob', $src);
    self::assertStringContainsString('workerEnvironment', $src);
    self::assertStringNotContainsString('WORKER_MEMORY_LIMIT', $src);
    self::assertStringNotContainsString('memory_limit=', $src);
    self::assertStringNotContainsString('PhpCliRuntimePreflight', $src);
    self::assertStringContainsString('expectedPhaseTotal', $src);
    self::assertStringContainsString("'done'", $src);
    self::assertStringContainsString('formatPipelineProgressLabel', $src);
    self::assertStringContainsString('themes=%s', $src);
    self::assertStringContainsString('s=%d/%d c=%d/%d', $src);
    // After proc_get_status reaps, trust exitcode — never use proc_close() return as exit.
    self::assertStringContainsString("array_key_exists('exitcode', \$status)", $src);
    self::assertStringContainsString("'on_progress'", $src);
    self::assertStringNotContainsString('Fiber::', $src);
    // Forbid naive identities×2 denominator (drafts never compile).
    self::assertStringNotContainsString('$total * 2', $src);
}

private function assert_ThemeLayoutEntitySolidifyCompilePipeline_testConcurrencyClampCeilingConstants(): void
{
    $ref = new \ReflectionClass(ThemeLayoutEntitySolidifyCompilePipeline::class);
    self::assertSame(10, $ref->getConstant('DEFAULT_CONCURRENCY'));
    self::assertSame(32, $ref->getConstant('MAX_CONCURRENCY'));
    self::assertFalse($ref->hasConstant('WORKER_MEMORY_LIMIT'));
}

private function assert_ThemeLayoutEntitySolidifyCompilePipeline_testWorkerScriptsExist(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/bin';
    self::assertFileExists($base . '/solidify-identity-job.php');
    self::assertFileExists($base . '/compile-identity-job.php');
    $solidify = (string) file_get_contents($base . '/solidify-identity-job.php');
    $compile = (string) file_get_contents($base . '/compile-identity-job.php');
    self::assertStringContainsString('runSolidifyJob', $solidify);
    self::assertStringContainsString('runCompileJob', $compile);
    self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_NESTED', $compile);
    self::assertStringNotContainsString('raiseCliMemoryFloor', $solidify);
    self::assertStringNotContainsString('raiseCliMemoryFloor', $compile);
    self::assertStringNotContainsString("ini_set('memory_limit'", $solidify);
    self::assertStringNotContainsString("ini_set('memory_limit'", $compile);
}

private function assert_ThemeLayoutEntitySolidifyCompilePipeline_testBatchPublisherCompileOptionAndPromoteSplit(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("options['compile']", $src);
    self::assertStringContainsString('function promote(', $src);
    self::assertStringContainsString('function rollbackPromote(', $src);
}

private function assert_ThemeLayoutEntitySolidifyCompilePipeline_testBakeCoordinatorDelegatesToPipeline(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('ThemeLayoutEntitySolidifyCompilePipeline', $src);
    self::assertStringContainsString('solidifyPendingIdentity', $src);
    self::assertStringContainsString('collectRebakeWorkItems', $src);
    self::assertStringContainsString('rebakeThemesAfterInjectionCollect', $src);
    self::assertStringContainsString('Flatten multi-theme upgrade', $src);
}

private function assert_ThemeLayoutEntityUpgradePurge_testPathsExposeGeneratedRootAndLegacyMigrate(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('function purgeAllEntities', $src);
    self::assertStringContainsString('function purgeEntityTree', $src);
    self::assertStringContainsString('function assertPurgeableEntityRoot', $src);
    self::assertStringContainsString('function legacyVarRoot', $src);
    self::assertStringContainsString('function migrateLegacyVarTreeToGenerated', $src);
    self::assertStringContainsString('GENERATED_DIR', $src);
    self::assertStringContainsString('theme_layout_entity_purge_outside_allowed_root', $src);
    self::assertStringContainsString('theme_layout_entity_purge_basename_mismatch', $src);
    self::assertStringContainsString('ROOT_SEGMENT', $src);
}

private function assert_ThemeLayoutEntityUpgradePurge_testDefaultRootIsUnderGenerated(): void
{
    if (!\defined('BP')) {
        self::markTestSkipped('BP undefined');
    }
    $paths = new ThemeLayoutEntityPaths();
    $root = \str_replace('\\', '/', $paths->root());
    self::assertStringContainsString('/generated/theme-layout-entities/', $root);
    self::assertStringNotContainsString('/var/runtime/theme-layout-entities/', $root);
}

private function assert_ThemeLayoutEntityUpgradePurge_testUpgradeEventsRegisterSolidificationObserver(): void
{
    $xml = simplexml_load_file('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/etc/event.xml');
    self::assertNotFalse($xml);
    foreach (['Weline_Framework_Setup::upgrade_after', 'Weline_Framework_Deploy::upgrade_after', 'Weline_Deploy::core_update_after'] as $event) {
        $observers = $xml->xpath('//*[local-name()="event" and @name="' . $event . '"]/*[local-name()="observer"]');
        $classes = array_map(static fn($observer): string => (string) $observer['instance'], $observers);
        self::assertContains('Weline\Theme\Observer\SetupUpgradeAfterPurgeLayoutEntities', $classes, $event);
    }
}

private function assert_ThemeLayoutEntityUpgradePurge_testThemeUpgradeCommandWiresLayoutSolidify(): void
{
    $upgrade = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeUpdate/ThemeUpgradeCutoverService.php';
    self::assertFileExists($upgrade);
    $src = (string) \file_get_contents($upgrade);
    self::assertStringContainsString('ThemeLayoutEntityUpgradeSolidifyService', $src);
    self::assertStringContainsString('cutoverFromThemeCommand', $src);
    self::assertStringContainsString('purge 旧布局固化物', $src);
    // Routine theme upgrade = layout-scoped URL invalidation, never full clear.
    self::assertStringContainsString("cache_invalidation'] = 'none'", $src);
    self::assertStringContainsString('purgeLayoutsForThemeTargets', $src);
    self::assertStringContainsString('theme_upgrade_scoped', $src);
    self::assertStringContainsString('flushScopedStorefrontCaches', $src);
}

private function assert_ThemeLayoutEntityUpgradePurge_testCutoverOnlyFullClearsWhenExplicitlyRequested(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme'
        . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("\$options['cache_invalidation'] ?? 'all'", $src);
    self::assertMatchesRegularExpression(
        '/if\s*\(\s*\$invalidation\s*===\s*[\'"]all[\'"]\s*\)\s*\{[^}]*clearAllThemeRelatedCaches\s*\(/s',
        $src,
    );
}

private function assert_ThemeLayoutEntityUpgradePurge_testDeployUpgradeAndCoreUpdateDispatchLifecyclePurgeEvents(): void
{
    $welineRoot = '/Users/weline/Project/Official/框架/app/code/Weline';
    $deployUpgrade = $welineRoot . '/Framework/Console/Console/Deploy/Upgrade.php';
    self::assertFileExists($deployUpgrade);
    $deploySrc = (string) \file_get_contents($deployUpgrade);
    self::assertStringContainsString("dispatch('Weline_Framework_Deploy::upgrade_after'", $deploySrc);
    $coreUpdate = $welineRoot . '/Deploy/Console/Update/Core.php';
    self::assertFileExists($coreUpdate);
    $coreSrc = (string) \file_get_contents($coreUpdate);
    self::assertStringContainsString("dispatch('Weline_Deploy::core_update_after'", $coreSrc);
}

private function assert_ThemeLayoutEntityUpgradePurge_testOfflineGcHooksReuseThemeRuntimeCacheCleaner(): void
{
    $cleaner = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeRuntimeCacheCleaner.php');
    self::assertStringContainsString('function sweepOrphanLayoutEntityArtifacts', $cleaner);
    self::assertStringContainsString('listVersionModeDirectories', $cleaner);
    $paths = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php');
    self::assertStringContainsString('function listVersionModeDirectories', $paths);
    self::assertStringContainsString('function purgeVersionModeDirectory', $paths);
}

private function assert_ThemeLayoutEntityUpgradePurge_testListAndPurgeVersionModeDirectoryLeavesNeighborIntact(): void
{
    $root = sys_get_temp_dir() . '/theme-purge-' . bin2hex(random_bytes(6)) . '/theme-layout-entities';
    $paths = new ThemeLayoutEntityPaths($root);
    $formal = new \Weline\Theme\Api\Version\ThemeVersionIdentity(7, 'shop.cn.app', 'test', 'frontend', 10, 'formal', 2);
    $draft = $formal->withVersion(10, 'draft', 2);
    $keep = $paths->pageLayoutPhtml($formal, 'homepage');
    $drop = $paths->pageLayoutPhtml($draft, 'homepage');
    try {
        mkdir(dirname($keep), 0770, true);
        mkdir(dirname($drop), 0770, true);
        file_put_contents($keep, 'FORMAL');
        file_put_contents($drop, 'DRAFT');
        self::assertCount(2, $paths->listVersionModeDirectories());
        self::assertGreaterThan(0, $paths->purgeVersionModeDirectory($paths->versionModeDir($draft)));
        self::assertFileDoesNotExist($drop);
        self::assertSame('FORMAL', file_get_contents($keep));
    } finally {
        $paths->purgeAllEntities();
        @rmdir(dirname($root));
    }
}

private function assert_ThemeLayoutEntityUpgradePurge_testPurgeEntityTreeRemovesSiblingThemeLayoutEntitiesUnderVar(): void
{
    if (!\defined('BP')) {
        self::markTestSkipped('BP undefined');
    }
    $varTmp = \rtrim((string) BP, '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'tmp';
    if (!\is_dir($varTmp) && !@\mkdir($varTmp, 0775, true) && !\is_dir($varTmp)) {
        self::markTestSkipped('cannot create var/tmp');
    }
    $root = $varTmp . \DIRECTORY_SEPARATOR . ThemeLayoutEntityPaths::ROOT_SEGMENT;
    if (\is_dir($root)) {
        $this->deleteDir_ThemeLayoutEntityUpgradePurge($root);
    }
    $page = $root . '/2/default.__store__/pages/abc/s1';
    self::assertTrue(@\mkdir($page, 0775, true) || \is_dir($page));
    self::assertNotFalse(\file_put_contents($page . '/layout.phtml', 'R43-STORE-PREVIEW'));
    $neighbor = $varTmp . \DIRECTORY_SEPARATOR . 'keep-neighbor.txt';
    self::assertNotFalse(\file_put_contents($neighbor, 'keep'));
    $paths = new ThemeLayoutEntityPaths();
    $deleted = $paths->purgeEntityTree($root);
    self::assertGreaterThan(0, $deleted);
    self::assertDirectoryDoesNotExist($root);
    self::assertFileExists($neighbor);
    @\unlink($neighbor);
}

private function assert_ThemeLayoutEntityUpgradePurge_testAssertPurgeableEntityRootRejectsBp(): void
{
    if (!\defined('BP')) {
        self::markTestSkipped('BP undefined');
    }
    $paths = new ThemeLayoutEntityPaths();
    $this->expectException(\RuntimeException::class);
    $paths->assertPurgeableEntityRoot((string) BP);
}

private function deleteDir_ThemeLayoutEntityUpgradePurge(string $dir): void
{
    if (!\is_dir($dir)) {
        return;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
    }
    @\rmdir($dir);
}

private function assert_ThemeLayoutEntityUpgradeSolidifyBoundThemes_testSolidifyAllThemesUsesApplicationBoundThemesNotAllInstalled(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('ThemeApplicationUsageService', $src);
    self::assertStringContainsString('themesForDefaultUpgrade', $src);
    self::assertStringContainsString('主题布局预固化仅处理站点已绑定主题', $src);
    self::assertStringContainsString('pipeline=global_flat', $src);
    self::assertStringContainsString('versions=current+draft', $src);
    self::assertStringContainsString('finishProgressLine', $src);
    self::assertStringContainsString('rebakeThemesAfterInjectionCollect', $src);
    self::assertStringContainsString('$themeIds', $src);
    self::assertStringNotContainsString('rebakeAfterInjectionCollect(null,', $src);
    // solidifyAllThemes: collect theme ids then one flatten call (not per-theme rebake).
    self::assertSame(1, preg_match('/function solidifyAllThemes\b[\s\S]*?\n    public function solidifyTheme\b/m', $src, $allThemesBlock));
    $allThemesBody = (string) ($allThemesBlock[0] ?? '');
    self::assertStringContainsString('rebakeThemesAfterInjectionCollect', $allThemesBody);
    self::assertStringNotContainsString('rebakeAfterInjectionCollect(', $allThemesBody);
    // CLI occupancy bar lives on the pipeline; upgrade passes null (no single-theme progress bar).
    self::assertStringContainsString("null,\n            true,", $allThemesBody);
    self::assertStringNotContainsString('$this->progress(', $allThemesBody);
    self::assertStringContainsString("'all_versions'", $src);
    self::assertStringContainsString('bakeOptions', $src);
}

private function assert_ThemeLayoutEntityWidgetAssets_testCollectorDedupesAndSplitsBuckets(): void
{
    $collector = new ThemeLayoutEntityAssetCollector(null);
    $manifest = $collector->collectFromNodes([['node_uid' => str_repeat('a', 32), 'widget_module' => 'Weline_Theme', 'widget_code' => 'default-header', 'widget_type' => 'header', 'is_active' => true, 'layout_source' => 'Weline_Theme:css/widgets/header-chrome.css,Weline_Theme:js/widgets/header.js', 'source' => 'Weline_Theme:css/widgets/header-chrome.css,Weline_Theme:css/widgets/header-search.css'], ['node_uid' => str_repeat('b', 32), 'widget_module' => 'Weline_Theme', 'widget_code' => 'featured-products', 'widget_type' => 'product', 'is_active' => true, 'layout_source' => 'Weline_Theme:css/widgets/should-skip-layout.css', 'source' => 'Weline_Theme:css/widgets/featured-products.css']], true);
    self::assertContains('Weline_Theme::css/widgets/header-chrome.css', $manifest['layout_css']);
    self::assertContains('Weline_Theme::js/widgets/header.js', $manifest['layout_js']);
    self::assertContains('Weline_Theme::css/widgets/header-search.css', $manifest['source_css']);
    self::assertContains('Weline_Theme::css/widgets/featured-products.css', $manifest['source_css']);
    self::assertNotContains('Weline_Theme::css/widgets/should-skip-layout.css', $manifest['layout_css']);
    // Deduped out of source because already in layout.
    self::assertNotContains('Weline_Theme::css/widgets/header-chrome.css', $manifest['source_css']);
    self::assertNotSame('', $manifest['fp']);
}

private function assert_ThemeLayoutEntityWidgetAssets_testMiniCartIconPromotesNestedMoneySummaryCssToLayoutBucket(): void
{
    $collector = new ThemeLayoutEntityAssetCollector(null);
    $manifest = $collector->collectFromNodes([['node_uid' => str_repeat('c', 32), 'widget_module' => 'Weline_Theme', 'widget_code' => 'mini-cart-icon', 'widget_type' => 'header', 'is_active' => true, 'layout_source' => 'Weline_Theme::css/widgets/mini-cart-drawer.css', 'source' => 'Weline_Theme::js/widgets/mini-cart-icon.js']], true);
    self::assertContains('Weline_Checkout::css/widgets/storefront-money-summary.css', $manifest['layout_css'], 'Nested money-summary must ride blocking layout pack, not print-deferred source');
    self::assertNotContains('Weline_Checkout::css/widgets/storefront-money-summary.css', $manifest['source_css']);
}

private function assert_ThemeLayoutEntityWidgetAssets_testEmitHtmlContainsMarkerAndTags(): void
{
    $collector = new ThemeLayoutEntityAssetCollector(null);
    $html = $collector->emitHtml(['layout_css' => [], 'layout_js' => [], 'source_css' => [], 'source_js' => [], 'fp' => 'deadbeef']);
    self::assertStringContainsString('data-weline-widget-assets-fp="deadbeef"', $html);
}

private function assert_ThemeLayoutEntityWidgetAssets_testTaglibDeclaresSourceAttrs(): void
{
    $widgetTaglib = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Widget/Taglib/Widget.php';
    self::assertFileExists($widgetTaglib);
    $src = (string) \file_get_contents($widgetTaglib);
    self::assertStringContainsString("'layout-source'", $src);
    self::assertStringContainsString("'source'", $src);
}

private function assert_ThemeLayoutEntityWidgetTypeFallback_test_catalog_exposes_module_code_fallback(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeComponentCatalog.php');
    self::assertStringContainsString('function findByModuleCode(', $src);
}

private function assert_ThemeLayoutEntityWidgetTypeFallback_test_renderer_falls_back_when_typed_find_misses(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityWidgetRenderer.php');
    self::assertStringContainsString('findByModuleCode', $src);
    self::assertStringContainsString('stale widget_type', $src);
}

private function assert_ThemeLayoutEntityWidgetTypeFallback_test_order_notice_registry_type_is_content_not_form(): void
{
    $tpl = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Order/view/templates/frontend/widgets/order-notice.phtml';
    self::assertFileExists($tpl);
    $src = (string) file_get_contents($tpl);
    self::assertStringContainsString('@widget.type {content}', $src);
    self::assertStringNotContainsString('@widget.type {form}', $src);
}

private function assert_ThemeLayoutNamespaceIsolation_testFormalCompilePinsThemeDataBeforeWarmAndCompile(): void
{
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php');
    self::assertStringContainsString('ThemeData::setCurrentTheme($theme)', $service);
    self::assertStringContainsString('ThemeData::setCurrentArea($identity->area)', $service);
    $pinPos = strpos($service, 'ThemeData::setCurrentTheme($theme)');
    $warmPos = strpos($service, 'warmPublishedResourcePacks(');
    $restorePos = strpos($service, 'ThemeData::setCurrentTheme($previousTheme)');
    self::assertNotFalse($pinPos);
    self::assertNotFalse($warmPos);
    self::assertNotFalse($restorePos);
    // PHPUnit assertLessThan($expected, $actual) ⇒ $actual < $expected
    self::assertLessThan($warmPos, $pinPos, 'ThemeData pin must precede warmPublishedResourcePacks call');
    self::assertLessThan($restorePos, $warmPos, 'warm call must precede ThemeData restore');
    self::assertStringContainsString('WelineTheme $theme', $service);
    self::assertStringContainsString('theme_layout_formal_warm_theme_missing', $service);
}

private function assert_ThemeLayoutNamespaceIsolation_testGatewayArtifactsRequireExplicitThemeNamespace(): void
{
    $gateway = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeResourceGateway.php');
    self::assertStringContainsString('buildHeadChromeArtifact', $gateway);
    self::assertStringContainsString('buildWidgetAssetArtifact', $gateway);
    self::assertStringContainsString('?WelineTheme $theme = null', $gateway);
    self::assertStringContainsString('Fail-closed: never write a bare /Weline/Theme/widget-assets pack', $gateway);
    self::assertStringContainsString('/Weline/Theme/theme-head/', $gateway);
}

private function assert_ThemeLayoutNamespaceIsolation_testHeadPacksResolveThemeFromThemeDataCurrent(): void
{
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeHeadChromeCssPack.php');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeHeadChromeJsPack.php');
    self::assertStringContainsString('ThemeData::getCurrentTheme', $css);
    self::assertStringContainsString('ThemeData::getCurrentTheme', $js);
    self::assertStringContainsString('buildHeadChromeArtifact', $css);
    self::assertStringContainsString('buildHeadChromeArtifact', $js);
}

private function assert_ThemeLayoutNamespaceIsolation_testUpgradeCollectSkipsInheritWithoutCrossThemeReuse(): void
{
    $coord = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php');
    self::assertStringContainsString('layoutNeedsOwnSolidify', $coord);
    self::assertStringContainsString('skipped_inherit', $coord);
    self::assertStringContainsString('ThemeLayoutDesignOverride::provides', $coord);
    $override = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutDesignOverride.php');
    self::assertStringContainsString('class ThemeLayoutDesignOverride', $override);
    self::assertStringContainsString('layer_key', $override);
}

private function assert_ThemeLayoutOriginWatch_testCronFastSkipsNonDevAndRegistersExecuteName(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Cron/LayoutOriginWatch.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("return 'theme_layout_origin_watch';", $src);
    self::assertStringContainsString("return 'skip:non_dev';", $src);
    self::assertStringContainsString("Env::system('deploy')", $src);
    self::assertStringContainsString("!== 'dev'", $src);
    self::assertStringContainsString('ThemeLayoutOriginWatchService', $src);
    self::assertStringContainsString('unlock_timeout', $src);
    self::assertStringContainsString('max(30', $src);
    self::assertStringContainsString('Cron 进程在跑', $src);
    $executeStart = \strpos($src, 'function execute(): string');
    self::assertNotFalse($executeStart);
    $executeBody = \substr($src, (int) $executeStart, 800);
    $skipPos = \strpos($executeBody, "skip:non_dev");
    $runPos = \strpos($executeBody, '$this->watch->run()');
    self::assertNotFalse($skipPos);
    self::assertNotFalse($runPos);
    self::assertLessThan($runPos, $skipPos);
}

private function assert_ThemeLayoutOriginWatch_testWatchServiceCascadesWidgetsViaLedgerNotLayoutTextScan(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutOriginWatchService.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('ownSolidifyTargetsForTheme', $src);
    self::assertStringContainsString('ensureCurrent', $src);
    self::assertStringContainsString('rematerializeChromeIfStale', $src);
    self::assertStringContainsString('chrome_digest_drift_heal', $src);
    self::assertStringContainsString('solidifyTheme', $src);
    self::assertStringContainsString('layout_type', $src);
    self::assertStringContainsString('layout_option', $src);
    self::assertStringContainsString('.origin-watch.json', $src);
    self::assertStringContainsString('watchedManifestFiles', $src);
    self::assertStringContainsString('Theme-internal .phtml + .css/.js', $src);
    self::assertStringContainsString('publishStaticAssets', $src);
    self::assertStringContainsString("'kind' => 'assets'", $src);
    self::assertStringContainsString('theme_layout_origin_watch_assets', $src);
    self::assertStringContainsString('warmAreaPacks', $src);
    self::assertStringContainsString('layoutTypesFromWidgetLedger', $src);
    self::assertStringContainsString('layoutTypesFromWidgetEmbeds', $src);
    self::assertStringContainsString('layoutHtmlEmbedsWidget', $src);
    self::assertStringContainsString('appendExternalWidgetSources', $src);
    self::assertStringContainsString('__ext__/', $src);
    self::assertStringContainsString('resolveWidgetStaticAbsolutes', $src);
    self::assertStringContainsString('/asset/', $src);
    self::assertStringContainsString("\$class['asset']", $src);
    self::assertStringContainsString('refreshLiveStaticMirrorsForChangedPaths', $src);
    self::assertStringContainsString('FpcStaticDiskStore::republishFromFileOnAllHosts', $src);
    self::assertStringContainsString('assets:live_static_mirror:', $src);
    self::assertStringContainsString('uriFromAppCodeAbsolute', $src);
    self::assertStringContainsString('processModulePrefixStatics', $src);
    self::assertStringContainsString('__modstatic__/', $src);
    self::assertStringContainsString('module_statics:live_static_mirror:', $src);
    self::assertStringContainsString("kind' => 'module_static'", $src);
    self::assertStringNotContainsString('purgeAllLive(', $src);
    self::assertStringContainsString('collectExternalWidgetRefs', $src);
    self::assertStringContainsString('resolveSolidifyCascade', $src);
    self::assertStringContainsString("kind' => 'widget'", $src);
    self::assertStringContainsString("kind' => 'template'", $src);
    self::assertStringContainsString('WidgetRegistryEntry', $src);
    self::assertStringContainsString('cascade:full_theme', $src);
    self::assertStringContainsString('embed_scan', $src);
    self::assertStringContainsString('force_pages', $src);
    self::assertStringContainsString("classified['widgets']", $src);
    self::assertStringContainsString('ThemeStorefrontSlugFpcPublisher', $src);
    self::assertStringContainsString('purgeLayoutsForThemeTargets', $src);
    self::assertStringContainsString('theme_layout_origin_watch', $src);
    self::assertStringContainsString('clearStorefrontCachesAfterBake', $src);
    self::assertStringContainsString('cache_cleared', $src);
    self::assertStringContainsString('allFrontendLayoutsForTheme', $src);
    // Layout-scoped slug purge only — never site-wide html-disk staging call.
    self::assertStringNotContainsString('clearAllThemeRelatedCaches(', $src);
    self::assertStringNotContainsString('ThemeRuntimeCacheCleaner', $src);
    // Order: solidify/heal before cache clear (string positions).
    $solidifyPos = \strpos($src, '$this->solidify->solidifyTheme');
    $clearPos = \strpos($src, 'clearStorefrontCachesAfterBake');
    self::assertNotFalse($solidifyPos);
    self::assertNotFalse($clearPos);
    self::assertLessThan($clearPos, $solidifyPos);
    // Embed scan matches real <w:widget> tags; must not treat slot accept= as embeds.
    self::assertStringContainsString('<w:widget\b', $src);
    self::assertStringContainsString('accept= lists are not embeds', $src);
    self::assertStringNotContainsString('ManagedChildAdmission', $src);
    self::assertStringContainsString('never unfiltered full-theme bake', $src);
    self::assertStringContainsString("'kind' => 'chrome'", $src);
    self::assertStringContainsString("'kind' => 'layout'", $src);
}

private function assert_ThemeLayoutOriginWatch_testBakeCoordinatorHonorsForcePagesPastDigestSkip(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("\$forcePages = !empty(\$options['force_pages'])", $src);
    self::assertStringContainsString('!$forcePages', $src);
    self::assertStringContainsString('generatedPageMatchesInputs', $src);
    $upgrade = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
    $upSrc = (string) \file_get_contents($upgrade);
    self::assertStringContainsString("\$out['force_pages'] = true", $upSrc);
}

private function assert_ThemeLayoutOriginWatch_testUsageServiceExposesOwnSolidifyTargetsFromReferenceOwnerScope(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeApplicationUsageService.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('function ownSolidifyTargetsForTheme(int $themeId): array', $src);
    self::assertStringContainsString('version_owner_scope', $src);
    self::assertStringContainsString('listOwnApplicationDetailRows', $src);
    self::assertStringNotContainsString('__store__.__channel__', $src);
}

private function assert_ThemeScopeVersionChromeMemo_testModelHasChromeDecodeMemoAndDoesNotPersistArrayViaSetChromePayload(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Model/ThemeScopeVersion.php');
    self::assertStringContainsString('chromePayloadMemo', $src);
    self::assertStringContainsString('chromePayloadMemoValid', $src);
    self::assertStringContainsString('CHROME_DECODE_CACHE_PREFIX', $src);
    self::assertStringContainsString('never persist arrays into longtext via save()', $src);
    self::assertStringContainsString('json_encode($nodes', $src);
    self::assertStringContainsString('function clearData(', $src);
    self::assertStringContainsString('function setData(', $src);
    self::assertStringContainsString('copyArrayTree', $src);
    self::assertStringContainsString('Copy-on-read', $src);
    self::assertStringContainsString('function peekChromePayload(', $src);
    self::assertStringContainsString('function ensureChromePayloadMemo(', $src);
}

private function assert_ThemeScopeVersionChromeMemo_testCopyArrayTreeIsRecursivePhpNotJsonRoundtrip(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemeScopeVersionService.php');
    $pos = \strpos($src, 'public static function copyArrayTree(array $data): array');
    self::assertNotFalse($pos);
    $chunk = \substr($src, $pos, 700);
    self::assertStringContainsString('self::copyArrayTree($value)', $chunk);
    self::assertStringNotContainsString('json_encode', $chunk);
    self::assertStringNotContainsString('json_decode', $chunk);
    self::assertStringNotContainsString('serialize', $chunk);
}

private function assert_ThemeScopeVersionChromeMemo_testCopyArrayTreeIsolatesNestedMutation(): void
{
    $src = ['header' => ['widget_code' => 'weline-header', 'meta' => ['config' => ['a' => 1]]], 'flags' => [true, false]];
    $copy = ThemeScopeVersionService::copyArrayTree($src);
    $copy['header']['widget_code'] = 'mutated';
    $copy['header']['meta']['config']['a'] = 99;
    $copy['flags'][0] = false;
    self::assertSame('weline-header', $src['header']['widget_code']);
    self::assertSame(1, $src['header']['meta']['config']['a']);
    self::assertTrue($src['flags'][0]);
}

private function assert_ThemeScopeVersionChromeMemo_testInstanceMemoAvoidsSecondJsonDecode(): void
{
    $version = new ThemeScopeVersion();
    $payload = ['header' => ['node_uid' => 'header', 'slot_id' => 'header', 'widget_code' => 'weline-header']];
    $json = \json_encode($payload, \JSON_THROW_ON_ERROR);
    // Raw JSON string path (not setChromePayload which pre-fills instance memo).
    $version->setData(ThemeScopeVersion::schema_fields_CHROME_PAYLOAD_JSON, $json);
    $version->setData(ThemeScopeVersion::schema_fields_ID, 4242);
    $ref = new \ReflectionClass($version);
    $first = $version->getChromePayload();
    $second = $version->getChromePayload();
    self::assertEquals($payload, $first);
    self::assertEquals($payload, $second);
    // Copy-on-read: mutating a returned tree must not poison the instance memo.
    $first['header']['widget_code'] = 'mutated';
    self::assertSame('weline-header', $version->getChromePayload()['header']['widget_code']);
    self::assertSame('weline-header', $second['header']['widget_code']);
    $memoProp = $ref->getProperty('chromePayloadMemoValid');
    $memoProp->setAccessible(true);
    self::assertTrue($memoProp->getValue($version));
}

private function assert_ThemeScopeVersionChromeMemo_testPeekBorrowsMemoWhileGetChromePayloadStillIsolates(): void
{
    $version = new ThemeScopeVersion();
    $payload = ['header' => ['node_uid' => 'header', 'slot_id' => 'header', 'widget_code' => 'weline-header']];
    $version->setChromePayload($payload);
    $peekA = $version->peekChromePayload();
    $peekB = $version->peekChromePayload();
    self::assertSame($peekA, $peekB);
    $copied = $version->getChromePayload();
    $copied['header']['widget_code'] = 'mutated';
    self::assertSame('weline-header', $version->peekChromePayload()['header']['widget_code']);
}

private function assert_ThemeScopeVersionModel_testModelHasChromePayloadAndNoPageType(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Model/ThemeScopeVersion.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('final class ThemeScopeVersion', $src);
    self::assertStringContainsString("schema_table = 'theme_scope_version'", $src);
    self::assertStringContainsString("schema_fields_CHROME_PAYLOAD_JSON = 'chrome_payload_json'", $src);
    self::assertStringContainsString("schema_fields_STRUCTURE_KEY = 'structure_key'", $src);
    self::assertStringContainsString('uk_theme_scope_version_number', $src);
    self::assertStringContainsString("'store_mode', 'area', 'version_number'", $src);
    self::assertStringContainsString("schema_fields_AREA = 'area'", $src);
    self::assertStringContainsString("schema_fields_LIFECYCLE = 'lifecycle'", $src);
    self::assertStringContainsString("schema_fields_CONTENT_REVISION = 'content_revision'", $src);
    self::assertStringContainsString("schema_fields_CREATION_SOURCE_KIND = 'creation_source_kind'", $src);
    self::assertStringContainsString("schema_fields_CREATION_SOURCE_VERSION_ID = 'creation_source_version_id'", $src);
    self::assertStringNotContainsString('schema_fields_PAGE_TYPE', $src);
    self::assertStringNotContainsString("= 'page_type'", $src);
    self::assertDoesNotMatchRegularExpression("/#\\[Col[^\\]]*page_type/i", $src);
}

private function assert_ThemeScopeVersionModel_testSelectionAndSnapshotModelsExistWithOwnerKeys(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Model/';
    foreach (['ThemeScopeVersionSelection.php' => 'uk_theme_scope_version_selection_owner', 'ThemeScopeVersionRevision.php' => 'uk_theme_scope_version_revision', 'ThemeScopeVersionResourceSnapshot.php' => 'uk_theme_scope_version_resource_snapshot', 'ThemeScopeVersionWidgetDecision.php' => 'uk_theme_scope_version_widget_decision'] as $file => $index) {
        $path = $base . $file;
        self::assertFileExists($path, $file);
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString($index, $src, $file);
    }
}

private function assert_ThemeScopeVersionModel_testWorkspaceUniqueKeyIncludesThemeVersionAndBindingExternal(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Model/ThemeScopeWorkspace.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('uk_theme_scope_workspace_version_identity', $src);
    self::assertStringContainsString('uk_theme_scope_workspace_binding_identity', $src);
    self::assertStringContainsString('THEME_VERSION_EXTERNAL', $src);
    self::assertStringContainsString("schema_fields_THEME_VERSION_ID = 'theme_version_id'", $src);
    self::assertStringNotContainsString('uk_theme_scope_workspace_identity', $src);
}

private function assert_WidgetAssetSourcePrefetch_testOptimizerPrefetchesSourcesBeforeTransformStreams(): void
{
    $optimizerSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/WidgetAssetOptimizer.php');
    self::assertMatchesRegularExpression('/function\s+transform\s*\([^)]*\)[^{]*\{[^}]*prefetchSources\s*\(/s', $optimizerSrc);
}

private function assert_WidgetAssetSourcePrefetch_testPublisherExposesPrefetchSourcesUsingWidgetAssetSourcePolicy(): void
{
    self::assertTrue(method_exists(WidgetAssetArtifactPublisher::class, 'prefetchSources'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/WidgetAssetArtifactPublisher.php');
    self::assertStringContainsString('prefetchPolicy', $src);
    self::assertStringContainsString('rememberPolicy', $src);
    self::assertStringContainsString('theme.widget_asset_source', $src);
    self::assertStringContainsString('resolveModuleSourcePath', $src);
    self::assertStringContainsString('sourceCachePolicy', $src);
    // Prime-on-prefetch: miss must build L2, not MGET-only.
    self::assertStringContainsString('Miss priming', $src);
    // v4 keys use Module::relative so CLI warmer and WLS share L2.
    self::assertStringContainsString('widget-source-v4|', $src);
    self::assertStringContainsString('sourceLogicalKey', $src);
}
}
