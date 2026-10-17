<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 10 *ContractTest files).
 * Observe one overall pass/fail via testAllDeveloperWorkspaceViewContractsPass().
 */
final class DeveloperWorkspaceViewContractsTest extends TestCase
{
    public function testAllDeveloperWorkspaceViewContractsPass(): void
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
            'ApiDocsDisclosurePadding::testDisclosurePadsSummaryAndBody' => function () {
            $this->assert_ApiDocsDisclosurePadding_testDisclosurePadsSummaryAndBody();
        },
            'ApiDocsHeaderToolsHumanization::testHeaderSplitsSettingsAndActions' => function () {
            $this->assert_ApiDocsHeaderToolsHumanization_testHeaderSplitsSettingsAndActions();
        },
            'ApiDocsHeaderToolsHumanization::testCssAlignsToolGroupsWithControlHeight' => function () {
            $this->assert_ApiDocsHeaderToolsHumanization_testCssAlignsToolGroupsWithControlHeight();
        },
            'ApiDocsI18nSettings::testModeLegendUsesI18nModeNotSectionTitle' => function () {
            $this->assert_ApiDocsI18nSettings_testModeLegendUsesI18nModeNotSectionTitle();
        },
            'ApiDocsI18nSettings::testTemplateExposesI18nModeCopy' => function () {
            $this->assert_ApiDocsI18nSettings_testTemplateExposesI18nModeCopy();
        },
            'ApiDocsRestFetch::testScriptsUseSendHttpInsteadOfDisabledApiRequest' => function () {
            $this->assert_ApiDocsRestFetch_testScriptsUseSendHttpInsteadOfDisabledApiRequest();
        },
            'ApiDocsSearchSplit::testTemplateSplitsModuleFilterAndApiSearch' => function () {
            $this->assert_ApiDocsSearchSplit_testTemplateSplitsModuleFilterAndApiSearch();
        },
            'ApiDocsSearchSplit::testScriptsSupportModuleFilter' => function () {
            $this->assert_ApiDocsSearchSplit_testScriptsSupportModuleFilter();
        },
            'ApiDocsSearchSplit::testApiActionAvoidsForceRegenAndBulkContent' => function () {
            $this->assert_ApiDocsSearchSplit_testApiActionAvoidsForceRegenAndBulkContent();
        },
            'DevToolPanelStacking::testDevToolPanelStacksAboveStorefrontResidentFloats' => function () {
            $this->assert_DevToolPanelStacking_testDevToolPanelStacksAboveStorefrontResidentFloats();
        },
            'DocsBrowserCodeFullscreen::testFullscreenHelpersExistInLiveAndParityScripts' => function () {
            $this->assert_DocsBrowserCodeFullscreen_testFullscreenHelpersExistInLiveAndParityScripts();
        },
            'DocsBrowserCodeFullscreen::testFullscreenStylesExist' => function () {
            $this->assert_DocsBrowserCodeFullscreen_testFullscreenStylesExist();
        },
            'DocsBrowserCodeFullscreen::testIndexExposesFullscreenI18nKeys' => function () {
            $this->assert_DocsBrowserCodeFullscreen_testIndexExposesFullscreenI18nKeys();
        },
            'DocsBrowserSearchQuery::testReadsSearchQueryKeysFromUrl' => function () {
            $this->assert_DocsBrowserSearchQuery_testReadsSearchQueryKeysFromUrl();
        },
            'DocsBrowserSearchQuery::testBootAppliesUrlSearchAutomatically' => function () {
            $this->assert_DocsBrowserSearchQuery_testBootAppliesUrlSearchAutomatically();
        },
            'DocsBrowserSearchQuery::testPopstateRestoresSearchFromUrl' => function () {
            $this->assert_DocsBrowserSearchQuery_testPopstateRestoresSearchFromUrl();
        },
            'DocsBrowserSearchQuery::testCatalogFilterIsLocalTreeOnly' => function () {
            $this->assert_DocsBrowserSearchQuery_testCatalogFilterIsLocalTreeOnly();
        },
            'DocsBrowserSearchQuery::testDocsIndexSplitsCatalogFilterAndDocumentSearch' => function () {
            $this->assert_DocsBrowserSearchQuery_testDocsIndexSplitsCatalogFilterAndDocumentSearch();
        },
            'DocsFrontendRuntimeHeader::testDocsControllerUsesBlankFullLayout' => function () {
            $this->assert_DocsFrontendRuntimeHeader_testDocsControllerUsesBlankFullLayout();
        },
            'DocsFrontendRuntimeHeader::testDocsIndexIsBlankFullContentFragment' => function () {
            $this->assert_DocsFrontendRuntimeHeader_testDocsIndexIsBlankFullContentFragment();
        },
            'DocsFrontendRuntimeHeader::testApiManagerIsBlankFullContentFragment' => function () {
            $this->assert_DocsFrontendRuntimeHeader_testApiManagerIsBlankFullContentFragment();
        },
            'DocsReadingToc::testDocsBrowserExposesFloatingTocHelpers' => function () {
            $this->assert_DocsReadingToc_testDocsBrowserExposesFloatingTocHelpers();
        },
            'DocsReadingToc::testApiDocsExposesFloatingTocHelpers' => function () {
            $this->assert_DocsReadingToc_testApiDocsExposesFloatingTocHelpers();
        },
            'DocsReadingToc::testDocsCssDefinesFloatingTocSurface' => function () {
            $this->assert_DocsReadingToc_testDocsCssDefinesFloatingTocSurface();
        },
        ];
    }

/** @return list<string> */
private function cssPaths_ApiDocsDisclosurePadding(): array
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    return [$themeRoot . '/view/statics/ui/pages/weline-developer-api.css', $moduleRoot . '/view/statics/css/api-docs.css'];
}

private function assert_ApiDocsDisclosurePadding_testDisclosurePadsSummaryAndBody(): void
{
    foreach ($this->cssPaths_ApiDocsDisclosurePadding() as $path) {
        self::assertFileExists($path, $path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('details.w-disclosure > summary', $src, $path);
        self::assertStringContainsString('details.w-disclosure > :not(summary)', $src, $path);
        self::assertStringContainsString('--weline-space-4', $src, $path);
        self::assertStringContainsString('overflow-wrap: break-word', $src, $path);
    }
}

private function assert_ApiDocsHeaderToolsHumanization_testHeaderSplitsSettingsAndActions(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/templates/Docs/api-manager.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('w-api-docs__tool-settings', $src);
    self::assertStringContainsString('w-api-docs__tool-actions', $src);
    self::assertStringContainsString('data-api-theme', $src);
    self::assertStringContainsString('data-api-production', $src);
    self::assertStringContainsString('data-api-action="open-login"', $src);
    self::assertStringContainsString('data-variant="outline"', $src);
    $settingsPos = strpos($src, 'w-api-docs__tool-settings');
    $actionsPos = strpos($src, 'w-api-docs__tool-actions');
    $themePos = strpos($src, 'data-api-theme');
    $loginPos = strpos($src, 'data-api-action="open-login"');
    self::assertNotFalse($settingsPos);
    self::assertNotFalse($actionsPos);
    self::assertNotFalse($themePos);
    self::assertNotFalse($loginPos);
    self::assertGreaterThan($settingsPos, $themePos);
    self::assertLessThan($actionsPos, $themePos);
    self::assertGreaterThan($actionsPos, $loginPos);
}

private function assert_ApiDocsHeaderToolsHumanization_testCssAlignsToolGroupsWithControlHeight(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    $paths = [$themeRoot . '/view/statics/ui/pages/weline-developer-api.css', $moduleRoot . '/view/statics/css/api-docs.css'];
    foreach ($paths as $path) {
        self::assertFileExists($path, $path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('.w-api-docs__tool-group', $src, $path);
        self::assertStringContainsString('var(--weline-control-height)', $src, $path);
        self::assertStringContainsString('.w-api-docs__tools', $src, $path);
        self::assertMatchesRegularExpression('/\.w-api-docs__tools\s*\{[^}]*gap:\s*var\(--weline-space-5\)/s', $src, $path);
        self::assertMatchesRegularExpression('/\.w-api-docs__theme-field\s*\{[^}]*display:\s*inline-flex/s', $src, $path);
        self::assertMatchesRegularExpression('/\.w-api-docs__tool-actions\s+\.w-button\[data-tone="neutral"\]\[data-variant="outline"\]/s', $src, $path);
    }
}

/** @return list<string> */
private function scriptPaths_ApiDocsI18nSettings(): array
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    return [$themeRoot . '/view/statics/ui/pages/weline-developer-api.js', $moduleRoot . '/view/statics/js/api-docs.js'];
}

private function assert_ApiDocsI18nSettings_testModeLegendUsesI18nModeNotSectionTitle(): void
{
    foreach ($this->scriptPaths_ApiDocsI18nSettings() as $path) {
        self::assertFileExists($path, $path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("t('i18nMode', '切换方式')", $src, $path);
        self::assertDoesNotMatchRegularExpression("/create\\('legend'[^)]*t\\('i18nSettings'\\)/", $src, $path);
        self::assertStringContainsString('display_name', $src, $path);
    }
}

private function assert_ApiDocsI18nSettings_testTemplateExposesI18nModeCopy(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/templates/Docs/api-manager.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("'i18nMode'", $src);
    self::assertStringContainsString('切换方式', $src);
}

private function assert_ApiDocsRestFetch_testScriptsUseSendHttpInsteadOfDisabledApiRequest(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    $paths = [$moduleRoot . '/view/statics/js/api-docs.js', $themeRoot . '/view/statics/ui/pages/weline-developer-api.js'];
    foreach ($paths as $path) {
        self::assertFileExists($path, $path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('async function sendHttp(', $src, $path);
        self::assertStringContainsString('await sendHttp(url.href', $src, $path);
        self::assertStringContainsString('await sendHttp(buildRestUrl(path, backend)', $src, $path);
        self::assertStringContainsString("credentials: 'same-origin'", $src, $path);
        self::assertStringContainsString('function restRequestPath(api)', $src, $path);
        self::assertStringContainsString(".filter((segment) => !/^\\{[^}]+\\}\$/.test(segment))", $src, $path);
        self::assertStringContainsString('buildRestUrl(restRequestPath(api)', $src, $path);
        self::assertStringNotContainsString('runtime.request(url.href', $src, $path);
        self::assertStringNotContainsString('runtime.request(buildRestUrl', $src, $path);
    }
}

private function assert_ApiDocsSearchSplit_testTemplateSplitsModuleFilterAndApiSearch(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/templates/Docs/api-manager.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('Weline_Theme::ui/pages/weline-developer-api.js', $src);
    self::assertStringContainsString('data-api-module-filter', $src);
    self::assertStringContainsString('data-api-search', $src);
    self::assertStringContainsString('w-api-docs__api-search', $src);
    $sidebarPos = strpos($src, 'w-api-docs__sidebar');
    $contentPos = strpos($src, 'w-api-docs__content');
    $moduleFilterPos = strpos($src, 'data-api-module-filter');
    $apiSearchPos = strpos($src, 'data-api-search');
    self::assertNotFalse($sidebarPos);
    self::assertNotFalse($contentPos);
    self::assertNotFalse($moduleFilterPos);
    self::assertNotFalse($apiSearchPos);
    self::assertGreaterThan($sidebarPos, $moduleFilterPos);
    self::assertLessThan($contentPos, $moduleFilterPos);
    self::assertGreaterThan($contentPos, $apiSearchPos);
}

private function assert_ApiDocsSearchSplit_testScriptsSupportModuleFilter(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    $paths = [$themeRoot . '/view/statics/ui/pages/weline-developer-api.js', $moduleRoot . '/view/statics/js/api-docs.js'];
    foreach ($paths as $path) {
        self::assertFileExists($path, $path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("document.querySelector('[data-api-module-filter]')", $src, $path);
        self::assertStringContainsString('function matchesModule', $src, $path);
        self::assertStringContainsString('state.moduleFilter', $src, $path);
        self::assertStringContainsString('function ensureAreaForCurrentFilters', $src, $path);
        self::assertStringContainsString('function matchesAreaQuery', $src, $path);
        self::assertStringContainsString('function queryTokens', $src, $path);
        self::assertStringContainsString('function haystackMatchesTokens', $src, $path);
        self::assertStringContainsString('QUERY_TOKEN_ALIASES', $src, $path);
        self::assertStringContainsString('.split(/\s+/)', $src, $path);
        self::assertStringContainsString('tokenVariants(token)', $src, $path);
        self::assertStringContainsString('function resolveRestBody', $src, $path);
        self::assertStringContainsString('function isAuthLoginApi', $src, $path);
        self::assertStringContainsString('function persistLoginSession', $src, $path);
        self::assertStringContainsString('isAuthLoginApi(api)', $src, $path);
        self::assertStringContainsString('resolveRestBody(api)', $src, $path);
        self::assertStringContainsString('function sortedGroupedApis', $src, $path);
        self::assertStringContainsString('function moduleListRank', $src, $path);
        self::assertStringContainsString('sortedGroupedApis(filtered)', $src, $path);
        self::assertStringContainsString('function resolveInitialApi', $src, $path);
        self::assertStringContainsString('const initialApi = resolveInitialApi()', $src, $path);
        self::assertStringContainsString('areaPinnedByUser', $src, $path);
        self::assertStringContainsString('function clearDocsFilters', $src, $path);
        self::assertStringContainsString('function syncSelectionToFilter', $src, $path);
        self::assertStringContainsString('clearDocsFilters()', $src, $path);
        self::assertStringContainsString('clearedModule', $src, $path);
        self::assertStringContainsString('clear-docs-filters', $src, $path);
        self::assertStringContainsString('data-api-filter-status', $src, $path);
        // 禁止在 renderList 内强制切区，否则深链 q 会锁死 Tab。
        self::assertDoesNotMatchRegularExpression('/function renderList\(\)\s*\{[^}]*ensureAreaForCurrentFilters\(\)/s', $src, $path);
        self::assertStringContainsString('function readUrlDocsParams', $src, $path);
        self::assertStringContainsString('function updateDocsUrl', $src, $path);
        self::assertStringContainsString("searchParams.set('module'", $src, $path);
        self::assertStringContainsString("searchParams.set('q'", $src, $path);
        self::assertStringContainsString('syncFilterInputsFromState', $src, $path);
    }
}

private function assert_ApiDocsSearchSplit_testApiActionAvoidsForceRegenAndBulkContent(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/Controller/Docs.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringNotContainsString('generateAll(true)', $src);
    self::assertStringContainsString('generateAll($forceRefresh)', $src);
    self::assertStringContainsString('$includeContent = $selectedApiId !== \'\' && $apiId === $selectedApiId', $src);
    self::assertStringContainsString('localizeApiDocument($api, $currentLanguage, $includeContent)', $src);
}

private function assert_DevToolPanelStacking_testDevToolPanelStacksAboveStorefrontResidentFloats(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $panel = (string) file_get_contents($moduleRoot . '/view/hooks/dev-tool-panel.phtml');
    self::assertSame(1, preg_match('/#dev-tool-panel\s*\{[^}]*?z-index:\s*(\d+)/s', $panel, $panelMatch), '#dev-tool-panel 必须声明数值 z-index');
    $panelZ = (int) $panelMatch[1];
    $musicCss = (string) file_get_contents(dirname($moduleRoot) . '/StoreMusic/view/statics/css/store-music.css');
    self::assertSame(1, preg_match('/--w-store-music-z-widget:\s*(\d+)/', $musicCss, $musicMatch));
    $musicZ = (int) $musicMatch[1];
    self::assertGreaterThan($musicZ, $panelZ, '开发面板必须高于进店音乐浮层，否则头像会挡在时间线/详情上');
    $csCss = (string) file_get_contents(dirname($moduleRoot) . '/CustomerService/view/statics/css/customer-service.css');
    self::assertSame(1, preg_match('/\.customer-service-widget\.has-unread[\s\S]*?z-index:\s*(\d+)/', $csCss, $csMatch));
    $csZ = (int) $csMatch[1];
    self::assertGreaterThan($csZ, $panelZ, '开发面板必须高于客服未读/打开态，否则客服球会挡面板');
    // 仍须低于主题编辑器 / 预览工具浮层（2147483100+）与 token 对话框（2147483600）。
    self::assertLessThan(2147483100, $panelZ, '开发面板层不应越过主题编辑器/预览工具浮层');
    self::assertLessThan(2147483600, $panelZ, '开发面板层应低于 panel-token 对话框');
}

/** @return list<string> */
private function docsScriptPaths_DocsBrowserCodeFullscreen(): array
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    return [$themeRoot . '/view/statics/ui/pages/weline-developer-docs.js', $moduleRoot . '/view/statics/js/docs-browser.js'];
}

private function assert_DocsBrowserCodeFullscreen_testFullscreenHelpersExistInLiveAndParityScripts(): void
{
    foreach ($this->docsScriptPaths_DocsBrowserCodeFullscreen() as $path) {
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('function isAsciiDiagram', $src, $path);
        self::assertStringContainsString('function openCodeFullscreen', $src, $path);
        self::assertStringContainsString('data-docs-fullscreen', $src, $path);
        self::assertStringContainsString('w-docs-code-fs', $src, $path);
    }
}

private function assert_DocsBrowserCodeFullscreen_testFullscreenStylesExist(): void
{
    $themeCss = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/../Theme/view/statics/ui/pages/weline-developer-docs.css';
    $parityCss = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/statics/css/docs-browser.css';
    foreach ([$themeCss, $parityCss] as $path) {
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('.w-docs-code-fs', $src, $path);
        self::assertStringContainsString('.w-docs-code-fs__pre', $src, $path);
        self::assertStringContainsString('[data-diagram="1"]', $src, $path);
    }
}

private function assert_DocsBrowserCodeFullscreen_testIndexExposesFullscreenI18nKeys(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/templates/Docs/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("'fullscreen'", $src);
    self::assertStringContainsString("'fullscreenClose'", $src);
}

/** @return list<string> */
private function docsScriptPaths_DocsBrowserSearchQuery(): array
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    return [$themeRoot . '/view/statics/ui/pages/weline-developer-docs.js', $moduleRoot . '/view/statics/js/docs-browser.js'];
}

/** @return array<string, string> */
private function docsScriptSources_DocsBrowserSearchQuery(): array
{
    $sources = [];
    foreach ($this->docsScriptPaths_DocsBrowserSearchQuery() as $path) {
        self::assertFileExists($path);
        $sources[$path] = (string) file_get_contents($path);
    }
    return $sources;
}

private function assert_DocsBrowserSearchQuery_testReadsSearchQueryKeysFromUrl(): void
{
    foreach ($this->docsScriptSources_DocsBrowserSearchQuery() as $path => $src) {
        self::assertStringContainsString("SEARCH_QUERY_KEYS = ['search', 'keyword', 'q', 'module']", $src, $path);
        self::assertStringContainsString('function readUrlSearchKeyword', $src, $path);
    }
}

private function assert_DocsBrowserSearchQuery_testBootAppliesUrlSearchAutomatically(): void
{
    foreach ($this->docsScriptSources_DocsBrowserSearchQuery() as $path => $src) {
        self::assertStringContainsString('const bootSearch = readUrlSearchKeyword()', $src, $path);
        self::assertStringContainsString('searchInput.value = bootSearch', $src, $path);
        self::assertStringContainsString('search(bootSearch, false)', $src, $path);
    }
}

private function assert_DocsBrowserSearchQuery_testPopstateRestoresSearchFromUrl(): void
{
    foreach ($this->docsScriptSources_DocsBrowserSearchQuery() as $path => $src) {
        self::assertStringContainsString('const keyword = readUrlSearchKeyword(url)', $src, $path);
        self::assertStringContainsString('search(keyword, false)', $src, $path);
    }
}

private function assert_DocsBrowserSearchQuery_testCatalogFilterIsLocalTreeOnly(): void
{
    foreach ($this->docsScriptSources_DocsBrowserSearchQuery() as $path => $src) {
        self::assertStringContainsString("document.querySelector('[data-docs-catalog-filter]')", $src, $path);
        self::assertStringContainsString('function filterTreeNodes', $src, $path);
        self::assertStringContainsString('function renderCatalogTree', $src, $path);
        self::assertStringContainsString('state.catalogFilter', $src, $path);
    }
}

private function assert_DocsBrowserSearchQuery_testDocsIndexSplitsCatalogFilterAndDocumentSearch(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/templates/Docs/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('Weline_Theme::ui/pages/weline-developer-docs.js', $src);
    self::assertStringContainsString('data-docs-catalog-filter', $src);
    self::assertStringContainsString('data-docs-search', $src);
    self::assertStringContainsString('w-docs-browser__doc-search', $src);
    // Sidebar owns catalog filter; content bar owns document search.
    $sidebarPos = strpos($src, 'w-docs-browser__sidebar');
    $contentPos = strpos($src, 'w-docs-browser__content');
    $catalogFilterPos = strpos($src, 'data-docs-catalog-filter');
    $docSearchPos = strpos($src, 'data-docs-search');
    self::assertNotFalse($sidebarPos);
    self::assertNotFalse($contentPos);
    self::assertNotFalse($catalogFilterPos);
    self::assertNotFalse($docSearchPos);
    self::assertGreaterThan($sidebarPos, $catalogFilterPos);
    self::assertLessThan($contentPos, $catalogFilterPos);
    self::assertGreaterThan($contentPos, $docSearchPos);
}

private function assert_DocsFrontendRuntimeHeader_testDocsControllerUsesBlankFullLayout(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/Controller/Docs.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("protected ?string \$layoutType = 'blank.full';", $src);
}

private function assert_DocsFrontendRuntimeHeader_testDocsIndexIsBlankFullContentFragment(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/templates/Docs/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertDoesNotMatchRegularExpression('/<!doctype\s+html/i', $src);
    self::assertDoesNotMatchRegularExpression('/<html\b/i', $src);
    self::assertStringNotContainsString('Weline_Backend::header/base.phtml', $src);
    self::assertStringNotContainsString('Weline\Backend\Block\Header\Base', $src);
    self::assertStringContainsString('weline-developer-docs.css', $src);
    self::assertStringContainsString('weline-developer-docs.js', $src);
}

private function assert_DocsFrontendRuntimeHeader_testApiManagerIsBlankFullContentFragment(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace' . '/view/templates/Docs/api-manager.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertDoesNotMatchRegularExpression('/<!doctype\s+html/i', $src);
    self::assertDoesNotMatchRegularExpression('/<html\b/i', $src);
    self::assertStringNotContainsString('Weline_Backend::header/base.phtml', $src);
    self::assertStringNotContainsString('Weline\Backend\Block\Header\Base', $src);
    self::assertStringContainsString('weline-developer-api.css', $src);
    self::assertStringContainsString('weline-developer-api.js', $src);
}

/** @return list<string> */
private function docsScriptPaths_DocsReadingToc(): array
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    return [$themeRoot . '/view/statics/ui/pages/weline-developer-docs.js', $moduleRoot . '/view/statics/js/docs-browser.js'];
}

/** @return list<string> */
private function apiScriptPaths_DocsReadingToc(): array
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    return [$themeRoot . '/view/statics/ui/pages/weline-developer-api.js', $moduleRoot . '/view/statics/js/api-docs.js'];
}

private function assert_DocsReadingToc_testDocsBrowserExposesFloatingTocHelpers(): void
{
    foreach ($this->docsScriptPaths_DocsReadingToc() as $path) {
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('function refreshDocumentToc', $src, $path);
        self::assertStringContainsString('function clearDocumentToc', $src, $path);
        self::assertStringContainsString('function toggleDocumentToc', $src, $path);
        self::assertStringContainsString('function setActiveDocsTocItem', $src, $path);
        self::assertStringContainsString('lockUntil', $src, $path);
        self::assertStringContainsString('data-docs-toc', $src, $path);
        self::assertStringContainsString('refreshDocumentToc(body)', $src, $path);
    }
}

private function assert_DocsReadingToc_testApiDocsExposesFloatingTocHelpers(): void
{
    foreach ($this->apiScriptPaths_DocsReadingToc() as $path) {
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('function refreshApiToc', $src, $path);
        self::assertStringContainsString('function clearApiToc', $src, $path);
        self::assertStringContainsString('function toggleApiToc', $src, $path);
        self::assertStringContainsString('data-api-toc', $src, $path);
        self::assertStringContainsString('refreshApiToc()', $src, $path);
    }
}

private function assert_DocsReadingToc_testDocsCssDefinesFloatingTocSurface(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/DeveloperWorkspace';
    $themeRoot = dirname($moduleRoot) . '/Theme';
    foreach ([$moduleRoot . '/view/statics/css/docs-browser.css', $themeRoot . '/view/statics/ui/pages/weline-developer-docs.css', $moduleRoot . '/view/statics/css/api-docs.css', $themeRoot . '/view/statics/ui/pages/weline-developer-api.css'] as $path) {
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertTrue(str_contains($src, '.w-docs-toc') || str_contains($src, '.w-api-toc'), $path);
    }
}
}
