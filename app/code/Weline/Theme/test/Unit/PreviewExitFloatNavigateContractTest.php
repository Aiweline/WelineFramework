<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: storefront preview exit = clear client token + GET gateway?exit=1.
 */
final class PreviewExitFloatNavigateContractTest extends TestCase
{
    public function testInjectedExitScriptClearsAndReplacesOnce(): void
    {
        $path = dirname(__DIR__, 2) . '/Observer/LayoutSlotRenderer.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        $start = strpos($source, 'function navigateExitPreview()');
        self::assertNotFalse($start);
        $end = strpos($source, 'function finishExit()', $start);
        self::assertNotFalse($end);
        $fn = substr($source, $start, $end - $start);

        self::assertStringContainsString('clearPreviewClientState();', $fn);
        self::assertStringContainsString('window.location.replace(buildExitNavigateUrl())', $fn);
        self::assertStringNotContainsString('forceNavigate', $fn);
        self::assertStringNotContainsString('fetch(url', $fn);
        self::assertStringNotContainsString(
            "notifyParentPreviewExit();\n            return;",
            $fn,
            'Must not abort after parent postMessage'
        );
    }

    public function testPreviewBootstrapClearsStaleClientTokenOnPersistFailure(): void
    {
        $path = dirname(__DIR__, 2) . '/view/ui/js/pages/preview-bootstrap.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('if (!persisted) {', $source);
        self::assertStringContainsString('clearClientToken();', $source);
        self::assertStringNotContainsString(
            "if (!persisted) {\n        if (urlToken) {\n            clearClientToken();\n        }",
            $source
        );
    }

    public function testBareGatewayWithoutLoginRedirectsHomeForBrowser(): void
    {
        $path = dirname(__DIR__, 2) . '/Controller/Frontend/ThemePreview/Gateway.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('裸开 gateway', $source);
        self::assertStringContainsString('PreviewExitService::class)->exit(', $source);
        self::assertStringContainsString('resolveExitRedirectUrl()', $source);
    }

    public function testFrameworkFpcBypassesScopedPreviewCookieAndQuery(): void
    {
        $path = dirname(__DIR__, 3) . '/Framework/Router/FullPageCacheCoordinator.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("'weline_preview_token'", $source);
        self::assertStringContainsString('hasPreviewTokenCookieHeader', $source);
        self::assertStringContainsString('weline_preview_token(?:_w\d+)?=', $source);
    }

    public function testWorkerFpcFastPathBypassesScopedPreviewCookie(): void
    {
        $path = dirname(__DIR__, 3) . '/Server/Service/WorkerFullPageCacheFastPath.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('hasPreviewTokenCookie', $source);
        self::assertStringContainsString('weline_preview_token(?:_w\d+)?=', $source);
    }

    public function testPreviewTokenResolveForbidsSharedResponseCache(): void
    {
        $path = dirname(__DIR__, 2) . '/Observer/ResolvePreviewToken.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('SharedResponseCachePolicy::forbid', $source);
        self::assertStringContainsString("'theme_preview_mode'", $source);
    }

    public function testPreviewExitClearsProcessFpc(): void
    {
        $path = dirname(__DIR__, 2) . '/Service/PreviewExitService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('FullPageCacheCoordinator::clearProcessCache()', $source);
    }
}
