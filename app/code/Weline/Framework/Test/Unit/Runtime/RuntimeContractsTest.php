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
            'WlsRuntimeDeferredHotCacheBagPrime::testBagWarmupInterfaceDeclaresCapabilityPrefix' => function () {
            $this->assert_WlsRuntimeDeferredHotCacheBagPrime_testBagWarmupInterfaceDeclaresCapabilityPrefix();
        },
            'WlsRuntimeDeferredWarmupDefaultLocalePath::testDeferredWarmupFiltersDefaultLocaleViaCanonicalize' => function () {
            $this->assert_WlsRuntimeDeferredWarmupDefaultLocalePath_testDeferredWarmupFiltersDefaultLocaleViaCanonicalize();
        },
            'WlsRuntimeDeferredWarmupDefaultLocalePath::testStorefrontWarmupPinsWebsiteLanguageIntoSyntheticRequest' => function () {
            $this->assert_WlsRuntimeDeferredWarmupDefaultLocalePath_testStorefrontWarmupPinsWebsiteLanguageIntoSyntheticRequest();
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
    $files = ['/View/Template.php', '/App/State.php', '/App.php', '/Http/ErrorPageRenderer.php', '/Http/Security/SecurityHeaderPolicyService.php', '/Http/HeaderCollector.php', '/Cache/KeyBuilder.php', '/Controller/Api/CspPolicy.php', '/Service/Query/QueryProviderRegistry.php'];
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
}

private function assert_WlsRuntimeDeferredHotCacheBagPrime_testBagWarmupInterfaceDeclaresCapabilityPrefix(): void
{
    $path = BP . 'app/code/Weline/Framework/Runtime/StorefrontHotCacheBagWarmupProviderInterface.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("CAPABILITY_PREFIX = 'storefront_hot_cache_bag_warmup.'", $source);
    self::assertStringContainsString('primeCriticalBags', $source);
}

private function assert_WlsRuntimeDeferredWarmupDefaultLocalePath_testDeferredWarmupFiltersDefaultLocaleViaCanonicalize(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/WlsRuntime.php');
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
