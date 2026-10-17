<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * Aggregated contract suite (merged from 14 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeControllerContractsPass().
 */
final class ThemeControllerContractsTest extends TestCase
{
    public function testAllThemeControllerContractsPass(): void
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
            'LayoutPreviewStorefrontUrl::testFrontendLayoutPreviewUsesStorefrontPathNotCustomShell' => function () {
            $this->assert_LayoutPreviewStorefrontUrl_testFrontendLayoutPreviewUsesStorefrontPathNotCustomShell();
        },
            'VisualEditorShellRetired::testVisualEditorAssetsAreDeleted' => function () {
            $this->assert_VisualEditorShellRetired_testVisualEditorAssetsAreDeleted();
        },
            'VisualEditorShellRetired::testConfigEntryPointsRedirectToThemeEditor' => function () {
            $this->assert_VisualEditorShellRetired_testConfigEntryPointsRedirectToThemeEditor();
        },
            'VisualEditorShellRetired::testThemeEditorJsDroppedDeadApiWiring' => function () {
            $this->assert_VisualEditorShellRetired_testThemeEditorJsDroppedDeadApiWiring();
        },
            'ThemeEditorBackendAreaGate::testEditorShellGatesBackendOptionOnThemeDirOnly' => function () {
            $this->assert_ThemeEditorBackendAreaGate_testEditorShellGatesBackendOptionOnThemeDirOnly();
        },
            'ThemeEditorBackendAreaGate::testAreaSwitchJsKeepsThemeIdAndForcesDashboard' => function () {
            $this->assert_ThemeEditorBackendAreaGate_testAreaSwitchJsKeepsThemeIdAndForcesDashboard();
        },
            'ThemeEditorBackendAreaGate::testMarkupRendererOnlyEmitsBackendOptionWhenAsked' => function () {
            $this->assert_ThemeEditorBackendAreaGate_testMarkupRendererOnlyEmitsBackendOptionWhenAsked();
        },
            'ThemeEditorScopeVersionApi::testThemeEditorDefinesScopeVersionPayloadMethods' => function () {
            $this->assert_ThemeEditorScopeVersionApi_testThemeEditorDefinesScopeVersionPayloadMethods();
        },
            'ThemeEditorScopeVersionApi::testThemeQueryProviderMapsScopeVersionRoutes' => function () {
            $this->assert_ThemeEditorScopeVersionApi_testThemeQueryProviderMapsScopeVersionRoutes();
        },
            'ThemeEditorScopeVersionApi::testThemeEditorTemplateExposesScopeVersionDataApiAttrs' => function () {
            $this->assert_ThemeEditorScopeVersionApi_testThemeEditorTemplateExposesScopeVersionDataApiAttrs();
        },
            'ThemeEditorScopeVersionApi::testPreviewTokenServiceCarriesOwnerVersionModeRevisionFields' => function () {
            $this->assert_ThemeEditorScopeVersionApi_testPreviewTokenServiceCarriesOwnerVersionModeRevisionFields();
        },
            'ThemeEditorScopeVersionApi::testThemeEditorJsPrefersScopeVersionApis' => function () {
            $this->assert_ThemeEditorScopeVersionApi_testThemeEditorJsPrefersScopeVersionApis();
        },
            'ThemePreviewPublishAndExitContext::testPublishAndExitDoesNotResolveIdentityFromFullPreviewContext' => function () {
            $this->assert_ThemePreviewPublishAndExitContext_testPublishAndExitDoesNotResolveIdentityFromFullPreviewContext();
        },
            'ThemePreviewPublishAndExitContext::testPublishAndExitFallsBackToMaterializedTypedClaims' => function () {
            $this->assert_ThemePreviewPublishAndExitContext_testPublishAndExitFallsBackToMaterializedTypedClaims();
        },
            'ThemePreviewPublishAndExitContext::testAssertRawLayoutContextSkipsPreviewShellTargetTypes' => function () {
            $this->assert_ThemePreviewPublishAndExitContext_testAssertRawLayoutContextSkipsPreviewShellTargetTypes();
        },
            'ThemeScopedReleaseBatchEndpoint::testControllerAndEditorShellExposeOneBatchPublishEndpoint' => function () {
            $this->assert_ThemeScopedReleaseBatchEndpoint_testControllerAndEditorShellExposeOneBatchPublishEndpoint();
        },
            'ThemeScopedReleaseBatchEndpoint::testCompatibilityPublisherDelegatesOneCompleteBatch' => function () {
            $this->assert_ThemeScopedReleaseBatchEndpoint_testCompatibilityPublisherDelegatesOneCompleteBatch();
        },
            'ThemeStandardLayoutPublish::testRunStandardLayoutPublishGatesDirtyWithoutCreateVersion' => function () {
            $this->assert_ThemeStandardLayoutPublish_testRunStandardLayoutPublishGatesDirtyWithoutCreateVersion();
        },
            'ThemeStandardLayoutPublish::testPublishEntrypointsUseStandardKernel' => function () {
            $this->assert_ThemeStandardLayoutPublish_testPublishEntrypointsUseStandardKernel();
        },
            'ThemeStandardLayoutPublish::testHttpScopedPublishRejectsPendingWithSameCode' => function () {
            $this->assert_ThemeStandardLayoutPublish_testHttpScopedPublishRejectsPendingWithSameCode();
        },
            'ThemeStandardLayoutPublish::testScopeVersionsPayloadIncludesSuggestedVersionName' => function () {
            $this->assert_ThemeStandardLayoutPublish_testScopeVersionsPayloadIncludesSuggestedVersionName();
        },
            'ThemeStandardLayoutPublish::testEditorPublishStopsPreemptiveBatch' => function () {
            $this->assert_ThemeStandardLayoutPublish_testEditorPublishStopsPreemptiveBatch();
        },
            'ThemeStandardLayoutPublish::testPublishScopeVersionStreamsSolidifyCompileGate' => function () {
            $this->assert_ThemeStandardLayoutPublish_testPublishScopeVersionStreamsSolidifyCompileGate();
        },
            'ThemeStandardLayoutPublish::testPublishAndExitAppliesPreviewProgressLocale' => function () {
            $this->assert_ThemeStandardLayoutPublish_testPublishAndExitAppliesPreviewProgressLocale();
        },
            'ThemeStandardLayoutPublish::testPreviewFloatHandlesRequiresNewVersion' => function () {
            $this->assert_ThemeStandardLayoutPublish_testPreviewFloatHandlesRequiresNewVersion();
        },
            'ThemeStandardLayoutPublish::testPublishFinalizesBakeAndCacheWithSse' => function () {
            $this->assert_ThemeStandardLayoutPublish_testPublishFinalizesBakeAndCacheWithSse();
        },
            'ThemeStandardLayoutPublish::testPublishOmitsUnknownContentRevisionAndRetriesCas' => function () {
            $this->assert_ThemeStandardLayoutPublish_testPublishOmitsUnknownContentRevisionAndRetriesCas();
        },
            'ThemePreviewContentEditorIsolation::testFrontendThemePreviewContentControllerIsDeleted' => function () {
            $this->assert_ThemePreviewContentEditorIsolation_testFrontendThemePreviewContentControllerIsDeleted();
        },
            'ThemePreviewContentEditorIsolation::testEditorCanvasLoadsStorefrontRouteNotHydrate' => function () {
            $this->assert_ThemePreviewContentEditorIsolation_testEditorCanvasLoadsStorefrontRouteNotHydrate();
        },
            'ThemePreviewContentEditorIsolation::testUiPagesEditorCanvasAlsoRoutesBackendToDashboard' => function () {
            $this->assert_ThemePreviewContentEditorIsolation_testUiPagesEditorCanvasAlsoRoutesBackendToDashboard();
        },
            'ThemePreviewContentEditorIsolation::testLayoutSlotRendererBootstrapsEditorCanvasOnAnyRoute' => function () {
            $this->assert_ThemePreviewContentEditorIsolation_testLayoutSlotRendererBootstrapsEditorCanvasOnAnyRoute();
        },
            'ThemePreviewStorefrontPublishAndExit::testFloatInjectsFrontendPublishUrlNotBackend' => function () {
            $this->assert_ThemePreviewStorefrontPublishAndExit_testFloatInjectsFrontendPublishUrlNotBackend();
        },
            'ThemePreviewStorefrontPublishAndExit::testFrontendPublishControllerAuthorizesTokenActorThenDelegates' => function () {
            $this->assert_ThemePreviewStorefrontPublishAndExit_testFrontendPublishControllerAuthorizesTokenActorThenDelegates();
        },
            'ThemePreviewStorefrontPublishAndExit::testAssetEditorProducerAcceptsPreviewPublishBinder' => function () {
            $this->assert_ThemePreviewStorefrontPublishAndExit_testAssetEditorProducerAcceptsPreviewPublishBinder();
        },
            'ThemePreviewStorefrontPublishAndExit::testPublishActorBinderRejectsDisabledActor' => function () {
            $this->assert_ThemePreviewStorefrontPublishAndExit_testPublishActorBinderRejectsDisabledActor();
        },
            'LegacyPolicyRedirect::testRouterDeclaresLegacyPolicyPermanentRedirects' => function () {
            $this->assert_LegacyPolicyRedirect_testRouterDeclaresLegacyPolicyPermanentRedirects();
        },
            'LegacyPolicyRedirect::testCreateAliasOwnedByCustomerRouter' => function () {
            $this->assert_LegacyPolicyRedirect_testCreateAliasOwnedByCustomerRouter();
        },
            'PolicyHydrateLayoutFromOriginPath::testIndexHydratesFromOriginPathBeforeDefaultPolicyLayout' => function () {
            $this->assert_PolicyHydrateLayoutFromOriginPath_testIndexHydratesFromOriginPathBeforeDefaultPolicyLayout();
        },
            'RouterLegalPublicRoutes::testFooterLegalLinksHaveDiscoverableThemeLayouts' => function () {
            $this->assert_RouterLegalPublicRoutes_testFooterLegalLinksHaveDiscoverableThemeLayouts();
        },
            'RouterLegalPublicRoutes::testPublicLayoutRegistersThemeModuleBeforeTranslatingMetadata' => function () {
            $this->assert_RouterLegalPublicRoutes_testPublicLayoutRegistersThemeModuleBeforeTranslatingMetadata();
        },
            'ThemeEditorScopedWidgetConfig::testWidgetConfigEndpointsPreferNodeUidHelpers' => function () {
            $this->assert_ThemeEditorScopedWidgetConfig_testWidgetConfigEndpointsPreferNodeUidHelpers();
        },
            'ThemeEditorShellLayout::testThemeEditorUsesDedicatedShellLayoutWithoutBaseBodyEnd' => function () {
            $this->assert_ThemeEditorShellLayout_testThemeEditorUsesDedicatedShellLayoutWithoutBaseBodyEnd();
        },
        ];
    }

private function assert_LayoutPreviewStorefrontUrl_testFrontendLayoutPreviewUsesStorefrontPathNotCustomShell(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/Config/Layout.php');
    self::assertStringContainsString('getFrontendUrlPathForPreview', $source);
    self::assertStringContainsString("\$url->getFrontendUrl(\$storefrontPath, \$params)", $source);
    self::assertStringContainsString("'shell' => 'theme-editor'", $source);
    self::assertStringNotContainsString("getBackendUrl('theme/backend/theme-editor/layout-preview'", $source);
    self::assertStringContainsString('Never open theme-preview/content or theme-editor/layout-preview', $source);
}

private function assert_VisualEditorShellRetired_testVisualEditorAssetsAreDeleted(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    self::assertFileDoesNotExist($root . '/view/statics/js/visual-editor.js');
    self::assertFileDoesNotExist($root . '/view/statics/js/visual-editor-theme-mode.js');
    self::assertFileDoesNotExist($root . '/view/statics/css/visual-editor.css');
    self::assertFileDoesNotExist($root . '/view/templates/backend/config/visual-editor.phtml');
}

private function assert_VisualEditorShellRetired_testConfigEntryPointsRedirectToThemeEditor(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    $layout = (string) \file_get_contents($root . '/Controller/Backend/Config/Layout.php');
    $themeConfig = (string) \file_get_contents($root . '/Controller/Backend/Config/ThemeConfig.php');
    self::assertStringContainsString("getBackendUrl('theme/backend/theme-editor'", $layout);
    self::assertStringNotContainsString('visual-editor.phtml', $layout);
    self::assertStringContainsString("getBackendUrl('theme/backend/theme-editor'", $themeConfig);
    self::assertStringNotContainsString('visual-editor.phtml', $themeConfig);
}

private function assert_VisualEditorShellRetired_testThemeEditorJsDroppedDeadApiWiring(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    $js = (string) \file_get_contents($root . '/view/statics/js/theme-editor.js');
    foreach (['apiExitPreview', 'apiPublishAndExit', 'apiRequestTakeover', 'apiForceTakeover', 'apiRenameVersion', 'apiRenderWidget', 'apiSaveCompiledLayout', 'apiScopedReleaseBatch', 'apiVirtualThemeBlockAction', 'apiVirtualThemeSaveSource', 'apiPreview:', 'schedulePreviewRefresh', 'buildWidgetCodeMetaHtml', 'function swapWidgetOrder', 'async function addWidget(', 'function buildFormBody'] as $needle) {
        self::assertStringNotContainsString($needle, $js, $needle);
    }
}

private function assert_ThemeEditorBackendAreaGate_testEditorShellGatesBackendOptionOnThemeDirOnly(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('private function themeHasBackendDir', $source);
    self::assertStringContainsString("\$editorAreaOptionsHtml = \$this->editorMarkupRenderer->renderEditorAreaOptions(\n" . "            \$frontendHasBackend,\n" . "            \$editorArea\n" . "        );", $source);
    self::assertStringContainsString("\$this->assign('theme_has_backend', \$frontendHasBackend);", $source);
    self::assertStringNotContainsString('$frontendHasBackend || $backendThemeId > 0', $source, 'backend_theme_id>0 must not unlock the backend editor-area option');
    self::assertStringContainsString("if (!\$frontendHasBackend) {\n" . "            \$editorArea = PreviewContextService::AREA_FRONTEND;\n" . "        }", $source);
    self::assertStringContainsString('Backend shell must not keep a storefront leftover page_type', $source);
    self::assertStringContainsString('$pageType = ThemeLayout::PAGE_TYPE_DASHBOARD;', $source);
    // Coerce must precede the prepareAssetEditorInput call in index().
    self::assertMatchesRegularExpression('/\$frontendHasBackend = \$this->themeHasBackendDir\(\$editingTheme\);\s*' . 'if \(\!\$frontendHasBackend\) \{\s*' . '\$editorArea = PreviewContextService::AREA_FRONTEND;\s*' . '\}[\s\S]*?' . 'if \(\$scopeContext instanceof ScopeContext\) \{\s*' . '\$assetInput = \$this->prepareAssetEditorInput/s', $source, 'backend coerce must run before prepareAssetEditorInput');
}

private function assert_ThemeEditorBackendAreaGate_testAreaSwitchJsKeepsThemeIdAndForcesDashboard(): void
{
    $paths = ['/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/statics/ui/pages/weline-theme-editor.js', '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/statics/js/theme-editor.js'];
    foreach ($paths as $path) {
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('function buildCanvasBackendPreviewUrl', $source);
        self::assertStringContainsString("page_type: area === 'backend' ? 'dashboard' : null", $source);
        self::assertStringContainsString('keepThemeId', $source);
        self::assertStringNotContainsString("theme_id: null,\n                    frontend_theme_id: null,\n                    backend_theme_id: null,\n                    // Do not force dashboard page_type", $source, 'area switch must not clear theme_id or skip dashboard page_type');
    }
}

private function assert_ThemeEditorBackendAreaGate_testMarkupRendererOnlyEmitsBackendOptionWhenAsked(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/Ui/ThemeEditorMarkupRenderer.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function renderEditorAreaOptions(bool $hasBackend, string $selectedArea)', $source);
    self::assertStringContainsString('if ($hasBackend)', $source);
    self::assertStringContainsString('value="backend"', $source);
    // Option markup is gated — never emit backend without $hasBackend.
    $fnStart = strpos($source, 'function renderEditorAreaOptions');
    self::assertNotFalse($fnStart);
    $fnBody = substr($source, $fnStart, 600);
    self::assertStringContainsString('if ($hasBackend)', $fnBody);
    self::assertMatchesRegularExpression('/if \(\$hasBackend\) \{[^}]*value="backend"/s', $fnBody);
}

private function assert_ThemeEditorScopeVersionApi_testThemeEditorDefinesScopeVersionPayloadMethods(): void
{
    $src = $this->readTheme_ThemeEditorScopeVersionApi('Controller/Backend/ThemeEditor.php');
    foreach (['function getScopeVersions(', 'function getScopeVersionsPayload(', 'function postCreateScopeDraft(', 'function createScopeDraftPayload(', 'function postSaveScopeVersion(', 'function saveScopeVersionPayload(', 'function postPublishScopeVersion(', 'function publishScopeVersionPayload(', 'function postRestoreScopeDefaults(', 'function restoreScopeDefaultsPayload(', 'ThemeVersionPublicationService', 'themeVersionOwnerFromContext'] as $needle) {
        self::assertStringContainsString($needle, $src, $needle);
    }
    foreach (['function getVersionsPayload(', 'function saveVersionPayload(', 'function switchVersionPayload(', 'function restoreOriginalPayload(', 'function publishVersionPayload(', 'function postSaveVersion(', 'function postSwitchVersion(', 'function postRestoreOriginal(', 'function postPublishVersion('] as $needle) {
        self::assertStringNotContainsString($needle, $src, $needle);
    }
}

private function assert_ThemeEditorScopeVersionApi_testThemeQueryProviderMapsScopeVersionRoutes(): void
{
    $src = $this->readTheme_ThemeEditorScopeVersionApi('extends/module/Weline_Framework/Query/ThemeQueryProvider.php');
    foreach (["/theme/backend/theme-editor/scope-versions' =>", "/theme/backend/theme-editor/create-scope-draft' =>", "/theme/backend/theme-editor/save-scope-version' =>", "/theme/backend/theme-editor/publish-scope-version' =>", "/theme/backend/theme-editor/restore-scope-defaults' =>", 'getScopeVersionsPayload()', 'createScopeDraftPayload()', 'saveScopeVersionPayload()', 'publishScopeVersionPayload()', 'restoreScopeDefaultsPayload()'] as $needle) {
        self::assertStringContainsString($needle, $src, $needle);
    }
    foreach (["/theme/backend/theme-editor/versions' =>", "/theme/backend/theme-editor/save-version' =>", "/theme/backend/theme-editor/switch-version' =>", "/theme/backend/theme-editor/restore-original' =>", "/theme/backend/theme-editor/publish-version' =>"] as $needle) {
        self::assertStringNotContainsString($needle, $src, $needle);
    }
    // Virtual-theme publish-version remains unrelated.
    self::assertStringContainsString("/theme/backend/virtual-theme/publish-version' =>", $src);
}

private function assert_ThemeEditorScopeVersionApi_testThemeEditorTemplateExposesScopeVersionDataApiAttrs(): void
{
    $src = $this->readTheme_ThemeEditorScopeVersionApi('view/templates/backend/ThemeEditor/index.phtml');
    foreach (['data-api-scope-versions=', 'data-api-create-scope-draft=', 'data-api-save-scope-version=', 'data-api-publish-scope-version=', 'data-api-restore-scope-defaults='] as $needle) {
        self::assertStringContainsString($needle, $src, $needle);
    }
}

private function assert_ThemeEditorScopeVersionApi_testPreviewTokenServiceCarriesOwnerVersionModeRevisionFields(): void
{
    $src = $this->readTheme_ThemeEditorScopeVersionApi('Service/PreviewTokenService.php');
    foreach (["'theme_version_id'", "'mode'", "'content_revision'", "'canonical_scope'", "'store_mode'", "'area'", "'owner_hash'"] as $needle) {
        self::assertStringContainsString($needle, $src, $needle);
    }
}

private function assert_ThemeEditorScopeVersionApi_testThemeEditorJsPrefersScopeVersionApis(): void
{
    foreach (['view/statics/js/theme-editor.js', 'view/statics/ui/pages/weline-theme-editor.js'] as $relative) {
        $src = $this->readTheme_ThemeEditorScopeVersionApi($relative);
        foreach (['apiScopeVersions', 'apiCreateScopeDraft', 'apiSaveScopeVersion', 'apiPublishScopeVersion', 'apiRestoreScopeDefaults', 'buildScopeVersionPayload', 'create-scope-draft', 'publish-scope-version', 'explicit_historical'] as $needle) {
            self::assertStringContainsString($needle, $src, $relative . ':' . $needle);
        }
        foreach (['preferScopeVersionApis', 'apiSaveVersion', 'apiSwitchVersion', 'apiRestoreOriginal', 'apiPublishVersion', 'apiVersions'] as $needle) {
            self::assertStringNotContainsString($needle, $src, $relative . ':' . $needle);
        }
    }
}

private function readTheme_ThemeEditorScopeVersionApi(string $relativeToTheme): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/' . \ltrim($relativeToTheme, '/');
    $src = \file_get_contents($path);
    self::assertIsString($src, $path);
    return $src;
}

private function assert_ThemePreviewPublishAndExitContext_testPublishAndExitDoesNotResolveIdentityFromFullPreviewContext(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    $start = strpos($source, 'function postPublishAndExit()');
    self::assertNotFalse($start);
    $end = strpos($source, "\n    public function ", $start + 1);
    self::assertNotFalse($end);
    $fn = substr($source, $start, $end - $start);
    self::assertStringNotContainsString('resolveVersionLayoutIdentity($previewContext)', $fn, 'Full previewContext carries shell target_type=layout and false-fails assertRawLayoutContextMatches');
    self::assertStringContainsString('resolvePreviewPublishTypedClaims(', $fn);
    self::assertStringContainsString("['editor_context' => \$typedClaims]", $fn);
    self::assertStringContainsString('PreviewContextService shell target_type', $fn);
    self::assertStringContainsString('runStandardLayoutPublish($typedContext', $fn);
    self::assertStringContainsString('Do not clear preview token on version-gate', $fn);
    self::assertStringNotContainsString('createAndPublishScopedLayoutVersion(', $fn);
    self::assertStringNotContainsString('theme_preview_typed_context_required', $fn);
}

private function assert_ThemePreviewPublishAndExitContext_testPublishAndExitFallsBackToMaterializedTypedClaims(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php';
    $source = (string) file_get_contents($path);
    $start = strpos($source, 'function resolvePreviewPublishTypedClaims(');
    self::assertNotFalse($start);
    $end = strpos($source, "\n    private function ", $start + 1);
    if ($end === false) {
        $end = strpos($source, "\n    public function ", $start + 1);
    }
    self::assertNotFalse($end);
    $fn = substr($source, $start, $end - $start);
    self::assertStringContainsString('ThemePreviewTypedEditorContextBinder', $fn);
    self::assertStringContainsString('decodeClaims(', $fn);
    self::assertStringContainsString('materializeClaims(', $fn);
    self::assertStringContainsString("\$data['editor_context']", $fn);
    self::assertStringContainsString("\$previewContext['editor_context']", $fn);
}

private function assert_ThemePreviewPublishAndExitContext_testAssertRawLayoutContextSkipsPreviewShellTargetTypes(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php';
    $source = (string) file_get_contents($path);
    $start = strpos($source, 'function assertRawLayoutContextMatches(');
    self::assertNotFalse($start);
    $end = strpos($source, "\n    private function ", $start + 1);
    if ($end === false) {
        $end = strpos($source, "\n    public function ", $start + 1);
    }
    self::assertNotFalse($end);
    $fn = substr($source, $start, $end - $start);
    self::assertStringContainsString('TARGET_TYPE_LAYOUT', $fn);
    self::assertStringContainsString('TARGET_TYPE_PATH', $fn);
    self::assertStringContainsString('TARGET_TYPE_PAGE', $fn);
    self::assertStringContainsString('continue;', $fn);
}

private function assert_ThemeScopedReleaseBatchEndpoint_testControllerAndEditorShellExposeOneBatchPublishEndpoint(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    $controller = (string) \file_get_contents($root . '/Controller/Backend/ThemeEditor.php');
    $template = (string) \file_get_contents($root . '/view/templates/backend/ThemeEditor/index.phtml');
    self::assertStringContainsString('public function postPublishScopedReleaseBatch()', $controller);
    self::assertStringContainsString("scopedWorkspacePayload('publish_batch')", $controller);
    self::assertStringContainsString("'publish_batch' => \$service->publishBatch(", $controller);
    self::assertStringContainsString('data-api-publish-scoped-release-batch=', $template);
    self::assertStringContainsString('publish-scoped-release-batch', $template);
}

private function assert_ThemeScopedReleaseBatchEndpoint_testCompatibilityPublisherDelegatesOneCompleteBatch(): void
{
    $controller = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php');
    $start = \strpos($controller, 'private function publishPendingScopedResources(');
    $end = \strpos($controller, 'private function replaceScopedLayoutDraftFromSnapshot(', (int) $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $method = \substr($controller, (int) $start, (int) $end - (int) $start);
    self::assertStringContainsString('ThemeEditorContext::RESOURCES', $method);
    self::assertSame(1, \substr_count($method, '$requests->publishBatch('));
    self::assertStringNotContainsString('$requests->publish([', $method);
}

private function themeEditorSource_ThemeStandardLayoutPublish(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php';
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function extractMethod_ThemeStandardLayoutPublish(string $source, string $functionName): string
{
    $start = strpos($source, 'function ' . $functionName . '(');
    self::assertNotFalse($start, $functionName . ' missing');
    $end = strpos($source, "\n    private function ", $start + 1);
    if ($end === false) {
        $end = strpos($source, "\n    public function ", $start + 1);
    }
    self::assertNotFalse($end, $functionName . ' end missing');
    return substr($source, $start, $end - $start);
}

private function assert_ThemeStandardLayoutPublish_testRunStandardLayoutPublishGatesDirtyWithoutCreateVersion(): void
{
    $fn = $this->extractMethod_ThemeStandardLayoutPublish($this->themeEditorSource_ThemeStandardLayoutPublish(), 'runStandardLayoutPublish');
    self::assertStringContainsString('theme_publish_requires_new_version', $fn);
    self::assertStringContainsString('hasPendingScopedChanges', $fn);
    self::assertStringContainsString('TYPE_PUBLISH', $fn);
    self::assertStringContainsString('saveScopedLayoutVersion(', $fn);
    self::assertStringContainsString('publishPendingScopedResources(', $fn);
    self::assertStringContainsString('markVersionPublished(', $fn);
    self::assertStringContainsString('theme_publish_version_mark_failed', $fn);
    self::assertStringContainsString('theme_publish_restore_version', $fn);
}

private function assert_ThemeStandardLayoutPublish_testPublishEntrypointsUseStandardKernel(): void
{
    $source = $this->themeEditorSource_ThemeStandardLayoutPublish();
    foreach (['postPublish', 'postPublishAndExit'] as $name) {
        $fn = $this->extractMethod_ThemeStandardLayoutPublish($source, $name);
        self::assertStringContainsString('runStandardLayoutPublish(', $fn, $name . ' must call runStandardLayoutPublish');
        self::assertStringNotContainsString('createAndPublishScopedLayoutVersion(', $fn, $name);
    }
    $scopePublish = $this->extractMethod_ThemeStandardLayoutPublish($source, 'publishScopeVersionPayload');
    self::assertStringContainsString('publishScopeVersionPayloadLocked', $scopePublish);
    self::assertStringContainsString('ThemeVersionPublicationService', $this->extractMethod_ThemeStandardLayoutPublish($source, 'publishScopeVersionPayloadLocked'));
    self::assertStringNotContainsString('function postPublishVersion(', $source);
    self::assertStringNotContainsString('function publishVersionPayload(', $source);
}

private function assert_ThemeStandardLayoutPublish_testHttpScopedPublishRejectsPendingWithSameCode(): void
{
    $fn = $this->extractMethod_ThemeStandardLayoutPublish($this->themeEditorSource_ThemeStandardLayoutPublish(), 'scopedWorkspacePayload');
    self::assertStringContainsString("'publish', 'publish_batch'", $fn);
    self::assertStringContainsString('hasPendingScopedChanges($gateContext)', $fn);
    self::assertStringContainsString('theme_publish_requires_new_version', $fn);
    self::assertStringContainsString('nextLayoutVersionSuggestion($gateContext)', $fn);
}

private function assert_ThemeStandardLayoutPublish_testScopeVersionsPayloadIncludesSuggestedVersionName(): void
{
    $source = $this->themeEditorSource_ThemeStandardLayoutPublish();
    $payload = $this->extractMethod_ThemeStandardLayoutPublish($source, 'getScopeVersionsPayload');
    $suggest = $this->extractMethod_ThemeStandardLayoutPublish($source, 'nextLayoutVersionSuggestion');
    self::assertStringContainsString("'suggested_version_name'", $suggest);
    self::assertStringContainsString("'v' . \$next", $suggest);
    self::assertStringContainsString("'suggested_version_name'", $payload);
    self::assertStringNotContainsString('function getVersionsPayload(', $source);
    self::assertStringNotContainsString('function getVersions(', $source);
}

private function assert_ThemeStandardLayoutPublish_testEditorPublishStopsPreemptiveBatch(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    foreach (['/view/statics/ui/pages/weline-theme-editor.js', '/view/statics/js/theme-editor.js'] as $relative) {
        $source = (string) file_get_contents($root . $relative);
        $start = strpos($source, 'async function publishTheme()');
        self::assertNotFalse($start, $relative);
        $end = strpos($source, 'async function detectPendingScopedChanges()', $start + 1);
        self::assertNotFalse($end, $relative);
        $fn = substr($source, $start, $end - $start);
        self::assertStringContainsString('requestStandardLayoutPublish(', $fn, $relative);
        self::assertStringContainsString('theme_publish_requires_new_version', $fn, $relative);
        self::assertStringContainsString('openResetProgressLock(', $fn, $relative);
        self::assertStringContainsString('bindSolidifyProgressHandler(', $fn, $relative);
        self::assertStringContainsString('onProgress: progressHandler', $fn, $relative);
        self::assertStringNotContainsString("publishLoadedScopedWorkspaces('theme_editor_publish')", $fn, $relative);
        self::assertStringNotContainsString('scoped_release_published: true', $fn, $relative);
    }
}

private function assert_ThemeStandardLayoutPublish_testPublishScopeVersionStreamsSolidifyCompileGate(): void
{
    $source = $this->themeEditorSource_ThemeStandardLayoutPublish();
    $post = $this->extractMethod_ThemeStandardLayoutPublish($source, 'postPublishScopeVersion');
    self::assertStringContainsString('wantsStandardPublishStream($data)', $post);
    self::assertStringContainsString('streamPublishScopeVersion(', $post);
    $stream = $this->extractMethod_ThemeStandardLayoutPublish($source, 'streamPublishScopeVersion');
    self::assertStringContainsString('SseWriter', $stream);
    self::assertStringContainsString("sendEvent('progress'", $stream);
    self::assertStringContainsString("sendEvent('done'", $stream);
    self::assertStringContainsString('publishScopeVersionPayload($onProgress)', $stream);
    $locked = $this->extractMethod_ThemeStandardLayoutPublish($source, 'publishScopeVersionPayloadLocked');
    self::assertStringContainsString("emit('solidify'", $locked);
    self::assertStringContainsString('bakePublishedScopeArtifacts(', $locked);
    self::assertStringContainsString('theme_scope_publish_bake_failed', $locked);
    self::assertStringContainsString("emit('solidify_done'", $locked);
    self::assertStringContainsString('正在固化并编译正式布局产物', $locked);
    // Pointer flip must not run before bake gate.
    $bakePos = strpos($locked, 'bakePublishedScopeArtifacts(');
    $pointerPos = strpos($locked, "emit('publish_pointer'");
    self::assertNotFalse($bakePos);
    self::assertNotFalse($pointerPos);
    self::assertLessThan($pointerPos, $bakePos);
}

private function assert_ThemeStandardLayoutPublish_testPublishAndExitAppliesPreviewProgressLocale(): void
{
    $source = $this->themeEditorSource_ThemeStandardLayoutPublish();
    $fn = $this->extractMethod_ThemeStandardLayoutPublish($source, 'postPublishAndExit');
    self::assertStringContainsString('resolvePublishProgressLocale(', $source);
    self::assertStringContainsString('applyPublishProgressLocale(', $fn);
    self::assertStringContainsString('clearPublishProgressLocale()', $fn);
    self::assertStringContainsString('State::setRequestLanguageOverride', $source);
    self::assertStringContainsString("\$data['locale']", $source);
}

private function assert_ThemeStandardLayoutPublish_testPreviewFloatHandlesRequiresNewVersion(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/LayoutSlotRenderer.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('theme_publish_requires_new_version', $source);
    self::assertStringContainsString('create_version: true', $source);
    self::assertStringContainsString('promptNewVersionName', $source);
    self::assertStringContainsString('openPreviewModalOverlay', $source);
    self::assertStringContainsString('z-index:2147483200', $source);
    self::assertStringContainsString('z-index: 2147483100', $source);
    self::assertStringContainsString('new RegExp(', $source);
    self::assertStringContainsString('text/event-stream', $source);
    self::assertStringContainsString('consumePublishSse', $source);
    self::assertStringContainsString('id="weline-preview-publish-btn"', $source);
    self::assertStringContainsString('data-w-preview-publish-lock', $source);
    self::assertStringContainsString('openPublishProgressLock', $source);
    self::assertStringContainsString('updatePublishProgress', $source);
    self::assertStringContainsString('finishPublishRedirect', $source);
    self::assertStringContainsString('buildPublishExitNavigateUrl', $source);
    self::assertStringContainsString('tearDownPreviewFloatChrome', $source);
    self::assertStringContainsString('theme_standard_publish_ok_stream_tail', $source);
    self::assertStringContainsString('z-index:2147483400', $source);
    self::assertStringContainsString('data-w-publish-bar', $source);
    self::assertStringContainsString('data-w-publish-steps', $source);
    self::assertStringContainsString('publishingTitle:', $source);
    self::assertStringContainsString('checkingGate:', $source);
    self::assertStringContainsString('stepPrefix:', $source);
    self::assertStringContainsString('progressByStep:', $source);
    self::assertStringContainsString('progressSourceMap:', $source);
    self::assertStringContainsString('resolvePublishProgressMessage', $source);
    self::assertStringContainsString('resolveStorefrontPublishLocale', $source);
    self::assertStringContainsString('previewMessages.publishingTitle', $source);
    self::assertStringContainsString('previewMessages.checkingGate', $source);
    self::assertStringContainsString('previewMessages.stepPrefix', $source);
    self::assertStringContainsString("payload.locale", $source);
    self::assertStringNotContainsString("titleEl.textContent = '正在发布主题'", $source);
    self::assertStringNotContainsString("openPublishProgressLock('检查发布条件…'", $source);
    // Publish is primary CTA (solid), exit is secondary — not ghost/disabled look.
    $publishBtnAt = strpos($source, 'id="weline-preview-publish-btn"');
    self::assertNotFalse($publishBtnAt);
    $publishChunk = substr($source, $publishBtnAt, 700);
    self::assertStringContainsString('rgba(255,255,255,0.98)', $publishChunk);
    $exitBtnAt = strpos($source, 'id="weline-preview-exit-btn"');
    self::assertNotFalse($exitBtnAt);
    $exitChunk = substr($source, $exitBtnAt, 700);
    self::assertStringContainsString('rgba(255,255,255,0.16)', $exitChunk);
    $promptStart = strpos($source, 'function promptNewVersionName(');
    self::assertNotFalse($promptStart);
    $promptEnd = strpos($source, 'publishBtn.addEventListener', $promptStart);
    self::assertNotFalse($promptEnd);
    $promptFn = substr($source, $promptStart, $promptEnd - $promptStart);
    self::assertStringNotContainsString('window.prompt(', $promptFn);
    self::assertStringNotContainsString('window.confirm(', $promptFn);
    self::assertStringNotContainsString('window.alert(', $promptFn);
    self::assertStringNotContainsString('Weline.UI.dialog.prompt', $promptFn);
    self::assertStringContainsString('openPreviewModalOverlay', $promptFn);
    self::assertStringContainsString('input.value = autoLabel', $promptFn);
    self::assertStringContainsString('suggested_version_name', $source);
}

private function assert_ThemeStandardLayoutPublish_testPublishFinalizesBakeAndCacheWithSse(): void
{
    $source = $this->themeEditorSource_ThemeStandardLayoutPublish();
    $finalize = $this->extractMethod_ThemeStandardLayoutPublish($source, 'finalizeStandardLayoutPublish');
    self::assertStringContainsString('ensureCurrentPublishedLayoutBaked(', $finalize);
    self::assertStringContainsString('bumpStaticVersion(', $finalize);
    self::assertStringContainsString('clearCache($themeId)', $finalize);
    self::assertStringContainsString('flushFullPageCache(', $finalize);
    $stream = $this->extractMethod_ThemeStandardLayoutPublish($source, 'streamStandardLayoutPublish');
    self::assertStringContainsString('SseWriter', $stream);
    self::assertStringContainsString("sendEvent('progress'", $stream);
    self::assertStringContainsString("sendEvent('done'", $stream);
    self::assertStringContainsString('compose_redirect', $stream);
    self::assertStringContainsString('正在清理预览会话并准备跳转', $source);
    self::assertStringContainsString('exit_preview', $source);
    foreach (['postPublish', 'postPublishAndExit'] as $name) {
        $fn = $this->extractMethod_ThemeStandardLayoutPublish($source, $name);
        self::assertStringContainsString('wantsStandardPublishStream(', $fn, $name);
        self::assertStringContainsString('streamStandardLayoutPublish(', $fn, $name);
        self::assertStringContainsString('finalizeStandardLayoutPublish(', $fn, $name);
    }
    self::assertStringNotContainsString('function postPublishVersion(', $source);
    self::assertStringNotContainsString('function publishVersionPayload(', $source);
    $workspace = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/Scoped/ThemeScopedWorkspace.php');
    self::assertStringContainsString('ThemeScopedVersionPublicationBridge::class', $workspace);
    $publication = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/Version/ThemeScopedVersionPublicationBridge.php');
    self::assertStringContainsString('bakePublishArtifactsForVersion(', $publication);
    self::assertStringContainsString('preparePublication(', $publication);
    self::assertStringNotContainsString('bakePublishedLayoutResourcesFromBatchReceipt(', $workspace);
    foreach (['/view/statics/ui/pages/weline-theme-editor.js', '/view/statics/js/theme-editor.js'] as $relative) {
        $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . $relative);
        self::assertStringContainsString('apiPublishScopeVersion', $js, $relative);
        self::assertStringContainsString('requestStandardLayoutPublish', $js, $relative);
        self::assertStringNotContainsString('apiPublishVersion', $js, $relative);
        $reqStart = strpos($js, 'async function requestStandardLayoutPublish(');
        self::assertNotFalse($reqStart, $relative);
        $reqEnd = strpos($js, 'async function openPreview()', $reqStart + 1);
        self::assertNotFalse($reqEnd, $relative);
        $reqFn = substr($js, $reqStart, $reqEnd - $reqStart);
        self::assertStringContainsString("Accept: 'text/event-stream'", $reqFn, $relative);
        self::assertStringContainsString('stream: 1', $reqFn, $relative);
        self::assertStringContainsString('consumeStandardPublishSse(', $reqFn, $relative);
        self::assertStringContainsString('onProgress', $reqFn, $relative);
    }
}

private function assert_ThemeStandardLayoutPublish_testPublishOmitsUnknownContentRevisionAndRetriesCas(): void
{
    $locked = $this->extractMethod_ThemeStandardLayoutPublish($this->themeEditorSource_ThemeStandardLayoutPublish(), 'publishScopeVersionPayloadLocked');
    self::assertStringContainsString('$clientContentRevision > 0', $locked);
    self::assertStringContainsString('content_revision_cas_failed', $locked);
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Theme';
    foreach (['/view/statics/ui/pages/weline-theme-editor.js', '/view/statics/js/theme-editor.js'] as $relative) {
        $js = (string) file_get_contents($root . $relative);
        $builderPos = strpos($js, 'function buildScopeVersionPayload(');
        self::assertNotFalse($builderPos, $relative);
        $builderEnd = strpos($js, "\n    function ", $builderPos + 1);
        self::assertNotFalse($builderEnd, $relative);
        $builder = substr($js, $builderPos, $builderEnd - $builderPos);
        self::assertStringContainsString('if (contentRevision > 0)', $builder, $relative);
        self::assertStringContainsString('payload.content_revision = contentRevision', $builder, $relative);
        $publishPos = strpos($js, 'async function publishThemeWithScope(');
        self::assertNotFalse($publishPos, $relative);
        $publishEnd = strpos($js, 'async function detectPendingScopedChanges()', $publishPos + 1);
        self::assertNotFalse($publishEnd, $relative);
        $publishFn = substr($js, $publishPos, $publishEnd - $publishPos);
        self::assertStringContainsString('content_revision_cas_failed', $publishFn, $relative);
        self::assertStringContainsString('resolvePublishFailureMessage', $publishFn, $relative);
        self::assertStringContainsString("await loadVersions().catch(() => null)", $publishFn, $relative);
    }
}

private function assert_ThemePreviewContentEditorIsolation_testFrontendThemePreviewContentControllerIsDeleted(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Frontend/ThemePreview/Content.php';
    self::assertFileDoesNotExist($path, 'Frontend theme-preview/content HTTP shell must be fully deleted');
}

private function assert_ThemePreviewContentEditorIsolation_testEditorCanvasLoadsStorefrontRouteNotHydrate(): void
{
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/statics/js/theme-editor.js';
    self::assertFileExists($js);
    $source = (string) file_get_contents($js);
    self::assertStringContainsString('function buildCanvasStorefrontPreviewUrl', $source);
    self::assertStringContainsString('function buildCanvasBackendPreviewUrl', $source);
    self::assertStringContainsString('function buildCanvasPreviewUrl', $source);
    self::assertStringContainsString('function resolveCanvasStorefrontPath', $source);
    self::assertStringContainsString('buildCanvasPreviewUrl(overrides)', $source);
    self::assertStringContainsString("url.searchParams.delete('page_type')", $source);
    self::assertStringContainsString("url.searchParams.delete('layout_type')", $source);
    self::assertStringContainsString("url.searchParams.delete('weline_preview_token')", $source);
    self::assertStringContainsString("url.searchParams.set('editor_area', 'backend')", $source);
    self::assertStringContainsString('weline_dashboard/backend/dashboard', $source);
    // Bare /product is Theme Policy shell + R43 mock — canvas must require product/{slug}.
    self::assertStringNotContainsString("product: 'product'", $source);
    self::assertStringNotContainsString('StorefrontSampleCanvasHydrator', $source);
}

private function assert_ThemePreviewContentEditorIsolation_testUiPagesEditorCanvasAlsoRoutesBackendToDashboard(): void
{
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/statics/ui/pages/weline-theme-editor.js';
    self::assertFileExists($js);
    $source = (string) file_get_contents($js);
    self::assertStringContainsString('function buildCanvasBackendPreviewUrl', $source);
    self::assertStringContainsString('function buildCanvasPreviewUrl', $source);
    self::assertStringContainsString('buildCanvasPreviewUrl(overrides)', $source);
    self::assertStringContainsString('weline_dashboard/backend/dashboard', $source);
    self::assertStringContainsString("url.searchParams.set('editor_area', 'backend')", $source);
}

private function assert_ThemePreviewContentEditorIsolation_testLayoutSlotRendererBootstrapsEditorCanvasOnAnyRoute(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/LayoutSlotRenderer.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function isEditorCanvasRequest', $source);
    self::assertStringContainsString('function bootstrapEditorCanvasIdentity', $source);
    self::assertStringContainsString('EditorModeAssetInjector', $source);
    self::assertStringContainsString("getParam('editor_mode'", $source);
    // 画布身份安装必须真实被调用（带 template 参数），仅断言无参形式会漏掉 $this->bootstrapEditorCanvasIdentity($template) 的真实调用。
    self::assertStringContainsString('bootstrapEditorCanvasIdentity($template)', $source);
}

private function assert_ThemePreviewStorefrontPublishAndExit_testFloatInjectsFrontendPublishUrlNotBackend(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/LayoutSlotRenderer.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("getFrontendUrl('theme/frontend/theme-preview/publish-and-exit')", $source);
    self::assertStringNotContainsString("getBackendUrl('theme/backend/theme-editor/publish-and-exit')", $source);
}

private function assert_ThemePreviewStorefrontPublishAndExit_testFrontendPublishControllerAuthorizesTokenActorThenDelegates(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Frontend/ThemePreview/PublishAndExit.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('file_access_actor_id', $source);
    self::assertStringContainsString('ThemePreviewPublishActorBinder::install', $source);
    self::assertStringContainsString('ThemePreviewPublishActorBinder::clear', $source);
    self::assertStringContainsString('postPublishAndExit()', $source);
    self::assertStringContainsString('theme_publish_requires_login', $source);
    self::assertStringContainsString('createDirectThemeEditor', $source);
    self::assertStringContainsString("getMethod()) !== 'POST'", $source);
    self::assertStringNotContainsString('getBackendUrl', $source);
}

private function assert_ThemePreviewStorefrontPublishAndExit_testAssetEditorProducerAcceptsPreviewPublishBinder(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/Scoped/ThemeAssetEditorContextProducer.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('ThemePreviewPublishActorBinder::current()', $source);
    self::assertStringContainsString('function resolveActor()', $source);
}

private function assert_ThemePreviewStorefrontPublishAndExit_testPublishActorBinderRejectsDisabledActor(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/ThemePreviewPublishActorBinder.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('theme_preview_publish_actor_invalid', $source);
    self::assertStringContainsString('getIsEnabled()', $source);
}

private function assert_LegacyPolicyRedirect_testRouterDeclaresLegacyPolicyPermanentRedirects(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Router.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('LEGACY_POLICY_REDIRECTS', $source);
    self::assertStringContainsString("'privacy' => 'policy/privacy'", $source);
    self::assertStringContainsString("'cookie' => 'policy/cookie'", $source);
    self::assertStringContainsString("'cookies' => 'policy/cookie'", $source);
    self::assertStringContainsString("'refund' => 'policy/refund'", $source);
    self::assertStringContainsString('ResponseTerminateException(301', $source);
    // Redirect map only — must not restore path→controller alias table.
    self::assertStringContainsString('these aliases are redirects, not a revived', $source);
}

private function assert_LegacyPolicyRedirect_testCreateAliasOwnedByCustomerRouter(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Router.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("'customer/account/create'", $source);
}

private function assert_PolicyHydrateLayoutFromOriginPath_testIndexHydratesFromOriginPathBeforeDefaultPolicyLayout(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Frontend/Policy.php');
    self::assertStringContainsString('hydrateLayoutParamsFromPublicPath', $source);
    self::assertStringContainsString('resolveVisitorPublicPath', $source);
    self::assertStringContainsString('WELINE_ORIGIN_REQUEST_URI', $source);
    self::assertStringContainsString('resolveFromPath', $source);
    self::assertMatchesRegularExpression('/function index\(\)[\s\S]*hydrateLayoutParamsFromPublicPath\(\)/', $source);
    self::assertStringNotContainsString("'url' => '@url'", $source);
}

private function assert_RouterLegalPublicRoutes_testFooterLegalLinksHaveDiscoverableThemeLayouts(): void
{
    $layoutsRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/layouts';
    self::assertDirectoryExists($layoutsRoot);
    foreach (FooterDefaultLinksHelper::legalLinks() as $link) {
        $path = trim((string) ($link['url'] ?? ''), '/');
        self::assertNotSame('', $path, 'legal link url must not be empty');
        if (!str_contains($path, '/')) {
            $file = $layoutsRoot . '/' . $path . '/default.phtml';
        } else {
            [$layoutType, $layoutOption] = explode('/', $path, 2);
            $file = $layoutsRoot . '/' . $layoutType . '/' . $layoutOption . '.phtml';
        }
        self::assertFileExists($file, 'missing Theme layout for legal link /' . $path . ' (expected ' . $file . ')');
    }
    self::assertFileExists($layoutsRoot . '/policy/accessibility.phtml');
    self::assertFileExists($layoutsRoot . '/policy/shipping.phtml');
    self::assertFileExists($layoutsRoot . '/error/default.phtml');
    self::assertFileExists($layoutsRoot . '/sitemap/default.phtml');
}

private function assert_RouterLegalPublicRoutes_testPublicLayoutRegistersThemeModuleBeforeTranslatingMetadata(): void
{
    $policyPath = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Frontend/Policy.php';
    self::assertFileExists($policyPath);
    $source = (string) file_get_contents($policyPath);
    $scopePosition = strpos($source, "\$this->request->addModule('Weline_Theme');");
    // 公开路由标题的翻译入口是 Theme WidgetI18n::label（源串默认简体中文），
    // 不再走 __($title)，否则断言恒为 false 而失去「注册先于翻译」的守护意义。
    $translationPosition = strpos($source, 'WidgetI18n::label($title)');
    self::assertNotFalse($scopePosition, 'Theme request scope must be registered explicitly.');
    self::assertNotFalse($translationPosition, 'Public route title must remain translatable.');
    self::assertLessThan($translationPosition, $scopePosition, 'Theme request scope must be registered before controller metadata is translated.');
    self::assertStringContainsString("'shipping'", $source);
    self::assertStringContainsString("'accessibility'", $source);
    self::assertStringContainsString("'error' => ['default']", $source);
    self::assertStringContainsString("'sitemap' => ['default']", $source);
}

private function assert_ThemeEditorScopedWidgetConfig_testWidgetConfigEndpointsPreferNodeUidHelpers(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
    self::assertStringContainsString('function resolveNodeUidFromEditorRequest', $source);
    self::assertStringContainsString('function resolveScopedDraftNode', $source);
    self::assertStringContainsString('function ensureScopedNodeI18nInstance', $source);
    foreach (['function getWidgetConfig(', 'function postSaveWidgetConfig(', 'function getWidgetFieldI18nPayload('] as $fn) {
        $start = \strpos($source, $fn);
        self::assertNotFalse($start, $fn);
        $next = \strpos($source, "\n    public function ", $start + 10);
        $body = $next === false ? \substr($source, (int) $start) : \substr($source, (int) $start, $next - (int) $start);
        self::assertStringContainsString('resolveNodeUidFromEditorRequest', $body, $fn);
        self::assertStringContainsString('resolveScopedDraftNode', $body, $fn);
        self::assertStringNotContainsString('$this->themeLayout->reset()->load($layoutId)', $body, $fn);
    }
    $saveStart = \strpos($source, 'function postSaveWidgetConfig(');
    self::assertNotFalse($saveStart);
    $saveNext = \strpos($source, "\n    public function ", $saveStart + 10);
    $saveBody = $saveNext === false ? \substr($source, (int) $saveStart) : \substr($source, (int) $saveStart, $saveNext - (int) $saveStart);
    self::assertStringContainsString("'scoped_workspace' => \$scopedWorkspace", $saveBody);
    self::assertStringContainsString('updateWidgetConfig(', $saveBody);
    $getStart = \strpos($source, 'function getWidgetConfig(');
    self::assertNotFalse($getStart);
    $getNext = \strpos($source, "\n    public function ", $getStart + 10);
    $getBody = $getNext === false ? \substr($source, (int) $getStart) : \substr($source, (int) $getStart, $getNext - (int) $getStart);
    // 无 @param 必须 success=true 空态，禁止 success=false 触发 BinQuery ERR /「加载配置失败」
    self::assertStringContainsString("'has_params' => false", $getBody);
    self::assertStringContainsString("'params' => []", $getBody);
    // GET 配置默认不得同步渲 preview（单部件预览可达数十秒，会堵住「点部件」）。
    // 契约演进：允许保留 tryBuildPreviewHtmlForWidget 入口，但必须先经 widgetConfigWantsPreviewHtml() 门控，
    // 仅显式 with_preview=1（画布回填）那一次才渲；禁止无条件调用。
    $previewGatePosition = strpos($getBody, 'widgetConfigWantsPreviewHtml()');
    $previewCallPosition = strpos($getBody, 'tryBuildPreviewHtmlForWidget');
    self::assertNotFalse($previewGatePosition, 'GET 配置必须以 widgetConfigWantsPreviewHtml() 门控预览渲染。');
    self::assertNotFalse($previewCallPosition, '自动保存后的画布回填仍需保留 preview 渲染入口。');
    self::assertLessThan($previewCallPosition, $previewGatePosition, '预览门控必须先于 preview 渲染，保证打开配置时不渲染预览。');
    self::assertDoesNotMatchRegularExpression('/if\s*\(\s*empty\(\s*\$params\s*\)\s*\)\s*\{\s*return\s+\$this->fetchJson\(\s*\[\s*\'success\'\s*=>\s*false/s', $getBody);
    $updateStart = \strpos($source, 'function postUpdateConfig(');
    self::assertNotFalse($updateStart);
    $updateNext = \strpos($source, "\n    public function ", $updateStart + 10);
    $updateBody = $updateNext === false ? \substr($source, (int) $updateStart) : \substr($source, (int) $updateStart, $updateNext - (int) $updateStart);
    self::assertStringContainsString('resolveNodeUidFromEditorRequest', $updateBody);
    self::assertStringNotContainsString('$this->themeLayout->reset()->load($layoutId)', $updateBody);
}

private function assert_ThemeEditorShellLayout_testThemeEditorUsesDedicatedShellLayoutWithoutBaseBodyEnd(): void
{
    $controller = $this->read_ThemeEditorShellLayout('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
    self::assertStringContainsString("private function useThemeEditorShellLayout(): void", $controller);
    self::assertStringContainsString("\$this->layoutType = 'theme-editor.default';", $controller);
    self::assertStringContainsString('$this->useThemeEditorShellLayout();', $controller);
    self::assertStringNotContainsString("\$this->layoutType = 'fullscreen.default';", $controller);
    self::assertStringNotContainsString('useFullscreenEditorLayout', $controller);
    $layout = $this->read_ThemeEditorShellLayout('app/code/Weline/Theme/view/theme/backend/layouts/theme-editor/default.phtml');
    self::assertStringContainsString('data-weline-shell="theme-editor"', $layout);
    self::assertStringContainsString('Weline_Theme::backend::layouts::theme-editor::body-end', $layout);
    self::assertStringContainsString('Weline_Admin::common/head.phtml', $layout);
    self::assertStringNotContainsString('Weline_Theme::backend::layouts::base::body-end', $layout);
    self::assertStringNotContainsString('type="topbar"', $layout);
    self::assertStringNotContainsString('type="footer"', $layout);
    $hooks = $this->read_ThemeEditorShellLayout('app/code/Weline/Theme/hook.php');
    self::assertStringContainsString("'Weline_Theme::backend::layouts::theme-editor::body-end'", $hooks);
    self::assertStringContainsString('theme-editor 不引用本 hook', $hooks);
}

private function read_ThemeEditorShellLayout(string $relative): string
{
    $path = BP . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}
}
