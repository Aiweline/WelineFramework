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

    public function testPublishAndExitUsesCredentialFetchNotEditorRequest(): void
    {
        $path = dirname(__DIR__, 2) . '/Observer/LayoutSlotRenderer.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        $start = strpos($source, 'function postPublishAndExit(');
        self::assertNotFalse($start);
        $end = strpos($source, 'publishBtn.addEventListener', $start);
        self::assertNotFalse($end);
        $helper = substr($source, $start, $end - $start);

        self::assertStringContainsString('fetch(publishUrl', $helper);
        self::assertStringContainsString("credentials: 'include'", $helper);
        self::assertStringContainsString('text/event-stream', $helper);
        self::assertStringContainsString('consumePublishSse', $helper);

        $listenerStart = strpos($source, 'publishBtn.addEventListener');
        self::assertNotFalse($listenerStart);
        $listenerEnd = strpos($source, '})();', $listenerStart);
        self::assertNotFalse($listenerEnd);
        $fn = substr($source, $listenerStart, $listenerEnd - $listenerStart);

        self::assertStringContainsString('postPublishAndExit(', $fn);
        self::assertStringContainsString('theme_publish_requires_new_version', $fn);
        self::assertStringContainsString('promptNewVersionName', $fn);
        self::assertStringContainsString('openPreviewModalOverlay', $source);
        self::assertStringContainsString('z-index:2147483200', $source);
        self::assertStringContainsString('new RegExp(', $source);
        self::assertStringNotContainsString('window.prompt(', $fn);
        self::assertStringContainsString('publishNeedsLogin', $fn);
        self::assertStringNotContainsString('editorRequest', $fn);
        self::assertStringNotContainsString("Api.resource('theme')", $fn);
        self::assertStringContainsString('frontend Worker', $source);
        self::assertStringContainsString('AREA_BACKEND', $source);
    }

    public function testPublishAndExitTearsDownFloatAndExitsViaGateway(): void
    {
        $path = dirname(__DIR__, 2) . '/Observer/LayoutSlotRenderer.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        $start = strpos($source, 'function finishPublishRedirect(');
        self::assertNotFalse($start);
        $end = strpos($source, 'function promptNewVersionName(', $start);
        self::assertNotFalse($end);
        $fn = substr($source, $start, $end - $start);

        self::assertStringContainsString('tearDownPreviewFloatChrome()', $fn);
        self::assertStringContainsString('buildPublishExitNavigateUrl(', $fn);
        self::assertStringContainsString('notifyParentPreviewExit()', $fn);
        self::assertStringContainsString('window.location.replace(navigateUrl)', $fn);
        // Must not jump straight to published storefront while HttpOnly cookie remains.
        self::assertStringNotContainsString('window.location.replace(redirectUrl)', $fn);

        $buildStart = strpos($source, 'function buildPublishExitNavigateUrl(');
        self::assertNotFalse($buildStart);
        $buildEnd = strpos($source, 'function tearDownPreviewFloatChrome(', $buildStart);
        self::assertNotFalse($buildEnd);
        $buildFn = substr($source, $buildStart, $buildEnd - $buildStart);
        self::assertStringContainsString("gateway.searchParams.set('exit', '1')", $buildFn);
        self::assertStringContainsString("gateway.searchParams.set('redirect', target)", $buildFn);
        self::assertStringContainsString("gateway.searchParams.set('token', token)", $buildFn);

        $tearStart = strpos($source, 'function tearDownPreviewFloatChrome(');
        self::assertNotFalse($tearStart);
        $tearEnd = strpos($source, 'function finishPublishRedirect(', $tearStart);
        self::assertNotFalse($tearEnd);
        $tearFn = substr($source, $tearStart, $tearEnd - $tearStart);
        self::assertStringContainsString('floatEl.remove()', $tearFn);
        self::assertStringContainsString('closePublishProgressLock()', $tearFn);
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
