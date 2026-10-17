<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Data\ScopeData;
use Weline\Websites\Extends\Module\Weline_Framework\Query\WebsitesQueryProvider;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\AdminWebsiteScope;
use Weline\Websites\Service\DefaultWebsiteService;
use Weline\Websites\Service\DomainParserService;
use Weline\Websites\Service\LocalWelineHostsSyncService;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;
use Weline\Websites\Service\ManagedLocalDomainProvisionService;
use Weline\Websites\Service\SalesChannelCatalog;
use Weline\Websites\Service\ScopeCatalogCacheWarmer;
use Weline\Websites\Service\ScopeCatalogHotCachePublisher;
use Weline\Websites\Service\ScopeMaintenanceGate;
use Weline\Websites\Service\ScopePathMatchCache;
use Weline\Websites\Service\ScopeResolver;
use Weline\Websites\Service\StoreCatalog;
use Weline\Websites\Service\StoreSelectOptions;
use Weline\Websites\Service\StorefrontScopeCatalogCacheCoordinator;
use Weline\Websites\Service\Value\ScopePathMatchHit;
use Weline\Websites\Service\Value\ScopePathMatchKey;
use Weline\Websites\Service\WebsiteChangeSnapshotFactory;
use Weline\Websites\Service\WebsiteEntryUrlService;
use Weline\Websites\Service\WebsiteScopeTreeService;
use Weline\Websites\Service\WebsiteStoreChannelDirectory;

/**
 * Aggregated contract suite (merged from 25 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesServiceContractsPass().
 */
final class WebsitesServiceContractsTest extends TestCase
{
    public function testAllWebsitesServiceContractsPass(): void
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
            'AdminWebsiteScope::testParseNonNegativeIdTreatsZeroAsDefaultSiteNotAll' => function () {
            $this->assert_AdminWebsiteScope_testParseNonNegativeIdTreatsZeroAsDefaultSiteNotAll();
        },
            'AdminWebsiteScope::testBuildSelectViewKeepsZeroValue' => function () {
            $this->assert_AdminWebsiteScope_testBuildSelectViewKeepsZeroValue();
        },
            'AdminWebsiteScope::testFilterAllConstantIsNull' => function () {
            $this->assert_AdminWebsiteScope_testFilterAllConstantIsNull();
        },
            'CurrencyCatalogReorderSoftInvalidation::testReorderWebsiteUsesSoftPathNotSetWebsiteCurrencies' => function () {
            $this->assert_CurrencyCatalogReorderSoftInvalidation_testReorderWebsiteUsesSoftPathNotSetWebsiteCurrencies();
        },
            'CurrencyCatalogReorderSoftInvalidation::testReorderModelSkipsHeavyInvalidate' => function () {
            $this->assert_CurrencyCatalogReorderSoftInvalidation_testReorderModelSkipsHeavyInvalidate();
        },
            'CurrencyCatalogReorderSoftInvalidation::testSoftServiceAvoidsWebsiteChanged' => function () {
            $this->assert_CurrencyCatalogReorderSoftInvalidation_testSoftServiceAvoidsWebsiteChanged();
        },
            'CurrencyCatalogReorderSoftInvalidation::testCurrencySelectAsyncSavesOnTagDrag' => function () {
            $this->assert_CurrencyCatalogReorderSoftInvalidation_testCurrencySelectAsyncSavesOnTagDrag();
        },
            'CurrencyCatalogReorderSoftInvalidation::testBumpCurrencyCatalogOnlySkipsApplyCommittedLegacyPath' => function () {
            $this->assert_CurrencyCatalogReorderSoftInvalidation_testBumpCurrencyCatalogOnlySkipsApplyCommittedLegacyPath();
        },
            'DefaultWebsiteServiceCurrency::testDefaultWebsiteUsesConfiguredFrameworkCurrency' => function () {
            $this->assert_DefaultWebsiteServiceCurrency_testDefaultWebsiteUsesConfiguredFrameworkCurrency();
        },
            'DefaultWebsiteServiceCurrency::testDefaultCurrencyAssociationIsNotHardCodedToCny' => function () {
            $this->assert_DefaultWebsiteServiceCurrency_testDefaultCurrencyAssociationIsNotHardCodedToCny();
        },
            'DefaultWebsiteServiceLocalDomain::testResolveLocalDefaultDomainsIncludesCurrentProjectHost' => function () {
            $this->assert_DefaultWebsiteServiceLocalDomain_testResolveLocalDefaultDomainsIncludesCurrentProjectHost();
        },
            'DefaultWebsiteServiceLocalDomain::testEnsureWebsiteDomainBindingMethodExistsForWlsSync' => function () {
            $this->assert_DefaultWebsiteServiceLocalDomain_testEnsureWebsiteDomainBindingMethodExistsForWlsSync();
        },
            'DomainParserManagedLocalRoot::testManagedTestWelineComRootWinsOverPublicSuffixList' => function () {
            $this->assert_DomainParserManagedLocalRoot_testManagedTestWelineComRootWinsOverPublicSuffixList();
        },
            'DomainParserManagedLocalRoot::testLegacyWelineTestRootStillResolved' => function () {
            $this->assert_DomainParserManagedLocalRoot_testLegacyWelineTestRootStillResolved();
        },
            'DomainParserManagedLocalRoot::testPublicDomainStillUsesRegistrableRoot' => function () {
            $this->assert_DomainParserManagedLocalRoot_testPublicDomainStillUsesRegistrableRoot();
        },
            'FrontendWorkerScopeHolePunchFastPath::testPunchVerifiesExistingCookieBeforeResolveChannelScope' => function () {
            $this->assert_FrontendWorkerScopeHolePunchFastPath_testPunchVerifiesExistingCookieBeforeResolveChannelScope();
        },
            'LanguageCatalogReorderSoftInvalidation::testReorderWebsiteUsesSoftPathNotSetWebsiteLanguages' => function () {
            $this->assert_LanguageCatalogReorderSoftInvalidation_testReorderWebsiteUsesSoftPathNotSetWebsiteLanguages();
        },
            'LanguageCatalogReorderSoftInvalidation::testReorderModelSkipsLegacyMembershipInvalidationAndI18nSync' => function () {
            $this->assert_LanguageCatalogReorderSoftInvalidation_testReorderModelSkipsLegacyMembershipInvalidationAndI18nSync();
        },
            'LanguageCatalogReorderSoftInvalidation::testSoftServiceAvoidsWebsiteChangedAndLegacyFullClear' => function () {
            $this->assert_LanguageCatalogReorderSoftInvalidation_testSoftServiceAvoidsWebsiteChangedAndLegacyFullClear();
        },
            'LanguageCatalogReorderSoftInvalidation::testLanguageSelectAsyncSavesOnTagDrag' => function () {
            $this->assert_LanguageCatalogReorderSoftInvalidation_testLanguageSelectAsyncSavesOnTagDrag();
        },
            'LanguageCatalogReorderSoftInvalidation::testBumpLanguageCatalogOnlySkipsApplyCommittedLegacyPath' => function () {
            $this->assert_LanguageCatalogReorderSoftInvalidation_testBumpLanguageCatalogOnlySkipsApplyCommittedLegacyPath();
        },
            'ManagedLocalDomainProvisionService::testPublicDomainIsSkippedWithoutHostsOrCertificateCalls' => function () {
            $this->assert_ManagedLocalDomainProvisionService_testPublicDomainIsSkippedWithoutHostsOrCertificateCalls();
        },
            'ManagedLocalDomainProvisionService::testManagedLocalDomainRunsHostsThenCertificateAndReportsReady' => function () {
            $this->assert_ManagedLocalDomainProvisionService_testManagedLocalDomainRunsHostsThenCertificateAndReportsReady();
        },
            'ManagedLocalDomainProvisionService::testAuthorizationPendingStopsBeforePretendingReady' => function () {
            $this->assert_ManagedLocalDomainProvisionService_testAuthorizationPendingStopsBeforePretendingReady();
        },
            'PublicDnsHostnamePoolEnsureService::testServiceOwnsPoolUpsertAndImmediateDns01Cert' => function () {
            $this->assert_PublicDnsHostnamePoolEnsureService_testServiceOwnsPoolUpsertAndImmediateDns01Cert();
        },
            'PublicDnsHostnamePoolEnsureService::testQueryProviderExposesEnsureOperation' => function () {
            $this->assert_PublicDnsHostnamePoolEnsureService_testQueryProviderExposesEnsureOperation();
        },
            'PublicDnsHostnamePoolEnsureService::testMailDnsApplyHooksPoolEnsureViaQuery' => function () {
            $this->assert_PublicDnsHostnamePoolEnsureService_testMailDnsApplyHooksPoolEnsureViaQuery();
        },
            'ResolvedLanguageCatalog::testInterfaceAndServiceExistWithInheritanceContract' => function () {
            $this->assert_ResolvedLanguageCatalog_testInterfaceAndServiceExistWithInheritanceContract();
        },
            'ResolvedLanguageCatalog::testQueryProviderExposesResolvedLanguageCodes' => function () {
            $this->assert_ResolvedLanguageCatalog_testQueryProviderExposesResolvedLanguageCodes();
        },
            'ResolvedLanguageCatalog::testModuleRegistersResolvedLanguageCatalog' => function () {
            $this->assert_ResolvedLanguageCatalog_testModuleRegistersResolvedLanguageCatalog();
        },
            'ScopeCatalogHotCachePublisher::testScopeResolverPublishesCatalogHotCacheAfterScopeDataInstall' => function () {
            $this->assert_ScopeCatalogHotCachePublisher_testScopeResolverPublishesCatalogHotCacheAfterScopeDataInstall();
        },
            'ScopeCatalogHotCachePublisher::testPublisherRequiresFrozenScopeBeforeWork' => function () {
            $this->assert_ScopeCatalogHotCachePublisher_testPublisherRequiresFrozenScopeBeforeWork();
        },
            'ScopeCatalogHotCachePublisher::testDefaultChannelBuilderDoesNotNestByCodeRememberPolicy' => function () {
            $this->assert_ScopeCatalogHotCachePublisher_testDefaultChannelBuilderDoesNotNestByCodeRememberPolicy();
        },
            'ScopeCatalogHotCachePublisher::testModuleProvidesScopeCatalogCacheWarmer' => function () {
            $this->assert_ScopeCatalogHotCachePublisher_testModuleProvidesScopeCatalogCacheWarmer();
        },
            'ScopeCatalogHotCachePublisher::testCacheClearWarmsWebsitePoolAfterFpc' => function () {
            $this->assert_ScopeCatalogHotCachePublisher_testCacheClearWarmsWebsitePoolAfterFpc();
        },
            'ScopeDisplayTypeResolver::testResolverPreferChannelThenStoreThenWebsiteAndFailSoftUnknown' => function () {
            $this->assert_ScopeDisplayTypeResolver_testResolverPreferChannelThenStoreThenWebsiteAndFailSoftUnknown();
        },
            'ScopeDisplayTypeResolver::testModelsOwnDisplayTypeAndChannelUrlOutsideSummaryV1' => function () {
            $this->assert_ScopeDisplayTypeResolver_testModelsOwnDisplayTypeAndChannelUrlOutsideSummaryV1();
        },
            'ScopeMaintenanceRenderContextReader::testStatusPrefersReaderThenRequestMemo' => function () {
            $this->assert_ScopeMaintenanceRenderContextReader_testStatusPrefersReaderThenRequestMemo();
        },
            'ScopePathMatchCache::testMatchKeyIgnoresQueryAndUsesCanonicalPath' => function () {
            $this->setUp_ScopePathMatchCache();
            try {
                $this->assert_ScopePathMatchCache_testMatchKeyIgnoresQueryAndUsesCanonicalPath();
            } finally {
                $this->tearDown_ScopePathMatchCache();
            }
        },
            'ScopePathMatchCache::testHitRoundTripPreservesStoreAndChannelSummaries' => function () {
            $this->setUp_ScopePathMatchCache();
            try {
                $this->assert_ScopePathMatchCache_testHitRoundTripPreservesStoreAndChannelSummaries();
            } finally {
                $this->tearDown_ScopePathMatchCache();
            }
        },
            'ScopePathMatchCache::testScopeDataInstallAndMatchHelpers' => function () {
            $this->setUp_ScopePathMatchCache();
            try {
                $this->assert_ScopePathMatchCache_testScopeDataInstallAndMatchHelpers();
            } finally {
                $this->tearDown_ScopePathMatchCache();
            }
        },
            'ScopePathMatchCache::testPathMatchCacheReadWriteUsesInjectedPool' => function () {
            $this->setUp_ScopePathMatchCache();
            try {
                $this->assert_ScopePathMatchCache_testPathMatchCacheReadWriteUsesInjectedPool();
            } finally {
                $this->tearDown_ScopePathMatchCache();
            }
        },
            'ScopeResolverChannelUrl::testResolveChannelMatchesRemainderPathAndRelaxesL2UsablePredicate' => function () {
            $this->assert_ScopeResolverChannelUrl_testResolveChannelMatchesRemainderPathAndRelaxesL2UsablePredicate();
        },
            'ScopeResolverChannelUrl::testScopeSwitcherHidesWhenOnlyDefaultStoreAndChannel' => function () {
            $this->assert_ScopeResolverChannelUrl_testScopeSwitcherHidesWhenOnlyDefaultStoreAndChannel();
        },
            'ScopeResolverChannelUrl::testScopeSwitcherLocalizesChannelHrefsViaGetFrontendUrl' => function () {
            $this->assert_ScopeResolverChannelUrl_testScopeSwitcherLocalizesChannelHrefsViaGetFrontendUrl();
        },
            'StoreChannelCatalog::testSummaryDtosAreFinalReadonlyAndProjectExactV1Fields' => function () {
            $this->assert_StoreChannelCatalog_testSummaryDtosAreFinalReadonlyAndProjectExactV1Fields();
        },
            'StoreChannelCatalog::testStoreCatalogMapsZeroWebsiteActiveAndTombstoneRows' => function () {
            $this->assert_StoreChannelCatalog_testStoreCatalogMapsZeroWebsiteActiveAndTombstoneRows();
        },
            'StoreChannelCatalog::testSalesChannelEffectiveEnabledFollowsParentStore' => function () {
            $this->assert_StoreChannelCatalog_testSalesChannelEffectiveEnabledFollowsParentStore();
        },
            'StoreChannelCatalog::testSalesChannelCatalogRejectsCrossWebsiteParent' => function () {
            $this->assert_StoreChannelCatalog_testSalesChannelCatalogRejectsCrossWebsiteParent();
        },
            'StoreChannelCatalog::testSalesChannelByCodeRejectsOversizedStoreIdBeforeBlankCodeShortcut' => function () {
            $this->assert_StoreChannelCatalog_testSalesChannelByCodeRejectsOversizedStoreIdBeforeBlankCodeShortcut();
        },
            'StoreChannelCatalog::testDirectoryUsesCatalogContractsAndBuildsNestedProjection' => function () {
            $this->assert_StoreChannelCatalog_testDirectoryUsesCatalogContractsAndBuildsNestedProjection();
        },
            'StoreChannelCatalog::testStoreAndSalesChannelWriteSurfacesRemainClosed' => function () {
            $this->assert_StoreChannelCatalog_testStoreAndSalesChannelWriteSurfacesRemainClosed();
        },
            'StoreChannelCatalog::testQueryProviderPublishesOnlyTheTwoReadOnlyV1CatalogOperations' => function () {
            $this->assert_StoreChannelCatalog_testQueryProviderPublishesOnlyTheTwoReadOnlyV1CatalogOperations();
        },
            'StoreChannelCatalog::testCatalogEnglishTranslationsContainNoChineseAndPreservePlaceholders' => function () {
            $this->assert_StoreChannelCatalog_testCatalogEnglishTranslationsContainNoChineseAndPreservePlaceholders();
        },
            'StoreSelectOptions::testFromRowsUsesStoreIdAsValue' => function () {
            $this->assert_StoreSelectOptions_testFromRowsUsesStoreIdAsValue();
        },
            'StorefrontScopeCatalogCache::testCatalogPoliciesUseTheCentralScopeCacheBoundary' => function () {
            $this->assert_StorefrontScopeCatalogCache_testCatalogPoliciesUseTheCentralScopeCacheBoundary();
        },
            'StorefrontScopeCatalogCache::testCatalogReadsDelegateToPolicyCacheWithoutRequestMemo' => function () {
            $this->assert_StorefrontScopeCatalogCache_testCatalogReadsDelegateToPolicyCacheWithoutRequestMemo();
        },
            'WebsiteCatalogProcessCache::testCatalogOwnsProcessBagAndClear' => function () {
            $this->assert_WebsiteCatalogProcessCache_testCatalogOwnsProcessBagAndClear();
        },
            'WebsiteCatalogProcessCache::testMaintenanceGateOwnsProcessBag' => function () {
            $this->assert_WebsiteCatalogProcessCache_testMaintenanceGateOwnsProcessBag();
        },
            'WebsiteCatalogProcessCache::testProcessCacheResetterClearsCatalogAndGate' => function () {
            $this->assert_WebsiteCatalogProcessCache_testProcessCacheResetterClearsCatalogAndGate();
        },
            'WebsiteEntryUrlServicePort::testWithCurrentRequestPortAppendsLivePortWhenStoredUrlHasNone' => function () {
            try {
                $this->assert_WebsiteEntryUrlServicePort_testWithCurrentRequestPortAppendsLivePortWhenStoredUrlHasNone();
            } finally {
                $this->tearDown_WebsiteEntryUrlServicePort();
            }
        },
            'WebsiteEntryUrlServicePort::testWithCurrentRequestPortLeavesExplicitPortUntouched' => function () {
            try {
                $this->assert_WebsiteEntryUrlServicePort_testWithCurrentRequestPortLeavesExplicitPortUntouched();
            } finally {
                $this->tearDown_WebsiteEntryUrlServicePort();
            }
        },
            'WebsiteEntryUrlServiceStorefrontChrome::testResolveStorefrontForBackendChromePrefersLiveRequestBaseHost' => function () {
            $this->assert_WebsiteEntryUrlServiceStorefrontChrome_testResolveStorefrontForBackendChromePrefersLiveRequestBaseHost();
        },
            'WebsiteFpcBakeOrchestrator::testOrchestratorSoftPullsSeoFacadeOnly' => function () {
            $this->assert_WebsiteFpcBakeOrchestrator_testOrchestratorSoftPullsSeoFacadeOnly();
        },
            'WebsiteFpcBakeOrchestrator::testCliCommandExistsWithAlias' => function () {
            $this->assert_WebsiteFpcBakeOrchestrator_testCliCommandExistsWithAlias();
        },
            'WebsiteFpcBakeOrchestrator::testSeoIsOptionalNotRequired' => function () {
            $this->assert_WebsiteFpcBakeOrchestrator_testSeoIsOptionalNotRequired();
        },
            'WebsiteResourceChange::testWeb02DefaultWebsiteImpactKeepsCurrentAndPreviousNamespacesAndUrls' => function () {
            $this->assert_WebsiteResourceChange_testWeb02DefaultWebsiteImpactKeepsCurrentAndPreviousNamespacesAndUrls();
        },
            'WebsiteResourceChange::testWeb03DeleteImpactPreservesCompletePreviousSurface' => function () {
            $this->assert_WebsiteResourceChange_testWeb03DeleteImpactPreservesCompletePreviousSurface();
        },
            'WebsiteResourceChange::testWeb04ChangedFieldsAreDeterministicForCreateUpdateAndDelete' => function () {
            $this->assert_WebsiteResourceChange_testWeb04ChangedFieldsAreDeterministicForCreateUpdateAndDelete();
        },
            'WebsiteSaveChangedCacheCdn::testWebsiteSavePublishesResourceChangeAndFlushDeferred' => function () {
            $this->assert_WebsiteSaveChangedCacheCdn_testWebsiteSavePublishesResourceChangeAndFlushDeferred();
        },
            'WebsiteSaveChangedCacheCdn::testWebsiteChangedTypeRegisteredWithCdnPurgeRecipe' => function () {
            $this->assert_WebsiteSaveChangedCacheCdn_testWebsiteChangedTypeRegisteredWithCdnPurgeRecipe();
        },
            'WebsiteSaveChangedCacheCdn::testCdnCapabilityRoutesWebsiteThroughPurgeWebsiteScope' => function () {
            $this->assert_WebsiteSaveChangedCacheCdn_testCdnCapabilityRoutesWebsiteThroughPurgeWebsiteScope();
        },
            'WebsiteSaveChangedCacheCdn::testWebsiteEditFormWarnsCacheAndCdnOnSave' => function () {
            $this->assert_WebsiteSaveChangedCacheCdn_testWebsiteEditFormWarnsCacheAndCdnOnSave();
        },
            'WebsiteSaveChangedCacheCdn::testWebsiteImpactCarriesNamespacesAndUrlsForCdn' => function () {
            $this->assert_WebsiteSaveChangedCacheCdn_testWebsiteImpactCarriesNamespacesAndUrlsForCdn();
        },
            'WebsiteScopeTreeService::testParseAndFormatNode' => function () {
            $this->assert_WebsiteScopeTreeService_testParseAndFormatNode();
        },
            'WebsiteScopeTreeService::testServiceSourceForbidsGlobalKind' => function () {
            $this->assert_WebsiteScopeTreeService_testServiceSourceForbidsGlobalKind();
        },
        ];
    }

private function assert_AdminWebsiteScope_testParseNonNegativeIdTreatsZeroAsDefaultSiteNotAll(): void
{
    self::assertSame(0, AdminWebsiteScope::parseNonNegativeId(0));
    self::assertSame(0, AdminWebsiteScope::parseNonNegativeId('0'));
    self::assertNull(AdminWebsiteScope::parseNonNegativeId(''));
    self::assertNull(AdminWebsiteScope::parseNonNegativeId('all'));
    self::assertNull(AdminWebsiteScope::parseNonNegativeId('ALL'));
    self::assertNull(AdminWebsiteScope::parseNonNegativeId(null));
    self::assertNull(AdminWebsiteScope::parseNonNegativeId('-1'));
    self::assertSame(23, AdminWebsiteScope::parseNonNegativeId('23'));
}

private function assert_AdminWebsiteScope_testBuildSelectViewKeepsZeroValue(): void
{
    $view = AdminWebsiteScope::buildSelectView(0);
    self::assertSame('0', $view['value']);
    self::assertArrayHasKey('options_json', $view);
    self::assertArrayHasKey('display', $view);
    $all = AdminWebsiteScope::buildSelectView(null);
    self::assertSame('', $all['value']);
}

private function assert_AdminWebsiteScope_testFilterAllConstantIsNull(): void
{
    self::assertNull(AdminWebsiteScope::FILTER_ALL);
}

private function assert_CurrencyCatalogReorderSoftInvalidation_testReorderWebsiteUsesSoftPathNotSetWebsiteCurrencies(): void
{
    $controller = \file_get_contents(BP . '/app/code/Weline/Websites/Controller/Admin/ScopeCurrency.php');
    self::assertIsString($controller);
    self::assertStringContainsString('reorderWebsiteCurrencies', $controller);
    self::assertStringContainsString('ResponseTerminateException', $controller);
    if (!\preg_match('/function postReorderWebsite\(\)\s*\{([\s\S]*?)\n    (?:public |private |protected )?function /', $controller, $m)) {
        self::fail('postReorderWebsite body not found');
    }
    $body = $m[1];
    self::assertStringContainsString('reorderWebsiteCurrencies', $body);
    self::assertStringNotContainsString('->setWebsiteCurrencies(', $body);
}

private function assert_CurrencyCatalogReorderSoftInvalidation_testReorderModelSkipsHeavyInvalidate(): void
{
    $model = \file_get_contents(BP . '/app/code/Weline/Websites/Model/WebsiteCurrency.php');
    self::assertIsString($model);
    self::assertStringContainsString('function reorderWebsiteCurrencies', $model);
    self::assertStringContainsString('CurrencyCatalogInvalidationService', $model);
    if (!\preg_match('/function reorderWebsiteCurrencies\([^)]*\): self\s*\{([\s\S]*?)\n    (?:public |private |protected )?function /', $model, $m)) {
        self::fail('reorderWebsiteCurrencies body not found');
    }
    $body = $m[1];
    self::assertStringContainsString('invalidateAfterReorder', $body);
    self::assertStringNotContainsString('invalidateWebsiteCurrency', $body);
}

private function assert_CurrencyCatalogReorderSoftInvalidation_testSoftServiceAvoidsWebsiteChanged(): void
{
    $service = \file_get_contents(BP . '/app/code/Weline/Websites/Service/Localization/CurrencyCatalogInvalidationService.php');
    self::assertIsString($service);
    self::assertStringContainsString('bumpCurrencyCatalogOnly', $service);
    self::assertStringNotContainsString('->invalidateWebsite(', $service);
    self::assertStringNotContainsString('invalidateWebsite(', $service);
}

private function assert_CurrencyCatalogReorderSoftInvalidation_testCurrencySelectAsyncSavesOnTagDrag(): void
{
    $js = \file_get_contents(BP . '/app/code/Weline/Currency/view/statics/js/currency-select.js');
    $css = \file_get_contents(BP . '/app/code/Weline/Currency/view/statics/css/currency-select.css');
    $form = \file_get_contents(BP . '/app/code/Weline/Websites/view/templates/Admin/Website/form.phtml');
    self::assertIsString($js);
    self::assertIsString($css);
    self::assertIsString($form);
    self::assertStringContainsString('scheduleReorderSave', $js);
    self::assertStringContainsString('resolveReorderUrl', $js);
    self::assertStringContainsString('data-w-currency-reorder-url', $js);
    self::assertStringContainsString('is-sortable', $js);
    self::assertStringContainsString('w-currency-select__drag-handle', $js);
    self::assertStringContainsString('cursor: grab', $css);
    self::assertStringContainsString("data-w-currency-reorder-url=\"@backend-url('*/admin/scope-currency/reorder-website')\"", $form);
    $taglib = \file_get_contents(BP . '/app/code/Weline/Currency/Taglib/CurrencySelect.php');
    self::assertIsString($taglib);
    self::assertStringContainsString('role="button"', $taglib);
    self::assertStringContainsString('w-currency-select__trigger', $taglib);
    self::assertDoesNotMatchRegularExpression('/<button\s+[^>]*w-currency-select__trigger/', $taglib);
}

private function assert_CurrencyCatalogReorderSoftInvalidation_testBumpCurrencyCatalogOnlySkipsApplyCommittedLegacyPath(): void
{
    $svc = \file_get_contents(BP . '/app/code/Weline/Websites/Service/WebsiteCacheInvalidationService.php');
    self::assertIsString($svc);
    self::assertStringContainsString('function bumpCurrencyCatalogOnly', $svc);
    if (!\preg_match('/function bumpCurrencyCatalogOnly\([\s\S]*?\n    (?:public |private |protected |\/\*\*)/', $svc, $m)) {
        self::fail('bumpCurrencyCatalogOnly body not found');
    }
    $body = $m[0];
    self::assertStringContainsString('COMPONENT_CURRENCY', $body);
    self::assertStringNotContainsString('invalidatePaths', $body);
}

private function assert_DefaultWebsiteServiceCurrency_testDefaultWebsiteUsesConfiguredFrameworkCurrency(): void
{
    $service = (new \ReflectionClass(DefaultWebsiteService::class))->newInstanceWithoutConstructor();
    $expected = strtoupper(trim((string) Env::system('currency', 'CNY'))) ?: 'CNY';
    self::assertSame($expected, $service->defaultRow()[Website::schema_fields_DEFAULT_CURRENCY] ?? null);
}

private function assert_DefaultWebsiteServiceCurrency_testDefaultCurrencyAssociationIsNotHardCodedToCny(): void
{
    $reflection = new \ReflectionMethod(DefaultWebsiteService::class, 'ensureDefaultCurrencyAndLanguage');
    $file = $reflection->getFileName();
    self::assertIsString($file);
    $lines = file($file);
    self::assertIsArray($lines);
    $source = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    self::assertStringContainsString('$defaultCurrency = $this->defaultCurrency();', $source);
    self::assertStringNotContainsString("in_array('CNY'", $source);
    self::assertStringNotContainsString("schema_fields_CURRENCY_CODE => 'CNY'", $source);
}

private function assert_DefaultWebsiteServiceLocalDomain_testResolveLocalDefaultDomainsIncludesCurrentProjectHost(): void
{
    $service = (new \ReflectionClass(DefaultWebsiteService::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(DefaultWebsiteService::class, 'resolveLocalDefaultDomains');
    $method->setAccessible(true);
    /** @var list<string> $domains */
    $domains = $method->invoke($service);
    self::assertContains('127.0.0.1', $domains);
    self::assertContains('localhost', $domains);
    $expected = LocalDomainPolicy::buildProjectHost(\substr(\sha1(\strtolower(\rtrim(\str_replace('\\', '/', (string) \getcwd()), '/'))), 0, 8), 'dev');
    self::assertContains($expected, $domains);
    self::assertStringEndsWith('.test.weline.com', $expected);
}

private function assert_DefaultWebsiteServiceLocalDomain_testEnsureWebsiteDomainBindingMethodExistsForWlsSync(): void
{
    self::assertTrue(\method_exists(DefaultWebsiteService::class, 'ensureWebsiteDomainBinding'));
}

private function assert_DomainParserManagedLocalRoot_testManagedTestWelineComRootWinsOverPublicSuffixList(): void
{
    $parser = new DomainParserService();
    self::assertSame('test.weline.com', $parser->parseRootDomain('grocery.test.weline.com'));
    self::assertSame('test.weline.com', $parser->parseRootDomain('p05113ef3.test.weline.com'));
    self::assertSame('test.weline.com', $parser->parseRootDomain('test.weline.com'));
}

private function assert_DomainParserManagedLocalRoot_testLegacyWelineTestRootStillResolved(): void
{
    $parser = new DomainParserService();
    self::assertSame('weline.test', $parser->parseRootDomain('shop.weline.test'));
}

private function assert_DomainParserManagedLocalRoot_testPublicDomainStillUsesRegistrableRoot(): void
{
    $parser = new DomainParserService();
    self::assertSame('example.com', $parser->parseRootDomain('www.example.com'));
}

private function assert_FrontendWorkerScopeHolePunchFastPath_testPunchVerifiesExistingCookieBeforeResolveChannelScope(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/FrontendWorkerScopeHolePunchService.php');
    self::assertStringContainsString('readExistingScopeCookie', $src);
    self::assertStringContainsString('SCOPE_COOKIE_NAME', $src);
    self::assertStringContainsString('verifyToken', $src);
    self::assertStringContainsString('Fast path: long HttpOnly Cookie already verifies', $src);
    $fastPos = \strpos($src, 'readExistingScopeCookie');
    $resolvePos = \strpos($src, 'resolveChannelScope(');
    $issuePos = \strpos($src, 'issueToken(');
    self::assertNotFalse($fastPos);
    self::assertNotFalse($resolvePos);
    self::assertNotFalse($issuePos);
    self::assertLessThan($resolvePos, $fastPos);
    self::assertLessThan($issuePos, $resolvePos);
}

private function assert_LanguageCatalogReorderSoftInvalidation_testReorderWebsiteUsesSoftPathNotSetWebsiteLanguages(): void
{
    $controller = \file_get_contents(BP . '/app/code/Weline/Websites/Controller/Admin/ScopeLanguage.php');
    self::assertIsString($controller);
    self::assertStringContainsString('reorderWebsiteLanguages', $controller);
    if (!\preg_match('/function postReorderWebsite\(\)\s*\{([\s\S]*?)\n    (?:public |private |protected )?function /', $controller, $m)) {
        self::fail('postReorderWebsite body not found');
    }
    $body = $m[1];
    self::assertStringContainsString('reorderWebsiteLanguages', $body);
    self::assertStringContainsString('ResponseTerminateException', $body);
    self::assertStringNotContainsString('->setWebsiteLanguages(', $body);
}

private function assert_LanguageCatalogReorderSoftInvalidation_testReorderModelSkipsLegacyMembershipInvalidationAndI18nSync(): void
{
    $model = \file_get_contents(BP . '/app/code/Weline/Websites/Model/WebsiteLanguage.php');
    self::assertIsString($model);
    self::assertStringContainsString('function reorderWebsiteLanguages', $model);
    self::assertStringContainsString('LanguageCatalogInvalidationService', $model);
    if (!\preg_match('/function reorderWebsiteLanguages\([^)]*\): self\s*\{([\s\S]*?)\n    \/\*\*/', $model, $m)) {
        self::fail('reorderWebsiteLanguages body not found');
    }
    $body = $m[1];
    self::assertStringContainsString('invalidateAfterReorder', $body);
    self::assertStringNotContainsString('clearWebsiteLanguageCaches', $body);
    self::assertStringNotContainsString('syncI18nTranslationTargets', $body);
    self::assertStringNotContainsString('syncThemeFontWarmup', $body);
}

private function assert_LanguageCatalogReorderSoftInvalidation_testSoftServiceAvoidsWebsiteChangedAndLegacyFullClear(): void
{
    $service = \file_get_contents(BP . '/app/code/Weline/Websites/Service/Localization/LanguageCatalogInvalidationService.php');
    self::assertIsString($service);
    self::assertStringContainsString('bumpLanguageCatalogOnly', $service);
    self::assertStringContainsString('binquery-i18n-getLanguageSwitcherCatalog', $service);
    self::assertStringContainsString("purge(\$domainId, 'tags'", $service);
    self::assertStringNotContainsString('w_changed(', $service);
    self::assertStringNotContainsString('->cacheClear(', $service);
    self::assertStringNotContainsString('purgeWebsiteScope(', $service);
    self::assertStringNotContainsString('legacy_full_clear', $service);
    self::assertStringNotContainsString('invalidateWebsite(', $service);
}

private function assert_LanguageCatalogReorderSoftInvalidation_testLanguageSelectAsyncSavesOnTagDrag(): void
{
    $js = \file_get_contents(BP . '/app/code/Weline/I18n/view/statics/js/language-select.js');
    self::assertIsString($js);
    self::assertStringContainsString('scheduleReorderSave', $js);
    self::assertStringContainsString('wReorderUrl', $js);
    self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
    self::assertStringContainsString('language_codes[]', $js);
    self::assertStringContainsString("emit('reorder'", $js);
    $form = \file_get_contents(BP . '/app/code/Weline/Websites/view/templates/Admin/Website/form.phtml');
    self::assertIsString($form);
    self::assertStringContainsString("data-w-language-reorder-url=\"@backend-url('*/admin/scope-language/reorder-website')\"", $form);
    self::assertStringNotContainsString('website-scope-language-manage', $form);
    self::assertStringNotContainsString('store-scope-language-manage', $form);
    self::assertStringNotContainsString('channel-scope-language-manage', $form);
    self::assertStringNotContainsString('scope-language/manage', $form);
    // 胶囊 DnD：mid-drag 禁止 renderTags；须指针穿透 + elementFromPoint 让位
    self::assertStringContainsString('elementFromPoint', $js);
    self::assertStringContainsString('insertBefore(dragging', $js);
    self::assertStringNotContainsString("renderTags();\n                    const dragging = tags.querySelector(`.w-language-select__tag", $js);
}

private function assert_LanguageCatalogReorderSoftInvalidation_testBumpLanguageCatalogOnlySkipsApplyCommittedLegacyPath(): void
{
    $svc = \file_get_contents(BP . '/app/code/Weline/Websites/Service/WebsiteCacheInvalidationService.php');
    self::assertIsString($svc);
    self::assertStringContainsString('function bumpLanguageCatalogOnly', $svc);
    if (!\preg_match('/function bumpLanguageCatalogOnly\([\s\S]*?\n    \/\*\*/', $svc, $m) && !\preg_match('/function bumpLanguageCatalogOnly\([\s\S]*?\n    public function /', $svc, $m)) {
        self::fail('bumpLanguageCatalogOnly body not found');
    }
    $body = $m[0];
    self::assertStringContainsString('bumpMany($paths)', $body);
    self::assertStringNotContainsString('invalidatePaths(', $body);
    self::assertStringNotContainsString('applyCommitted(', $body);
    self::assertStringNotContainsString('runLegacyFallback(', $body);
}

private function assert_ManagedLocalDomainProvisionService_testPublicDomainIsSkippedWithoutHostsOrCertificateCalls(): void
{
    $hosts = $this->createMock(LocalWelineHostsSyncService::class);
    $hosts->expects(self::never())->method('ensureHostsInjected');
    $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
    $certs->expects(self::never())->method('ensureWildcardCertificateForDomain');
    $service = new ManagedLocalDomainProvisionService($hosts, $certs, null, static fn(): array => ['ok' => true, 'skipped' => true]);
    $result = $service->ensureForManualCreate('example.com');
    self::assertFalse($result['success']);
    self::assertTrue($result['skipped'] ?? false);
    self::assertFalse($result['local_ready']);
    self::assertFalse($service->isManagedLocalDomain('example.com'));
}

private function assert_ManagedLocalDomainProvisionService_testManagedLocalDomainRunsHostsThenCertificateAndReportsReady(): void
{
    $domain = 'grocery.test.weline.com';
    $hosts = $this->createMock(LocalWelineHostsSyncService::class);
    $hosts->expects(self::once())->method('ensureHostsInjected')->with($domain)->willReturn(['success' => true, 'domain' => $domain]);
    $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
    $certs->expects(self::once())->method('ensureWildcardCertificateForDomain')->with($domain, 0)->willReturn(['success' => true, 'domain' => $domain, 'wildcard_domain' => '*.test.weline.com', 'cert_id' => 42]);
    $pool = $this->createMock(DomainPool::class);
    $pool->method('loadByDomain')->willReturnSelf();
    $pool->method('getPoolId')->willReturn(7);
    $pool->method('getHttpsStatus')->willReturn(DomainPool::HTTPS_STATUS_NONE);
    $pool->expects(self::atLeastOnce())->method('setIsLocalServer')->with(true)->willReturnSelf();
    $pool->expects(self::atLeastOnce())->method('setHttpsStatus')->willReturnSelf();
    $pool->expects(self::atLeastOnce())->method('setCertId')->with(42)->willReturnSelf();
    $pool->expects(self::atLeastOnce())->method('save')->willReturn(true);
    $pool->method('setStatus')->willReturnSelf();
    $pool->method('setResolveStatus')->willReturnSelf();
    $pool->method('setDnsStatus')->willReturnSelf();
    $pool->method('setCdnStatus')->willReturnSelf();
    $pool->method('setResolveCheckedAt')->willReturnSelf();
    $pool->method('setResolveError')->willReturnSelf();
    $pool->method('setResolvedIp')->willReturnSelf();
    $pool->method('setHttpsError')->willReturnSelf();
    $pool->method('setPoolLifecycleStage')->willReturnSelf();
    $pool->method('setSiteReady')->willReturnSelf();
    $pool->method('calculateSiteReady')->willReturn(true);
    $edgeCalled = false;
    $service = new ManagedLocalDomainProvisionService($hosts, $certs, $pool, static function () use (&$edgeCalled): array {
        $edgeCalled = true;
        return ['ok' => true, 'message' => 'edge synced'];
    });
    self::assertTrue($service->isManagedLocalDomain($domain));
    $result = $service->ensureForManualCreate($domain, 0);
    self::assertTrue($result['success']);
    self::assertTrue($result['local_ready']);
    self::assertSame($domain, $result['domain']);
    self::assertTrue(($result['hosts']['success'] ?? false) === true);
    self::assertTrue(($result['certificate']['success'] ?? false) === true);
    self::assertTrue($edgeCalled);
    self::assertTrue(($result['edge']['ok'] ?? false) === true);
}

private function assert_ManagedLocalDomainProvisionService_testAuthorizationPendingStopsBeforePretendingReady(): void
{
    $domain = 'pending-demo.test.weline.com';
    $hosts = $this->createMock(LocalWelineHostsSyncService::class);
    $hosts->expects(self::once())->method('ensureHostsInjected')->willReturn(['success' => false, 'authorization_pending' => true, 'authorization_already_started' => true, 'message' => '等待管理员批准']);
    $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
    $certs->expects(self::never())->method('ensureWildcardCertificateForDomain');
    $pool = $this->createMock(DomainPool::class);
    $pool->method('loadByDomain')->willReturnSelf();
    $pool->method('getPoolId')->willReturn(3);
    $pool->method('getHttpsStatus')->willReturn(DomainPool::HTTPS_STATUS_NONE);
    $pool->method('setStatus')->willReturnSelf();
    $pool->method('setResolveStatus')->willReturnSelf();
    $pool->method('setDnsStatus')->willReturnSelf();
    $pool->method('setCdnStatus')->willReturnSelf();
    $pool->method('setIsLocalServer')->willReturnSelf();
    $pool->method('setResolveCheckedAt')->willReturnSelf();
    $pool->method('setResolveError')->willReturnSelf();
    $pool->method('setResolvedIp')->willReturnSelf();
    $pool->method('setHttpsStatus')->willReturnSelf();
    $pool->method('setPoolLifecycleStage')->willReturnSelf();
    $pool->method('setSiteReady')->willReturnSelf();
    $pool->method('calculateSiteReady')->willReturn(false);
    $pool->method('save')->willReturn(true);
    $service = new ManagedLocalDomainProvisionService($hosts, $certs, $pool, static function (): array {
        self::fail('edge sync must not run while hosts authorization is pending');
    });
    $result = $service->ensureForManualCreate($domain);
    self::assertFalse($result['success']);
    self::assertFalse($result['local_ready']);
    self::assertTrue($result['authorization_pending'] ?? false);
    self::assertTrue($result['authorization_already_started'] ?? false);
}

private function ensureSource_PublicDnsHostnamePoolEnsureService(): string
{
    $path = BP . '/app/code/Weline/Websites/Service/PublicDnsHostnamePoolEnsureService.php';
    self::assertFileExists($path);
    return (string) \file_get_contents($path);
}

private function assert_PublicDnsHostnamePoolEnsureService_testServiceOwnsPoolUpsertAndImmediateDns01Cert(): void
{
    $src = $this->ensureSource_PublicDnsHostnamePoolEnsureService();
    self::assertStringContainsString('class PublicDnsHostnamePoolEnsureService', $src);
    self::assertStringContainsString('ensureRootDomain', $src);
    self::assertStringContainsString('ensurePoolHostname', $src);
    self::assertStringContainsString('ensureDnsRegistrarAccount', $src);
    self::assertStringContainsString("challenge_strategy' => 'dns01'", $src);
    self::assertStringContainsString('CertificateRequestService', $src);
    self::assertStringContainsString('applyAfterResolvePass', $src);
    self::assertStringContainsString('markCertPending', $src);
    self::assertStringNotContainsString('LocalDomainRegisteredEventDispatcher', $src);
    self::assertStringNotContainsString('Weline_Server::domain::local_domain_registered', $src);
}

private function assert_PublicDnsHostnamePoolEnsureService_testQueryProviderExposesEnsureOperation(): void
{
    $path = BP . '/app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("'ensurePublicDnsHostnamesInPool'", $src);
    self::assertStringContainsString('function ensurePublicDnsHostnamesInPool', $src);
    self::assertStringContainsString('PublicDnsHostnamePoolEnsureService', $src);
}

private function assert_PublicDnsHostnamePoolEnsureService_testMailDnsApplyHooksPoolEnsureViaQuery(): void
{
    $path = BP . '/app/code/Weline/Mail/Controller/Backend/Index.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('ensureMailHostnamesInDomainPool', $src);
    self::assertStringContainsString("w_query('websites', 'ensurePublicDnsHostnamesInPool'", $src);
    self::assertStringContainsString("'source' => 'mail_dns'", $src);
}

private function assert_ResolvedLanguageCatalog_testInterfaceAndServiceExistWithInheritanceContract(): void
{
    $iface = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Api/Localization/ResolvedLanguageCatalogInterface.php';
    $svc = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/Localization/ResolvedLanguageCatalog.php';
    $override = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/ScopeLanguageOverride.php';
    self::assertFileExists($iface);
    self::assertFileExists($svc);
    self::assertFileExists($override);
    $ifaceSrc = (string) \file_get_contents($iface);
    $svcSrc = (string) \file_get_contents($svc);
    $overrideSrc = (string) \file_get_contents($override);
    self::assertStringContainsString('getResolvedLanguageCodes', $ifaceSrc);
    self::assertStringContainsString('saveOverride', $ifaceSrc);
    self::assertStringContainsString('clearOverride', $ifaceSrc);
    self::assertStringContainsString('hasOverride', $ifaceSrc);
    self::assertStringContainsString('CHANNEL_ID_STORE_SCOPE = -1', $overrideSrc);
    self::assertStringContainsString('CHANNEL_ID_STORE_SCOPE', $svcSrc);
    self::assertStringContainsString('expandWebsiteUnionAndWarmup', $svcSrc);
}

private function assert_ResolvedLanguageCatalog_testQueryProviderExposesResolvedLanguageCodes(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("'getResolvedLanguageCodes'", $src);
    self::assertStringContainsString('getResolvedLanguageCodes($params)', $src);
}

private function assert_ResolvedLanguageCatalog_testModuleRegistersResolvedLanguageCatalog(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/etc/module.php';
    self::assertSame(\Weline\Websites\Service\Localization\ResolvedLanguageCatalog::class, $module['provides'][\Weline\Websites\Api\Localization\ResolvedLanguageCatalogInterface::class] ?? null);
}

private function assert_ScopeCatalogHotCachePublisher_testScopeResolverPublishesCatalogHotCacheAfterScopeDataInstall(): void
{
    $src = (string) file_get_contents((string) (new \ReflectionClass(ScopeResolver::class))->getFileName());
    self::assertStringContainsString('ScopeData::install', $src);
    self::assertStringContainsString('publishScopeCatalogHotCache', $src);
    self::assertStringContainsString('ScopeCatalogHotCachePublisher', $src);
    $installPos = \strpos($src, 'ScopeData::install');
    $publishPos = \strpos($src, 'publishScopeCatalogHotCache');
    self::assertNotFalse($installPos);
    self::assertNotFalse($publishPos);
    self::assertGreaterThan($installPos, (int) $publishPos);
}

private function assert_ScopeCatalogHotCachePublisher_testPublisherRequiresFrozenScopeBeforeWork(): void
{
    $src = (string) file_get_contents((string) (new \ReflectionClass(ScopeCatalogHotCachePublisher::class))->getFileName());
    self::assertStringContainsString('freezeCurrent', $src);
    self::assertStringNotContainsString('projectLocaleCatalogBag', $src);
    self::assertStringNotContainsString('StorefrontRenderContext::projectLocaleCatalog', $src);
    self::assertStringContainsString('ResolvedLanguageCatalog process bag', $src);
    self::assertStringContainsString('hasCompleteFrozenScope', $src);
    self::assertStringContainsString('rememberPolicy', $src);
    self::assertStringContainsString('byStore', $src);
}

private function assert_ScopeCatalogHotCachePublisher_testDefaultChannelBuilderDoesNotNestByCodeRememberPolicy(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/SalesChannelCatalog.php');
    self::assertStringContainsString('ScopeCatalogHotCachePublisher', $src);
    // Fallback path must not call byCodeForStore inside default: builder.
    $methodStart = \strpos($src, 'function defaultChannelWithStore');
    self::assertNotFalse($methodStart);
    $methodEnd = \strpos($src, 'function byCodeForStore', $methodStart);
    self::assertNotFalse($methodEnd);
    $body = \substr($src, $methodStart, $methodEnd - $methodStart);
    self::assertStringNotContainsString('$this->byCodeForStore(', $body);
    self::assertStringContainsString('schema_fields_CODE', $body);
}

private function assert_ScopeCatalogHotCachePublisher_testModuleProvidesScopeCatalogCacheWarmer(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/etc/module.php';
    self::assertIsArray($module);
    $provides = $module['provides'] ?? [];
    self::assertArrayHasKey('cache.warmer.websites.scope_catalog', $provides);
    self::assertSame(ScopeCatalogCacheWarmer::class, $provides['cache.warmer.websites.scope_catalog']);
    self::assertSame('website', (new ScopeCatalogCacheWarmer())->getTargetPool());
}

private function assert_ScopeCatalogHotCachePublisher_testCacheClearWarmsWebsitePoolAfterFpc(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/Console/Cache/Clear.php');
    self::assertStringContainsString("warmUp('fpc')", $src);
    self::assertStringContainsString("warmUp('website')", $src);
    $fpcPos = \strpos($src, "warmUp('fpc')");
    $webPos = \strpos($src, "warmUp('website')");
    self::assertNotFalse($fpcPos);
    self::assertNotFalse($webPos);
    self::assertGreaterThan((int) $fpcPos, (int) $webPos);
}

private function assert_ScopeDisplayTypeResolver_testResolverPreferChannelThenStoreThenWebsiteAndFailSoftUnknown(): void
{
    $resolverPath = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/ScopeDisplayTypeResolver.php';
    $registryPath = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/ScopeDisplayTypeRegistry.php';
    $interfacePath = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Api/ScopeDisplayTypeProviderInterface.php';
    $extendsPath = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/extends.php';
    self::assertFileExists($resolverPath);
    self::assertFileExists($registryPath);
    self::assertFileExists($interfacePath);
    self::assertFileExists($extendsPath);
    $resolver = (string) file_get_contents($resolverPath);
    $interface = (string) file_get_contents($interfacePath);
    $extends = (string) file_get_contents($extendsPath);
    self::assertStringContainsString('channel → store → website', $resolver);
    self::assertStringContainsString('REQUEST_PRIVATE_KEY', $resolver);
    self::assertStringContainsString('runtime.request_context.display_type', $resolver);
    self::assertStringContainsString('isKnownCode', $resolver);
    self::assertStringNotContainsString('createCatalogConstraint', $interface);
    self::assertStringContainsString('getCode()', $interface);
    self::assertStringContainsString('getLabel()', $interface);
    self::assertStringContainsString('getModule()', $interface);
    self::assertStringContainsString("'ScopeDisplayType'", $extends);
    self::assertStringContainsString('ScopeDisplayTypeProviderInterface', $extends);
}

private function assert_ScopeDisplayTypeResolver_testModelsOwnDisplayTypeAndChannelUrlOutsideSummaryV1(): void
{
    $website = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/Website.php');
    $store = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/Store.php');
    $channel = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/SalesChannel.php');
    $storeSummary = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Api/Catalog/Data/StoreSummary.php');
    $channelSummary = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Api/Catalog/Data/SalesChannelSummary.php');
    self::assertStringContainsString("schema_fields_DISPLAY_TYPE = 'display_type'", $website);
    self::assertStringContainsString("schema_fields_DISPLAY_TYPE = 'display_type'", $store);
    self::assertStringContainsString("schema_fields_DISPLAY_TYPE = 'display_type'", $channel);
    self::assertStringContainsString("schema_fields_URL = 'url'", $channel);
    self::assertStringNotContainsString('display_type', $storeSummary);
    self::assertStringNotContainsString('display_type', $channelSummary);
    self::assertStringNotContainsString("'url'", $channelSummary);
}

private function assert_ScopeMaintenanceRenderContextReader_testStatusPrefersReaderThenRequestMemo(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/ScopeMaintenanceGate.php');
    self::assertStringContainsString('StorefrontRenderContextReader::maintenance', $src);
    self::assertStringContainsString("rememberForRequest(", $src);
    self::assertStringContainsString("'scope_maintenance'", $src);
    self::assertStringContainsString('$this->status($candidate)', $src);
    self::assertStringNotContainsString('$this->repository->status($candidate)', $src);
    self::assertTrue(class_exists(ScopeMaintenanceGate::class));
}

private function setUp_ScopePathMatchCache(): void
{
    parent::setUp();
    Context::leave();
    Context::enter(new Context());
    RequestContext::init();
    ScopeData::resetRequestState();
    ScopePathMatchCache::clearProcessCache();
}

private function tearDown_ScopePathMatchCache(): void
{
    ScopeData::resetRequestState();
    ScopePathMatchCache::clearProcessCache();
    RequestContext::resetWelineVars();
    Context::leave();
    parent::tearDown();
}

private function assert_ScopePathMatchCache_testMatchKeyIgnoresQueryAndUsesCanonicalPath(): void
{
    $key = ScopePathMatchKey::fromTrustedUrl('https://shop.example.test:8443/outlet/catalog?x=1#frag');
    self::assertSame('https', $key->scheme);
    self::assertSame('shop.example.test', $key->host);
    self::assertSame(8443, $key->port);
    self::assertSame('/outlet/catalog', $key->path);
    self::assertNotSame($key->cacheIdentity('v1'), $key->cacheIdentity('v2'));
}

private function assert_ScopePathMatchCache_testHitRoundTripPreservesStoreAndChannelSummaries(): void
{
    $store = new StoreSummary(3, 1, 'outlet', 'Outlet', Store::MODE_NORMAL, false, true, Store::LIFECYCLE_ACTIVE, null, 'https://shop.example.test/outlet');
    $channel = new SalesChannelSummary(9, 1, 3, SalesChannel::CODE_DEFAULT, 'Default', true, true, Store::LIFECYCLE_ACTIVE, true);
    $hit = ScopePathMatchHit::fromResolved(1, 'shop', $store, $channel, '/catalog');
    $restored = ScopePathMatchHit::fromArray($hit->toArray());
    self::assertInstanceOf(ScopePathMatchHit::class, $restored);
    self::assertTrue($restored->matchesWebsite(1, 'shop'));
    self::assertSame(3, $restored->storeSummary()?->id);
    self::assertSame(9, $restored->channelSummary()?->id);
    self::assertSame('/catalog', $restored->routePath);
}

private function assert_ScopePathMatchCache_testScopeDataInstallAndMatchHelpers(): void
{
    $store = new StoreSummary(0, 0, Store::CODE_DEFAULT, 'Default', Store::MODE_NORMAL, true, true, Store::LIFECYCLE_ACTIVE, null, null);
    $channel = new SalesChannelSummary(0, 0, 0, SalesChannel::CODE_DEFAULT, 'Default', true, true, Store::LIFECYCLE_ACTIVE, true);
    ScopeData::install($store, $channel, '/');
    self::assertTrue(ScopeData::matchesStoreId(0));
    self::assertTrue(ScopeData::matchesChannelId(0));
    self::assertTrue(ScopeData::matchesStoreCode(0, 'default'));
    self::assertTrue(ScopeData::matchesChannelCode(0, 'default'));
    self::assertSame('/', ScopeData::getRoutePath());
    ScopeData::resetRequestState();
    self::assertNull(ScopeData::getStore());
}

private function assert_ScopePathMatchCache_testPathMatchCacheReadWriteUsesInjectedPool(): void
{
    $pool = new ScopePathMatchCachePoolStub();
    $cache = new class($pool) extends ScopePathMatchCache
    {
        public function __construct(CachePoolInterface $pool)
        {
            parent::__construct($pool);
        }
        public function registryVersion(): string
        {
            return 'test-registry-v1';
        }
    };
    $key = new ScopePathMatchKey('https', 'shop.example.test', 443, '/outlet');
    $store = new StoreSummary(5, 2, 'outlet', 'Outlet', Store::MODE_NORMAL, false, true, Store::LIFECYCLE_ACTIVE, null, 'https://shop.example.test/outlet');
    $channel = new SalesChannelSummary(7, 2, 5, SalesChannel::CODE_DEFAULT, 'Default', true, true, Store::LIFECYCLE_ACTIVE, true);
    $hit = ScopePathMatchHit::fromResolved(2, 'brand', $store, $channel, '/');
    $cache->writeMatch($key, $hit);
    $read = $cache->readMatch($key);
    self::assertInstanceOf(ScopePathMatchHit::class, $read);
    self::assertSame(5, $read->storeId);
    self::assertSame(5, $cache->readStoreSnapshot(5)?->id);
    self::assertSame(7, $cache->readChannelSnapshot(7)?->id);
    self::assertSame(0, $pool->gets, 'A same-worker path hit must not round-trip to WLS.');
    $secondReader = new class($pool) extends ScopePathMatchCache
    {
        public function __construct(CachePoolInterface $pool)
        {
            parent::__construct($pool);
        }
        public function registryVersion(): string
        {
            return 'test-registry-v1';
        }
    };
    self::assertInstanceOf(ScopePathMatchHit::class, $secondReader->readMatch($key));
    self::assertSame(5, $secondReader->readStoreSnapshot(5)?->id);
    self::assertSame(7, $secondReader->readChannelSnapshot(7)?->id);
    self::assertSame(0, $pool->gets, 'A new RequestContext in the same worker must reuse L1.');
}

private function assert_ScopeResolverChannelUrl_testResolveChannelMatchesRemainderPathAndRelaxesL2UsablePredicate(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/ScopeResolver.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('[$channel, $routePath] = $this->resolveChannel(', $source);
    self::assertStringContainsString('channelUrlReader()->urlsByStore', $source);
    self::assertStringContainsString('channel_url_ambiguous', $source);
    self::assertStringContainsString('channel_url_invalid', $source);
    self::assertStringContainsString('freezeCurrentFromRequestContext', $source);
    self::assertMatchesRegularExpression('/function isUsableCachedChannel\([\s\S]*?parentStoreLifecycleStatus === Store::LIFECYCLE_ACTIVE;\s*}/', $source);
    self::assertDoesNotMatchRegularExpression('/function isUsableCachedChannel\([\s\S]*?\$channel->isDefault\s*&&/', $source);
}

private function assert_ScopeResolverChannelUrl_testScopeSwitcherHidesWhenOnlyDefaultStoreAndChannel(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/ScopeSwitcherPresenter.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('$nonDefaultCount > 0', $source);
    self::assertStringContainsString('$navigableCount > 0', $source);
    self::assertStringContainsString('channelUrls->urlsByStore', $source);
}

private function assert_ScopeResolverChannelUrl_testScopeSwitcherLocalizesChannelHrefsViaGetFrontendUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/ScopeSwitcherPresenter.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('localizeStorefrontEntryUrl', $source);
    self::assertStringContainsString('storefrontRouteFromEntryUrl', $source);
    self::assertStringContainsString('getFrontendUrl($route)', $source);
    self::assertStringContainsString('CanonicalStorefrontUrl::fromStoreUrl', $source);
    self::assertStringContainsString("'url' => \$clickable ? \$this->localizeStorefrontEntryUrl(trim((string)\$channelEntry)) : ''", $source);
    // Must not emit raw absolute entry URLs as clickable hrefs.
    self::assertStringNotContainsString("'url' => \$clickable ? trim((string)\$channelEntry) : ''", $source);
}

private function assert_StoreChannelCatalog_testSummaryDtosAreFinalReadonlyAndProjectExactV1Fields(): void
{
    $store = new StoreSummary(1, 0, 'default', '默认店铺', Store::MODE_NORMAL, true, true, Store::LIFECYCLE_ACTIVE, null, null);
    $channel = new SalesChannelSummary(1, 0, 1, 'default', '默认渠道', true, true, Store::LIFECYCLE_ACTIVE, true);
    $storeReflection = new \ReflectionClass(StoreSummary::class);
    self::assertTrue($storeReflection->isFinal());
    self::assertTrue($storeReflection->isReadOnly());
    self::assertSame(['store_id', 'website_id', 'code', 'name', 'store_mode', 'is_default', 'enabled', 'lifecycle_status', 'tombstoned_at', 'url'], \array_keys($store->toArray()));
    $channelReflection = new \ReflectionClass(SalesChannelSummary::class);
    self::assertTrue($channelReflection->isFinal());
    self::assertTrue($channelReflection->isReadOnly());
    self::assertSame(['channel_id', 'website_id', 'store_id', 'code', 'name', 'is_default', 'enabled', 'parent_store_lifecycle_status', 'effective_enabled'], \array_keys($channel->toArray()));
}

private function assert_StoreChannelCatalog_testStoreCatalogMapsZeroWebsiteActiveAndTombstoneRows(): void
{
    $active = self::validStoreRow_StoreChannelCatalog();
    $tombstone = self::validStoreRow_StoreChannelCatalog([Store::schema_fields_ID => 2, Store::schema_fields_CODE => 'archived', Store::schema_fields_NAME => '归档店铺', Store::schema_fields_STORE_MODE => Store::MODE_TEST, Store::schema_fields_IS_DEFAULT => 0, Store::schema_fields_STATUS => 0, Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_TOMBSTONE, Store::schema_fields_TOMBSTONED_AT => '2026-07-24 00:00:00']);
    /** @var list<StoreSummary> $mapped */
    $mapped = self::invokePrivate_StoreChannelCatalog($this->storeCatalog_StoreChannelCatalog(), 'mapRows', [[$active, $tombstone], 0]);
    self::assertCount(2, $mapped);
    self::assertSame(0, $mapped[0]->websiteId);
    self::assertTrue($mapped[0]->isDefault);
    self::assertSame(Store::LIFECYCLE_ACTIVE, $mapped[0]->lifecycleStatus);
    self::assertFalse($mapped[1]->enabled);
    self::assertSame(Store::LIFECYCLE_TOMBSTONE, $mapped[1]->lifecycleStatus);
    self::assertSame('2026-07-24 00:00:00', $mapped[1]->tombstonedAt);
}

/** @return iterable<string, array{list<array<string, mixed>>, list<string>}> */
private function invalidStoreRows_StoreChannelCatalog(): iterable
{
    yield 'non-canonical code' => [[self::validStoreRow_StoreChannelCatalog([Store::schema_fields_CODE => 'Bad Code', Store::schema_fields_IS_DEFAULT => 0])], ['不是规范值', 'non-canonical code']];
    yield 'code above 64 characters' => [[self::validStoreRow_StoreChannelCatalog([Store::schema_fields_CODE => \str_repeat('a', Store::CODE_MAX_LENGTH + 1), Store::schema_fields_IS_DEFAULT => 0])], ['店铺代码不能超过', 'Store code cannot exceed']];
    yield 'name above 128 characters' => [[self::validStoreRow_StoreChannelCatalog([Store::schema_fields_CODE => 'secondary', Store::schema_fields_NAME => \str_repeat('店', Store::NAME_MAX_LENGTH + 1), Store::schema_fields_IS_DEFAULT => 0])], ['店铺名称不能超过', 'Store name cannot exceed']];
    yield 'active row with tombstone timestamp' => [[self::validStoreRow_StoreChannelCatalog([Store::schema_fields_CODE => 'active_with_tombstone', Store::schema_fields_IS_DEFAULT => 0, Store::schema_fields_TOMBSTONED_AT => '2026-07-24 00:00:00'])], ['不允许存在墓碑时间', 'cannot have a tombstone timestamp']];
    yield 'enabled tombstone' => [[self::validStoreRow_StoreChannelCatalog([Store::schema_fields_CODE => 'enabled_tombstone', Store::schema_fields_IS_DEFAULT => 0, Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_TOMBSTONE, Store::schema_fields_TOMBSTONED_AT => '2026-07-24 00:00:00'])], ['必须处于停用状态', 'must be disabled']];
    yield 'default marker mismatch' => [[self::validStoreRow_StoreChannelCatalog([Store::schema_fields_CODE => 'secondary'])], ['default 代码与默认标记不一致', 'inconsistent default code and flag']];
    yield 'duplicate default codes' => [[self::validStoreRow_StoreChannelCatalog(), self::validStoreRow_StoreChannelCatalog([Store::schema_fields_ID => 2, Store::schema_fields_NAME => '第二默认店铺'])], ['店铺代码重复', 'duplicate Store code']];
}

private function assert_StoreChannelCatalog_testSalesChannelEffectiveEnabledFollowsParentStore(): void
{
    $activeParent = new StoreSummary(11, 0, 'web', '网站店铺', Store::MODE_DEV, false, true, Store::LIFECYCLE_ACTIVE, null);
    $tombstoneParent = new StoreSummary(11, 0, 'web', '网站店铺', Store::MODE_DEV, false, false, Store::LIFECYCLE_TOMBSTONE, '2026-07-24 00:00:00');
    $catalog = $this->salesChannelCatalog_StoreChannelCatalog($activeParent);
    $row = self::validChannelRow_StoreChannelCatalog();
    /** @var list<SalesChannelSummary> $active */
    $active = self::invokePrivate_StoreChannelCatalog($catalog, 'mapRows', [[$row], 11, $activeParent]);
    /** @var list<SalesChannelSummary> $tombstone */
    $tombstone = self::invokePrivate_StoreChannelCatalog($catalog, 'mapRows', [[$row], 11, $tombstoneParent]);
    self::assertTrue($active[0]->effectiveEnabled);
    self::assertSame(Store::LIFECYCLE_ACTIVE, $active[0]->parentStoreLifecycleStatus);
    self::assertFalse($tombstone[0]->effectiveEnabled);
    self::assertSame(Store::LIFECYCLE_TOMBSTONE, $tombstone[0]->parentStoreLifecycleStatus);
}

private function assert_StoreChannelCatalog_testSalesChannelCatalogRejectsCrossWebsiteParent(): void
{
    $parent = new StoreSummary(11, 0, 'web', '网站店铺', Store::MODE_DEV, false, true, Store::LIFECYCLE_ACTIVE, null);
    $row = self::validChannelRow_StoreChannelCatalog([SalesChannel::schema_fields_WEBSITE_ID => 9]);
    try {
        self::invokePrivate_StoreChannelCatalog($this->salesChannelCatalog_StoreChannelCatalog($parent), 'mapRows', [[$row], 11, $parent]);
        self::fail('A cross-Website parent Store must be rejected.');
    } catch (\RuntimeException $exception) {
        self::assertMessageContainsAny_StoreChannelCatalog($exception->getMessage(), ['与父店铺的 Website 归属不一致', "does not belong to the parent Store's Website"]);
    }
}

/** @return iterable<string, array{array<string, mixed>, list<string>}> */
private function invalidSalesChannelRows_StoreChannelCatalog(): iterable
{
    yield 'code above 64 characters' => [self::validChannelRow_StoreChannelCatalog([SalesChannel::schema_fields_CODE => \str_repeat('a', SalesChannel::CODE_MAX_LENGTH + 1)]), ['渠道代码不能超过', 'SalesChannel code cannot exceed']];
    yield 'name above 128 characters' => [self::validChannelRow_StoreChannelCatalog([SalesChannel::schema_fields_NAME => \str_repeat('渠', SalesChannel::NAME_MAX_LENGTH + 1)]), ['渠道名称不能超过', 'SalesChannel name cannot exceed']];
}

private function assert_StoreChannelCatalog_testSalesChannelByCodeRejectsOversizedStoreIdBeforeBlankCodeShortcut(): void
{
    $parent = new StoreSummary(11, 0, 'web', '网站店铺', Store::MODE_DEV, false, true, Store::LIFECYCLE_ACTIVE, null);
    $this->expectException(\InvalidArgumentException::class);
    $this->salesChannelCatalog_StoreChannelCatalog($parent)->byCode(2147483648, '');
}

private function assert_StoreChannelCatalog_testDirectoryUsesCatalogContractsAndBuildsNestedProjection(): void
{
    $store = new StoreSummary(1, 0, 'default', '默认店铺', Store::MODE_NORMAL, true, true, Store::LIFECYCLE_ACTIVE, null);
    $channel = new SalesChannelSummary(1, 0, 1, 'default', '默认渠道', true, true, Store::LIFECYCLE_ACTIVE, true);
    $directory = new WebsiteStoreChannelDirectory(new FixedStoreCatalog($store), new FixedSalesChannelCatalog($channel));
    $rows = $directory->forWebsite(0);
    self::assertCount(1, $rows);
    self::assertSame(0, $rows[0]['website_id']);
    self::assertSame('default', $rows[0]['code']);
    self::assertSame([$channel->toArray()], $rows[0]['channels']);
}

private function assert_StoreChannelCatalog_testStoreAndSalesChannelWriteSurfacesRemainClosed(): void
{
    foreach ([BP . 'app/code/Weline/Websites/Controller/Admin/Store.php', BP . 'app/code/Weline/Websites/Controller/Admin/SalesChannel.php', BP . 'app/code/Weline/Websites/Controller/Backend/Store.php', BP . 'app/code/Weline/Websites/Controller/Backend/SalesChannel.php'] as $controller) {
        self::assertFileDoesNotExist($controller);
    }
    $menu = (string) \file_get_contents(BP . 'app/code/Weline/Websites/etc/backend/menu.xml');
    self::assertDoesNotMatchRegularExpression('/action=["\'][^"\']*(?:sales[-_]?channel|\/store)(?:\/|["\'])/i', $menu);
    $index = (string) \file_get_contents(BP . 'app/code/Weline/Websites/view/templates/Admin/Website/index.phtml');
    self::assertStringContainsString('method="get"', $index);
    self::assertStringContainsString('for="search-input"', $index);
    $form = (string) \file_get_contents(BP . 'app/code/Weline/Websites/view/templates/Admin/Website/form.phtml');
    $table = (string) \file_get_contents(BP . 'app/code/Weline/Websites/view/templates/Admin/Website/datatable.phtml');
    foreach ([$form, $table] as $template) {
        self::assertDoesNotMatchRegularExpression('/<(?:input|select|textarea)[^>]+name=["\'][^"\']*(?:store|channel)/i', $template);
        self::assertDoesNotMatchRegularExpression('/(?:href|action)=["\'][^"\']*(?:sales[-_]?channel|\/store)(?:\/|["\'])/i', $template);
    }
    self::assertStringContainsString('.accordion-button:focus-visible', $form);
    self::assertStringNotContainsString('.accordion-button:focus {', $form);
}

private function assert_StoreChannelCatalog_testQueryProviderPublishesOnlyTheTwoReadOnlyV1CatalogOperations(): void
{
    $provider = ObjectManager::getInstance(WebsitesQueryProvider::class);
    $operations = $provider->getDescriptor()['operations'] ?? [];
    self::assertIsArray($operations);
    $catalogOperations = \array_values(\array_filter($operations, static fn(array $operation): bool => \in_array($operation['name'] ?? '', ['getStoreCatalogV1', 'getSalesChannelCatalogV1'], true)));
    self::assertSame(['getStoreCatalogV1', 'getSalesChannelCatalogV1'], \array_column($catalogOperations, 'name'));
    foreach ($catalogOperations as $operation) {
        self::assertSame('v1', $operation['contract_version'] ?? null);
        self::assertSame('read', $operation['mode'] ?? null);
        // frontend=true 允许后台 Weline.Api worker；auth=backend + external=false 关闭前台匿名/外联
        self::assertTrue($operation['frontend'] ?? false);
        self::assertSame('backend', $operation['auth'] ?? null);
        self::assertTrue($operation['backend'] ?? false);
        self::assertFalse($operation['external'] ?? true);
        self::assertFalse($operation['graph'] ?? true);
    }
    self::assertSame(0, $catalogOperations[0]['params'][0]['min'] ?? null);
    self::assertSame(0, $catalogOperations[1]['params'][0]['min'] ?? null);
    self::assertSame(2147483647, $catalogOperations[0]['params'][0]['max'] ?? null);
    self::assertSame(2147483647, $catalogOperations[1]['params'][0]['max'] ?? null);
    self::assertSame(['store_id', 'website_id', 'code', 'name', 'store_mode', 'is_default', 'enabled', 'lifecycle_status', 'tombstoned_at', 'url'], \array_column($catalogOperations[0]['returns']['items']['fields'] ?? [], 'name'));
    self::assertSame(['channel_id', 'website_id', 'store_id', 'code', 'name', 'is_default', 'enabled', 'parent_store_lifecycle_status', 'effective_enabled'], \array_column($catalogOperations[1]['returns']['items']['fields'] ?? [], 'name'));
    $storeOrChannelOperations = \array_values(\array_filter($operations, static fn(array $operation): bool => \preg_match('/(?:Store|SalesChannel)/D', (string) ($operation['name'] ?? '')) === 1));
    self::assertCount(2, $storeOrChannelOperations);
}

/** @return iterable<string, array{string, array<string, mixed>}> */
private function invalidCatalogParams_StoreChannelCatalog(): iterable
{
    yield 'missing website' => ['getStoreCatalogV1', []];
    yield 'non-canonical website' => ['getStoreCatalogV1', ['website_id' => '00']];
    yield 'negative website' => ['getStoreCatalogV1', ['website_id' => -1]];
    yield 'website above signed int' => ['getStoreCatalogV1', ['website_id' => 2147483648]];
    yield 'website with extra parameter' => ['getStoreCatalogV1', ['website_id' => 0, 'unexpected' => true]];
    yield 'missing store' => ['getSalesChannelCatalogV1', []];
    yield 'negative store' => ['getSalesChannelCatalogV1', ['store_id' => -1]];
    yield 'store above signed int' => ['getSalesChannelCatalogV1', ['store_id' => 2147483648]];
    yield 'store with extra parameter' => ['getSalesChannelCatalogV1', ['store_id' => 1, 'unexpected' => true]];
}

private function assert_StoreChannelCatalog_testCatalogEnglishTranslationsContainNoChineseAndPreservePlaceholders(): void
{
    $keys = [];
    foreach ([StoreCatalog::class, SalesChannelCatalog::class, WebsiteStoreChannelDirectory::class] as $class) {
        $file = (new \ReflectionClass($class))->getFileName();
        self::assertIsString($file);
        $source = \file_get_contents($file);
        self::assertIsString($source);
        self::assertGreaterThan(0, \preg_match_all("/__\\(\\s*'([^']+)'/u", $source, $matches));
        $keys = \array_merge($keys, $matches[1]);
    }
    $keys = \array_values(\array_unique(\array_merge($keys, ['按网站读取店铺目录 v1', '网站 ID；0 是合法的系统默认站点', '按店铺读取销售渠道目录 v1', '目录查询缺少必填参数：%{1}', '目录查询只允许参数：%{1}', '目录查询参数 %{1} 超出整数范围', '目录查询参数 %{1} 必须是规范整数', '目录查询参数 %{1} 必须大于或等于 %{2}', '目录查询参数 %{1} 不能大于 %{2}', '正式', '开发', '测试', '活动', '墓碑', 'Store / SalesChannel 目录', 'Store 与 SalesChannel 只读目录', '%{1} 个 Store', '只读', '暂无 Store/Channel 目录，请运行 setup:upgrade 补齐默认目录。', '默认', '启用', '停用', '此 Store 暂无 SalesChannel', 'SalesChannel 列表', '有效', '不可用', 'Store / SalesChannel', '列表只读展示站点下的店铺模式、生命周期与销售渠道，不在此处修改目录。', 'Store / SalesChannel 只读目录', '此处仅展示目录。Store 模式、生命周期与 SalesChannel 不会随 Website 表单保存而修改。', '保存 Website 后，系统会在升级与站点保存流程中建立默认 Store 和 SalesChannel。', '当前 Website 暂无 Store/Channel 目录，请运行 setup:upgrade 补齐默认目录。', '网站目录行缺少 website_id'])));
    self::assertGreaterThanOrEqual(80, \count($keys));
    $translations = self::loadTranslations_StoreChannelCatalog(BP . 'app/code/Weline/Websites/i18n/en_US.csv');
    foreach ($keys as $key) {
        self::assertArrayHasKey($key, $translations, 'Missing en_US translation for: ' . $key);
        $translation = $translations[$key];
        self::assertNotSame('', \trim($translation), 'Empty en_US translation for: ' . $key);
        self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $translation, 'en_US translation still contains Chinese for: ' . $key);
        self::assertSame(self::placeholders_StoreChannelCatalog($key), self::placeholders_StoreChannelCatalog($translation), 'Translation placeholders differ for: ' . $key);
    }
}

/** @return array<string, string> */
private function loadTranslations_StoreChannelCatalog(string $file): array
{
    $handle = \fopen($file, 'rb');
    if ($handle === false) {
        throw new \RuntimeException('Unable to open translation file: ' . $file);
    }
    $translations = [];
    try {
        while (($row = \fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (\count($row) < 2) {
                continue;
            }
            $translations[(string) $row[0]] = (string) $row[1];
        }
    } finally {
        \fclose($handle);
    }
    return $translations;
}

/** @return list<string> */
private function placeholders_StoreChannelCatalog(string $value): array
{
    \preg_match_all('/%\{[^}]+}/u', $value, $matches);
    $placeholders = $matches[0];
    \sort($placeholders);
    return $placeholders;
}

private function storeCatalog_StoreChannelCatalog(): StoreCatalog
{
    return new StoreCatalog(ObjectManager::getInstance(Store::class, [], false), ObjectManager::getInstance(Website::class, [], false));
}

private function salesChannelCatalog_StoreChannelCatalog(StoreSummary $parent): SalesChannelCatalog
{
    return new SalesChannelCatalog(ObjectManager::getInstance(SalesChannel::class, [], false), new FixedStoreCatalog($parent));
}

/** @param array<string, mixed> $replace */
private function validStoreRow_StoreChannelCatalog(array $replace = []): array
{
    return \array_replace([Store::schema_fields_ID => 1, Store::schema_fields_WEBSITE_ID => 0, Store::schema_fields_CODE => 'default', Store::schema_fields_NAME => '默认店铺', Store::schema_fields_STORE_MODE => Store::MODE_NORMAL, Store::schema_fields_IS_DEFAULT => 1, Store::schema_fields_STATUS => 1, Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_ACTIVE, Store::schema_fields_TOMBSTONED_AT => null, Store::schema_fields_URL => null], $replace);
}

/** @param array<string, mixed> $replace */
private function validChannelRow_StoreChannelCatalog(array $replace = []): array
{
    return \array_replace([SalesChannel::schema_fields_ID => 21, SalesChannel::schema_fields_WEBSITE_ID => 0, SalesChannel::schema_fields_STORE_ID => 11, SalesChannel::schema_fields_CODE => 'web', SalesChannel::schema_fields_NAME => '网站渠道', SalesChannel::schema_fields_IS_DEFAULT => 0, SalesChannel::schema_fields_STATUS => 1], $replace);
}

/** @param non-empty-list<string> $expectedFragments */
private function assertMessageContainsAny_StoreChannelCatalog(string $actual, array $expectedFragments): void
{
    foreach ($expectedFragments as $expectedFragment) {
        if (\str_contains($actual, $expectedFragment)) {
            self::assertTrue(true);
            return;
        }
    }
    self::fail('Failed asserting that the localized message contains one of: ' . \implode(' | ', $expectedFragments) . '. Actual: ' . $actual);
}

/** @param list<mixed> $arguments */
private function invokePrivate_StoreChannelCatalog(object $target, string $method, array $arguments): mixed
{
    $reflection = new \ReflectionMethod($target, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($target, $arguments);
}

private function assert_StoreSelectOptions_testFromRowsUsesStoreIdAsValue(): void
{
    $options = StoreSelectOptions::fromRows([['store_id' => 0, 'name' => '默认店铺', 'code' => 'default', 'is_default' => true], ['store_id' => 3, 'name' => '旗舰店', 'code' => 'flagship', 'is_default' => false], ['store_id' => 3, 'name' => '重复应跳过', 'code' => 'x']]);
    self::assertCount(2, $options);
    self::assertSame('0', $options[0]['value']);
    self::assertStringContainsString('#0', $options[0]['label']);
    self::assertSame('3', $options[1]['value']);
    self::assertSame('#3 旗舰店', StoreSelectOptions::resolveDisplay($options, '3'));
}

private function assert_StorefrontScopeCatalogCache_testCatalogPoliciesUseTheCentralScopeCacheBoundary(): void
{
    $storePolicy = StorefrontScopeCatalogCacheCoordinator::storePolicy();
    $channelPolicy = StorefrontScopeCatalogCacheCoordinator::channelPolicy();
    self::assertInstanceOf(CachePolicy::class, $storePolicy);
    self::assertSame('website', $storePolicy->scope);
    self::assertSame('website', $storePolicy->pool);
    self::assertSame(['catalog'], $storePolicy->dependencies);
    self::assertInstanceOf(CachePolicy::class, $channelPolicy);
    self::assertSame('store', $channelPolicy->scope);
    self::assertSame('website', $channelPolicy->pool);
    self::assertSame(['catalog'], $channelPolicy->dependencies);
}

private function assert_StorefrontScopeCatalogCache_testCatalogReadsDelegateToPolicyCacheWithoutRequestMemo(): void
{
    $storeSource = (string) file_get_contents((string) (new \ReflectionClass(StoreCatalog::class))->getFileName());
    $channelSource = (string) file_get_contents((string) (new \ReflectionClass(SalesChannelCatalog::class))->getFileName());
    self::assertStringNotContainsString('rememberForRequest', $storeSource);
    self::assertStringContainsString('rememberPolicy', $storeSource);
    self::assertStringContainsString('StorefrontScopeCatalogCacheCoordinator::storePolicy()', $storeSource);
    self::assertStringNotContainsString('rememberForRequest', $channelSource);
    self::assertStringContainsString('rememberPolicy', $channelSource);
    self::assertStringContainsString('StorefrontScopeCatalogCacheCoordinator::channelPolicy()', $channelSource);
}

private function assert_WebsiteCatalogProcessCache_testCatalogOwnsProcessBagAndClear(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/WebsiteCatalog.php');
    self::assertStringContainsString('$processBag', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('PROCESS_ALL_KEY', $src);
    self::assertStringContainsString('PROCESS_DEFAULT_ID_KEY', $src);
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
    self::assertStringContainsString('ScopeIdentity::global()', $src);
    self::assertStringContainsString('websites.catalog.all', $src);
}

private function assert_WebsiteCatalogProcessCache_testMaintenanceGateOwnsProcessBag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/ScopeMaintenanceGate.php');
    self::assertStringContainsString('$processStatusByScopeKey', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
    self::assertStringContainsString('forgetScoped', $src);
}

private function assert_WebsiteCatalogProcessCache_testProcessCacheResetterClearsCatalogAndGate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('WebsiteCatalog::clearProcessCache()', $src);
    self::assertStringContainsString('ScopeMaintenanceGate::clearProcessCache()', $src);
}

private ?string $previousHttpHost_WebsiteEntryUrlServicePort = null;

private function tearDown_WebsiteEntryUrlServicePort(): void
{
    if ($this->previousHttpHost_WebsiteEntryUrlServicePort === null) {
        unset($_SERVER['HTTP_HOST']);
    } else {
        $_SERVER['HTTP_HOST'] = $this->previousHttpHost_WebsiteEntryUrlServicePort;
    }
    parent::tearDown();
}

private function assert_WebsiteEntryUrlServicePort_testWithCurrentRequestPortAppendsLivePortWhenStoredUrlHasNone(): void
{
    $this->previousHttpHost_WebsiteEntryUrlServicePort = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = 'p05113ef3.test.weline.com:9555';
    $service = new WebsiteEntryUrlService(new \Weline\Websites\Model\WebsiteDomain());
    self::assertSame('http://p05113ef3.test.weline.com:9555', $service->withCurrentRequestPort('http://p05113ef3.test.weline.com'));
}

private function assert_WebsiteEntryUrlServicePort_testWithCurrentRequestPortLeavesExplicitPortUntouched(): void
{
    $this->previousHttpHost_WebsiteEntryUrlServicePort = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = 'p05113ef3.test.weline.com:9555';
    $service = new WebsiteEntryUrlService(new \Weline\Websites\Model\WebsiteDomain());
    self::assertSame('http://p05113ef3.test.weline.com:8080', $service->withCurrentRequestPort('http://p05113ef3.test.weline.com:8080'));
}

private function assert_WebsiteEntryUrlServiceStorefrontChrome_testResolveStorefrontForBackendChromePrefersLiveRequestBaseHost(): void
{
    $service = new WebsiteEntryUrlService(new \Weline\Websites\Model\WebsiteDomain());
    $request = $this->createMock(Request::class);
    $request->method('getBaseHost')->willReturn('https://p05113ef3.test.weline.com:9555');
    $url = $service->resolveStorefrontForBackendChrome($request, [Website::schema_fields_URL => 'http://localhost']);
    self::assertSame('https://p05113ef3.test.weline.com:9555', $url);
}

private function assert_WebsiteFpcBakeOrchestrator_testOrchestratorSoftPullsSeoFacadeOnly(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/WebsiteFpcBakeOrchestrator.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('SitemapBakeSource', $src);
    self::assertStringContainsString('class_exists', $src);
    self::assertStringNotContainsString('use Weline\Seo\Service\\', $src);
    self::assertStringNotContainsString('use Weline\Seo\Model\\', $src);
    self::assertStringContainsString('SitemapFpcBakeEngine', $src);
    self::assertStringContainsString('FpcOfflineBakeExporter', $src);
    self::assertStringContainsString('StorefrontWebsiteCodeResolverInterface', $src);
}

private function assert_WebsiteFpcBakeOrchestrator_testCliCommandExistsWithAlias(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Console/Website/Fpc/Bake.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('website:fpc:bake', $src);
    self::assertStringContainsString('website:sitemap-fpc-bake', $src);
    self::assertStringContainsString('WebsiteFpcBakeOrchestrator', $src);
}

private function assert_WebsiteFpcBakeOrchestrator_testSeoIsOptionalNotRequired(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/etc/module.php';
    self::assertIsArray($module);
    $requires = $module['requires'] ?? [];
    $optional = $module['optional'] ?? [];
    self::assertArrayNotHasKey('Weline_Seo', $requires);
    self::assertArrayHasKey('Weline_Seo', $optional);
}

private function assert_WebsiteResourceChange_testWeb02DefaultWebsiteImpactKeepsCurrentAndPreviousNamespacesAndUrls(): void
{
    $factory = $this->factory_WebsiteResourceChange();
    $before = $this->snapshot_WebsiteResourceChange('legacy-default', 'https://old.example.test/', [['domain' => 'old.example.test', 'sub_path' => '/shop']]);
    $after = $this->snapshot_WebsiteResourceChange('default', 'https://new.example.test/', [['domain' => 'new.example.test', 'sub_path' => 'store']]);
    $impact = $factory->impact($before, $after);
    self::assertSame(0, $before[Website::schema_fields_ID]);
    self::assertSame(0, $after[Website::schema_fields_ID]);
    self::assertContains('website/default', $impact['namespaces']);
    self::assertContains('website/default/domain', $impact['namespaces']);
    self::assertContains('global/websites-registry', $impact['namespaces']);
    self::assertContains('website/legacy-default', $impact['previous_namespaces']);
    self::assertContains('website/legacy-default/config/start-page', $impact['previous_namespaces']);
    self::assertContains('https://new.example.test/', $impact['urls']);
    self::assertContains('https://new.example.test/store', $impact['urls']);
    self::assertContains('https://old.example.test/', $impact['previous_urls']);
    self::assertContains('https://old.example.test/shop', $impact['previous_urls']);
}

private function assert_WebsiteResourceChange_testWeb03DeleteImpactPreservesCompletePreviousSurface(): void
{
    $factory = $this->factory_WebsiteResourceChange();
    $before = $this->snapshot_WebsiteResourceChange('shop', 'https://shop.example.test/', [['domain' => 'shop.example.test', 'sub_path' => 'catalog']]);
    $impact = $factory->impact($before, null);
    self::assertSame([], $impact['namespaces']);
    self::assertSame([], $impact['urls']);
    self::assertContains('website/shop', $impact['previous_namespaces']);
    self::assertContains('website/shop/language', $impact['previous_namespaces']);
    self::assertContains('https://shop.example.test/', $impact['previous_urls']);
    self::assertContains('https://shop.example.test/catalog', $impact['previous_urls']);
}

private function assert_WebsiteResourceChange_testWeb04ChangedFieldsAreDeterministicForCreateUpdateAndDelete(): void
{
    $factory = $this->factory_WebsiteResourceChange();
    self::assertSame(['code', 'name'], $factory->changedFields(['name' => 'Before', 'code' => 'default'], ['name' => 'After', 'code' => 'shop']));
    self::assertSame(['code', 'name'], $factory->changedFields(null, ['name' => 'After', 'code' => 'shop']));
    self::assertSame(['code', 'name'], $factory->changedFields(['name' => 'Before', 'code' => 'default'], null));
    self::assertSame([], $factory->changedFields(['name' => 'Same', 'code' => 'default'], ['code' => 'default', 'name' => 'Same']));
}

private function factory_WebsiteResourceChange(): WebsiteChangeSnapshotFactory
{
    $config = (new \ReflectionClass(ConfigStore::class))->newInstanceWithoutConstructor();
    self::assertInstanceOf(ConfigStore::class, $config);
    return new WebsiteChangeSnapshotFactory($this->createStub(Website::class), $this->createStub(WebsiteDomain::class), $this->createStub(WebsiteCurrency::class), $this->createStub(WebsiteLanguage::class), $config, new NamespacePath());
}

/** @param list<array{domain:string,sub_path:string}> $domains @return array<string,mixed> */
private function snapshot_WebsiteResourceChange(string $code, string $url, array $domains): array
{
    return [Website::schema_fields_ID => 0, Website::schema_fields_NAME => 'Default website', Website::schema_fields_CODE => $code, Website::schema_fields_URL => $url, 'domains' => $domains, 'currency' => ['CNY'], 'language' => ['zh_Hans_CN'], 'start_page_config' => ['frontend' => 'index', 'backend' => 'dashboard']];
}

private function assert_WebsiteSaveChangedCacheCdn_testWebsiteSavePublishesResourceChangeAndFlushDeferred(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php');
    self::assertStringContainsString('w_changed($change)', $controller);
    self::assertStringContainsString('flushDeferred(', $controller);
    self::assertStringContainsString('publishWebsiteChange', $controller);
    self::assertStringContainsString("resourceType: 'website'", $controller);
}

private function assert_WebsiteSaveChangedCacheCdn_testWebsiteChangedTypeRegisteredWithCdnPurgeRecipe(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/extends/module/Weline_Framework/Changed/Type/WebsiteChangedType.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("return 'website'", $src);
    self::assertStringContainsString('CODE_BUMP_NAMESPACES', $src);
    self::assertStringContainsString('CODE_CDN_PURGE', $src);
    self::assertStringContainsString('allow_empty', $src);
    self::assertStringContainsString('CODE_PURGE_FPC_URLS', $src);
}

private function assert_WebsiteSaveChangedCacheCdn_testCdnCapabilityRoutesWebsiteThroughPurgeWebsiteScope(): void
{
    $cdnCapability = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Cdn/extends/module/Weline_Framework/Changed/Capability/CdnCapability.php');
    self::assertStringContainsString("resourceType() === 'website'", $cdnCapability);
    self::assertStringContainsString('purgeWebsiteScope', $cdnCapability);
    $purger = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Cdn/Service/CachePurger.php');
    self::assertStringContainsString('function purgeWebsiteScope', $purger);
    self::assertStringContainsString("'triggered' => true", $purger);
}

private function assert_WebsiteSaveChangedCacheCdn_testWebsiteEditFormWarnsCacheAndCdnOnSave(): void
{
    $form = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml');
    self::assertStringContainsString('data-testid="website-save-cache-cdn-warning"', $form);
    self::assertStringContainsString('data-testid="website-save-cache-cdn-warning-near-save"', $form);
    self::assertStringContainsString('保存网站会清理本站本地缓存与 CDN', $form);
    self::assertStringContainsString('若未调整关键信息，不建议保存', $form);
    self::assertStringContainsString('保存会清理本站缓存与 CDN', $form);
    self::assertStringContainsString('data-website-save="1"', $form);
}

private function assert_WebsiteSaveChangedCacheCdn_testWebsiteImpactCarriesNamespacesAndUrlsForCdn(): void
{
    $factory = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/WebsiteChangeSnapshotFactory.php');
    self::assertStringContainsString("'namespaces'", $factory);
    self::assertStringContainsString("'urls'", $factory);
    self::assertStringContainsString('websites-registry', $factory);
}

private function assert_WebsiteScopeTreeService_testParseAndFormatNode(): void
{
    self::assertSame(['kind' => 'website', 'id' => 0], WebsiteScopeTreeService::parseNode('website:0'));
    self::assertSame(['kind' => 'store', 'id' => 12], WebsiteScopeTreeService::parseNode('store:12'));
    self::assertSame(['kind' => 'channel', 'id' => 3], WebsiteScopeTreeService::parseNode('channel:3'));
    self::assertNull(WebsiteScopeTreeService::parseNode('store:_'));
    self::assertNull(WebsiteScopeTreeService::parseNode('global'));
    self::assertSame('website:0', WebsiteScopeTreeService::formatNode('website', 0));
}

private function assert_WebsiteScopeTreeService_testServiceSourceForbidsGlobalKind(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/WebsiteScopeTreeService.php');
    self::assertStringContainsString("preg_match('/^(website|store|channel):", $source);
    self::assertStringNotContainsString("'global'", $source);
    self::assertStringContainsString('buildTree', $source);
    self::assertStringContainsString('resolveSelection', $source);
    self::assertStringContainsString('WebsiteScopeTreeBrandLogoResolver', $source);
    self::assertStringContainsString("'logo'", $source);
    $resolver = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Service/WebsiteScopeTreeBrandLogoResolver.php');
    self::assertStringContainsString('ThemeBrandResolver', $resolver);
    self::assertStringContainsString('class_exists', $resolver);
}
}

final class ScopePathMatchCachePoolStub implements CachePoolInterface
{
    /** @var array<string, mixed> */
    private array $store = [];
    public int $gets = 0;
    public int $sets = 0;
    public function get(string $key): mixed
    {
        $this->gets++;
        return $this->store[$key] ?? false;
    }
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->sets++;
        $this->store[$key] = $value;
        return true;
    }
    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }
    public function clear(): bool
    {
        $this->store = [];
        return true;
    }
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }
    public function getIdentity(): string
    {
        return 'scope_path_match_stub';
    }
    public function getTip(): string
    {
        return 'stub';
    }
    public function isPermanent(): bool
    {
        return false;
    }
    public function getMultiple(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get((string) $key);
        }
        return $out;
    }
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }
    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }
        return true;
    }
    public function deleteCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool
    {
        return $this->delete($key);
    }
    public function hasCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool
    {
        return $this->has($key);
    }
    public function getCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): mixed
    {
        return $this->get($key);
    }
    public function setCustom(string $key, mixed $value, int $ttl = 0, bool $website = false, bool $lang = false, bool $currency = false): bool
    {
        return $this->set($key, $value, $ttl);
    }
    public function getStats(): array
    {
        return ['identity' => $this->getIdentity(), 'hits' => 0, 'misses' => 0, 'hit_ratio' => 0.0, 'permanent' => false];
    }
}

final class FixedStoreCatalog implements StoreCatalogInterface
{
    public function __construct(private readonly StoreSummary $store)
    {
    }
    public function byWebsite(int $websiteId): array
    {
        return $websiteId === $this->store->websiteId ? [$this->store] : [];
    }
    public function byCode(int $websiteId, string $storeCode): ?StoreSummary
    {
        return $websiteId === $this->store->websiteId && $storeCode === $this->store->code ? $this->store : null;
    }
    public function byId(int $storeId): ?StoreSummary
    {
        return $storeId === $this->store->id ? $this->store : null;
    }
    public function defaultStore(int $websiteId): ?StoreSummary
    {
        return $websiteId === $this->store->websiteId && $this->store->isDefault ? $this->store : null;
    }
    public function all(): array
    {
        return [$this->store];
    }
}

final class FixedSalesChannelCatalog implements SalesChannelCatalogInterface
{
    public function __construct(private readonly SalesChannelSummary $channel)
    {
    }
    public function byStore(int $storeId): array
    {
        return $storeId === $this->channel->storeId ? [$this->channel] : [];
    }
    public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary
    {
        return $storeId === $this->channel->storeId && $channelCode === $this->channel->code ? $this->channel : null;
    }
    public function byId(int $channelId): ?SalesChannelSummary
    {
        return $channelId === $this->channel->id ? $this->channel : null;
    }
    public function defaultChannel(int $storeId): ?SalesChannelSummary
    {
        return $storeId === $this->channel->storeId && $this->channel->isDefault ? $this->channel : null;
    }
    public function defaultChannelForStore(StoreSummary $store): ?SalesChannelSummary
    {
        return $store->id === $this->channel->storeId && $this->channel->isDefault ? $this->channel : null;
    }
}
