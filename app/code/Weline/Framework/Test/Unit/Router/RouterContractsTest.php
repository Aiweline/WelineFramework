<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\FullPageCacheReclaimableAdapter;
use Weline\Framework\Test\ProjectRoot;
use Weline\Framework\View\Helper\HtmlCacheAdmission;

/**
 * Aggregated contract suite (merged from 10 *ContractTest files).
 * Observe one overall pass/fail via testAllRouterContractsPass().
 */
final class RouterContractsTest extends TestCase
{
    public function testAllRouterContractsPass(): void
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
            'FpcDefaultAllowPolicy::testCoordinatorFallsBackToOneYearWhenNoExtraMatches' => function () {
            $this->assert_FpcDefaultAllowPolicy_testCoordinatorFallsBackToOneYearWhenNoExtraMatches();
        },
            'FpcDefaultAllowPolicy::testSearchHardExcludeRemovedAndShortTtlExtraPresent' => function () {
            $this->assert_FpcDefaultAllowPolicy_testSearchHardExcludeRemovedAndShortTtlExtraPresent();
        },
            'FpcDefaultAllowPolicy::testSensitiveSurfacesDeclareEnabledFalse' => function () {
            $this->assert_FpcDefaultAllowPolicy_testSensitiveSurfacesDeclareEnabledFalse();
        },
            'FullPageCacheDiagTrack::testIgnorableQueryParamsDoNotSwallowDiagBypassKeys' => function () {
            $this->assert_FullPageCacheDiagTrack_testIgnorableQueryParamsDoNotSwallowDiagBypassKeys();
        },
            'FullPageCacheDiagTrack::testProductQueryIsPathOnlyButStormKeysRemainInCacheIdentity' => function () {
            $this->assert_FullPageCacheDiagTrack_testProductQueryIsPathOnlyButStormKeysRemainInCacheIdentity();
        },
            'FullPageCacheDiagTrack::testQueryAllowlistRetainHooksPresent' => function () {
            $this->assert_FullPageCacheDiagTrack_testQueryAllowlistRetainHooksPresent();
        },
            'FullPageCacheDiagTrack::testProductFpcDoesNotHardExcludeSearchPathSegment' => function () {
            $this->assert_FullPageCacheDiagTrack_testProductFpcDoesNotHardExcludeSearchPathSegment();
        },
            'FullPageCacheDiagTrack::testCanonicalizePreservesInternalRouterLayoutKeysOutOfCacheKey' => function () {
            $this->assert_FullPageCacheDiagTrack_testCanonicalizePreservesInternalRouterLayoutKeysOutOfCacheKey();
        },
            'FullPageCacheGuideOriginPrettyPath::testCoordinatorPrefersWelineOriginRequestUriForRawFullUri' => function () {
            $this->setUp_FullPageCacheGuideOriginPrettyPath();
            $this->assert_FullPageCacheGuideOriginPrettyPath_testCoordinatorPrefersWelineOriginRequestUriForRawFullUri();
        },
            'FullPageCacheGuideOriginPrettyPath::testCoordinatorPrefersFrozenEngineIdentityUrlBeforeOriginFallback' => function () {
            $this->setUp_FullPageCacheGuideOriginPrettyPath();
            $this->assert_FullPageCacheGuideOriginPrettyPath_testCoordinatorPrefersFrozenEngineIdentityUrlBeforeOriginFallback();
        },
            'FullPageCacheGuideOriginPrettyPath::testCanonicalizeDoesNotOverwriteOriginOrFullRequestUri' => function () {
            $this->setUp_FullPageCacheGuideOriginPrettyPath();
            $this->assert_FullPageCacheGuideOriginPrettyPath_testCanonicalizeDoesNotOverwriteOriginOrFullRequestUri();
        },
            'FullPageCacheGuideOriginPrettyPath::testAppFreezesEngineIdentityUrlFromVisitorOrigin' => function () {
            $this->setUp_FullPageCacheGuideOriginPrettyPath();
            $this->assert_FullPageCacheGuideOriginPrettyPath_testAppFreezesEngineIdentityUrlFromVisitorOrigin();
        },
            'FullPageCacheGuideOriginPrettyPath::testUrlLocksImmutableVisitorRequestUri' => function () {
            $this->setUp_FullPageCacheGuideOriginPrettyPath();
            $this->assert_FullPageCacheGuideOriginPrettyPath_testUrlLocksImmutableVisitorRequestUri();
        },
            'FullPageCacheGuideOriginPrettyPath::testUrlMatrixExpanderDocumentsPathLocaleNotQueryIdentity' => function () {
            $this->setUp_FullPageCacheGuideOriginPrettyPath();
            $this->assert_FullPageCacheGuideOriginPrettyPath_testUrlMatrixExpanderDocumentsPathLocaleNotQueryIdentity();
        },
            'FullPageCacheHitCookieSuppress::testMarkCacheHitSourceClearsCookiesBeforeAndAfterDecorators' => function () {
            $this->assert_FullPageCacheHitCookieSuppress_testMarkCacheHitSourceClearsCookiesBeforeAndAfterDecorators();
        },
            'FullPageCacheHomepageReceiptSharedRehydrate::testResolveHomepageProcessReceiptRehydratesFromSharedOnProcessMiss' => function () {
            $this->assert_FullPageCacheHomepageReceiptSharedRehydrate_testResolveHomepageProcessReceiptRehydratesFromSharedOnProcessMiss();
        },
            'FullPageCacheProcessL1HomepagePin::testProcessL1EvictionPrefersNonPinnedAndRefusesLocaleOverHomepage' => function () {
            $this->assert_FullPageCacheProcessL1HomepagePin_testProcessL1EvictionPrefersNonPinnedAndRefusesLocaleOverHomepage();
        },
            'FullPageCacheProcessL1Pointer::testProcessL1PointerOnlyAndSoftCompactApisExist' => function () {
            $this->assert_FullPageCacheProcessL1Pointer_testProcessL1PointerOnlyAndSoftCompactApisExist();
        },
            'FullPageCacheProcessL1Pointer::testReclaimableAdapterSoftCompactUsesRealBytes' => function () {
            $this->assert_FullPageCacheProcessL1Pointer_testReclaimableAdapterSoftCompactUsesRealBytes();
        },
            'FullPageCacheProcessL1Pointer::testSoftCompactIsCallable' => function () {
            $this->assert_FullPageCacheProcessL1Pointer_testSoftCompactIsCallable();
        },
            'FullPageCacheProductCardCssIntegrity::testCoordinatorUsesSharedHtmlCacheAdmission' => function () {
            $this->assert_FullPageCacheProductCardCssIntegrity_testCoordinatorUsesSharedHtmlCacheAdmission();
        },
            'FullPageCacheProductCardCssIntegrity::testTemplateHookStoresUseSharedAdmission' => function () {
            $this->assert_FullPageCacheProductCardCssIntegrity_testTemplateHookStoresUseSharedAdmission();
        },
            'FullPageCacheProductCardCssIntegrity::testAdmissionPredicateLogic' => function () {
            $this->assert_FullPageCacheProductCardCssIntegrity_testAdmissionPredicateLogic();
        },
            'FullPageCacheProductCardCssIntegrity::testCoordinatorHealsProductCardCssBeforeAdmission' => function () {
            $this->assert_FullPageCacheProductCardCssIntegrity_testCoordinatorHealsProductCardCssBeforeAdmission();
        },
            'FullPageCacheProductCardCssIntegrity::testResponseEmissionHealsProductCardCssIndependentOfFpcPublish' => function () {
            $this->assert_FullPageCacheProductCardCssIntegrity_testResponseEmissionHealsProductCardCssIndependentOfFpcPublish();
        },
            'FullPageCachePublishMissNoStoreEdge::testPublishResponseSetsNoStoreAfterSuccessfulStoreSnapshot' => function () {
            $this->assert_FullPageCachePublishMissNoStoreEdge_testPublishResponseSetsNoStoreAfterSuccessfulStoreSnapshot();
        },
            'FullPageCacheSharedForbidPublish::testCanPublishHardBlocksSharedForbidAndHeaderCollectorNoStore' => function () {
            $this->assert_FullPageCacheSharedForbidPublish_testCanPublishHardBlocksSharedForbidAndHeaderCollectorNoStore();
        },
        ];
    }

private function repoRoot_FpcDefaultAllowPolicy(): string
{
    // .../Framework/Test/Unit/Router → repo root
    return '/Users/weline/Project/Official/框架';
}

private function assert_FpcDefaultAllowPolicy_testCoordinatorFallsBackToOneYearWhenNoExtraMatches(): void
{
    $src = (string) \file_get_contents(self::repoRoot_FpcDefaultAllowPolicy() . '/app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertMatchesRegularExpression("/\\?\\?\\s*\\[\\s*'enabled'\\s*=>\\s*true\\s*,\\s*'ttl'\\s*=>\\s*31536000\\b/", $src);
    self::assertStringNotContainsString("'ttl' => 3600, 'policy_fingerprint' => 'not-applicable'", $src);
}

private function assert_FpcDefaultAllowPolicy_testSearchHardExcludeRemovedAndShortTtlExtraPresent(): void
{
    $coordinator = (string) \file_get_contents(self::repoRoot_FpcDefaultAllowPolicy() . '/app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertStringNotContainsString("// Search results vary by q/type/category_id; never share one FPC body.\n            'search',", $coordinator);
    $search = (string) \file_get_contents(self::repoRoot_FpcDefaultAllowPolicy() . '/app/code/Weline/Search/Controller/Frontend/Index.php');
    self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b/', $search);
    self::assertMatchesRegularExpression('/ttl\s*=\s*3600\b/', $search);
    self::assertDoesNotMatchRegularExpression('/enabled\s*=\s*false/', $search);
}

private function assert_FpcDefaultAllowPolicy_testSensitiveSurfacesDeclareEnabledFalse(): void
{
    $root = self::repoRoot_FpcDefaultAllowPolicy();
    $files = [$root . '/app/code/Weline/Compare/Controller/Frontend/Index.php', $root . '/app/code/Weline/Newsletter/Controller/Frontend/Subscribe.php', $root . '/app/code/Weline/HelpPay/Controller/Frontend/Payer.php', $root . '/app/code/Weline/CustomerService/Controller/Frontend/Bind.php', $root . '/app/code/Weline/Multipass/Controller/Frontend/Identity.php'];
    foreach ($files as $file) {
        self::assertFileExists($file, $file);
        $src = (string) \file_get_contents($file);
        self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b[^\n]*enabled\s*=\s*false/', $src, \basename($file));
    }
}

private function assert_FullPageCacheDiagTrack_testIgnorableQueryParamsDoNotSwallowDiagBypassKeys(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('IGNORABLE_QUERY_PARAM_KEYS', $source);
    $keys = $this->readPrivateConstArray_FullPageCacheDiagTrack('IGNORABLE_QUERY_PARAM_KEYS');
    self::assertArrayNotHasKey('_wb_nc', $keys);
    self::assertArrayNotHasKey('wls_tpl_perf', $keys);
    self::assertArrayNotHasKey('wb_nc', $keys);
    $prefixes = $this->readPrivateConstList_FullPageCacheDiagTrack('IGNORABLE_QUERY_PARAM_PREFIXES');
    foreach ($prefixes as $prefix) {
        self::assertFalse(str_starts_with('_wb_nc', (string) $prefix) || (string) $prefix === '_wb_', 'IGNORABLE prefix must not swallow _wb_nc: ' . $prefix);
        self::assertNotSame('wls_', (string) $prefix, 'IGNORABLE must not swallow wls_tpl_perf via wls_');
    }
}

private function assert_FullPageCacheDiagTrack_testProductQueryIsPathOnlyButStormKeysRemainInCacheIdentity(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('isCacheKeyRetainedQueryParam', $source);
    self::assertStringContainsString("'_wb_nc'", $source);
    self::assertStringContainsString("'wls_tpl_perf'", $source);
    self::assertStringContainsString('FpcPierceStamp::isValidCurrentRequest', $source);
    $coordinator = (new \ReflectionClass(FullPageCacheCoordinator::class))->newInstanceWithoutConstructor();
    $filter = new \ReflectionMethod(FullPageCacheCoordinator::class, 'filterQueryStringForCacheKey');
    $filter->setAccessible(true);
    self::assertSame('', $filter->invoke($coordinator, 'size=s&style_type=hong-se-jiu&utm_source=x'));
    // Without pierce stamp, storm keys must not retain a distinct cache identity.
    self::assertSame('', $filter->invoke($coordinator, 'size=s&_wb_nc=1'));
    self::assertSame('', $filter->invoke($coordinator, 'wls_tpl_perf=1&gclid=abc'));
}

private function assert_FullPageCacheDiagTrack_testQueryAllowlistRetainHooksPresent(): void
{
    $framework = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $coord = (string) file_get_contents($framework . '/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('isPathAllowlistedCacheKeyQueryParam', $coord);
    self::assertStringContainsString('currentPathCacheKeyQueryAllowlist', $coord);
    self::assertStringContainsString('queryKeysFromPolicy', $coord);
    self::assertStringContainsString('canonicalCacheKeyUriHasQueryVariant', $coord);
    self::assertStringContainsString('canonicalCacheKeyUriHasQueryVariant($fullUri)', $coord);
    $extra = (string) file_get_contents($framework . '/Extends/module/Weline_Framework/Extra/Type/FpcExtraType.php');
    self::assertStringContainsString('query_keys', $extra);
    self::assertStringContainsString('MAX_QUERY_KEYS', $extra);
    $snapshot = (string) file_get_contents($framework . '/Controller/Extra/FpcPolicySnapshot.php');
    self::assertStringContainsString('requestCarriesAllowlistedQuery', $snapshot);
    $resolver = (string) file_get_contents($framework . '/Controller/Extra/ExtraPolicyResolver.php');
    self::assertStringContainsString('queryKeysForPath', $resolver);
    self::assertStringContainsString('requestCarriesAllowlistedQueryForPath', $resolver);
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product/Controller/Frontend/Catalog.php');
    self::assertMatchesRegularExpression('/@Extra\\s+type=fpc\\b[^\\n]*query_keys=/', $catalog);
    self::assertStringContainsString('query_keys=af_available_colors,', $catalog);
    self::assertStringContainsString(',page,price,sort', $catalog);
    $keysHelper = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product/Service/StorefrontListingFpcQueryKeys.php');
    self::assertStringContainsString('class StorefrontListingFpcQueryKeys', $keysHelper);
}

private function assert_FullPageCacheDiagTrack_testProductFpcDoesNotHardExcludeSearchPathSegment(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/FullPageCacheCoordinator.php');
    // Search Extra path-only shell (ttl=3600); hard-exclude must stay removed.
    self::assertStringNotContainsString("// Search results vary by q/type/category_id; never share one FPC body.\n            'search',", $source);
    self::assertDoesNotMatchRegularExpression("/blockedPrefixes\\s*=\\s*\\\\array_filter\\(\\[(?:.|\\n)*?'search',/", $source);
}

private function assert_FullPageCacheDiagTrack_testCanonicalizePreservesInternalRouterLayoutKeysOutOfCacheKey(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('internalRouterGetParamKeys', $source);
    self::assertStringContainsString("'layout_type'", $source);
    self::assertStringContainsString("'layout_option'", $source);
    self::assertStringContainsString("'theme_page_title'", $source);
    // Must restore after path-only filter, not retain into cache identity.
    self::assertMatchesRegularExpression('/function canonicalizeCurrentRequestForCacheBuild[\s\S]*internalRouterGetParamKeys/', $source);
    self::assertDoesNotMatchRegularExpression('/function isCacheKeyRetainedQueryParam[\s\S]*layout_type/', $source);
}

/**
 * @return array<string, bool>
 */
private function readPrivateConstArray_FullPageCacheDiagTrack(string $name): array
{
    $ref = new \ReflectionClass(FullPageCacheCoordinator::class);
    $const = $ref->getReflectionConstant($name);
    self::assertNotFalse($const);
    $value = $const->getValue();
    self::assertIsArray($value);
    return $value;
}

/**
 * @return list<string>
 */
private function readPrivateConstList_FullPageCacheDiagTrack(string $name): array
{
    $ref = new \ReflectionClass(FullPageCacheCoordinator::class);
    $const = $ref->getReflectionConstant($name);
    self::assertNotFalse($const);
    $value = $const->getValue();
    self::assertIsArray($value);
    return array_values(array_map('strval', $value));
}

private function setUp_FullPageCacheGuideOriginPrettyPath(): void
{
    ProjectRoot::defineBp(__DIR__);
}

private function assert_FullPageCacheGuideOriginPrettyPath_testCoordinatorPrefersWelineOriginRequestUriForRawFullUri(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('function getRawFullUri', $source);
    self::assertStringContainsString('WELINE_ORIGIN_REQUEST_URI', $source);
    self::assertStringContainsString('visitor-facing pretty path', $source);
    self::assertStringContainsString('/destinations', $source);
    self::assertStringContainsString('/guide?view=', $source);
    self::assertLessThan(strpos($source, "WelineEnv::server('WELINE_ORIGIN_REQUEST_URI'") ?: PHP_INT_MAX, strpos($source, 'function getRawFullUri') ?: PHP_INT_MAX);
}

private function assert_FullPageCacheGuideOriginPrettyPath_testCoordinatorPrefersFrozenEngineIdentityUrlBeforeOriginFallback(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    $getRawPos = strpos($source, 'function getRawFullUri');
    $nextFn = strpos($source, "\n    private function ", $getRawPos + 10);
    $body = $nextFn === false ? substr($source, $getRawPos) : substr($source, $getRawPos, $nextFn - $getRawPos);
    self::assertNotFalse($getRawPos);
    self::assertStringContainsString("RequestContext::get('fpc.engine_identity_url'", $body);
    self::assertStringContainsString('FpcEngineIdentity::isGuideControllerUri', $body);
    self::assertStringContainsString('WELINE_VISITOR_REQUEST_URI', $body);
    self::assertStringContainsString('Engine-aligned Shared identity', $body);
    self::assertStringContainsString('generated/html/live/{host}/{path}', $body);
    $freezeInBody = strpos($body, "RequestContext::get('fpc.engine_identity_url'");
    $originInBody = strpos($body, "WelineEnv::server('WELINE_ORIGIN_REQUEST_URI'");
    self::assertNotFalse($freezeInBody);
    self::assertNotFalse($originInBody);
    self::assertLessThan($originInBody, $freezeInBody);
}

private function assert_FullPageCacheGuideOriginPrettyPath_testCanonicalizeDoesNotOverwriteOriginOrFullRequestUri(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    $fnPos = strpos($source, 'function canonicalizeCurrentRequestForCacheBuild');
    self::assertNotFalse($fnPos);
    $nextFn = strpos($source, "\n    private function ", $fnPos + 10);
    $body = $nextFn === false ? substr($source, $fnPos) : substr($source, $fnPos, $nextFn - $fnPos);
    self::assertStringContainsString('Do NOT overwrite WELINE_ORIGIN_REQUEST_URI', $body);
    self::assertStringNotContainsString("WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI'", $body);
    self::assertStringNotContainsString("WelineEnv::setServer('WELINE_FULL_REQUEST_URI'", $body);
    self::assertStringNotContainsString("WelineEnv::set('full_request_uri'", $body);
    self::assertStringNotContainsString("WelineEnv::set('origin_request_uri'", $body);
}

private function assert_FullPageCacheGuideOriginPrettyPath_testAppFreezesEngineIdentityUrlFromVisitorOrigin(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/App.php');
    self::assertStringContainsString('FpcEngineIdentity::freezeFullUrl', $source);
    self::assertStringContainsString('WELINE_VISITOR_REQUEST_URI', $source);
    self::assertStringContainsString('Freeze Shared FPC identity', $source);
    self::assertStringContainsString('generated/html/live/{host}/{path}', $source);
}

private function assert_FullPageCacheGuideOriginPrettyPath_testUrlLocksImmutableVisitorRequestUri(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/Http/Url.php');
    self::assertStringContainsString('WELINE_VISITOR_REQUEST_URI', $source);
    self::assertStringContainsString('Immutable visitor path for Shared FPC', $source);
}

private function assert_FullPageCacheGuideOriginPrettyPath_testUrlMatrixExpanderDocumentsPathLocaleNotQueryIdentity(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/Event/Changed/UrlMatrixExpander.php');
    self::assertStringContainsString('path 语种，非 query 标记', $source);
    self::assertStringContainsString('withPathVariant', $source);
}

private function assert_FullPageCacheHitCookieSuppress_testMarkCacheHitSourceClearsCookiesBeforeAndAfterDecorators(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'cache_hit\')', $src);
    self::assertStringContainsString('Decorators must not reintroduce Set-Cookie', $src);
    self::assertStringContainsString("WelineEnv::set('response.from_cache', true", $src);
    $runtimeSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Server/Runtime/WlsRuntime.php');
    self::assertNotSame('', $runtimeSrc);
    self::assertStringContainsString('SharedFpcSetCookieSuppress::isArmed()', $runtimeSrc);
    self::assertStringContainsString("'cookies' => \$suppressCookies ? [] : \$headerCollector->getCookies()", $runtimeSrc);
}

private function assert_FullPageCacheHomepageReceiptSharedRehydrate_testResolveHomepageProcessReceiptRehydratesFromSharedOnProcessMiss(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    $pos = \strpos($source, 'function resolveHomepageProcessReceipt(');
    self::assertNotFalse($pos);
    $next = \strpos($source, "\n    private function isRootHomepageFullUri(", $pos);
    self::assertNotFalse($next);
    $body = \substr($source, $pos, $next - $pos);
    self::assertStringContainsString('hydrateSharedPayload', $body);
    self::assertStringContainsString('setProcessCachedPayload', $body);
    self::assertStringContainsString('homepage-ready-but-receipt-missing', $body);
}

private function assert_FullPageCacheProcessL1HomepagePin_testProcessL1EvictionPrefersNonPinnedAndRefusesLocaleOverHomepage(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('function isProcessFpcHomepagePinnedKey(', $source);
    self::assertStringContainsString('function evictProcessFpcPayloadForIncoming(', $source);
    self::assertStringContainsString('processLocalizedHomepageReceipts', $source);
    self::assertStringContainsString('processCriticalCatalogReceipts', $source);
    self::assertStringContainsString('registerRootCatalogProcessReceipt', $source);
    self::assertStringContainsString('isRootCatalogProductsFullUri', $source);
    self::assertStringContainsString('Keep homepage/catalog receipt L1', $source);
    self::assertStringContainsString('not a parallel epoch-less static bag', $source);
    $getPos = \strpos($source, 'function getProcessCachedPayload(');
    self::assertNotFalse($getPos);
    $setPos = \strpos($source, 'function setProcessCachedPayload(', $getPos);
    self::assertNotFalse($setPos);
    $getBody = \substr($source, $getPos, $setPos - $getPos);
    self::assertStringContainsString('unset(self::$processFpcPayloadCache[$cacheKey])', $getBody);
    self::assertStringContainsString('self::$processFpcPayloadCache[$cacheKey] = $payload', $getBody);
}

private function assert_FullPageCacheProcessL1Pointer_testProcessL1PointerOnlyAndSoftCompactApisExist(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(FullPageCacheCoordinator::class))->getFileName());
    self::assertStringContainsString('processL1PointerOnlyPayload', $src);
    self::assertStringContainsString('PROCESS_FPC_INLINE_MAX_BYTES', $src);
    self::assertStringContainsString('softCompactProcessCache', $src);
    self::assertStringContainsString('processPayloadTotalBytes', $src);
    self::assertStringContainsString('hydrateSharedPayload($payload)', $src);
}

private function assert_FullPageCacheProcessL1Pointer_testReclaimableAdapterSoftCompactUsesRealBytes(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(FullPageCacheReclaimableAdapter::class))->getFileName());
    self::assertStringContainsString('processPayloadTotalBytes', $src);
    self::assertStringContainsString('softCompactProcessCache', $src);
    self::assertStringContainsString('function estimateBytes()', $src);
    self::assertStringContainsString('function compact()', $src);
}

private function assert_FullPageCacheProcessL1Pointer_testSoftCompactIsCallable(): void
{
    FullPageCacheCoordinator::clearProcessCache();
    self::assertSame(0, FullPageCacheCoordinator::processPayloadTotalBytes());
    self::assertSame(0, FullPageCacheCoordinator::softCompactProcessCache());
}

private function assert_FullPageCacheProductCardCssIntegrity_testCoordinatorUsesSharedHtmlCacheAdmission(): void
{
    $src = (string) file_get_contents((new \ReflectionClass(\Weline\Framework\Router\FullPageCacheCoordinator::class))->getFileName());
    self::assertStringContainsString('HtmlCacheAdmission::admit', $src);
    self::assertStringContainsString('skip publish html cache admission rejected', $src);
    self::assertStringContainsString('invalidate hit html cache admission rejected', $src);
    self::assertStringContainsString('invalidate stale hit html cache admission rejected', $src);
    self::assertStringContainsString('healStorefrontProductCardCss', $src);
    self::assertStringNotContainsString('function storefrontProductCardCssIntegrityOk', $src);
    self::assertStringNotContainsString('function storefrontModuleTitleIntegrityOk', $src);
}

private function assert_FullPageCacheProductCardCssIntegrity_testTemplateHookStoresUseSharedAdmission(): void
{
    $src = (string) file_get_contents((new \ReflectionClass(\Weline\Framework\View\Template::class))->getFileName());
    self::assertStringContainsString('HtmlCacheAdmission::admit', $src);
    self::assertStringNotContainsString('scrubModulePlaceholderTitlesHtml', $src);
}

private function assert_FullPageCacheProductCardCssIntegrity_testAdmissionPredicateLogic(): void
{
    self::assertTrue(HtmlCacheAdmission::storefrontProductCardCssOk('<html><body>no cards</body></html>'));
    self::assertTrue(HtmlCacheAdmission::storefrontProductCardCssOk('<style data-weline-product-card-css="1"></style>' . '<article data-testid="weline-product-card">ok</article>'));
    self::assertFalse(HtmlCacheAdmission::storefrontProductCardCssOk('<article data-testid="weline-product-card">poison</article>'));
    self::assertFalse(HtmlCacheAdmission::admit('<h1>Weline_Theme</h1>'));
    $poison = '<html><body><article data-testid="weline-product-card">x</article></body></html>';
    $healed = HtmlCacheAdmission::healStorefrontProductCardCss($poison);
    self::assertStringContainsString('data-weline-product-card-css', $healed);
    self::assertTrue(HtmlCacheAdmission::admit($healed));
}

private function assert_FullPageCacheProductCardCssIntegrity_testCoordinatorHealsProductCardCssBeforeAdmission(): void
{
    $src = (string) file_get_contents((new \ReflectionClass(\Weline\Framework\Router\FullPageCacheCoordinator::class))->getFileName());
    self::assertStringContainsString('healStorefrontProductCardCss', $src);
    self::assertStringContainsString('healed product-card css before publish', $src);
}

private function assert_FullPageCacheProductCardCssIntegrity_testResponseEmissionHealsProductCardCssIndependentOfFpcPublish(): void
{
    $src = (string) file_get_contents((new \ReflectionClass(\Weline\Framework\Http\Response::class))->getFileName());
    self::assertStringContainsString('healStorefrontProductCardCssIfNeeded', $src);
    self::assertStringContainsString('HtmlCacheAdmission::healStorefrontProductCardCss', $src);
    self::assertStringContainsString('weline-product-card', $src);
}

private function assert_FullPageCachePublishMissNoStoreEdge_testPublishResponseSetsNoStoreAfterSuccessfulStoreSnapshot(): void
{
    $source = \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertIsString($source);
    self::assertStringContainsString("setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')", $source);
    self::assertStringContainsString('must not persist the MISS headers', $source);
    $publishPos = \strpos($source, 'function publishResponse(');
    $noStorePos = \strpos($source, "setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')");
    $setProcessPos = \strpos($source, 'setProcessCachedPayload(');
    self::assertNotFalse($publishPos);
    self::assertNotFalse($noStorePos);
    self::assertNotFalse($setProcessPos);
    self::assertGreaterThan($setProcessPos, $noStorePos, 'no-store must be applied after payload headers were snapshotted for storage');
}

private function assert_FullPageCacheSharedForbidPublish_testCanPublishHardBlocksSharedForbidAndHeaderCollectorNoStore(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('function sharedResponseForbidsPublish', $src);
    self::assertStringContainsString('function sharedHeaderCollectorHasNoStore', $src);
    self::assertStringContainsString('function markLiveResponseUncacheable', $src);
    self::assertStringContainsString('SharedResponseCachePolicy::isForbidden()', $src);
    self::assertStringContainsString('sharedHeaderCollectorHasNoStore()', $src);
    self::assertStringContainsString('sharedResponseForbidsPublish()', $src);
    self::assertStringContainsString('GuardHeaders::STATUS_BYPASS', $src);
    self::assertStringContainsString("directive === 'no-store'", $src);
    self::assertStringContainsString('X-Weline-Shared-Cache-Forbidden', $src);
}
}
