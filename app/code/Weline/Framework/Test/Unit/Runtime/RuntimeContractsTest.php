<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use ReflectionProperty as ReflectionProperty;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SoftSurvivorCatalog;
use Weline\Framework\Runtime\StorefrontHotCacheBagWarmupProviderInterface;
use Weline\Framework\Runtime\StorefrontProcessL1HydrateCoordinator;
use Weline\Framework\Runtime\StorefrontRenderContext;
use Weline\Framework\Runtime\StorefrontWidgetRuntimeSchedule;
use Weline\Framework\Runtime\TemplateRenderRouteTrace;
use Weline\Server\Runtime\WlsRuntime;
use Weline\Server\Service\Memory\WorkerHostPressureApplier;
use Weline\Server\Service\WorkerResponseMemoryGuard;

/**
 * Aggregated contract suite (merged from 13 *ContractTest files).
 * Observe one overall pass/fail via testAllRuntimeContractsPass().
 */
final class RuntimeContractsTest extends TestCase
{
    public function testAllRuntimeContractsPass(): void
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
            'ProcessCacheResetContextSurface::testTwoArgCacheClearDerivesAllHard' => function () {
            $this->assert_ProcessCacheResetContextSurface_testTwoArgCacheClearDerivesAllHard();
        },
            'ProcessCacheResetContextSurface::testTwoArgSoftPressureDerivesContentSoft' => function () {
            $this->assert_ProcessCacheResetContextSurface_testTwoArgSoftPressureDerivesContentSoft();
        },
            'ProcessCacheResetContextSurface::testTwoArgAggressiveDerivesAllHard' => function () {
            $this->assert_ProcessCacheResetContextSurface_testTwoArgAggressiveDerivesAllHard();
        },
            'RequestPathSuperglobalRead::testHotPathFilesDoNotReadProcessSuperglobals' => function () {
            $this->assert_RequestPathSuperglobalRead_testHotPathFilesDoNotReadProcessSuperglobals();
        },
            'RequestPathSuperglobalRead::testUrlParserReadsOriginFromContextNotServerGlobal' => function () {
            $this->assert_RequestPathSuperglobalRead_testUrlParserReadsOriginFromContextNotServerGlobal();
        },
            'SoftSurvivorMatrix::testRegistrySymbolsAreSoftSurvivors' => function () {
            $this->assert_SoftSurvivorMatrix_testRegistrySymbolsAreSoftSurvivors();
        },
            'SoftSurvivorMatrix::testGuardOrchestrationHasNoDomainBagFqcn' => function () {
            $this->assert_SoftSurvivorMatrix_testGuardOrchestrationHasNoDomainBagFqcn();
        },
            'SoftSurvivorMatrix::testApplierHasNoPrivateRegistry' => function () {
            $this->assert_SoftSurvivorMatrix_testApplierHasNoPrivateRegistry();
        },
            'StorefrontProcessL1HydrateCoordinator::testInterfaceDeclaresHydrateModeAndMethod' => function () {
            try {
                $this->assert_StorefrontProcessL1HydrateCoordinator_testInterfaceDeclaresHydrateModeAndMethod();
            } finally {
                $this->tearDown_StorefrontProcessL1HydrateCoordinator();
            }
        },
            'StorefrontProcessL1HydrateCoordinator::testCoordinatorRequestAndDeferRetry' => function () {
            try {
                $this->assert_StorefrontProcessL1HydrateCoordinator_testCoordinatorRequestAndDeferRetry();
            } finally {
                $this->tearDown_StorefrontProcessL1HydrateCoordinator();
            }
        },
            'StorefrontProcessL1HydrateCoordinator::testCoordinatorSourceForbidsSsrAndDocumentsLayerB' => function () {
            try {
                $this->assert_StorefrontProcessL1HydrateCoordinator_testCoordinatorSourceForbidsSsrAndDocumentsLayerB();
            } finally {
                $this->tearDown_StorefrontProcessL1HydrateCoordinator();
            }
        },
            'StorefrontProcessL1HydrateSmell::testHotCacheSoftTrimPrefersNonChromeBeforeChrome' => function () {
            $this->assert_StorefrontProcessL1HydrateSmell_testHotCacheSoftTrimPrefersNonChromeBeforeChrome();
        },
            'StorefrontProcessL1HydrateSmell::testUnifiedFpcHitSetsHonestSourceHeader' => function () {
            $this->assert_StorefrontProcessL1HydrateSmell_testUnifiedFpcHitSetsHonestSourceHeader();
        },
            'StorefrontProcessL1HydrateSmell::testPreRouterHonorsProcessL1BeforeHtmlDiskWhenLegacySharedGated' => function () {
            $this->assert_StorefrontProcessL1HydrateSmell_testPreRouterHonorsProcessL1BeforeHtmlDiskWhenLegacySharedGated();
        },
            'StorefrontRenderContextInstallerDecouple::testInstallerDoesNotSoftPullForeignModules' => function () {
            $this->assert_StorefrontRenderContextInstallerDecouple_testInstallerDoesNotSoftPullForeignModules();
        },
            'StorefrontRenderContextInstallerDecouple::testTimezoneDoesNotSoftPullWebsiteData' => function () {
            $this->assert_StorefrontRenderContextInstallerDecouple_testTimezoneDoesNotSoftPullWebsiteData();
        },
            'StorefrontWidgetRuntimeSchedule::testCollectInlineSpecsFromCompiledPhpExport' => function () {
            $this->assert_StorefrontWidgetRuntimeSchedule_testCollectInlineSpecsFromCompiledPhpExport();
        },
            'StorefrontWidgetRuntimeSchedule::testEmptyPhpYieldsNoSpecs' => function () {
            $this->assert_StorefrontWidgetRuntimeSchedule_testEmptyPhpYieldsNoSpecs();
        },
            'StorefrontWidgetRuntimeSchedule::testScheduleIsNotSecondRenderContextAuthorityBag' => function () {
            $this->assert_StorefrontWidgetRuntimeSchedule_testScheduleIsNotSecondRenderContextAuthorityBag();
        },
            'StorefrontWidgetRuntimeSchedule::testHotCachePathPrefetchRunsBeforeGetFetchFileScan' => function () {
            $this->assert_StorefrontWidgetRuntimeSchedule_testHotCachePathPrefetchRunsBeforeGetFetchFileScan();
        },
            'StorefrontWidgetRuntimeSchedule::testThemeHookPrimesBeforeLayoutFetch' => function () {
            $this->assert_StorefrontWidgetRuntimeSchedule_testThemeHookPrimesBeforeLayoutFetch();
        },
            'StorefrontWidgetRuntimeSchedule::testAssetPrimerUsesPrefetchSourcesNotFakeGetMultiple' => function () {
            $this->assert_StorefrontWidgetRuntimeSchedule_testAssetPrimerUsesPrefetchSourcesNotFakeGetMultiple();
        },
            'TemplateRenderRouteTrace::testSourceDeclaresStormGateAndPanelIds' => function () {
            try {
                $this->assert_TemplateRenderRouteTrace_testSourceDeclaresStormGateAndPanelIds();
            } finally {
                $this->tearDown_TemplateRenderRouteTrace();
            }
        },
            'TemplateRenderRouteTrace::testFpcBypassAndCacheKeyRetainRouteQuery' => function () {
            try {
                $this->assert_TemplateRenderRouteTrace_testFpcBypassAndCacheKeyRetainRouteQuery();
            } finally {
                $this->tearDown_TemplateRenderRouteTrace();
            }
        },
            'TemplateRenderRouteTrace::testMarkOrdersAndFlagsDuplicateIdentity' => function () {
            try {
                $this->assert_TemplateRenderRouteTrace_testMarkOrdersAndFlagsDuplicateIdentity();
            } finally {
                $this->tearDown_TemplateRenderRouteTrace();
            }
        },
            'WlsRuntimeAdoptHomepageFpcMeta::testAdoptCallsWorkerReadinessMarkBusinessHomepageHot' => function () {
            $this->assert_WlsRuntimeAdoptHomepageFpcMeta_testAdoptCallsWorkerReadinessMarkBusinessHomepageHot();
        },
            'WlsRuntimeAdoptHomepageFpcMeta::testDeferredWarmupAdoptsHomepageImmediatelyAndPrefersCatalogSlot' => function () {
            $this->assert_WlsRuntimeAdoptHomepageFpcMeta_testDeferredWarmupAdoptsHomepageImmediatelyAndPrefersCatalogSlot();
        },
            'WlsRuntimeAdoptHomepageFpcMeta::testDeferredWarmupSkipsLocalesAfterConsecutiveProbeFailures' => function () {
            $this->assert_WlsRuntimeAdoptHomepageFpcMeta_testDeferredWarmupSkipsLocalesAfterConsecutiveProbeFailures();
        },
            'WlsRuntimeAdoptHomepageFpcMeta::testDeferredWarmupForcesProductsAndSealsCriticalBeforeLocales' => function () {
            $this->assert_WlsRuntimeAdoptHomepageFpcMeta_testDeferredWarmupForcesProductsAndSealsCriticalBeforeLocales();
        },
            'WlsRuntimeDeferredHotCacheBagPrime::testDeferredCriticalSealPrimesHotCacheBagsBeforeLocales' => function () {
            $this->assert_WlsRuntimeDeferredHotCacheBagPrime_testDeferredCriticalSealPrimesHotCacheBagsBeforeLocales();
        },
            'WlsRuntimeDeferredHotCacheBagPrime::testBagWarmupInterfaceDeclaresCapabilityPrefix' => function () {
            $this->assert_WlsRuntimeDeferredHotCacheBagPrime_testBagWarmupInterfaceDeclaresCapabilityPrefix();
        },
            'WlsRuntimeDeferredHotCacheBagPrime::testBagPrimeFallsBackWhenCompiledRegistryLags' => function () {
            $this->assert_WlsRuntimeDeferredHotCacheBagPrime_testBagPrimeFallsBackWhenCompiledRegistryLags();
        },
            'WlsRuntimeDeferredWarmupDefaultLocalePath::testDeferredWarmupFiltersDefaultLocaleViaCanonicalize' => function () {
            $this->assert_WlsRuntimeDeferredWarmupDefaultLocalePath_testDeferredWarmupFiltersDefaultLocaleViaCanonicalize();
        },
            'WlsRuntimeDeferredWarmupDefaultLocalePath::testStorefrontWarmupPinsWebsiteLanguageIntoSyntheticRequest' => function () {
            $this->assert_WlsRuntimeDeferredWarmupDefaultLocalePath_testStorefrontWarmupPinsWebsiteLanguageIntoSyntheticRequest();
        },
            'WlsRuntimeFailOpenDeferredWarmup::testFailOpenForcesDeferredWarmupEvenWhenFlagDefaultOff' => function () {
            $this->setUp_WlsRuntimeFailOpenDeferredWarmup();
            try {
                $this->assert_WlsRuntimeFailOpenDeferredWarmup_testFailOpenForcesDeferredWarmupEvenWhenFlagDefaultOff();
            } finally {
                $this->tearDown_WlsRuntimeFailOpenDeferredWarmup();
            }
        },
            'WlsRuntimeFailOpenDeferredWarmup::testStrictHitWithDeferredFlagOffDoesNotForceWarmup' => function () {
            $this->setUp_WlsRuntimeFailOpenDeferredWarmup();
            try {
                $this->assert_WlsRuntimeFailOpenDeferredWarmup_testStrictHitWithDeferredFlagOffDoesNotForceWarmup();
            } finally {
                $this->tearDown_WlsRuntimeFailOpenDeferredWarmup();
            }
        },
            'WlsRuntimeFailOpenDeferredWarmup::testMissedProofForcesDeferredEvenWhenFailOpenDisabled' => function () {
            $this->setUp_WlsRuntimeFailOpenDeferredWarmup();
            try {
                $this->assert_WlsRuntimeFailOpenDeferredWarmup_testMissedProofForcesDeferredEvenWhenFailOpenDisabled();
            } finally {
                $this->tearDown_WlsRuntimeFailOpenDeferredWarmup();
            }
        },
            'WlsRuntimePublicOriginWarmupHost::testSelectStorefrontWarmupHostPrefersPublicEdgePortOverWorkerPort' => function () {
            $this->setUp_WlsRuntimePublicOriginWarmupHost();
            try {
                $this->assert_WlsRuntimePublicOriginWarmupHost_testSelectStorefrontWarmupHostPrefersPublicEdgePortOverWorkerPort();
            } finally {
                $this->tearDown_WlsRuntimePublicOriginWarmupHost();
            }
        },
            'WlsRuntimePublicOriginWarmupHost::testResolveWorkerPublicOriginFallsBackToInstancePublicOrigin' => function () {
            $this->setUp_WlsRuntimePublicOriginWarmupHost();
            try {
                $this->assert_WlsRuntimePublicOriginWarmupHost_testResolveWorkerPublicOriginFallsBackToInstancePublicOrigin();
            } finally {
                $this->tearDown_WlsRuntimePublicOriginWarmupHost();
            }
        },
            'WlsRuntimePublicOriginWarmupHost::testProcessLocalDynamicWarmupHostsPreferPublicOriginAuthority' => function () {
            $this->setUp_WlsRuntimePublicOriginWarmupHost();
            try {
                $this->assert_WlsRuntimePublicOriginWarmupHost_testProcessLocalDynamicWarmupHostsPreferPublicOriginAuthority();
            } finally {
                $this->tearDown_WlsRuntimePublicOriginWarmupHost();
            }
        },
            'WlsRuntimePublicOriginWarmupHost::testAdoptProofLogsIncompleteOrAdoptedStages' => function () {
            $this->setUp_WlsRuntimePublicOriginWarmupHost();
            try {
                $this->assert_WlsRuntimePublicOriginWarmupHost_testAdoptProofLogsIncompleteOrAdoptedStages();
            } finally {
                $this->tearDown_WlsRuntimePublicOriginWarmupHost();
            }
        },
        ];
    }

private function assert_ProcessCacheResetContextSurface_testTwoArgCacheClearDerivesAllHard(): void
{
    $ctx = new ProcessCacheResetContext(ProcessCacheResetContext::REASON_CACHE_CLEAR, true);
    self::assertTrue($ctx->isExplicitCacheClear());
    self::assertTrue($ctx->allowsRegistryClear());
    self::assertFalse($ctx->isSoftContent());
    self::assertSame(ProcessCacheResetContext::SURFACE_ALL, $ctx->surface);
    self::assertSame(ProcessCacheResetContext::TIER_HARD, $ctx->tier);
}

private function assert_ProcessCacheResetContextSurface_testTwoArgSoftPressureDerivesContentSoft(): void
{
    $ctx = new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false);
    self::assertTrue($ctx->isSoftContent());
    self::assertFalse($ctx->allowsRegistryClear());
}

private function assert_ProcessCacheResetContextSurface_testTwoArgAggressiveDerivesAllHard(): void
{
    $ctx = new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, true);
    self::assertTrue($ctx->allowsRegistryClear());
    self::assertFalse($ctx->isSoftContent());
}

private function assert_RequestPathSuperglobalRead_testHotPathFilesDoNotReadProcessSuperglobals(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $files = ['/View/Template.php', '/App/State.php', '/App.php', '/Http/ErrorPageRenderer.php', '/Http/StorefrontNotFoundStaticPage.php', '/Http/Security/SecurityHeaderPolicyService.php', '/Http/HeaderCollector.php', '/Cache/KeyBuilder.php', '/Controller/Api/CspPolicy.php', '/Service/Query/QueryProviderRegistry.php'];
    foreach ($files as $relative) {
        $source = (string) \file_get_contents($root . $relative);
        $code = \preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source;
        $code = \preg_replace('/^\s*(\/\/|#).*$/m', '', $code) ?? $code;
        self::assertDoesNotMatchRegularExpression('/\$_(GET|POST|COOKIE|REQUEST|FILES)\b/', $code, $relative);
        self::assertDoesNotMatchRegularExpression('/\$_SERVER\s*\[/', $code, $relative);
    }
}

private function assert_RequestPathSuperglobalRead_testUrlParserReadsOriginFromContextNotServerGlobal(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Url.php');
    self::assertStringNotContainsString("?? \$_SERVER['WELINE_ORIGIN_REQUEST_URI']", $source);
    self::assertStringNotContainsString("?? \$_SERVER['WELINE_URL_PATH_LANG']", $source);
    self::assertStringNotContainsString("\$_SERVER['WELINE_WEBSITE_URL']", $source);
    self::assertStringContainsString("WelineEnv::server('WELINE_ORIGIN_REQUEST_URI'", $source);
}

private function assert_SoftSurvivorMatrix_testRegistrySymbolsAreSoftSurvivors(): void
{
    self::assertFalse(SoftSurvivorCatalog::isRegistrySoftSurvivor('Weline\Framework\Router\FullPageCacheCoordinator::$processFpcPayloadCache'));
    self::assertSame(SoftSurvivorCatalog::ROLE_CONTENT, SoftSurvivorCatalog::roleFor('Weline\Framework\Router\FullPageCacheCoordinator::$processFpcPayloadCache'));
    self::assertTrue(SoftSurvivorCatalog::isRegistrySoftSurvivor('Weline\Framework\Service\Query\QueryProviderRegistry::$processCompiledRegistry'));
    self::assertTrue(SoftSurvivorCatalog::isRegistrySoftSurvivor('Weline\Framework\Compilation\CompiledPhpRegistry::$entries'));
    self::assertTrue(SoftSurvivorCatalog::isRegistrySoftSurvivor('Weline\Widget\Service\WidgetRegistry::$staticCachedRegistry'));
    self::assertSame(SoftSurvivorCatalog::ROLE_CONTENT, SoftSurvivorCatalog::roleFor('Weline\Framework\Phrase\DictionaryCacheNamespace::$bagRefs'));
}

private function assert_SoftSurvivorMatrix_testGuardOrchestrationHasNoDomainBagFqcn(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(WorkerResponseMemoryGuard::class))->getFileName());
    self::assertStringContainsString('ProcessMemoryReclaimFacade::registry()', $src);
    self::assertStringNotContainsString('NodePool::trimForMemoryPressure()', $src);
    self::assertStringNotContainsString('NodePool::reset()', $src);
    self::assertStringNotContainsString('DictionaryCacheNamespace::processMemoryReclaimable()', $src);
    self::assertStringNotContainsString('Parser::clearWorkerCaches()', $src);
    self::assertStringNotContainsString('TemplateCacheManager::clearProcessMemoryCache()', $src);
    self::assertStringNotContainsString('FullPageCacheCoordinator::clearProcessCache()', $src);
    self::assertStringNotContainsString('MemoryCacheService::purgeAll()', $src);
}

private function assert_SoftSurvivorMatrix_testApplierHasNoPrivateRegistry(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(WorkerHostPressureApplier::class))->getFileName());
    self::assertStringContainsString('compactForHostLevel', $src);
    self::assertStringNotContainsString('new MemoryReclaimableRegistry', $src);
    self::assertStringNotContainsString('processMemoryReclaimable()', $src);
}

private function tearDown_StorefrontProcessL1HydrateCoordinator(): void
{
    StorefrontProcessL1HydrateCoordinator::resetForTests();
    parent::tearDown();
}

private function assert_StorefrontProcessL1HydrateCoordinator_testInterfaceDeclaresHydrateModeAndMethod(): void
{
    $path = BP . 'app/code/Weline/Framework/Runtime/StorefrontHotCacheBagWarmupProviderInterface.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('MODE_HYDRATE', $source);
    self::assertStringContainsString('MODE_PRIME', $source);
    self::assertStringContainsString('hydrateCriticalBagsFromShared', $source);
    self::assertTrue(\interface_exists(StorefrontHotCacheBagWarmupProviderInterface::class));
    self::assertSame('hydrate', StorefrontHotCacheBagWarmupProviderInterface::MODE_HYDRATE);
    self::assertSame('prime', StorefrontHotCacheBagWarmupProviderInterface::MODE_PRIME);
}

private function assert_StorefrontProcessL1HydrateCoordinator_testCoordinatorRequestAndDeferRetry(): void
{
    self::assertFalse(StorefrontProcessL1HydrateCoordinator::hasPendingRequest());
    StorefrontProcessL1HydrateCoordinator::requestAfterReclaim('soft_reclaim');
    self::assertTrue(StorefrontProcessL1HydrateCoordinator::hasPendingRequest());
    self::assertSame('soft_reclaim', StorefrontProcessL1HydrateCoordinator::pendingReason());
    // Coalesce storm: second reclaim within window must not clear / reset due time.
    StorefrontProcessL1HydrateCoordinator::requestAfterReclaim('soft_reclaim');
    self::assertTrue(StorefrontProcessL1HydrateCoordinator::hasPendingRequest());
    $taken = StorefrontProcessL1HydrateCoordinator::takePendingRequest();
    self::assertTrue($taken['taken']);
    self::assertSame('soft_reclaim', $taken['reason']);
    self::assertFalse(StorefrontProcessL1HydrateCoordinator::hasPendingRequest());
    // Still inside coalesce window → drop.
    StorefrontProcessL1HydrateCoordinator::requestAfterReclaim('soft_reclaim');
    self::assertFalse(StorefrontProcessL1HydrateCoordinator::hasPendingRequest());
    StorefrontProcessL1HydrateCoordinator::resetForTests();
    StorefrontProcessL1HydrateCoordinator::deferRetry(30.0);
    self::assertFalse(StorefrontProcessL1HydrateCoordinator::hasPendingRequest(), 'deferRetry must not be due immediately');
}

private function assert_StorefrontProcessL1HydrateCoordinator_testCoordinatorSourceForbidsSsrAndDocumentsLayerB(): void
{
    $path = BP . 'app/code/Weline/Framework/Runtime/StorefrontProcessL1HydrateCoordinator.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('Layer B', $source);
    self::assertStringContainsString('hydrateCriticalBagsFromShared', $source);
    self::assertStringContainsString('hydrate_seeded_unexpected', $source);
    self::assertStringNotContainsString('runDeferredStorefrontCriticalWarmup', $source);
    self::assertStringNotContainsString('runInternalWarmupRequest', $source);
}

private function assert_StorefrontProcessL1HydrateSmell_testHotCacheSoftTrimPrefersNonChromeBeforeChrome(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Cache/Service/StorefrontScopeHotCache.php');
    $trimBody = $this->extractMethodBody_StorefrontProcessL1HydrateSmell($source, 'trimProcessCacheToBudget');
    self::assertStringContainsString('oldestNonChromeProcessKey', $trimBody);
    self::assertStringContainsString('oldestChromeProcessKey', $trimBody);
    self::assertTrue(\strpos($trimBody, 'oldestNonChromeProcessKey') < \strpos($trimBody, 'oldestChromeProcessKey'), 'non-chrome must be preferred before chrome last-resort');
    self::assertStringContainsString('theme.chrome.', $source);
    self::assertStringContainsString('theme.head.', $source);
}

private function assert_StorefrontProcessL1HydrateSmell_testUnifiedFpcHitSetsHonestSourceHeader(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString("setHeader('X-Weline-FPC-Source', \$cacheSource)", $source);
    $unified = $this->extractMethodBody_StorefrontProcessL1HydrateSmell($source, 'getUnifiedCachedResponse');
    self::assertStringContainsString('X-Weline-FPC-Source', $unified);
    self::assertStringContainsString("\$cacheSource === 'process'", $unified);
}

private function assert_StorefrontProcessL1HydrateSmell_testPreRouterHonorsProcessL1BeforeHtmlDiskWhenLegacySharedGated(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    $body = $this->extractMethodBody_StorefrontProcessL1HydrateSmell($source, 'getFormattedCachedResponseForFullUri');
    self::assertStringContainsString('allowLegacyShared', $body);
    self::assertStringContainsString('tryFormattedProcessL1HitForFullUri', $source);
    // Must not early-return solely to cold-disk when legacy Shared is gated.
    self::assertStringNotContainsString("if (!\$this->legacyPreRouterFpcAllowed()) {\n            return \$this->tryFormattedColdDiskDurableHit", $body);
}

private function extractMethodBody_StorefrontProcessL1HydrateSmell(string $source, string $method): string
{
    $start = \strpos($source, 'function ' . $method);
    self::assertNotFalse($start, 'missing method ' . $method);
    $brace = \strpos($source, '{', $start);
    self::assertNotFalse($brace);
    $depth = 0;
    $len = \strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return \substr($source, $brace, $i - $brace + 1);
            }
        }
    }
    self::fail('unclosed method ' . $method);
    return '';
}

private function assert_StorefrontRenderContextInstallerDecouple_testInstallerDoesNotSoftPullForeignModules(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/StorefrontRenderContextInstaller.php');
    self::assertStringNotContainsString('LanguageSwitcher', $src);
    self::assertStringNotContainsString('ScopeMaintenanceGate', $src);
    self::assertStringNotContainsString('WebsiteData', $src);
    self::assertStringContainsString('preferredInstalledLanguageCodes', $src);
    self::assertStringContainsString('LocalizationProviderRegistry', $src);
    self::assertStringNotContainsString('projectedLocaleCatalog', $src);
    self::assertStringNotContainsString('LOCALE_CATALOG_BAG_KEY', $src);
}

private function assert_StorefrontRenderContextInstallerDecouple_testTimezoneDoesNotSoftPullWebsiteData(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/DateTime/Timezone.php');
    self::assertStringNotContainsString('WebsiteData', $src);
    self::assertStringContainsString('getWelineTimezone', $src);
}

private function assert_StorefrontWidgetRuntimeSchedule_testCollectInlineSpecsFromCompiledPhpExport(): void
{
    $php = <<<'PHP'
    <?= \Weline\Widget\Taglib\Widget::renderRuntimeInline(array (
      'type' => 'block',
      'name' => 'hero-banner',
      'code' => 'hero-banner',
      'module' => 'Weline_Theme',
      'params' => array (),
    )) ?>
    <?= \Weline\Widget\Taglib\Widget::renderRuntimeInline(array (
      'type' => 'product',
      'name' => 'featured',
      'code' => 'featured-products',
      'module' => 'Weline_Product',
    )) ?>
    PHP;
    $specs = StorefrontWidgetRuntimeSchedule::collectInlineSpecsFromPhp($php);
    self::assertCount(2, $specs);
    self::assertSame('block', $specs[0]['type']);
    self::assertSame('hero-banner', $specs[0]['code']);
    self::assertSame('Weline_Theme', $specs[0]['module']);
    self::assertSame('featured-products', $specs[1]['code']);
    self::assertSame('Weline_Product', $specs[1]['module']);
}

private function assert_StorefrontWidgetRuntimeSchedule_testEmptyPhpYieldsNoSpecs(): void
{
    self::assertSame([], StorefrontWidgetRuntimeSchedule::collectInlineSpecsFromPhp(''));
    self::assertSame([], StorefrontWidgetRuntimeSchedule::collectInlineSpecsFromPhp('<?php echo "hi";'));
}

private function assert_StorefrontWidgetRuntimeSchedule_testScheduleIsNotSecondRenderContextAuthorityBag(): void
{
    self::assertNotSame(StorefrontRenderContext::BAG_KEY, StorefrontWidgetRuntimeSchedule::LATCH_KEY);
    self::assertStringContainsString('widget_runtime_schedule', StorefrontWidgetRuntimeSchedule::LATCH_KEY);
    self::assertStringNotContainsString('render_context', StorefrontWidgetRuntimeSchedule::LATCH_KEY);
    $scheduleSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/StorefrontWidgetRuntimeSchedule.php');
    self::assertStringContainsString('prefetchPolicy', $scheduleSrc);
    self::assertStringContainsString('不是第二套 RequestContext 渲染权威袋', $scheduleSrc);
    self::assertStringNotContainsString('getMultipleCustom', $scheduleSrc);
    self::assertStringContainsString('primeBeforeLayoutFetch', $scheduleSrc);
    self::assertStringContainsString('StorefrontPagePrefetchContributionRegistry', $scheduleSrc);
    self::assertStringNotContainsString('Weline\\\\Theme\\\\Service\\\\Storefront\\\\StorefrontWidgetRuntimeAssetPrimer', $scheduleSrc);
}

private function assert_StorefrontWidgetRuntimeSchedule_testHotCachePathPrefetchRunsBeforeGetFetchFileScan(): void
{
    $scheduleSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/StorefrontWidgetRuntimeSchedule.php');
    $hotPos = strpos($scheduleSrc, 'delegateHotCachePagePrefetch($template, ...$templateRefs)');
    $dictPos = strpos($scheduleSrc, 'delegatePageDictionaryPrefetch($template, ...$templateRefs)');
    $scanPos = strpos($scheduleSrc, '$this->scanTemplateRefs($template, ...$templateRefs)');
    self::assertNotFalse($hotPos);
    self::assertNotFalse($dictPos);
    self::assertNotFalse($scanPos);
    self::assertLessThan($dictPos, $hotPos, 'HotCache/path prefetch must run before dictionary prime');
    self::assertLessThan($scanPos, $dictPos, 'dictionary prime must run before getFetchFile scan');
    self::assertStringContainsString('delegateHotCachePagePrefetch($template, ...$templateRefs)', $scheduleSrc);
}

private function assert_StorefrontWidgetRuntimeSchedule_testThemeHookPrimesBeforeLayoutFetch(): void
{
    $observer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Observer/ControllerFetchFileAfter.php');
    self::assertStringContainsString('StorefrontWidgetRuntimeSchedule', $observer);
    self::assertStringContainsString('primeWidgetRuntimeSchedule', $observer);
    self::assertStringContainsString('primeBeforeLayoutFetch', $observer);
    $primePos = strpos($observer, 'primeWidgetRuntimeSchedule');
    $fetchPos = strpos($observer, '$template->fetch(');
    self::assertNotFalse($primePos);
    self::assertNotFalse($fetchPos);
    // At least one prime call appears before the first layout fetch in the file.
    self::assertLessThan($fetchPos, $primePos);
}

private function assert_StorefrontWidgetRuntimeSchedule_testAssetPrimerUsesPrefetchSourcesNotFakeGetMultiple(): void
{
    $primerPath = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Service/Storefront/StorefrontWidgetRuntimeAssetPrimer.php';
    self::assertFileExists($primerPath);
    $src = (string) file_get_contents($primerPath);
    self::assertStringContainsString('prefetchSources', $src);
    self::assertStringContainsString('WidgetAssetArtifactPublisher', $src);
    self::assertStringNotContainsString('getMultiple(', $src);
    self::assertStringNotContainsString('CachePool::getMultiple', $src);
}

private function tearDown_TemplateRenderRouteTrace(): void
{
    TemplateRenderRouteTrace::clearMemo();
    parent::tearDown();
}

private function assert_TemplateRenderRouteTrace_testSourceDeclaresStormGateAndPanelIds(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/TemplateRenderRouteTrace.php');
    self::assertStringContainsString('wls_tpl_route', $src);
    self::assertStringContainsString('FpcPierceStamp::isValidCurrentRequest', $src);
    self::assertStringContainsString('wls-tpl-route', $src);
    self::assertStringContainsString('wls-tpl-route-json', $src);
    self::assertStringContainsString("'dup'", $src);
}

private function assert_TemplateRenderRouteTrace_testFpcBypassAndCacheKeyRetainRouteQuery(): void
{
    $bypass = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/Fpc/FpcBypassEvaluator.php');
    self::assertStringContainsString("'wls_tpl_route'", $bypass);
    $coordPath = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/FullPageCacheCoordinator.php';
    $coord = (string) file_get_contents($coordPath);
    self::assertNotFalse(strpos($coord, "'wls_tpl_route'"), 'FullPageCacheCoordinator must retain wls_tpl_route storm key');
    self::assertMatchesRegularExpression("/function isCacheKeyRetainedQueryParam[\\s\\S]*'wls_tpl_route'/", $coord);
}

private function assert_TemplateRenderRouteTrace_testMarkOrdersAndFlagsDuplicateIdentity(): void
{
    if (!RequestContext::isInitialized()) {
        RequestContext::init();
    }
    RequestContext::set('view.template.route_trace', true);
    TemplateRenderRouteTrace::clearMemo();
    TemplateRenderRouteTrace::mark('ob_file', 'layouts/homepage/default.phtml');
    TemplateRenderRouteTrace::mark('widget', 'Weline_Theme/full-header@header');
    TemplateRenderRouteTrace::mark('widget', 'Weline_Theme/full-header@header');
    $events = TemplateRenderRouteTrace::events();
    self::assertCount(3, $events);
    self::assertSame(1, $events[0]['seq']);
    self::assertFalse($events[0]['dup']);
    self::assertFalse($events[1]['dup']);
    self::assertTrue($events[2]['dup']);
    self::assertSame(2, $events[2]['hit']);
    $html = TemplateRenderRouteTrace::appendToHtml('<html><body><main>ok</main></body></html>');
    self::assertStringContainsString('id="wls-tpl-route"', $html);
    self::assertStringContainsString('id="wls-tpl-route-json"', $html);
    self::assertStringContainsString('DUP×2', $html);
    self::assertStringContainsString('<!-- wls-tpl-route', $html);
}

private function assert_WlsRuntimeAdoptHomepageFpcMeta_testAdoptCallsWorkerReadinessMarkBusinessHomepageHot(): void
{
    $source = \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    self::assertIsString($source);
    self::assertStringContainsString('publishAdoptedHomepageFpcProofToWorkerReadiness', $source);
    self::assertStringContainsString('WorkerReadinessState::markBusinessHomepageHot', $source);
    self::assertStringContainsString('homepage-fpc:deferred-warmup:adopted', $source);
}

private function assert_WlsRuntimeAdoptHomepageFpcMeta_testDeferredWarmupAdoptsHomepageImmediatelyAndPrefersCatalogSlot(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    self::assertStringContainsString("if (\$path === '/' && !(bool)(\$this->readyGateHomepageFpcProof['hit'] ?? false))", $source);
    self::assertStringContainsString('candidate_uris', $source);
    self::assertStringContainsString("\$sample['full_uri']", $source);
    $orderPos = \strpos($source, 'Hot-path reserve: `/` + directory representative `/products`');
    self::assertNotFalse($orderPos);
    $localeBudgetPos = \strpos($source, '$localeBudget = \max(0, $budget - \count($criticalList))', $orderPos);
    self::assertNotFalse($localeBudgetPos);
    self::assertGreaterThan($orderPos, $localeBudgetPos);
}

private function assert_WlsRuntimeAdoptHomepageFpcMeta_testDeferredWarmupSkipsLocalesAfterConsecutiveProbeFailures(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    self::assertStringContainsString('$localeFailSkipThreshold = 2', $source);
    self::assertStringContainsString('$skipRemainingLocales = true', $source);
    self::assertStringContainsString("\$isCriticalPath = (\$path === '/' || \$path === '/products')", $source);
}

private function assert_WlsRuntimeAdoptHomepageFpcMeta_testDeferredWarmupForcesProductsAndSealsCriticalBeforeLocales(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    self::assertStringContainsString("\$paths['/products'] = '/products';", $source);
    self::assertStringContainsString('critical_sealed', $source);
    self::assertStringContainsString('retouchDeferredCriticalProcessL1', $source);
    // wls-perf B: near-virgin localeBudget hard-sealed to 0 (was min(..., 2)).
    self::assertStringContainsString('$localeBudget = 0;', $source);
    self::assertStringContainsString('locale_deferred_paths', $source);
    // P7 B′: default locale idle budget=0 → skipped; begin only when env budget>0.
    self::assertStringContainsString('wls.worker.storefront_locale_idle_budget', $source);
    self::assertStringContainsString('locale_idle_skipped', $source);
    self::assertStringContainsString('locale_idle_begin', $source);
    self::assertStringNotContainsString('$localeIdleBudget = $localeBudget', $source);
    self::assertStringContainsString("primeDeferredStorefrontHotCacheBags('post_critical_heavy')", $source);
    self::assertStringNotContainsString('$localeBudget = \min($localeBudget, 2);', $source);
    $sealPos = \strpos($source, "logDeferredStorefrontWarmupStage('critical_sealed'");
    self::assertNotFalse($sealPos);
    $heavyPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('post_critical_heavy')", (int) $sealPos);
    self::assertNotFalse($heavyPos);
    $idleHeavyPos = \strpos($source, "awaitDeferredStorefrontIdleGate('post_critical_heavy')", (int) $sealPos);
    self::assertNotFalse($idleHeavyPos);
    $localeSkippedPos = \strpos($source, "logDeferredStorefrontWarmupStage('locale_idle_skipped'", (int) $heavyPos);
    self::assertNotFalse($localeSkippedPos);
    $localeIdlePos = \strpos($source, "logDeferredStorefrontWarmupStage('locale_idle_begin'", (int) $heavyPos);
    self::assertNotFalse($localeIdlePos);
    $idleLocalePos = \strpos($source, "awaitDeferredStorefrontIdleGate('locale_idle')", (int) $heavyPos);
    self::assertNotFalse($idleLocalePos);
    $localeRunPos = \strpos($source, 'runStorefrontFpcWarmupInternal($localeList', (int) $heavyPos);
    self::assertNotFalse($localeRunPos);
    self::assertTrue($localeRunPos > $sealPos, 'locale warmup call site must remain after critical_sealed stage');
    self::assertTrue($heavyPos > $sealPos, 'post_critical_heavy bag prime must run after critical_sealed');
    self::assertTrue($idleHeavyPos < $heavyPos, 'idle-gate must precede post_critical_heavy');
    self::assertTrue($idleLocalePos < $localeIdlePos, 'locale idle-gate must precede locale_idle_begin (budget>0 path)');
    self::assertTrue($localeSkippedPos < $localeRunPos, 'locale_idle_skipped (default budget=0) must precede locale FPC call site');
    // P8 O1 / UC-post-locale: skip path must log post_locale_skipped; full prime gated.
    self::assertStringContainsString('$localeSsrRan', $source);
    self::assertStringContainsString("logDeferredStorefrontWarmupStage('post_locale_skipped'", $source);
    self::assertStringContainsString("'locale_ssr_not_run'", $source);
    $postLocaleSkippedPos = \strpos($source, "logDeferredStorefrontWarmupStage('post_locale_skipped'", (int) $localeRunPos);
    self::assertNotFalse($postLocaleSkippedPos);
    $primeFinalPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('post_locale')", (int) $localeRunPos);
    self::assertNotFalse($primeFinalPos);
    self::assertTrue($primeFinalPos > $localeRunPos, 'post_locale retouch call site must remain after locale FPC (gated by localeSsrRan)');
    // Cold-locale: locale_bag_prime is after done, not inside heavy.
    $donePos = \strpos($source, "logDeferredStorefrontWarmupStage('done'", (int) $heavyPos);
    self::assertNotFalse($donePos);
    $localeBagPos = \strpos($source, 'runLocaleBagPrimeShards', (int) $donePos);
    self::assertNotFalse($localeBagPos);
}

private function assert_WlsRuntimeDeferredHotCacheBagPrime_testDeferredCriticalSealPrimesHotCacheBagsBeforeLocales(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    self::assertStringContainsString('primeDeferredStorefrontHotCacheBags', $source);
    self::assertStringContainsString('hot_cache_bags_primed', $source);
    self::assertStringContainsString('PostResponseTaskQueue::drain', $source);
    self::assertStringContainsString('StorefrontHotCacheBagWarmupProviderInterface::CAPABILITY_PREFIX', $source);
    self::assertStringContainsString('invokeStorefrontHotCacheBagProviders', $source);
    self::assertStringContainsString('maybeCaptureHotCacheBagPrimeBeforeReset', $source);
    self::assertStringContainsString('WLS_PRIME_HOT_CACHE_BAGS', $source);
    self::assertStringContainsString('deferredHotCacheBagPrimePending', $source);
    self::assertStringNotContainsString("'X-WLS-Fpc-Bypass'", $source);
    self::assertStringContainsString('deferredHotCacheBagPrimeStage', $source);
    self::assertStringContainsString('wls.storefront_hot_cache_bag_prime.stage', $source);
    self::assertStringContainsString('shouldRunDeferredStorefrontPeerHotCacheBagHydrate', $source);
    self::assertStringContainsString('runDeferredStorefrontPeerHotCacheBagHydrate', $source);
    self::assertStringContainsString('peer_bags_hydrated', $source);
    self::assertStringContainsString("primeDeferredStorefrontHotCacheBags('peer_hydrate')", $source);
    self::assertStringContainsString('needsDeferredStorefrontWarmup', $source);
    self::assertStringContainsString('Weline\\\\Product\\\\Api\\\\Runtime\\\\StorefrontHotCacheBagWarmupProvider', $source);
    // Product must run before Theme so header chrome is MRU after catalog.full.
    $productFallbackPos = \strpos($source, "'storefront_hot_cache_bag_warmup.Weline_Product'");
    $themeFallbackPos = \strpos($source, "'storefront_hot_cache_bag_warmup.Weline_Theme'", (int) $productFallbackPos + 1);
    self::assertNotFalse($productFallbackPos);
    self::assertNotFalse($themeFallbackPos);
    self::assertTrue($productFallbackPos < $themeFallbackPos, 'Product bag provider must be ordered before Theme (header MRU)');
    $methodStart = (int) \strpos($source, 'function runDeferredStorefrontCriticalWarmup');
    self::assertGreaterThan(0, $methodStart);
    $prePrimePos = \strpos($source, "primeDeferredStorefrontHotCacheBags('pre_critical')", $methodStart);
    self::assertNotFalse($prePrimePos);
    $criticalRunPos = \strpos($source, 'runStorefrontFpcWarmupInternal($criticalList', $methodStart);
    self::assertNotFalse($criticalRunPos);
    self::assertTrue($prePrimePos < $criticalRunPos, 'pre_critical bag prime must run before critical FPC seal (public race window)');
    $sealPos = \strpos($source, "logDeferredStorefrontWarmupStage('critical_sealed'", $methodStart);
    self::assertNotFalse($sealPos);
    $primeCriticalPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('critical')", $methodStart);
    self::assertNotFalse($primeCriticalPos);
    self::assertTrue($criticalRunPos < $primeCriticalPos && $primeCriticalPos < $sealPos, 'critical bag retouch must run after FPC seal and before critical_sealed log');
    $heavyPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('post_critical_heavy')", (int) $sealPos);
    self::assertNotFalse($heavyPos);
    self::assertTrue($heavyPos > $sealPos, 'heavy catalog seed must run after critical_sealed (out of first-request window)');
    self::assertStringContainsString('awaitDeferredStorefrontIdleGate', $source);
    self::assertStringContainsString("awaitDeferredStorefrontIdleGate('post_critical_heavy')", $source);
    // P7 B′: locale idle-gate retained for explicit budget>0 path only.
    self::assertStringContainsString("awaitDeferredStorefrontIdleGate('locale_idle')", $source);
    self::assertStringContainsString('wls.worker.storefront_locale_idle_budget', $source);
    self::assertStringContainsString('locale_idle_skipped', $source);
    self::assertStringNotContainsString('$localeIdleBudget = $localeBudget', $source);
    self::assertStringContainsString("'idle_gate'", $source);
    self::assertStringContainsString('fiberHotCacheBagPrimeStates', $source);
    self::assertStringContainsString('beginHotCacheBagPrimeLatch', $source);
    self::assertStringContainsString('takeHotCacheBagPrimeCapture', $source);
    // HF-ED-P0-01 / P5 O2: call site + helper must land together — undefined method
    // during WLS drain/reload means stale worker bytecode, not a permanent design hole.
    self::assertStringContainsString('isHotCacheBagPrimePendingForCurrentFiber', $source);
    self::assertStringContainsString('private function isHotCacheBagPrimePendingForCurrentFiber(): bool', $source);
    $captureBody = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'maybeCaptureHotCacheBagPrimeBeforeReset');
    self::assertStringContainsString('isHotCacheBagPrimePendingForCurrentFiber()', $captureBody);
    self::assertStringContainsString('capture_retry', $source);
    self::assertStringContainsString('db_span_count', $source);
    self::assertStringContainsString('wls_span_count', $source);
    $idleHeavyPos = \strpos($source, "awaitDeferredStorefrontIdleGate('post_critical_heavy')", (int) $sealPos);
    self::assertNotFalse($idleHeavyPos);
    self::assertTrue($idleHeavyPos < $heavyPos, 'idle-gate must run after critical_sealed and before post_critical_heavy');
    $localeRunPos = \strpos($source, 'runStorefrontFpcWarmupInternal($localeList', (int) $heavyPos);
    self::assertNotFalse($localeRunPos);
    self::assertStringContainsString('runStorefrontFpcWarmupInternal($localeList, $hosts, true)', $source);
    $skippedPos = \strpos($source, "logDeferredStorefrontWarmupStage('locale_idle_skipped'", (int) $heavyPos);
    self::assertNotFalse($skippedPos);
    self::assertTrue($skippedPos < $localeRunPos, 'locale_idle_skipped (default budget=0) must precede locale FPC call site');
    // P8 O1 / UC-post-locale: post_locale full prime only when locale SSR ran.
    self::assertStringContainsString('$localeSsrRan', $source);
    self::assertStringContainsString("logDeferredStorefrontWarmupStage('post_locale_skipped'", $source);
    self::assertStringContainsString("'locale_ssr_not_run'", $source);
    self::assertStringContainsString("'stage' => 'post_locale_skipped'", $source);
    $postLocaleSkippedPos = \strpos($source, "logDeferredStorefrontWarmupStage('post_locale_skipped'", (int) $localeRunPos);
    self::assertNotFalse($postLocaleSkippedPos);
    $primeFinalPos = \strpos($source, "primeDeferredStorefrontHotCacheBags('post_locale')", (int) $localeRunPos);
    self::assertNotFalse($primeFinalPos);
    self::assertTrue($primeFinalPos > $localeRunPos);
    // Gated branch: localeSsrRan must appear before the post_locale prime call.
    $localeSsrRanPos = \strpos($source, '$localeSsrRan = $localeList !== []', (int) $heavyPos);
    self::assertNotFalse($localeSsrRanPos);
    self::assertTrue($localeSsrRanPos < $primeFinalPos, 'localeSsrRan gate must precede post_locale bag prime');
    self::assertTrue($postLocaleSkippedPos > $localeRunPos, 'post_locale_skipped stage must follow locale FPC call site (skip/no-op path)');
    // Cold-locale P0: locale_bag_prime after done (out of UC-deferred ≤5s wall clock).
    $donePos = \strpos($source, "logDeferredStorefrontWarmupStage('done'", (int) $heavyPos);
    self::assertNotFalse($donePos);
    self::assertStringContainsString('runLocaleBagPrimeShards', $source);
    self::assertStringContainsString('locale_bag_prime', $source);
    self::assertStringContainsString('locale_bag_prime_done', $source);
    self::assertStringContainsString('locale_bag_prime_truncated', $source);
    self::assertStringContainsString('storefront_locale_bag_prime_max_locales', $source);
    self::assertStringContainsString('resolveDeferredWarmupWebsiteLanguageCodes', $source);
    self::assertStringContainsString('extractLocaleCodeFromWarmupPath', $source);
    self::assertStringContainsString('runLocaleBagPrimeShards($localeDeferred)', $source);
    self::assertStringContainsString('primeDeferredLocalePhraseAndSwitcher', $source);
    self::assertStringContainsString('prefetchGlobalDictionaryModulesForLocales', $source);
    self::assertStringContainsString('warmStorefrontCatalogCaches', $source);
    self::assertStringContainsString('locale_phrase_prime', $source);
    self::assertStringContainsString('locale_switcher_catalog_prime', $source);
    $localeBagPrimeCallPos = \strpos($source, '$this->runLocaleBagPrimeShards($localeDeferred)', (int) $donePos);
    self::assertNotFalse($localeBagPrimeCallPos);
    self::assertTrue($localeBagPrimeCallPos > $donePos, 'locale_bag_prime must run after UC-deferred done (out of mandatory wall clock)');
    // currency_bag_prime: primary lang × non-default currencies (after locale bags).
    self::assertStringContainsString('runCurrencyBagPrimeShards', $source);
    self::assertStringContainsString('currency_bag_prime', $source);
    self::assertStringContainsString('currency_bag_prime_done', $source);
    self::assertStringContainsString('storefront_currency_bag_prime_max_currencies', $source);
    self::assertStringContainsString('resolveDeferredWarmupWebsiteCurrencyCodes', $source);
    self::assertStringContainsString('resolveDeferredWarmupWebsiteScopes', $source);
    self::assertStringContainsString('storefront_deferred_warmup_max_websites', $source);
    self::assertStringContainsString('runBoundedDeferredPrimeShards', $source);
    self::assertStringContainsString('ActiveCertificateDomainSourceInterface', $source);
    self::assertStringContainsString('website_scopes_error', $source);
    self::assertStringContainsString('_error', $source);
    $currencyBagPrimeCallPos = \strpos($source, '$this->runCurrencyBagPrimeShards()', (int) $localeBagPrimeCallPos);
    self::assertNotFalse($currencyBagPrimeCallPos);
    self::assertTrue($currencyBagPrimeCallPos > $localeBagPrimeCallPos, 'currency_bag_prime must run after locale_bag_prime');
    // category_surface_prime: top /category/** FPC seal after currency bags.
    self::assertStringContainsString('runCategorySurfaceFpcPrimeShards', $source);
    self::assertStringContainsString('category_surface_prime', $source);
    self::assertStringContainsString('category_surface_prime_done', $source);
    self::assertStringContainsString('storefront_category_surface_prime_max_paths', $source);
    self::assertStringContainsString('resolveCategorySurfaceWarmupPaths', $source);
    // Framework Runtime must not hardcode Product catalog slugs.
    self::assertStringNotContainsString('/category/women', $source);
    self::assertStringNotContainsString('/category/hanfu', $source);
    self::assertStringContainsString('FpcWarmupProviderInterface', $source);
    $categorySurfaceCallPos = \strpos($source, '$this->runCategorySurfaceFpcPrimeShards()', (int) $currencyBagPrimeCallPos);
    self::assertNotFalse($categorySurfaceCallPos);
    self::assertTrue($categorySurfaceCallPos > $currencyBagPrimeCallPos, 'category_surface_prime must run after currency_bag_prime');
    // DRY: bounded helper used by locale / currency / category shard runners.
    $localeBody = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'runLocaleBagPrimeShards');
    $currencyBody = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'runCurrencyBagPrimeShards');
    $categoryBody = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'runCategorySurfaceFpcPrimeShards');
    self::assertStringContainsString('runBoundedDeferredPrimeShards', $localeBody);
    self::assertStringContainsString('runBoundedDeferredPrimeShards', $currencyBody);
    self::assertStringContainsString('runBoundedDeferredPrimeShards', $categoryBody);
    self::assertStringContainsString('resolveDeferredWarmupWebsiteScopes', $localeBody);
    self::assertStringContainsString('resolveDeferredWarmupWebsiteScopes', $currencyBody);
    self::assertStringContainsString('resolveDeferredWarmupWebsiteScopes', $categoryBody);
    // Language/currency resolvers take website_id (not sole hard-coded ID_DEFAULT path).
    $langResolve = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'resolveDeferredWarmupWebsiteLanguageCodes');
    $curResolve = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'resolveDeferredWarmupWebsiteCurrencyCodes');
    self::assertStringContainsString('$websiteId', $langResolve);
    self::assertStringContainsString('$websiteId', $curResolve);
    self::assertStringContainsString('readSharedSnapshotById($websiteId)', $langResolve);
    self::assertStringContainsString('readSharedSnapshotById($websiteId)', $curResolve);
    $peerHydrateBody = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'runDeferredStorefrontPeerHotCacheBagHydrate');
    self::assertStringContainsString('runLocaleBagPrimeShards', $peerHydrateBody, 'peer Workers must run locale_bag_prime after peer_hydrate (not owner-only)');
    self::assertStringContainsString('runCurrencyBagPrimeShards', $peerHydrateBody, 'peer Workers must run currency_bag_prime after peer_hydrate (not owner-only)');
    self::assertStringNotContainsString('runCategorySurfaceFpcPrimeShards', $peerHydrateBody, 'category_surface_prime must stay owner-only (peer concurrent seal thrash)');
    // Failed critical path must still run post-done primes (not locale-only).
    $criticalBody = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'runDeferredStorefrontCriticalWarmup');
    $failedPos = \strpos($criticalBody, "logDeferredStorefrontWarmupStage('failed'");
    self::assertNotFalse($failedPos);
    $failedTail = \substr($criticalBody, (int) $failedPos);
    $failedReturnPos = \strpos($failedTail, 'return;');
    self::assertNotFalse($failedReturnPos);
    $failedSlice = \substr($failedTail, 0, (int) $failedReturnPos);
    self::assertStringContainsString('runLocaleBagPrimeShards', $failedSlice);
    self::assertStringContainsString('runCurrencyBagPrimeShards', $failedSlice);
    self::assertStringContainsString('runCategorySurfaceFpcPrimeShards', $failedSlice);
    // Must not fold multi-locale bag prime into post_critical_heavy.
    $heavyBodyEnd = (int) $localeRunPos;
    $heavySlice = \substr($source, (int) $heavyPos, \max(0, $heavyBodyEnd - (int) $heavyPos));
    self::assertStringNotContainsString('runLocaleBagPrimeShards', $heavySlice);
    self::assertStringNotContainsString('runCurrencyBagPrimeShards', $heavySlice);
    self::assertStringNotContainsString('runCategorySurfaceFpcPrimeShards', $heavySlice);
}

private function assert_WlsRuntimeDeferredHotCacheBagPrime_testBagWarmupInterfaceDeclaresCapabilityPrefix(): void
{
    $path = BP . 'app/code/Weline/Framework/Runtime/StorefrontHotCacheBagWarmupProviderInterface.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("CAPABILITY_PREFIX = 'storefront_hot_cache_bag_warmup.'", $source);
    self::assertStringContainsString('primeCriticalBags', $source);
}

private function assert_WlsRuntimeDeferredHotCacheBagPrime_testBagPrimeFallsBackWhenCompiledRegistryLags(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Server/Runtime/WlsRuntime.php');
    $body = $this->extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime($source, 'invokeStorefrontHotCacheBagProviders');
    self::assertStringContainsString('class_exists($implementation)', $body);
    self::assertStringContainsString('no_bag_warmup_providers', $body);
    self::assertStringContainsString('Weline\\\\Theme\\\\Api\\\\Runtime\\\\StorefrontHotCacheBagWarmupProvider', $body);
}

private function extractMethodBody_WlsRuntimeDeferredHotCacheBagPrime(string $source, string $method): string
{
    $start = \strpos($source, 'function ' . $method . '(');
    self::assertNotFalse($start, $method . ' missing');
    $brace = \strpos($source, '{', $start);
    self::assertNotFalse($brace);
    $depth = 0;
    $len = \strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return \substr($source, $brace, $i - $brace + 1);
            }
        }
    }
    self::fail('unclosed method ' . $method);
    return '';
}

private function assert_WlsRuntimeDeferredWarmupDefaultLocalePath_testDeferredWarmupFiltersDefaultLocaleViaCanonicalize(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/WlsRuntime.php');
    self::assertStringContainsString('filterDeferredStorefrontWarmupPath', $source);
    self::assertStringContainsString('canonicalizeStorefrontLocalizationPath', $source);
    self::assertStringContainsString('resolveDeferredWarmupWebsiteDefaults', $source);
    self::assertStringContainsString('set_cookie_names', $source);
    self::assertStringContainsString('readSharedSnapshotById', $source);
    // Must not use Env `lang` as website-default fallback (re-admits /en_US/).
    self::assertStringContainsString('resolveWebsiteDefaultLanguage', $source);
    self::assertStringContainsString('resolveWebsiteDefaultCurrency', $source);
    self::assertStringContainsString('WELINE_WEBSITE_LANGUAGE', $source);
    self::assertStringNotContainsString("Env::get('lang', null)", $this->extractMethodBody_WlsRuntimeDeferredWarmupDefaultLocalePath($source, 'resolveDeferredWarmupWebsiteDefaults'));
}

private function assert_WlsRuntimeDeferredWarmupDefaultLocalePath_testStorefrontWarmupPinsWebsiteLanguageIntoSyntheticRequest(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/WlsRuntime.php');
    $body = $this->extractMethodBody_WlsRuntimeDeferredWarmupDefaultLocalePath($source, 'runStorefrontFpcWarmupAttempt');
    self::assertStringContainsString('WELINE_WEBSITE_LANGUAGE', $body);
    self::assertStringContainsString('WELINE_WEBSITE_CURRENCY', $body);
    self::assertStringContainsString('gzip, deflate', $body);
}

private function extractMethodBody_WlsRuntimeDeferredWarmupDefaultLocalePath(string $source, string $method): string
{
    $pos = \strpos($source, 'function ' . $method . '(');
    self::assertNotFalse($pos, $method . ' missing');
    $next = \strpos($source, "\n    private function ", $pos + 1);
    if ($next === false) {
        $next = \strpos($source, "\n    public function ", $pos + 1);
    }
    if ($next === false) {
        $next = \strlen($source);
    }
    return \substr($source, $pos, $next - $pos);
}

/** @var array<string, string|false> */
private array $envBackup_WlsRuntimeFailOpenDeferredWarmup = [];

/** @var array<string, mixed> */
private array $serverBackup_WlsRuntimeFailOpenDeferredWarmup = [];

private function setUp_WlsRuntimeFailOpenDeferredWarmup(): void
{
    parent::setUp();
    foreach (['WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED', 'WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN', 'WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID', 'WLS_PROCESS_ROLE', 'WLS_WORKER_ID'] as $key) {
        $current = \getenv($key);
        $this->envBackup_WlsRuntimeFailOpenDeferredWarmup[$key] = $current === false ? false : (string) $current;
    }
    foreach (['WLS_PROCESS_ROLE', 'WLS_WORKER_ID'] as $key) {
        $this->serverBackup_WlsRuntimeFailOpenDeferredWarmup[$key] = $_SERVER[$key] ?? null;
        $this->serverBackup_WlsRuntimeFailOpenDeferredWarmup['env:' . $key] = $_ENV[$key] ?? null;
    }
    $_SERVER['WLS_PROCESS_ROLE'] = 'worker';
    $_ENV['WLS_PROCESS_ROLE'] = 'worker';
    \putenv('WLS_PROCESS_ROLE=worker');
    $_SERVER['WLS_WORKER_ID'] = '1';
    $_ENV['WLS_WORKER_ID'] = '1';
    \putenv('WLS_WORKER_ID=1');
    \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID=1');
}

private function tearDown_WlsRuntimeFailOpenDeferredWarmup(): void
{
    foreach ($this->envBackup_WlsRuntimeFailOpenDeferredWarmup as $key => $value) {
        if ($value === false) {
            \putenv($key);
            unset($_ENV[$key]);
        } else {
            \putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
    foreach (['WLS_PROCESS_ROLE', 'WLS_WORKER_ID'] as $key) {
        $server = $this->serverBackup_WlsRuntimeFailOpenDeferredWarmup[$key] ?? null;
        $env = $this->serverBackup_WlsRuntimeFailOpenDeferredWarmup['env:' . $key] ?? null;
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
    }
    parent::tearDown();
}

private function assert_WlsRuntimeFailOpenDeferredWarmup_testFailOpenForcesDeferredWarmupEvenWhenFlagDefaultOff(): void
{
    \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=1');
    \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=0');
    $runtime = new WlsRuntime();
    $this->setHomepageProof_WlsRuntimeFailOpenDeferredWarmup($runtime, ['hit' => false, 'reason' => 'homepage-fpc:deferred-after-ready:fail-open']);
    self::assertTrue($this->invokeShouldRun_WlsRuntimeFailOpenDeferredWarmup($runtime));
}

private function assert_WlsRuntimeFailOpenDeferredWarmup_testStrictHitWithDeferredFlagOffDoesNotForceWarmup(): void
{
    \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=0');
    \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=0');
    $runtime = new WlsRuntime();
    $this->setHomepageProof_WlsRuntimeFailOpenDeferredWarmup($runtime, ['hit' => true, 'fpc_status' => 'HIT', 'source' => 'process', 'full_uri' => 'https://example.test/', 'reason' => 'strict-prime', 'http_status' => 200]);
    self::assertFalse($this->invokeShouldRun_WlsRuntimeFailOpenDeferredWarmup($runtime));
}

private function assert_WlsRuntimeFailOpenDeferredWarmup_testMissedProofForcesDeferredEvenWhenFailOpenDisabled(): void
{
    \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=0');
    \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=0');
    $runtime = new WlsRuntime();
    $this->setHomepageProof_WlsRuntimeFailOpenDeferredWarmup($runtime, ['hit' => false, 'reason' => 'missing']);
    self::assertTrue($this->invokeShouldRun_WlsRuntimeFailOpenDeferredWarmup($runtime));
}

private function invokeShouldRun_WlsRuntimeFailOpenDeferredWarmup(WlsRuntime $runtime): bool
{
    $method = new ReflectionMethod(WlsRuntime::class, 'shouldRunDeferredStorefrontCriticalWarmup');
    $method->setAccessible(true);
    return (bool) $method->invoke($runtime);
}

/**
 * @param array<string, mixed> $proof
 */
private function setHomepageProof_WlsRuntimeFailOpenDeferredWarmup(WlsRuntime $runtime, array $proof): void
{
    $property = new ReflectionProperty(WlsRuntime::class, 'readyGateHomepageFpcProof');
    $property->setAccessible(true);
    $property->setValue($runtime, $proof);
}

/** @var array<string, mixed> */
private array $serverBackup_WlsRuntimePublicOriginWarmupHost = [];

/** @var array<string, string|false> */
private array $envBackup_WlsRuntimePublicOriginWarmupHost = [];

private function setUp_WlsRuntimePublicOriginWarmupHost(): void
{
    parent::setUp();
    foreach (['WLS_INSTANCE', 'WLS_INSTANCE_NAME', 'WLS_PUBLIC_ORIGIN'] as $key) {
        $this->serverBackup_WlsRuntimePublicOriginWarmupHost[$key] = $_SERVER[$key] ?? null;
        $this->serverBackup_WlsRuntimePublicOriginWarmupHost['env:' . $key] = $_ENV[$key] ?? null;
        $this->envBackup_WlsRuntimePublicOriginWarmupHost[$key] = \getenv($key);
    }
}

private function tearDown_WlsRuntimePublicOriginWarmupHost(): void
{
    foreach (['WLS_INSTANCE', 'WLS_INSTANCE_NAME', 'WLS_PUBLIC_ORIGIN'] as $key) {
        $server = $this->serverBackup_WlsRuntimePublicOriginWarmupHost[$key] ?? null;
        $env = $this->serverBackup_WlsRuntimePublicOriginWarmupHost['env:' . $key] ?? null;
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
        $previous = $this->envBackup_WlsRuntimePublicOriginWarmupHost[$key] ?? false;
        if ($previous === false) {
            \putenv($key);
        } else {
            \putenv($key . '=' . $previous);
        }
    }
    parent::tearDown();
}

private function assert_WlsRuntimePublicOriginWarmupHost_testSelectStorefrontWarmupHostPrefersPublicEdgePortOverWorkerPort(): void
{
    $runtime = (new ReflectionClass(WlsRuntime::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(WlsRuntime::class, 'selectStorefrontWarmupHost');
    $method->setAccessible(true);
    self::assertSame('p05113ef3.test.weline.com:9555', $method->invoke($runtime, ['p05113ef3.test.weline.com:9555', 'p05113ef3.test.weline.com:19655', '127.0.0.1:19655']));
}

private function assert_WlsRuntimePublicOriginWarmupHost_testResolveWorkerPublicOriginFallsBackToInstancePublicOrigin(): void
{
    if (!\defined('BP')) {
        self::markTestSkipped('BP undefined');
    }
    $instanceFile = VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . 'default.json';
    if (!\is_file($instanceFile)) {
        self::markTestSkipped('default instance metadata missing');
    }
    $data = \json_decode((string) \file_get_contents($instanceFile), true);
    $publicOrigin = \is_array($data) ? \trim((string) ($data['public_origin'] ?? '')) : '';
    if ($publicOrigin === '' || !\str_contains($publicOrigin, '://')) {
        self::markTestSkipped('default instance has no public_origin');
    }
    \putenv('WLS_PUBLIC_ORIGIN');
    unset($_ENV['WLS_PUBLIC_ORIGIN'], $_SERVER['WLS_PUBLIC_ORIGIN']);
    $_SERVER['WLS_INSTANCE'] = 'default';
    $_ENV['WLS_INSTANCE'] = 'default';
    \putenv('WLS_INSTANCE=default');
    $runtime = (new ReflectionClass(WlsRuntime::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(WlsRuntime::class, 'resolveWorkerPublicOrigin');
    $method->setAccessible(true);
    $origin = $method->invoke($runtime);
    self::assertIsArray($origin);
    self::assertSame('https', $origin['scheme'] ?? null);
    self::assertStringContainsString('.test.weline.com:', (string) ($origin['host'] ?? ''));
    self::assertStringNotContainsString(':19655', (string) ($origin['host'] ?? ''));
    self::assertSame(\parse_url($publicOrigin, \PHP_URL_HOST) . ':' . \parse_url($publicOrigin, \PHP_URL_PORT), $origin['host']);
}

private function assert_WlsRuntimePublicOriginWarmupHost_testProcessLocalDynamicWarmupHostsPreferPublicOriginAuthority(): void
{
    if (!\defined('BP')) {
        self::markTestSkipped('BP undefined');
    }
    $instanceFile = VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . 'default.json';
    if (!\is_file($instanceFile)) {
        self::markTestSkipped('default instance metadata missing');
    }
    $data = \json_decode((string) \file_get_contents($instanceFile), true);
    $publicOrigin = \is_array($data) ? \trim((string) ($data['public_origin'] ?? '')) : '';
    $mainPort = \is_array($data) ? (int) ($data['main_port'] ?? $data['port'] ?? 0) : 0;
    if ($publicOrigin === '' || $mainPort <= 0) {
        self::markTestSkipped('default instance missing public_origin/main_port');
    }
    $publicPort = (int) \parse_url($publicOrigin, \PHP_URL_PORT);
    if ($publicPort <= 0 || $publicPort === $mainPort) {
        self::markTestSkipped('public_origin port equals worker port; authority drift not observable');
    }
    \putenv('WLS_PUBLIC_ORIGIN');
    unset($_ENV['WLS_PUBLIC_ORIGIN'], $_SERVER['WLS_PUBLIC_ORIGIN']);
    $_SERVER['WLS_INSTANCE'] = 'default';
    $_ENV['WLS_INSTANCE'] = 'default';
    \putenv('WLS_INSTANCE=default');
    $runtime = (new ReflectionClass(WlsRuntime::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(WlsRuntime::class, 'resolveProcessLocalDynamicWarmupHosts');
    $method->setAccessible(true);
    $hosts = $method->invoke($runtime);
    self::assertSame([\parse_url($publicOrigin, \PHP_URL_HOST) . ':' . $publicPort], $hosts);
    self::assertStringNotContainsString(':' . $mainPort, (string) ($hosts[0] ?? ''));
}

private function assert_WlsRuntimePublicOriginWarmupHost_testAdoptProofLogsIncompleteOrAdoptedStages(): void
{
    $source = (string) \file_get_contents((new ReflectionClass(WlsRuntime::class))->getFileName() ?: '');
    self::assertStringContainsString("logDeferredStorefrontWarmupStage('adopted'", $source);
    self::assertStringContainsString("logDeferredStorefrontWarmupStage('incomplete'", $source);
    self::assertStringContainsString('homepage-not-ready', $source);
    self::assertStringContainsString("'public_origin'", $source);
}
}
