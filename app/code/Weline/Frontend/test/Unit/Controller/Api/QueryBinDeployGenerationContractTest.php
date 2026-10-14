<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;

/**
 * Theme published generation mismatch on QueryBin handshake must force client reload.
 * System deploy_version alone must not be the storefront generation authority.
 */
final class QueryBinDeployGenerationContractTest extends TestCase
{
    public function testHandshakeAssertsClientThemeGeneration(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php'
        );
        self::assertStringContainsString('assertClientThemeGenerationCurrent', $src);
        self::assertStringContainsString("'deploy_reload_required'", $src);
        self::assertStringContainsString('主题已发布新版本。请重新加载页面以继续。', $src);
        self::assertStringContainsString('StorefrontGenerationIdentity', $src);
        self::assertStringContainsString('theme_published_version_id', $src);
        self::assertStringNotContainsString('assertClientDeployGenerationCurrent', $src);
        self::assertStringNotContainsString('currentServerDeployVersion', $src);
        // Backend-attested handshake must not 409 on missing storefront theme stamp.
        self::assertMatchesRegularExpression(
            '/\$backendBootstrapId\s*===\s*\'\'\s*\)\s*\{\s*\$this->assertClientThemeGenerationCurrent/',
            $src
        );
    }

    public function testApiJsComparesHolePunchThemePublishedVersion(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js'
        );
        self::assertStringContainsString('payload.theme_published_version_id', $script);
        self::assertStringContainsString('themePublishedVersionId', $script);
        self::assertStringContainsString("error.code = 'deploy_reload_required'", $script);
        self::assertStringContainsString('deploy_reload_required:', $script);
        self::assertStringContainsString("readScopeTipCache(currentStorefrontLocale(), 'deploy_reload_required')", $script);
        self::assertStringContainsString('rememberScopeTipsFromPayload(payload)', $script);
        self::assertStringContainsString("locale: pageLocale || undefined", $script);
        self::assertStringNotContainsString('payload.deploy_version', $script);
    }

    public function testQueryBinErrorEnvelopeCarriesLocaleTipForDeployReload(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php'
        );
        self::assertStringContainsString('FrontendScopeReloadTip::build', $src);
        self::assertStringContainsString("\$errorPayload['tip'] = \$tip", $src);
    }

    public function testBuildClientConfigReadsDeployFromRuntimeRootNotOnlyApiSubtree(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js'
        );
        self::assertStringContainsString('runtimeConfig.deployVersion', $script);
        self::assertStringContainsString('runtimeConfig.workerBuildId', $script);
        self::assertStringContainsString('runtimeConfig.themePublishedVersionId', $script);
        // api → runtime fallback order (SharedWorker may only see mergeApiConfig / api subtree).
        self::assertMatchesRegularExpression(
            '/apiConfig\\.themePublishedVersionId[\\s\\S]*?runtimeConfig\\.themePublishedVersionId/',
            $script
        );
        $header = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/view/blocks/header/base.phtml'
        );
        self::assertStringContainsString("'deployVersion' => \$deployVersion", $header);
        // api subtree must also carry stamps so mergeApiConfig alone cannot stick on "dev"
        // or omit themePublishedVersionId (QueryBin handshake 409 / deploy_reload_required).
        self::assertMatchesRegularExpression(
            "/'api'\\s*=>\\s*\\[[\\s\\S]*?'deployVersion'\\s*=>\\s*\\\$deployVersion/",
            $header
        );
        self::assertMatchesRegularExpression(
            "/'api'\\s*=>\\s*\\[[\\s\\S]*?'themePublishedVersionId'\\s*=>\\s*\\\$themePublishedVersionId/",
            $header
        );
        self::assertMatchesRegularExpression(
            "/'api'\\s*=>\\s*\\[[\\s\\S]*?'themePublishedVersion'\\s*=>\\s*\\\$themePublishedVersion/",
            $header
        );
    }

    public function testWorkerSessionThemeMatchIsStrictEmptyAware(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/view/statics/js/weline-api-worker.js'
        );
        // Empty session theme must not match a non-empty page theme (and vice versa).
        self::assertStringContainsString('const themeGenerationMatches = sessionThemeId === configThemeId;', $script);
        self::assertStringNotContainsString(
            "const themeGenerationMatches = configThemeId === ''\n            || sessionThemeId === ''\n            || sessionThemeId === configThemeId;",
            $script
        );
        self::assertStringContainsString('theme generation mismatch', $script);
        self::assertStringContainsString('sessionsByScopeKey.clear()', $script);
    }

    public function testNonceCommitPassesThemePublishedVersionId(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php'
        );
        $nonceCallPos = \strpos($src, 'validateSessionAndConsumeNonce(');
        self::assertNotFalse($nonceCallPos);
        $nonceCall = \substr($src, (int)$nonceCallPos, 420);
        self::assertStringContainsString("\$headers['nonce']", $nonceCall);
        self::assertStringContainsString("\$headers['theme_published_version_id']", $nonceCall);

        $service = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Service/Query/FrontendWorkerSessionService.php'
        );
        $methodPos = \strpos($service, 'function validateSessionAndConsumeNonce(');
        self::assertNotFalse($methodPos);
        $methodBody = \substr($service, (int)$methodPos, 2200);
        self::assertStringContainsString('string $themePublishedVersionId = \'\',', $methodBody);
        self::assertStringContainsString('$themePublishedVersionId,', $methodBody);
        // Nonce-path assertSession must not hardcode empty theme string.
        self::assertStringNotContainsString("\$now,\n                '',", $methodBody);
    }

    public function testCoordinatorEmitsDeployHeaderAndMustRevalidate(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );
        self::assertStringContainsString('applyDeployGenerationResponseHeaders', $src);
        self::assertStringContainsString('X-Weline-Deploy-Version', $src);
        self::assertStringContainsString("', must-revalidate'", $src);
    }
}
