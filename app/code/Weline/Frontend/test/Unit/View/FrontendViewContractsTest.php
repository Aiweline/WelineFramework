<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\Limits;
use Weline\Framework\Test\TestCore;

/**
 * Aggregated contract suite (merged from 15 *ContractTest files).
 * Observe one overall pass/fail via testAllFrontendViewContractsPass().
 */
final class FrontendViewContractsTest extends TestCase
{
    public function testAllFrontendViewContractsPass(): void
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
            'FrontendHeadBaseHref::testPublicHeadDeclaresRuntimeFrontendUrlBaseHref' => function () {
            $this->assert_FrontendHeadBaseHref_testPublicHeadDeclaresRuntimeFrontendUrlBaseHref();
        },
            'FrontendRuntimeThemeVersion::testHeaderRuntimeInjectsThemePublishedVersionFields' => function () {
            $this->assert_FrontendRuntimeThemeVersion_testHeaderRuntimeInjectsThemePublishedVersionFields();
        },
            'FrontendRuntimeThemeVersion::testHeaderProdDeployVersionRejectsStickyDev' => function () {
            $this->assert_FrontendRuntimeThemeVersion_testHeaderProdDeployVersionRejectsStickyDev();
        },
            'GuestStorefrontFpc::testFrontendHeaderGatesSessionAndCsrfOnExistingCookie' => function () {
            $this->assert_GuestStorefrontFpc_testFrontendHeaderGatesSessionAndCsrfOnExistingCookie();
        },
            'GuestStorefrontFpc::testDeliveryWidgetUsesReadOnlyGuestShellWithoutCookie' => function () {
            $this->assert_GuestStorefrontFpc_testDeliveryWidgetUsesReadOnlyGuestShellWithoutCookie();
        },
            'GuestStorefrontFpc::testQuickAddHookDoesNotStartSessionForAnonymousShell' => function () {
            $this->assert_GuestStorefrontFpc_testQuickAddHookDoesNotStartSessionForAnonymousShell();
        },
            'StorefrontGenerationCookieSync::testCheckStorefrontGenerationIdentitySyncsCookieWithoutReloadPrompt' => function () {
            $this->assert_StorefrontGenerationCookieSync_testCheckStorefrontGenerationIdentitySyncsCookieWithoutReloadPrompt();
        },
            'WelineApiBackendAclDeniedUi::testFrontendApiSplitsAclDeniedUiByEnvironment' => function () {
            $this->assert_WelineApiBackendAclDeniedUi_testFrontendApiSplitsAclDeniedUiByEnvironment();
        },
            'WelineApiErrorPresentation::testWorkerAttachesStructuredProtocolErrorDetails' => function () {
            $this->assert_WelineApiErrorPresentation_testWorkerAttachesStructuredProtocolErrorDetails();
        },
            'WelineApiErrorPresentation::testWorkerRetriesTransientGatewayFailuresWithoutResettingSession' => function () {
            $this->assert_WelineApiErrorPresentation_testWorkerRetriesTransientGatewayFailuresWithoutResettingSession();
        },
            'WelineApiErrorPresentation::testApiPresentsFriendlyToastWithExpandableDetails' => function () {
            $this->assert_WelineApiErrorPresentation_testApiPresentsFriendlyToastWithExpandableDetails();
        },
            'WelineApiErrorPresentation::testApiSilentTransientFailuresDoNotFanOutOrResetWorker' => function () {
            $this->assert_WelineApiErrorPresentation_testApiSilentTransientFailuresDoNotFanOutOrResetWorker();
        },
            'WelineApiErrorPresentation::testApiScopeConflictCodesAutoReloadOnce' => function () {
            $this->assert_WelineApiErrorPresentation_testApiScopeConflictCodesAutoReloadOnce();
        },
            'WelineApiErrorPresentation::testWorkerReusesSessionByScopeCanonicalKey' => function () {
            $this->assert_WelineApiErrorPresentation_testWorkerReusesSessionByScopeCanonicalKey();
        },
            'WelineApiErrorPresentation::testApiWaitsForScopeBootstrapWithSoftPleaseWaitTip' => function () {
            $this->assert_WelineApiErrorPresentation_testApiWaitsForScopeBootstrapWithSoftPleaseWaitTip();
        },
            'WelineApiErrorPresentation::testWelineJsPreservesFullApiAcrossInit' => function () {
            $this->assert_WelineApiErrorPresentation_testWelineJsPreservesFullApiAcrossInit();
        },
            'WelineApiNoCartCoupling::testWelineApiJsHasNoCartStatusKeysOrMarkCart' => function () {
            $this->assert_WelineApiNoCartCoupling_testWelineApiJsHasNoCartStatusKeysOrMarkCart();
        },
            'WelineApiNoCartCoupling::testFrontendRuntimeApiConfigOmitsCartCookieKey' => function () {
            $this->assert_WelineApiNoCartCoupling_testFrontendRuntimeApiConfigOmitsCartCookieKey();
        },
            'WelineApiPathLocale::testQueryBinClientResolvesLocaleFromPathNotCookie' => function () {
            $this->assert_WelineApiPathLocale_testQueryBinClientResolvesLocaleFromPathNotCookie();
        },
            'WelineApiWorkerBinaryLimits::testWorkerMapKeyLimitMatchesFrameworkBinaryLimits' => function () {
            $this->assert_WelineApiWorkerBinaryLimits_testWorkerMapKeyLimitMatchesFrameworkBinaryLimits();
        },
            'WelineApiWorkerCacheMarker::testWorkerBuildsWqCacheMarkerFromCatalog' => function () {
            $this->assert_WelineApiWorkerCacheMarker_testWorkerBuildsWqCacheMarkerFromCatalog();
        },
            'WelineApiWorkerCacheMarker::testHeaderBaseInjectsCacheCatalogIntoApiConfig' => function () {
            $this->assert_WelineApiWorkerCacheMarker_testHeaderBaseInjectsCacheCatalogIntoApiConfig();
        },
            'WelineApiWorkerNonce::testWorkerSerializesSignedRequests' => function () {
            $this->assert_WelineApiWorkerNonce_testWorkerSerializesSignedRequests();
        },
            'WelineApiWorkerNonce::testApiSuppressesNonceReplayToast' => function () {
            $this->assert_WelineApiWorkerNonce_testApiSuppressesNonceReplayToast();
        },
            'WelineApiWorkerNonce::testWorkerWaitsHandshakeCooldownInsteadOfSyntheticThrow' => function () {
            $this->assert_WelineApiWorkerNonce_testWorkerWaitsHandshakeCooldownInsteadOfSyntheticThrow();
        },
            'WelineApiWorkerNonce::testWorkerRejectsQueryBinRedirectsAndSurfacesNonBinaryHeads' => function () {
            $this->assert_WelineApiWorkerNonce_testWorkerRejectsQueryBinRedirectsAndSurfacesNonBinaryHeads();
        },
            'WelineApiWorkerNonce::testWorkerDetectMaintenanceRequiresExplicitSignal' => function () {
            $this->assert_WelineApiWorkerNonce_testWorkerDetectMaintenanceRequiresExplicitSignal();
        },
            'WelineApiWorkerNonce::testApiHandleHttpErrorDoesNotTreatBare503AsMaintenance' => function () {
            $this->assert_WelineApiWorkerNonce_testApiHandleHttpErrorDoesNotTreatBare503AsMaintenance();
        },
            'WelineApiWorkerResponseCache::testWorkerDeclaresTtlCacheAndInflightDedupe' => function () {
            $this->assert_WelineApiWorkerResponseCache_testWorkerDeclaresTtlCacheAndInflightDedupe();
        },
            'WelineApiWorkerResponseCache::testWorkerClearsLocalCacheWhenDeployVersionChanges' => function () {
            $this->assert_WelineApiWorkerResponseCache_testWorkerClearsLocalCacheWhenDeployVersionChanges();
        },
            'WelineApiWorkerResponseCache::testWorkerInvalidatesConsentStatusAfterAccept' => function () {
            $this->assert_WelineApiWorkerResponseCache_testWorkerInvalidatesConsentStatusAfterAccept();
        },
            'WelineApiWorkerResponseCache::testDevConsoleSurfacesLocalCacheFlag' => function () {
            $this->assert_WelineApiWorkerResponseCache_testDevConsoleSurfacesLocalCacheFlag();
        },
            'WelineAttributeModuleLoad::testModulesLoadDeferByDefaultWithoutBusinessModuleNames' => function () {
            $this->assert_WelineAttributeModuleLoad_testModulesLoadDeferByDefaultWithoutBusinessModuleNames();
        },
            'WelineAttributeModuleLoad::testObserveMutationsCoalescedUsesTrailingIdleNotDoubleRaf' => function () {
            $this->assert_WelineAttributeModuleLoad_testObserveMutationsCoalescedUsesTrailingIdleNotDoubleRaf();
        },
            'WelineAttributeModuleLoad::testSecondaryModulesBootOnDomContentLoadedNotWindowLoad' => function () {
            $this->assert_WelineAttributeModuleLoad_testSecondaryModulesBootOnDomContentLoadedNotWindowLoad();
        },
            'WelineAttributeModuleLoad::testGlobalVarFallbackIsFrameworkCoreOnly' => function () {
            $this->assert_WelineAttributeModuleLoad_testGlobalVarFallbackIsFrameworkCoreOnly();
        },
            'WelineAttributeModuleLoad::testModuleStaticPathKeepsQueryOutsideFilePath' => function () {
            $this->assert_WelineAttributeModuleLoad_testModuleStaticPathKeepsQueryOutsideFilePath();
        },
            'WelineAttributeModuleLoad::testWorkerScriptFetchHasTimeout' => function () {
            $this->assert_WelineAttributeModuleLoad_testWorkerScriptFetchHasTimeout();
        },
            'WelineDomMutationBus::testDomObserveBusIsExportedAndSharedByFingerprint' => function () {
            $this->assert_WelineDomMutationBus_testDomObserveBusIsExportedAndSharedByFingerprint();
        },
            'WelineDomMutationBus::testVueStyleMaxFlushDepthAndTakeRecords' => function () {
            $this->assert_WelineDomMutationBus_testVueStyleMaxFlushDepthAndTakeRecords();
        },
            'WelineDomMutationBus::testCriticalSurfacesPreferWelineDomObserve' => function () {
            $this->assert_WelineDomMutationBus_testCriticalSurfacesPreferWelineDomObserve();
        },
            'WelineDomMutationBus::testFirstPartySurfacesForbidRawDocumentMutationObserverOutsideFallback' => function () {
            $this->assert_WelineDomMutationBus_testFirstPartySurfacesForbidRawDocumentMutationObserverOutsideFallback();
        },
            'WelineMountOrchestrator::testWelineJsExposesMountOrchestratorWithoutBusinessNames' => function () {
            $this->assert_WelineMountOrchestrator_testWelineJsExposesMountOrchestratorWithoutBusinessNames();
        },
            'WelineMountOrchestrator::testMountOrchestratorIsLazyUntilPageDeclaresHosts' => function () {
            $this->assert_WelineMountOrchestrator_testMountOrchestratorIsLazyUntilPageDeclaresHosts();
        },
            'WelineMountOrchestrator::testScanSecondPassDoesNotForceRemount' => function () {
            $this->assert_WelineMountOrchestrator_testScanSecondPassDoesNotForceRemount();
        },
        ];
    }

private function assert_FrontendHeadBaseHref_testPublicHeadDeclaresRuntimeFrontendUrlBaseHref(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/templates/public/head.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("getFrontendUrl('/')", $source);
    self::assertStringContainsString('<base href="<?=', $source);
    self::assertDoesNotMatchRegularExpression('/<base\s+href="@url\{\'\/\'\}"/', $source, 'document base must not compile-bake @url root');
    // Taglib still rewrites URL shorthand inside // comments and can leak PHP into HTML.
    self::assertStringNotContainsString("@url{'/'}", $source, 'head.phtml must not contain @url root even in comments');
    self::assertStringNotContainsString('url-of-root', $source, 'head.phtml must not spell the Taglib root-url token even in comments');
    self::assertStringNotContainsString('http://', $source, 'head.phtml must not contain http:// even in comments (?> leak risk after rewrite)');
    self::assertStringContainsString("preg_replace('/[?#].*$/'", $source, 'document base must strip query/fragment from getFrontendUrl root');
    self::assertStringContainsString('toDocumentRootRelative', $source, 'document base must strip Origin to root-relative path');
    self::assertLessThan((int) strpos($source, '<meta charset="UTF-8">'), (int) strpos($source, '<base href="<?='), 'base must precede charset so relative resolution is established early');
}

private function assert_FrontendRuntimeThemeVersion_testHeaderRuntimeInjectsThemePublishedVersionFields(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/blocks/header/base.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('themePublishedVersionId', $src);
    self::assertStringContainsString('themePublishedVersion', $src);
    self::assertStringContainsString('deployVersion', $src);
    // api subtree must mirror theme stamps (same pattern as deployVersion) for Worker handshake.
    self::assertMatchesRegularExpression("/'api'\\s*=>\\s*\\[[\\s\\S]*?'themePublishedVersionId'\\s*=>\\s*\\\$themePublishedVersionId/", $src);
    self::assertMatchesRegularExpression("/'api'\\s*=>\\s*\\[[\\s\\S]*?'themePublishedVersion'\\s*=>\\s*\\\$themePublishedVersion/", $src);
    self::assertStringContainsString('ThemePublishedVersionRuntimeResolver', $src);
    self::assertStringContainsString('weline-api.js', $src);
    self::assertStringContainsString('account-session.js', $src);
    self::assertStringContainsString('Customer/view/statics/js/account-session.js', $src);
    self::assertStringContainsString('weline.js', $src);
    self::assertStringContainsString('welineJsMtime', $src);
    self::assertStringContainsString('welineJsSrc', $src);
    self::assertStringContainsString('mini-cart-icon.js', $src);
    self::assertStringContainsString('mini-cart-drawer.css', $src);
    self::assertStringContainsString('frontendJsMtime', $src);
    self::assertStringContainsString('frontendAssetVersion', $src);
    self::assertStringContainsString("'assetVersion' => \$frontendAssetVersion", $src);
    // Soft Scope wait tip seeded under api (server __(), not page i18n bag).
    self::assertStringContainsString("'softTips'", $src);
    self::assertStringContainsString('scope_bootstrap_waiting', $src);
    self::assertStringContainsString('FrontendScopeReloadTip::build', $src);
    // query-bin must be document-root-relative so Weline.Api same-origin matches workerUrl.
    self::assertStringContainsString('toDocumentRootRelative', $src);
    self::assertStringContainsString("getFrontendApiUrl('framework/query-bin'", $src);
}

/**
 * E1：PROD 下 deployVersion 不得粘滞字面 `dev`（与 current.json 对齐；缺/粘滞时文档化守卫 `prod`）。
 */
private function assert_FrontendRuntimeThemeVersion_testHeaderProdDeployVersionRejectsStickyDev(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/blocks/header/base.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("VAR_DIR . 'deploy/current.json'", $src);
    self::assertStringContainsString("\$deployMeta['deploy_version']", $src);
    self::assertStringContainsString('PROD 禁止沿用粘滞的 deploy_version=dev', $src);
    self::assertStringContainsString("strcasecmp(\$deployVersion, 'dev') === 0", $src);
    self::assertStringContainsString("\$deployVersion = 'prod';", $src);
    self::assertStringContainsString("'/static/Weline/Frontend/base/weline.modules.js'", $src);
}

private function assert_GuestStorefrontFpc_testFrontendHeaderGatesSessionAndCsrfOnExistingCookie(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/blocks/header/base.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('SessionCookieNameResolver::hasRequestCookie()', $source);
    self::assertMatchesRegularExpression('/\$hasSessionCookie\s*=.*?if\s*\(\$hasSessionCookie\).*?SessionFactory::frontend\(\)/s', $source);
    self::assertMatchesRegularExpression('/if\s*\(\$hasSessionCookie\).*?Token::create\(\'csrf\'/s', $source);
}

private function assert_GuestStorefrontFpc_testDeliveryWidgetUsesReadOnlyGuestShellWithoutCookie(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Checkout/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('SessionCookieNameResolver::hasRequestCookie()', $source);
    self::assertMatchesRegularExpression('/if\s*\(\$hasSessionCookie\).*?getContext\(\)/s', $source);
    self::assertStringNotContainsString('$isEditorMode', $source, 'delivery widget must not use editor_mode stub (preview_storefront_delivery_parity)');
}

private function assert_GuestStorefrontFpc_testQuickAddHookDoesNotStartSessionForAnonymousShell(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Shipping/view/hooks/Weline_Checkout/frontend/widgets/checkout-delivery-context/quick-add.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('SessionCookieNameResolver::hasRequestCookie()', $source);
    self::assertMatchesRegularExpression('/if\s*\(\$hasSessionCookie\).*?createSession\(\).*?sessionCountry/s', $source);
    self::assertStringContainsString('csrf="off"', $source);
}

private function assert_StorefrontGenerationCookieSync_testCheckStorefrontGenerationIdentitySyncsCookieWithoutReloadPrompt(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('function syncStorefrontGenerationCookieToPage', $src);
    self::assertStringContainsString('function checkStorefrontGenerationIdentity', $src);
    self::assertStringContainsString('syncStorefrontGenerationCookieToPage(pageId)', $src);
    // Boot-time cookie≠page must not call prompt (reload loop on html-disk HIT).
    self::assertMatchesRegularExpression('/function checkStorefrontGenerationIdentity\(\)\s*\{[\s\S]*?syncStorefrontGenerationCookieToPage\(pageId\);[\s\S]*?return true;[\s\S]*?\}/', $src);
    $checkBody = '';
    if (\preg_match('/function checkStorefrontGenerationIdentity\(\)\s*\{([\s\S]*?)\n    \}/', $src, $m)) {
        $checkBody = $m[1];
    }
    self::assertNotSame('', $checkBody);
    self::assertStringNotContainsString('promptStorefrontGenerationRefresh', $checkBody);
    self::assertStringContainsString('promptStorefrontGenerationRefresh', $src);
}

private function assert_WelineApiBackendAclDeniedUi_testFrontendApiSplitsAclDeniedUiByEnvironment(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js');
    self::assertStringContainsString("backend_acl_denied", $source);
    self::assertStringContainsString('FrontendWorkerBackendAuthorizationException', $source);
    self::assertStringContainsString('isBackendAclDeniedError', $source);
    self::assertStringContainsString('showBackendAclDeniedUi', $source);
    self::assertStringContainsString('dialog.alert', $source);
    self::assertStringContainsString('isDevMode()', $source);
    self::assertStringContainsString('toast.error', $source);
    self::assertStringContainsString('_welineBackendAclUiShown', $source);
    self::assertMatchesRegularExpression('/if\s*\(\s*isBackendAclDeniedError\(error\)\s*\)\s*\{[\s\S]*?showBackendAclDeniedUi\(error\)/', $source);
    self::assertMatchesRegularExpression('/if\s*\(\s*isDevMode\(\)\s*\)\s*\{[\s\S]*?dialog\.alert/', $source);
}

private function assert_WelineApiErrorPresentation_testWorkerAttachesStructuredProtocolErrorDetails(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString("code = 'origin_unreachable'", $script);
    self::assertStringContainsString("code = 'unexpected_html'", $script);
    self::assertStringContainsString("code = 'wqb_invalid_magic'", $script);
    self::assertStringContainsString('details,', $script);
    self::assertStringContainsString('responseKind: desc.kind', $script);
    self::assertStringContainsString("details: error && error.details ? String(error.details) : ''", $script);
}

private function assert_WelineApiErrorPresentation_testWorkerRetriesTransientGatewayFailuresWithoutResettingSession(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('function isTransientGatewayResult(result)', $script);
    self::assertStringContainsString('gatewayAttempt < 2', $script);
    self::assertStringContainsString("code: 'service_unavailable'", $script);
    self::assertStringContainsString('noteHandshakeSoftBackoff()', $script);
    self::assertStringContainsString('response.status === 502 || response.status === 503 || response.status === 504', $script);
}

private function assert_WelineApiErrorPresentation_testApiPresentsFriendlyToastWithExpandableDetails(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('resolveErrorPresentation', $script);
    self::assertStringContainsString('origin_unreachable', $script);
    self::assertStringContainsString("i18nText('源站暂时不可用')", $script);
    self::assertStringContainsString("i18nText('查看详情')", $script);
    self::assertStringContainsString('toastOptions', $script);
    self::assertStringContainsString('details: presentation.details', $script);
    self::assertStringContainsString('error.details = serverError.details', $script);
    // Business failures on HTTP 200 must not stamp "(HTTP 200)" / "code:200".
    self::assertStringContainsString('const transportFailed = status >= 400;', $script);
    self::assertStringContainsString('transportFailed ? `${code}:${status}` : code', $script);
}

private function assert_WelineApiErrorPresentation_testApiSilentTransientFailuresDoNotFanOutOrResetWorker(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('isTransientServiceError(error)', $script);
    self::assertStringContainsString('meta && meta.silent', $script);
    self::assertStringContainsString('silentStatus === 502', $script);
    self::assertStringContainsString('without resetting the Worker', $script);
}

private function assert_WelineApiErrorPresentation_testApiScopeConflictCodesAutoReloadOnce(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('request_scope_already_conflicts', $script);
    self::assertStringContainsString('scope_context_conflict', $script);
    self::assertStringContainsString('scope_reload_required', $script);
    self::assertStringContainsString('scope_binding_required', $script);
    self::assertStringContainsString('deploy_reload_required', $script);
    self::assertStringContainsString("i18nText('站点已更新')", $script);
    self::assertStringContainsString("i18nText('商城范围未就绪')", $script);
    self::assertStringContainsString('autoReload: true', $script);
    self::assertStringContainsString('maybeAutoReloadForScopeConflict', $script);
    self::assertStringContainsString('promptStorefrontGenerationRefresh', $script);
    self::assertStringNotContainsString('window.confirm(', $script);
    $welineJs = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('promptStorefrontGenerationRefresh', $welineJs);
    self::assertStringNotContainsString('window.confirm(', $welineJs);
    self::assertStringContainsString('weline.api.scope_reload_at', $script);
    self::assertStringContainsString('weline.api.scope_tips_by_locale', $script);
    self::assertStringContainsString('rememberScopeTipsFromPayload', $script);
    self::assertStringContainsString('readScopeTipCache', $script);
    self::assertStringContainsString('writeScopeTipCache', $script);
    self::assertStringContainsString('error.tip = normalizeScopeTip(serverError.tip)', $script);
    self::assertStringContainsString('window.location.reload()', $script);
    // autoReload must run before silent / onHttpError short-circuit.
    self::assertStringContainsString('presentation.autoReload && maybeAutoReloadForScopeConflict(presentation)', $script);
    // Main-thread client must adopt Scope ids on channel/store navigations.
    self::assertStringContainsString('client.config.website_id = freshConfig.website_id;', $script);
    self::assertStringContainsString('client.config.store_id = freshConfig.store_id;', $script);
    self::assertStringContainsString('client.config.channel_id = freshConfig.channel_id;', $script);
    self::assertStringNotContainsString("error.code = 'scope_context_conflict';\n            throw error;", $script);
    // Stamp expiry recovers in-page (re-punch + retry); only identity/theme drift reloads.
    self::assertStringContainsString('isScopeStampRecoverableError', $script);
    self::assertStringContainsString('recoverScopeStamp', $script);
    self::assertStringContainsString('__scopeStampRetry', $script);
    self::assertStringContainsString('forceHandshake: forceHandshake', $script);
    self::assertStringContainsString("i18nText('当前页面商城范围已过期，正在重新签章以继续。')", $script);
    self::assertStringContainsString("i18nText('页面绑定信息缺失或已失效，正在重新签章以继续。')", $script);
    self::assertStringNotContainsString("message: i18nText('当前页面商城范围已过期，正在刷新以继续。'),\n                autoReload: true,", $script);
    self::assertStringNotContainsString("message: i18nText('页面绑定信息缺失或已失效，正在刷新以继续。'),\n                autoReload: true,", $script);
}

private function assert_WelineApiErrorPresentation_testWorkerReusesSessionByScopeCanonicalKey(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('SharedWorker survives full navigations', $script);
    self::assertStringContainsString('sessionsByScopeKey', $script);
    self::assertStringContainsString('function scopeSessionIdentityKey(config)', $script);
    self::assertStringContainsString('buildScopeCanonicalFromIds', $script);
    self::assertStringContainsString('Must NOT use Host+path', $script);
    // Stamp recover forces SharedWorker to drop badge + re-handshake after Cookie re-punch.
    self::assertStringContainsString('forceHandshake', $script);
    self::assertStringContainsString('async function ensureSession(config, options = {})', $script);
    self::assertStringContainsString("code === 'scope_binding_required'", $script);
    // Storefront handshake must not send one-shot opaque bootstrap id.
    self::assertStringContainsString('never send scope_bootstrap_id', $script);
    self::assertStringNotContainsString('handshakePayload.scope_bootstrap_id', $script);
    self::assertStringNotContainsString('sessionsByUrlKey', $script);
    self::assertStringNotContainsString("throw Object.assign(new Error('The page Worker bootstrap changed while this worker was active.')", $script);
}

private function assert_WelineApiErrorPresentation_testApiWaitsForScopeBootstrapWithSoftPleaseWaitTip(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('notifyScopeBootstrapWaiting', $script);
    self::assertStringContainsString('renderScopeWaitTip', $script);
    self::assertStringContainsString('resolveScopeBootstrapWaitingMessage', $script);
    self::assertStringContainsString('softTips', $script);
    self::assertStringContainsString('scope_bootstrap_waiting', $script);
    self::assertStringContainsString('Please wait a moment...', $script);
    self::assertStringContainsString("SCOPE_WAIT_TIP_SOURCE = '请稍等片刻...'", $script);
    // Soft tip: Toast success ⇒ stop; fallback only when shown is false (no Theme DOM probe).
    self::assertStringContainsString('if (!shown) {', $script);
    self::assertStringContainsString('this.renderScopeWaitTip(message);', $script);
    self::assertStringNotContainsString("[data-theme-notice-region] .weline-notice-toast", $script);
    self::assertStringContainsString('SCOPE_HOLE_PUNCH_TIMEOUT_MS', $script);
    self::assertStringContainsString("timedOut.code = 'scope_bootstrap_timeout'", $script);
    self::assertStringContainsString('scope_bootstrap_timeout:', $script);
    // Tip waits for first long-visa ensure — not opaque bootstrap punching.
    self::assertStringContainsString('Not "still punching opaque bootstrap"', $script);
    self::assertStringContainsString('isScopeStampAlreadyEnsured', $script);
    self::assertStringContainsString('SCOPE_STAMP_MEMORY_KEY', $script);
    self::assertStringContainsString('Authorized long Scope Cookie path', $script);
    self::assertStringContainsString('const ensureScopeStamp = (ids) => {', $script);
    // Cross-page: ensure must honor sessionStorage before fetch (not page-memory only).
    self::assertStringContainsString('if (isScopeStampAlreadyEnsured(ids)) {', $script);
    self::assertStringContainsString('rememberScopeStampKey(stampKey, expiresAt)', $script);
    self::assertStringContainsString('SCOPE_STAMP_SKEW_MS', $script);
    self::assertStringContainsString('isScopeStampFresh', $script);
    self::assertStringContainsString('scheduleScopeStampRenewal', $script);
    self::assertStringContainsString('bindScopeStampVisibilityRenew', $script);
    self::assertStringContainsString('scopeStampEnsuredExpiresAt', $script);
    // 204 / rollout-off: remember tab decision (not a forged Cookie soft TTL).
    self::assertStringContainsString('no_stamp: true', $script);
    self::assertStringContainsString('rememberNoStampDecision', $script);
    self::assertStringContainsString('scopeStampNoStampEnsured', $script);
    self::assertStringContainsString('noStamp: true', $script);
    self::assertStringNotContainsString('SCOPE_STAMP_SOFT_TTL_SEC', $script);
    // Must not clear memory on 204 success (that caused every-page re-POST).
    self::assertStringNotContainsString("if (payload && payload.no_stamp === true) {\n                forgetScopeStampKey();", $script);
    self::assertStringContainsString('isScopeBindingMissingError', $script);
    // Binding miss must drop optimistic memory (Reload otherwise loops forever).
    self::assertStringContainsString('forgetScopeStampKey();', $script);
    self::assertStringContainsString('Drop optimistic stamp memory before reload', $script);
    self::assertStringContainsString('allowRepunch && isScopeStampRecoverableError(error)', $script);
    self::assertStringContainsString('warmup({ forceHandshake: true })', $script);
    self::assertStringContainsString('shouldDeferScopeStampErrorUi', $script);
    // Intermediate stamp 401 must not paint DevConsole BinQuery ERR while recover retries.
    self::assertStringContainsString('recovering: deferStampUi', $script);
    self::assertStringContainsString('recovering,', $script);
    $devConsole = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-dev-console.js');
    self::assertStringContainsString("meta.recovering === true", $devConsole);
    self::assertStringContainsString('re-signing scope', $devConsole);
    self::assertStringContainsString('Success does not require bootstrap_id', $script);
    self::assertStringNotContainsString('SCOPE_BOOTSTRAP_OPAQUE', $script);
    self::assertStringNotContainsString('readScopeBootstrapId', $script);
    // Non-bootstrap QueryBin queues behind handshake-only warmup; tip only on Worker demand.
    self::assertStringContainsString('notifyScopeBootstrapWaiting()', $script);
    self::assertStringContainsString('return this.warmup().then(() => this.sendToWorker(payload));', $script);
    self::assertStringContainsString('Worker ensureSession demand', $script);
    self::assertStringContainsString('Ordinary path: Worker handshake only', $script);
    // Must NOT eager bootstrapScope / hole-punch on script load.
    self::assertStringNotContainsString('ApiModule.bootstrapScope().catch((error) => {', $script);
    self::assertStringNotContainsString("if (readScopeBootstrapId() !== '') {\n            ApiModule.bootstrapScope()", $script);
    self::assertStringContainsString('bindScopeStampVisibilityRenew();', $script);
    // Full module must always promote onto Weline.Api (stub must not stick).
    self::assertStringContainsString('window.Weline.Api = ApiModule;', $script);
    self::assertStringContainsString('Always promote — stub must not remain if full module loaded', $script);
}

private function assert_WelineApiErrorPresentation_testWelineJsPreservesFullApiAcrossInit(): void
{
    $script = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('window.Weline.Api.__full === true', $script);
    self::assertStringContainsString('_promoteFullApi', $script);
    self::assertStringContainsString('bootstrapScope: async () => {', $script);
    self::assertStringContainsString('never leave a stub on top of an already-full WelineApiModule', $script);
    // Prefetch API module only — must not call bootstrapScope on DOM ready.
    self::assertStringContainsString('preLoadApiModule', $script);
    self::assertStringContainsString("Weline.preLoad('api')", $script);
    self::assertStringNotContainsString('apiModule.bootstrapScope()', $script);
    self::assertStringNotContainsString('preLoadScopeBootstrapApi', $script);
}

private function assert_WelineApiNoCartCoupling_testWelineApiJsHasNoCartStatusKeysOrMarkCart(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringNotContainsString('cartFlagStorageKey', $js);
    self::assertStringNotContainsString('cartProbeSessionKey', $js);
    self::assertStringNotContainsString('cartCountCookieKey', $js);
    self::assertStringNotContainsString('autoEnableOnCartClickSelector', $js);
    self::assertStringNotContainsString('weline_cart_has_items', $js);
    self::assertStringNotContainsString('weline_cart_item_count', $js);
    self::assertStringNotContainsString('restoreCartState', $js);
    self::assertStringNotContainsString('markCartActive', $js);
    self::assertStringNotContainsString('markCartEmpty', $js);
    self::assertStringNotContainsString('listenCartTriggers', $js);
    self::assertStringContainsString('enableAutoRequests', $js);
    self::assertStringContainsString('weline:api:auto-enabled', $js);
}

private function assert_WelineApiNoCartCoupling_testFrontendRuntimeApiConfigOmitsCartCookieKey(): void
{
    $phtml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/blocks/header/base.phtml');
    self::assertStringNotContainsString('cartCountCookieKey', $phtml);
}

private function assert_WelineApiPathLocale_testQueryBinClientResolvesLocaleFromPathNotCookie(): void
{
    $api = (string) \file_get_contents(\BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js');
    $worker = (string) \file_get_contents(\BP . 'app/code/Weline/Frontend/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('Path/query language: never read WELINE_USER_LANG cookie', $api);
    self::assertStringContainsString('detectPathLanguage', $api);
    self::assertStringContainsString('readQueryLanguage', $api);
    self::assertStringContainsString('readDocumentLanguage', $api);
    self::assertStringContainsString('resolveCurrentLanguage', $api);
    self::assertStringContainsString('detectPathCurrency', $api);
    self::assertStringContainsString('readQueryCurrency', $api);
    self::assertStringContainsString('pathname:', $api);
    self::assertStringContainsString('detectPathLanguage(config.pathname', $worker);
    self::assertStringNotContainsString("document.cookie.match('WELINE_USER_LANG", $api);
}

private function assert_WelineApiWorkerBinaryLimits_testWorkerMapKeyLimitMatchesFrameworkBinaryLimits(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertMatchesRegularExpression('/const MAX_MAP_KEYS = ' . Limits::MAP_KEYS . ';/', $script);
    self::assertStringContainsString('Map exceeds ' . Limits::MAP_KEYS . ' key limit.', $script);
    self::assertMatchesRegularExpression('/const MAX_LIST_ITEMS = ' . Limits::LIST_ITEMS . ';/', $script);
}

private function assert_WelineApiWorkerCacheMarker_testWorkerBuildsWqCacheMarkerFromCatalog(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('async function buildCacheMarker', $source);
    self::assertStringContainsString('async function withCacheMarkerUrl', $source);
    self::assertStringContainsString("__wq_cache", $source);
    self::assertStringContainsString('cacheCatalog', $source);
    self::assertStringContainsString('website_id', $source);
    self::assertStringContainsString('withCacheMarkerUrl(', $source);
}

private function assert_WelineApiWorkerCacheMarker_testHeaderBaseInjectsCacheCatalogIntoApiConfig(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/blocks/header/base.phtml');
    self::assertStringContainsString('BinQueryCacheCatalogExporter', $source);
    self::assertStringContainsString("'cacheCatalog'", $source);
    self::assertStringContainsString("'website_id'", $source);
}

private function assert_WelineApiWorkerNonce_testWorkerSerializesSignedRequests(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('signedRequestChain', $script);
    self::assertStringContainsString('signedTelemetryChain', $script);
    self::assertStringContainsString('TELEMETRY_CAPABILITIES', $script);
    self::assertStringContainsString("'visitor.trackPixel': true", $script);
    self::assertStringContainsString('isTelemetryCapability', $script);
    self::assertStringContainsString("lane === 'telemetry'", $script);
    self::assertStringContainsString('const sessionSnapshot = peekSession(config);', $script);
    self::assertStringContainsString('peekSession(config) === sessionSnapshot', $script);
    self::assertStringContainsString('enqueueSignedRequest', $script);
    self::assertStringContainsString('await ensureSession(config);', $script);
    self::assertStringContainsString('Weline worker session is unavailable.', $script);
    self::assertStringContainsString("cache: 'no-store'", $script);
    self::assertStringContainsString('dispatchCachedOrNetwork', $script);
    self::assertStringContainsString('function isNonceReuse', $script);
    self::assertStringContainsString('if (isNonceReuse(status, body))', $script);
    self::assertStringContainsString('if (!result.responseOk && isNonceReuse(result.status, result.body))', $script);
}

private function assert_WelineApiWorkerNonce_testApiSuppressesNonceReplayToast(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('nonce has already been used', $script);
    self::assertStringContainsString('worker session is unavailable', $script);
    self::assertStringContainsString('isWorkerHandshakeCooldownNoise', $script);
    self::assertStringContainsString('shouldDedupeDefaultErrorToast', $script);
    self::assertStringContainsString('cooling down after capacity', $script);
    self::assertStringContainsString('createDedicatedWorkerFromScriptUrl', $script);
    self::assertStringContainsString('wrapQueryBinWorkerTransport', $script);
    self::assertStringContainsString('new SharedWorker(scriptUrl', $script);
    self::assertStringContainsString('inflightWorkerScriptBoots', $script);
    self::assertStringContainsString('URL.createObjectURL', $script);
    self::assertStringContainsString('new Worker(blobUrl)', $script);
    self::assertStringContainsString('createUrlWorker', $script);
    self::assertStringContainsString('createBlobWorker', $script);
    self::assertStringContainsString('createSharedWorker', $script);
    self::assertStringContainsString("['shared', createSharedWorker],\n                ['blob', createBlobWorker],\n                ['url', createUrlWorker],", $script);
    self::assertStringContainsString('No QueryBin worker transport booted', $script);
    self::assertStringContainsString('awaitQueryBinTransportReady', $script);
    self::assertStringContainsString("const QUERY_BIN_TRANSPORT_PING = 'transport-ping'", $script);
    self::assertStringContainsString("const QUERY_BIN_TRANSPORT_READY = 'transport-ready'", $script);
    self::assertStringNotContainsString('setTimeout(finishOk', $script);
    $worker = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString("if (message.type === 'transport-ping')", $worker);
    self::assertStringContainsString("port.postMessage({ id, type: 'transport-ready' });", $worker);
    self::assertLessThan(
        strpos($worker, 'normalizeConfig(message.config'),
        strpos($worker, "if (message.type === 'transport-ping')"),
        'transport-ping must be answered before config validation'
    );
    self::assertStringContainsString('recoverWorkerAfterTimeout', $script);
    self::assertStringContainsString('workerRecoverPromise', $script);
    self::assertStringContainsString('Script still downloading', $script);
    self::assertStringContainsString('abortBoot', $script);
    self::assertStringContainsString('if (!abortBoot && this.workerStartPromise && !this.worker)', $script);
}

private function assert_WelineApiWorkerNonce_testWorkerWaitsHandshakeCooldownInsteadOfSyntheticThrow(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('function sleepMs(ms)', $script);
    self::assertStringContainsString('noteHandshakeSoftBackoff', $script);
    self::assertStringContainsString('Math.min(waitMs, 4000)', $script);
    self::assertStringNotContainsString("throw Object.assign(new Error('Weline worker handshake is cooling down after capacity/auth pressure.')", $script);
}

private function assert_WelineApiWorkerNonce_testWorkerRejectsQueryBinRedirectsAndSurfacesNonBinaryHeads(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString("redirect: 'manual'", $script);
    self::assertStringContainsString('function assertBinaryFetchResponse(response)', $script);
    self::assertStringContainsString('Query-bin redirected instead of returning WQB1', $script);
    self::assertStringContainsString('function describeResponseBytes(responseBytes)', $script);
    self::assertStringContainsString('got HTML instead of WQB1', $script);
    self::assertStringContainsString('head_hex=', $script);
}

private function assert_WelineApiWorkerNonce_testWorkerDetectMaintenanceRequiresExplicitSignal(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('function detectMaintenance(status, body, headers)', $script);
    self::assertStringContainsString("headerValue(headers, 'x-weline-maintenance')", $script);
    self::assertStringContainsString("code === 'maintenance'", $script);
    self::assertStringNotContainsString('if (status === 503) return true;', $script);
    self::assertStringContainsString('tryParseJsonBytes', $script);
}

private function assert_WelineApiWorkerNonce_testApiHandleHttpErrorDoesNotTreatBare503AsMaintenance(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('Explicit maintenance only', $script);
    self::assertStringNotContainsString('|| Number(status) === 503', $script);
    self::assertStringContainsString("toLowerCase() === 'maintenance'", $script);
}

private function assert_WelineApiWorkerResponseCache_testWorkerDeclaresTtlCacheAndInflightDedupe(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('RESPONSE_CACHE_TTL_MS', $script);
    self::assertStringContainsString("'region.list': 4 * 60 * 60 * 1000", $script);
    self::assertStringContainsString("'region.country_profile': 12 * 60 * 60 * 1000", $script);
    self::assertStringContainsString("'consent.status': 60 * 60 * 1000", $script);
    self::assertStringContainsString('responseCacheInflight', $script);
    self::assertStringContainsString('dispatchCachedOrNetwork', $script);
    self::assertStringContainsString('canonicalizeResponseCacheParams', $script);
    self::assertStringContainsString('X-Weline-Worker-Response-Cache', $script);
    self::assertStringContainsString('weline-querybin-response-cache', $script);
    self::assertStringContainsString("cache: 'no-store'", $script);
}

private function assert_WelineApiWorkerResponseCache_testWorkerClearsLocalCacheWhenDeployVersionChanges(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    $api = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('activeResponseCacheDeployVersion', $script);
    self::assertStringContainsString('ensureResponseCacheDeployVersion', $script);
    self::assertStringContainsString('clearResponseCacheNotMatchingDeploy', $script);
    self::assertStringContainsString('await ensureResponseCacheDeployVersion(config)', $script);
    self::assertStringContainsString('wqrc1|', $script);
    self::assertStringContainsString('responseCacheL1.clear()', $api);
    self::assertStringContainsString("String(client.config.deployVersion || '') !== String(freshConfig.deployVersion || '')", $api);
}

private function assert_WelineApiWorkerResponseCache_testWorkerInvalidatesConsentStatusAfterAccept(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString("'consent.accept': ['consent.status']", $script);
    self::assertStringContainsString("'consent.withdraw': ['consent.status']", $script);
}

private function assert_WelineApiWorkerResponseCache_testDevConsoleSurfacesLocalCacheFlag(): void
{
    $console = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-dev-console.js');
    $api = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    $worker = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString('localCacheBadge', $console);
    self::assertStringContainsString('LOCAL ', $console);
    self::assertStringContainsString('NET live', $console);
    self::assertStringContainsString('ms wall · ', $console);
    self::assertStringContainsString('workerElapsedMs', $console);
    self::assertStringContainsString('resolveWorkerResponseCacheState', $api);
    self::assertStringContainsString('responseCacheL1', $api);
    self::assertStringContainsString("localCache: 'l1'", $api);
    self::assertStringContainsString('workerElapsedMs', $worker);
    self::assertStringContainsString("'live'", $worker);
}

private function assert_WelineAttributeModuleLoad_testModulesLoadDeferByDefaultWithoutBusinessModuleNames(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('maxConcurrentScripts = 4', $js);
    self::assertStringContainsString('modulesLoad:', $js);
    self::assertStringContainsString('deferByDefault: true', $js);
    self::assertStringContainsString('eagerModules: []', $js);
    self::assertStringContainsString('deferredModules: []', $js);
    self::assertStringContainsString('loadDeclaredDeferred: true', $js);
    self::assertStringContainsString('shouldDeferAttributeModule', $js);
    self::assertStringContainsString("modLoad === 'defer'", $js);
    self::assertStringContainsString('requestIdleCallback', $js);
    self::assertStringContainsString('空闲延迟', $js);
    self::assertStringContainsString('近屏延迟', $js);
    self::assertStringContainsString('data-weline-load-when', $js);
    self::assertStringContainsString('scheduleVisibleDeferred', $js);
    self::assertStringContainsString('IntersectionObserver', $js);
    self::assertStringContainsString('visibleRootMargin', $js);
    self::assertStringContainsString('NEVER put business module names', $js);
    self::assertStringNotContainsString('weline.cart.pending_coupon', $js);
    self::assertStringNotContainsString("preLoad('cart')", $js);
    self::assertStringContainsString('maintenanceAsyncWait', $js);
    self::assertStringContainsString('installMaintenanceHandlerLazyBridge', $js);
    self::assertStringNotContainsString('weline-maintenance-wait-modal', $js);
    self::assertStringNotContainsString('resolveMaintenanceMeta', $js);
    self::assertStringNotContainsString('card.innerHTML', $js);
    self::assertStringNotContainsString('维护补偿礼金', $js);
    self::assertStringNotContainsString('抱歉，网站正在升级维护', $js);
    self::assertStringNotContainsString('giftPanelHtml', $js);
    self::assertStringContainsString('installDevMutationObserverGuard', $js);
    self::assertStringContainsString('weline:dev:mutation-loop', $js);
    self::assertStringContainsString('MutationObserver 反馈环', $js);
    self::assertStringContainsString('delivery_storm', $js);
    self::assertStringContainsString('startMarkerDiscovery', $js);
    self::assertStringContainsString('observeMutationsCoalesced', $js);
    self::assertStringContainsString('observeDomMutations', $js);
    self::assertStringContainsString('Weline.dom = Object.assign', $js);
    self::assertStringContainsString('discoveryHandle', $js);
    self::assertStringNotContainsString('cartFlagStorageKey', $js);
}

private function assert_WelineAttributeModuleLoad_testObserveMutationsCoalescedUsesTrailingIdleNotDoubleRaf(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js') . (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/view/statics/js/dom-observer.js');
    self::assertStringContainsString('function observeMutationsCoalesced', $js);
    self::assertStringContainsString('Weline.observeMutationsCoalesced = observeMutationsCoalesced', $js);
    self::assertStringContainsString('scheduleFlushTrailing', $js);
    self::assertStringContainsString('Quiet window FIRST', $js);
    self::assertStringContainsString('window.setTimeout', $js);
    self::assertStringContainsString('requestIdleCallback', $js);
    self::assertStringContainsString('withPaused', $js);
    self::assertStringContainsString('禁止双 rAF', $js);
    self::assertStringNotContainsString('requestIdleCallback(kickFlush, { timeout: idleTimeoutMs })', $js);
    self::assertStringNotContainsString('cartFlagStorageKey', $js);
}

private function assert_WelineAttributeModuleLoad_testSecondaryModulesBootOnDomContentLoadedNotWindowLoad(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('whenDomReady', $js);
    self::assertStringContainsString("document.readyState === 'loading'", $js);
    self::assertStringContainsString("document.addEventListener('DOMContentLoaded'", $js);
    self::assertStringContainsString('whenDomReady(run)', $js);
    self::assertStringContainsString('whenDomReady(boot)', $js);
    self::assertStringContainsString('whenDomReady(preload)', $js);
    self::assertStringNotContainsString('ensureHtmlDocumentLoaded', $js);
    self::assertStringNotContainsString('await this.ensureHtmlDocumentLoaded()', $js);
    self::assertStringNotContainsString("window.addEventListener('load'", $js);
    self::assertStringNotContainsString("document.readyState === 'complete'", $js);
}

private function assert_WelineAttributeModuleLoad_testGlobalVarFallbackIsFrameworkCoreOnly(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString("'api': 'WelineApiModule'", $js);
    self::assertStringContainsString("'dom': 'WelineDomModule'", $js);
    self::assertStringNotContainsString("'account': 'WelineAccountModule'", $js);
    self::assertStringNotContainsString('Account:', $js);
    self::assertStringNotContainsString('Locale:', $js);
    self::assertStringNotContainsString('autoPreLoadModules', $js);
    self::assertStringNotContainsString('pathHeuristicModules', $js);
    self::assertStringNotContainsString('markPathHeuristic', $js);
    self::assertStringNotContainsString('isPathHeuristic', $js);
    self::assertStringNotContainsString('路径启发式', $js);
    self::assertStringNotContainsString('路径启发式预加载', $js);
    self::assertStringContainsString('已跳过（已加载/加载中）', $js);
    self::assertStringNotContainsString('persistLangPreference', $js);
    self::assertStringNotContainsString('i18n: {', $js);
    self::assertStringNotContainsString('switchLang: async (lang)', $js);
    self::assertStringContainsString('WelineI18n', $js);
    self::assertStringContainsString('Transport/microkernel aliases only', $js);
}

private function assert_WelineAttributeModuleLoad_testModuleStaticPathKeepsQueryOutsideFilePath(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('Keep ?query out of the filesystem path', $js);
    self::assertStringContainsString('querySuffix', $js);
}

private function assert_WelineAttributeModuleLoad_testWorkerScriptFetchHasTimeout(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline-api.js');
    self::assertStringContainsString('timeoutMs', $js);
    self::assertStringContainsString('AbortController', $js);
    self::assertStringContainsString('createDedicatedWorkerFromScriptUrl', $js);
}

private function welineJs_WelineDomMutationBus(): string
{
    return (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js') . (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/view/statics/js/dom-observer.js');
}

private function assert_WelineDomMutationBus_testDomObserveBusIsExportedAndSharedByFingerprint(): void
{
    $js = $this->welineJs_WelineDomMutationBus();
    self::assertStringContainsString('function observeDomMutations', $js);
    self::assertStringContainsString('domMutationChannels', $js);
    self::assertStringContainsString('Weline.dom = Object.assign', $js);
    self::assertStringContainsString('observe: observeDomMutations', $js);
    self::assertStringContainsString('documentWideTargetToken', $js);
    self::assertStringContainsString('__shared: true', $js);
    self::assertStringContainsString('Quiet window FIRST', $js);
    self::assertStringContainsString('startMarkerDiscovery', $js);
    self::assertStringContainsString('observeDomMutations({', $js);
}

private function assert_WelineDomMutationBus_testVueStyleMaxFlushDepthAndTakeRecords(): void
{
    $js = $this->welineJs_WelineDomMutationBus();
    self::assertStringContainsString('MAX_FLUSH_DEPTH = 50', $js);
    self::assertStringContainsString('MAX_FLUSHES_PER_WINDOW = 40', $js);
    self::assertStringContainsString('takeRecords', $js);
    self::assertStringContainsString('reportFeedbackLoop', $js);
    self::assertStringContainsString('max_flush_depth', $js);
    self::assertStringContainsString('flush_storm', $js);
    self::assertStringContainsString('weline:dom:mutation-loop', $js);
    self::assertStringContainsString('参考 Vue maxUpdateDepth', $js);
    self::assertStringContainsString('never observeThenDeliver', $js);
    self::assertStringContainsString('__welineBusOwned', $js);
    self::assertStringContainsString('__welineDomGateAdopted', $js);
    self::assertStringContainsString('installDocumentObserveGate', $js);
    self::assertStringContainsString('__WelineDomObserveGateInstalled', $js);
    self::assertStringContainsString('__WelineNativeMutationObserver', $js);
}

private function assert_WelineDomMutationBus_testCriticalSurfacesPreferWelineDomObserve(): void
{
    $ui = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/ui/js/weline-ui.js');
    $captcha = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Captcha/view/statics/js/captcha-lazy.js');
    $preview = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/ui/js/pages/theme-preview.js');
    $editorMode = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/js/editor-mode.js');
    $apiDom = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline-api-dom.js');
    $floatLayer = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/js/storefront-float-layer.js');
    $i18nShell = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/I18n/view/statics/js/inline-edit-shell-20261014d.js');
    self::assertStringContainsString('Weline.dom.observe', $ui);
    self::assertStringContainsString('Weline.dom.observe', $captcha);
    self::assertStringContainsString('Weline.dom.observe', $preview);
    self::assertStringContainsString('Weline.dom.observe', $editorMode);
    self::assertStringContainsString('Weline.dom.observe', $apiDom);
    self::assertStringContainsString('weline-api-dom:late-discovery', $apiDom);
    self::assertStringContainsString('Weline.dom.observe', $floatLayer);
    self::assertStringContainsString('storefront-float-layer', $floatLayer);
    self::assertStringContainsString('Weline.dom.observe', $i18nShell);
    self::assertStringContainsString('i18n-inline-edit-shell', $i18nShell);
}

/**
 * First-party storefront/runtime surfaces must not attach a raw MutationObserver
 * to document/documentElement/body except inside named *Fallback* helpers or weline.js bus.
 *
 * @return list<string>
 */
private function firstPartyPathsUnderGate_WelineDomMutationBus(): array
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline';
    return [$root . '/Theme/view/ui/js/weline-ui.js', $root . '/Theme/view/ui/js/pages/theme-preview.js', $root . '/Theme/view/statics/js/editor-mode.js', $root . '/Captcha/view/statics/js/captcha-lazy.js', $root . '/Frontend/view/statics/js/weline-api-dom.js', $root . '/Cart/view/statics/js/widgets/mini-cart-icon.js', $root . '/Product/view/statics/js/widgets/product-sticky-purchase.js', $root . '/Theme/view/statics/js/widgets/site-blocks.js', $root . '/Theme/view/statics/js/widgets/mini-cart-icon.js', $root . '/Theme/view/statics/js/storefront-float-layer.js', $root . '/Visitor/view/statics/js/pixel.js', $root . '/I18n/view/statics/js/inline-edit-shell.js', $root . '/I18n/view/statics/js/inline-edit-shell-20261014d.js', $root . '/Framework/View/Form/FormRenderer.php'];
}

/**
 * Strip ARCH_MO_FALLBACK_* regions (local quiet-window polyfills when bus missing).
 */
private function stripFallbackHelpers_WelineDomMutationBus(string $source): string
{
    $stripped = \preg_replace('/\/\*\s*ARCH_MO_FALLBACK_START\s*\*\/[\s\S]*?\/\*\s*ARCH_MO_FALLBACK_END\s*\*\//', '', $source);
    return \is_string($stripped) ? $stripped : $source;
}

private function assert_WelineDomMutationBus_testFirstPartySurfacesForbidRawDocumentMutationObserverOutsideFallback(): void
{
    $pattern = '/new\s+MutationObserver[\s\S]{0,1200}?(?:document\.documentElement|document\.body|observe\s*\(\s*document\b)/';
    $violations = [];
    foreach ($this->firstPartyPathsUnderGate_WelineDomMutationBus() as $path) {
        if (!\is_file($path)) {
            continue;
        }
        $raw = (string) \file_get_contents($path);
        $src = $this->stripFallbackHelpers_WelineDomMutationBus($raw);
        if (\preg_match($pattern, $src)) {
            $violations[] = $path;
        }
    }
    self::assertSame([], $violations, "Document-wide raw MutationObserver forbidden; use Weline.dom.observe. Violations:\n" . \implode("\n", $violations));
}

private function assert_WelineMountOrchestrator_testWelineJsExposesMountOrchestratorWithoutBusinessNames(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('Weline.mount', $js);
    self::assertStringContainsString('data-weline-mount', $js);
    self::assertStringContainsString('data-weline-mount-load', $js);
    self::assertStringContainsString('data-weline-mount-after', $js);
    self::assertStringContainsString('provide:', $js);
    self::assertStringContainsString('scan:', $js);
    self::assertStringContainsString('waitFor:', $js);
    self::assertStringContainsString('no_provider', $js);
    self::assertStringContainsString('weline:mount:scan', $js);
    self::assertStringContainsString('weline:mount:ready', $js);
    // Framework must not hard-code business surfaces / modules.
    self::assertStringNotContainsString('customer/social-quick', $js);
    self::assertStringNotContainsString("provide('account'", $js);
    self::assertStringNotContainsString("load('account')", $js);
    self::assertStringNotContainsString('WelineAccountModule', $js);
    self::assertStringNotContainsString('b2bSellingMode', $js);
}

private function assert_WelineMountOrchestrator_testMountOrchestratorIsLazyUntilPageDeclaresHosts(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('pageDeclaresMount', $js);
    self::assertStringContainsString('ensureMountCore', $js);
    self::assertStringContainsString('no_hosts', $js);
    self::assertStringContainsString("querySelector('[data-weline-mount]')", $js);
    // Auto-scan must gate on declaration presence.
    self::assertStringContainsString('if (!pageDeclaresMount(document))', $js);
}

private function assert_WelineMountOrchestrator_testScanSecondPassDoesNotForceRemount(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/view/statics/js/weline.js');
    self::assertStringContainsString('runPass(force)', $js);
    self::assertStringContainsString('runPass(false)', $js);
    self::assertStringContainsString('must not force-remount settled hosts', $js);
}
}
