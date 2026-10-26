<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllFrontendApiContractsPass().
 */
final class FrontendApiContractsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('BP')) {
            // 仓库根（composer 契约测试惯例：BP = repo root）。
            $root = dirname(__DIR__, 9);
            // __DIR__ 在个别运行环境丢非 ASCII 段时回落真实仓库根。
            if (!\is_dir($root . '/app/code/Weline')) {
                $probe = __DIR__;
                for ($i = 0; $i < 12; ++$i) {
                    if (\is_dir($probe . '/app/code/Weline')) {
                        $root = $probe;
                        break;
                    }
                    $parent = \dirname($probe);
                    if ($parent === $probe) {
                        break;
                    }
                    $probe = $parent;
                }
            }
            \define('BP', $root . \DIRECTORY_SEPARATOR);
        }
        parent::setUpBeforeClass();
    }

    public function testAllFrontendApiContractsPass(): void
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
            'QueryBinCacheHeader::testQueryBinResolvesBinQueryCacheHeadersForCalls' => function () {
            $this->assert_QueryBinCacheHeader_testQueryBinResolvesBinQueryCacheHeadersForCalls();
        },
            'QueryBinLocalization::testApplyLocalizationPrefersDocumentPathname' => function () {
            $this->assert_QueryBinLocalization_testApplyLocalizationPrefersDocumentPathname();
        },
            'QueryBinDeployGeneration::testHandshakeAssertsClientThemeGeneration' => function () {
            $this->assert_QueryBinDeployGeneration_testHandshakeAssertsClientThemeGeneration();
        },
            'QueryBinDeployGeneration::testApiJsComparesHolePunchThemePublishedVersion' => function () {
            $this->assert_QueryBinDeployGeneration_testApiJsComparesHolePunchThemePublishedVersion();
        },
            'QueryBinDeployGeneration::testQueryBinErrorEnvelopeCarriesLocaleTipForDeployReload' => function () {
            $this->assert_QueryBinDeployGeneration_testQueryBinErrorEnvelopeCarriesLocaleTipForDeployReload();
        },
            'QueryBinDeployGeneration::testBuildClientConfigReadsDeployFromRuntimeRootNotOnlyApiSubtree' => function () {
            $this->assert_QueryBinDeployGeneration_testBuildClientConfigReadsDeployFromRuntimeRootNotOnlyApiSubtree();
        },
            'QueryBinDeployGeneration::testWorkerSessionThemeMatchIsStrictEmptyAware' => function () {
            $this->assert_QueryBinDeployGeneration_testWorkerSessionThemeMatchIsStrictEmptyAware();
        },
            'QueryBinDeployGeneration::testNonceCommitPassesThemePublishedVersionId' => function () {
            $this->assert_QueryBinDeployGeneration_testNonceCommitPassesThemePublishedVersionId();
        },
            'QueryBinDeployGeneration::testCoordinatorEmitsDeployHeaderAndMustRevalidate' => function () {
            $this->assert_QueryBinDeployGeneration_testCoordinatorEmitsDeployHeaderAndMustRevalidate();
        },
            'ScopeBootstrapRoute::testApiScanWrapperExtendsFrontendImplementation' => function () {
            $this->assert_ScopeBootstrapRoute_testApiScanWrapperExtendsFrontendImplementation();
        },
            'ScopeBootstrapRoute::testRouterFastPathAndStripAliasAreWired' => function () {
            $this->assert_ScopeBootstrapRoute_testRouterFastPathAndStripAliasAreWired();
        },
            'ScopeBootstrapRoute::testClientFetchesCanonicalHolePunchPath' => function () {
            $this->assert_ScopeBootstrapRoute_testClientFetchesCanonicalHolePunchPath();
        },
            'ScopeBootstrapRoute::testResponseCarriesAcceptableStorefrontGenerationIds' => function () {
            $this->assert_ScopeBootstrapRoute_testResponseCarriesAcceptableStorefrontGenerationIds();
        },
        ];
    }

private function assert_QueryBinCacheHeader_testQueryBinResolvesBinQueryCacheHeadersForCalls(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/Controller/Api/QueryBin.php');
    self::assertStringContainsString('resolveCacheHeaders', $source);
    self::assertStringContainsString('BinQueryCachePolicy', $source);
    self::assertStringContainsString('readCacheMarker', $source);
    self::assertStringContainsString('array $cacheHeaders', $source);
    self::assertDoesNotMatchRegularExpression('/private function binaryResponse\([^\)]*\)\s*:\s*Response\s*\{\s*[^}]*setHeader\(\'Cache-Control\',\s*\'no-store\'\);/s', $source, 'binaryResponse must accept cache headers instead of hardcoding only no-store');
}

private function assert_ScopeBootstrapRoute_testResponseCarriesAcceptableStorefrontGenerationIds(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/Controller/Api/ScopeBootstrap.php');
    // Warmup-transition tolerance contract: server hands the client the acceptable list.
    self::assertStringContainsString("'acceptable_theme_published_version_ids'", $src);
    self::assertStringContainsString('collectAcceptableStorefrontGenerationIds', $src);
    self::assertStringContainsString('acceptableStorefrontGenerationIds', $src);
}

private function assert_QueryBinLocalization_testApplyLocalizationPrefersDocumentPathname(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend';
    $source = (string) file_get_contents($base . '/Controller/Api/QueryBin.php');
    self::assertStringContainsString('State::writeLocalizationHintsFromUri($pathname)', $source);
    self::assertStringContainsString('resolvePathLocalization', $source);
    self::assertStringContainsString('pathLanguage === \'\'', $source);
    self::assertStringContainsString('State::isLanguageCodeShape', $source);
    $worker = (string) file_get_contents($base . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('Case-fix Script/REGION', $worker);
}

private function assert_QueryBinDeployGeneration_testHandshakeAssertsClientThemeGeneration(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php');
    self::assertStringContainsString('assertClientThemeGenerationCurrent', $src);
    self::assertStringContainsString("'deploy_reload_required'", $src);
    self::assertStringContainsString('主题已发布新版本。请重新加载页面以继续。', $src);
    self::assertStringContainsString('StorefrontGenerationIdentity', $src);
    // Warmup-transition tolerance (2026-10-26): QueryBin accepts the current generation
    // plus the most recent ACCEPTABLE_GENERATION_DEPTH sealed generations of the same
    // theme chain × owner quad. Strict current-only remains in the promote assertion.
    self::assertStringNotContainsString('collectAcceptableStorefrontGenerationIds', $src);
    self::assertStringContainsString('isAcceptableStorefrontGenerationId', $src);
    self::assertStringContainsString('page_generation_token', $src);
    self::assertStringContainsString('theme_published_version_id', $src);
    self::assertStringNotContainsString('assertClientDeployGenerationCurrent', $src);
    self::assertStringNotContainsString('currentServerDeployVersion', $src);
    // Backend-attested handshake must not 409 on missing storefront theme stamp.
    self::assertMatchesRegularExpression('/\$backendBootstrapId\s*===\s*\'\'\s*\)\s*\{\s*\$this->assertClientThemeGenerationCurrent/', $src);

    // TU epoch must move both the wire generation and the FPC slot token.
    $resolver = (string) \file_get_contents(BP . 'app/code/Weline/Theme/Service/ThemePublishedVersionRuntimeResolver.php');
    self::assertStringContainsString("'.e' . \$tuEpoch", $resolver);
    $runtime = (string) \file_get_contents(BP . 'app/code/Weline/Theme/Service/ThemeUpdate/ThemeUpdateRuntime.php');
    self::assertStringContainsString("'~e' . \$epoch", $runtime);
}

private function assert_QueryBinDeployGeneration_testApiJsComparesHolePunchThemePublishedVersion(): void
{
    $script = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js');
    self::assertStringContainsString('payload.theme_published_version_id', $script);
    self::assertStringContainsString('themePublishedVersionId', $script);
    self::assertStringContainsString("error.code = 'deploy_reload_required'", $script);
    self::assertStringContainsString('deploy_reload_required:', $script);
    self::assertStringContainsString("readScopeTipCache(currentStorefrontLocale(), 'deploy_reload_required')", $script);
    self::assertStringContainsString('rememberScopeTipsFromPayload(payload)', $script);
    // Warmup-transition tolerance: page stamp listed by the server keeps working (no reload).
    self::assertStringContainsString('acceptable_theme_published_version_ids', $script);
    self::assertStringContainsString('pageGenerationAcceptable', $script);
    self::assertStringContainsString("locale: pageLocale || undefined", $script);
    self::assertStringNotContainsString('payload.deploy_version', $script);
    // Client response-cache generation segment = theme published id, not system deploy.
    self::assertStringContainsString('config.themePublishedVersionId', $script);
    self::assertMatchesRegularExpression('/buildClientResponseCacheKey[\s\S]*?themePublishedVersionId[\s\S]*?join\(\'\|\'\)/', $script);
    self::assertStringNotContainsString("String((config && config.deployVersion) || ''),\n            String((config && config.locale) || ''),", $script);
    // SharedWorker config refresh clears L1 only on theme generation change.
    self::assertStringContainsString('Storefront generation = Scope-published theme id only (not system deployVersion).', $script);
}

private function assert_QueryBinDeployGeneration_testQueryBinErrorEnvelopeCarriesLocaleTipForDeployReload(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php');
    self::assertStringContainsString('FrontendScopeReloadTip::build', $src);
    self::assertStringContainsString("\$errorPayload['tip'] = \$tip", $src);
}

private function assert_QueryBinDeployGeneration_testBuildClientConfigReadsDeployFromRuntimeRootNotOnlyApiSubtree(): void
{
    $script = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js');
    self::assertStringContainsString('runtimeConfig.deployVersion', $script);
    self::assertStringContainsString('runtimeConfig.workerBuildId', $script);
    self::assertStringContainsString('runtimeConfig.themePublishedVersionId', $script);
    // api → runtime fallback order (SharedWorker may only see mergeApiConfig / api subtree).
    self::assertMatchesRegularExpression('/apiConfig\.themePublishedVersionId[\s\S]*?runtimeConfig\.themePublishedVersionId/', $script);
    $header = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/view/blocks/header/base.phtml');
    self::assertStringContainsString("'deployVersion' => \$deployVersion", $header);
    // api subtree must also carry stamps so mergeApiConfig alone cannot stick on "dev"
    // or omit themePublishedVersionId (QueryBin handshake 409 / deploy_reload_required).
    self::assertMatchesRegularExpression("/'api'\\s*=>\\s*\\[[\\s\\S]*?'deployVersion'\\s*=>\\s*\\\$deployVersion/", $header);
    self::assertMatchesRegularExpression("/'api'\\s*=>\\s*\\[[\\s\\S]*?'themePublishedVersionId'\\s*=>\\s*\\\$themePublishedVersionId/", $header);
    self::assertMatchesRegularExpression("/'api'\\s*=>\\s*\\[[\\s\\S]*?'themePublishedVersion'\\s*=>\\s*\\\$themePublishedVersion/", $header);
}

private function assert_QueryBinDeployGeneration_testWorkerSessionThemeMatchIsStrictEmptyAware(): void
{
    $script = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api-worker.js');
    // Empty session theme must not match a non-empty page theme (and vice versa).
    self::assertStringContainsString('const themeGenerationMatches = sessionThemeId === configThemeId;', $script);
    self::assertStringNotContainsString("const themeGenerationMatches = configThemeId === ''\n            || sessionThemeId === ''\n            || sessionThemeId === configThemeId;", $script);
    self::assertStringContainsString('theme generation mismatch', $script);
    self::assertStringContainsString('sessionsByScopeKey.clear()', $script);
    // Handshake 409 must carry the current page-generation token to the main thread.
    self::assertStringContainsString('pageGenerationToken:', $script);
    self::assertStringContainsString('body.error.page_generation_token', $script);
    // Worker error serialization must preserve the token for the main thread.
    self::assertStringContainsString('page_generation_token: error && error.pageGenerationToken', $script);

    $service = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Service/Query/FrontendWorkerSessionService.php');
    self::assertMatchesRegularExpression(
        "/throw new FrontendQueryException\\(\\s*'deploy_reload_required',\\s*'Worker session theme generation mismatch\\.',\\s*409,/s",
        $service,
    );
}

private function assert_QueryBinDeployGeneration_testNonceCommitPassesThemePublishedVersionId(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php');
    $nonceCallPos = \strpos($src, 'validateSessionAndConsumeNonce(');
    self::assertNotFalse($nonceCallPos);
    $nonceCall = \substr($src, (int) $nonceCallPos, 420);
    self::assertStringContainsString("\$headers['nonce']", $nonceCall);
    self::assertStringContainsString("\$headers['theme_published_version_id']", $nonceCall);
    $service = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Service/Query/FrontendWorkerSessionService.php');
    $methodPos = \strpos($service, 'function validateSessionAndConsumeNonce(');
    self::assertNotFalse($methodPos);
    $methodBody = \substr($service, (int) $methodPos, 2200);
    self::assertStringContainsString('string $themePublishedVersionId = \'\',', $methodBody);
    self::assertStringContainsString('$themePublishedVersionId,', $methodBody);
    // Nonce-path assertSession must not hardcode empty theme string.
    self::assertStringNotContainsString("\$now,\n                '',", $methodBody);
}

private function assert_QueryBinDeployGeneration_testCoordinatorEmitsDeployHeaderAndMustRevalidate(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
    self::assertStringContainsString('applyDeployGenerationResponseHeaders', $src);
    self::assertStringContainsString('X-Weline-Deploy-Version', $src);
    self::assertStringContainsString("', must-revalidate'", $src);
}

private function assert_ScopeBootstrapRoute_testApiScanWrapperExtendsFrontendImplementation(): void
{
    $wrapper = BP . 'app/code/Weline/Api/Api/Frontend/ScopeBootstrap.php';
    self::assertFileExists($wrapper);
    $src = (string) \file_get_contents($wrapper);
    self::assertStringContainsString('namespace Weline\Api\Api\Frontend;', $src);
    self::assertStringContainsString('extends \Weline\Frontend\Controller\Api\ScopeBootstrap', $src);
}

private function assert_ScopeBootstrapRoute_testRouterFastPathAndStripAliasAreWired(): void
{
    $router = BP . 'app/code/Weline/Framework/Router/Core.php';
    $routerSrc = (string) \file_get_contents($router);
    self::assertStringContainsString('isFrontendScopeBootstrapApiUrl', $routerSrc);
    self::assertStringContainsString('routeFrontendScopeBootstrapApi', $routerSrc);
    self::assertStringContainsString('frontend/scope-bootstrap', $routerSrc);
    self::assertStringContainsString('\Weline\Frontend\Controller\Api\ScopeBootstrap::class', $routerSrc);
    $helper = BP . 'app/code/Weline/Framework/Module/Helper/Data.php';
    $helperSrc = (string) \file_get_contents($helper);
    self::assertStringContainsString('collectFrontendScopeBootstrapRouteAlias', $helperSrc);
    self::assertStringContainsString("str_starts_with(\$route, 'api/frontend/scope-bootstrap')", $helperSrc);
    $matcher = BP . 'app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php';
    $matcherSrc = (string) \file_get_contents($matcher);
    self::assertStringContainsString('matchesWorkerScopeBootstrapRoute', $matcherSrc);
    self::assertStringContainsString('api/frontend/scope-bootstrap', $matcherSrc);
}

private function assert_ScopeBootstrapRoute_testClientFetchesCanonicalHolePunchPath(): void
{
    $js = BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js';
    self::assertFileExists($js);
    $src = (string) \file_get_contents($js);
    self::assertStringContainsString("fetch('/api/frontend/scope-bootstrap'", $src);
    $controller = (string) \file_get_contents(BP . 'app/code/Weline/Frontend/Controller/Api/ScopeBootstrap.php');
    self::assertStringContainsString("'page_generation_token' =>", $controller);
}
}
