<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllServerViewContractsPass().
 */
final class ServerViewContractsTest extends TestCase
{
    public function testAllServerViewContractsPass(): void
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
            'ServerManagerIndex::testBadgeExpressionIsValidJavascript' => function () {
            $this->assert_ServerManagerIndex_testBadgeExpressionIsValidJavascript();
        },
            'ServerManagerIndex::testPanelVisibilityUsesHiddenAttribute' => function () {
            $this->assert_ServerManagerIndex_testPanelVisibilityUsesHiddenAttribute();
        },
            'ServerManagerIndex::testLoadServerDataWaitsForAdminRequestBridge' => function () {
            $this->assert_ServerManagerIndex_testLoadServerDataWaitsForAdminRequestBridge();
        },
            'ServerManagerMenu::testMenuXmlRegistersServerManagerUnderSystemServiceGroup' => function () {
            $this->assert_ServerManagerMenu_testMenuXmlRegistersServerManagerUnderSystemServiceGroup();
        },
            'ServerManagerMenu::testControllerDeclaresServerManagerAcl' => function () {
            $this->assert_ServerManagerMenu_testControllerDeclaresServerManagerAcl();
        },
            'WelinePanelSecurityProbesTab::testTabsAfterRegistersSecurityProbesTab' => function () {
            $this->assert_WelinePanelSecurityProbesTab_testTabsAfterRegistersSecurityProbesTab();
        },
            'WelinePanelSecurityProbesTab::testSearchAreasRegistersMountAndEndpoint' => function () {
            $this->assert_WelinePanelSecurityProbesTab_testSearchAreasRegistersMountAndEndpoint();
        },
            'WelinePanelSecurityProbesTab::testPanelJsExposesMountApi' => function () {
            $this->assert_WelinePanelSecurityProbesTab_testPanelJsExposesMountApi();
        },
            'WlsDevPanelAttackStatsHttp::testRuntimeConfigExposesWlsProvidedLocation' => function () {
            $this->assert_WlsDevPanelAttackStatsHttp_testRuntimeConfigExposesWlsProvidedLocation();
        },
            'WlsDevPanelAttackStatsHttp::testFetchWlsPanelJsonUsesLocationAwareTransport' => function () {
            $this->assert_WlsDevPanelAttackStatsHttp_testFetchWlsPanelJsonUsesLocationAwareTransport();
        },
            'WlsDevPanelAttackStatsHttp::testHealthProbeUsesLocationAwareNativeTransport' => function () {
            $this->assert_WlsDevPanelAttackStatsHttp_testHealthProbeUsesLocationAwareNativeTransport();
        },
            'WlsDevPanelAttackStatsHttp::testTransportKeepsTestAndHealthNativeOnBothLocations' => function () {
            $this->assert_WlsDevPanelAttackStatsHttp_testTransportKeepsTestAndHealthNativeOnBothLocations();
        },
            'WlsDevPanelAttackStatsHttp::testPanelJsBridgesTestAndHealthPathsToNativeFetch' => function () {
            $this->assert_WlsDevPanelAttackStatsHttp_testPanelJsBridgesTestAndHealthPathsToNativeFetch();
        },
            'WlsDevPanelAttackStatsHttp::testSecurityTabAutoloadsAndRefreshIsOnTitleRight' => function () {
            $this->assert_WlsDevPanelAttackStatsHttp_testSecurityTabAutoloadsAndRefreshIsOnTitleRight();
        },
            'WlsDevPanelAttackStatsHttp::testAttackStatsUiRendersScanWarnings' => function () {
            $this->assert_WlsDevPanelAttackStatsHttp_testAttackStatsUiRendersScanWarnings();
        },
            'WlsPanelHostEmbed::testMenuPointsToHostAction' => function () {
            $this->assert_WlsPanelHostEmbed_testMenuPointsToHostAction();
        },
            'WlsPanelHostEmbed::testHostTemplateHasIframeAndModeHooks' => function () {
            $this->assert_WlsPanelHostEmbed_testHostTemplateHasIframeAndModeHooks();
        },
            'WlsPanelHostEmbed::testControllerAssignsFrameUrlWithEmbeddedFlag' => function () {
            $this->assert_WlsPanelHostEmbed_testControllerAssignsFrameUrlWithEmbeddedFlag();
        },
            'WlsPanelHostEmbed::testStandaloneShellUsesMainColumnScrollport' => function () {
            $this->assert_WlsPanelHostEmbed_testStandaloneShellUsesMainColumnScrollport();
        },
            'ServerStatics::testPanelScriptsAvoidNativeDialogs' => function () {
            $this->assert_ServerStatics_testPanelScriptsAvoidNativeDialogs();
        },
        ];
    }

private function source_ServerManagerIndex(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/view/templates/backend/ServerManager/index.phtml';
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function assert_ServerManagerIndex_testBadgeExpressionIsValidJavascript(): void
{
    $source = $this->source_ServerManagerIndex();
    self::assertStringNotContainsString('summary.missing > ?', $source);
    self::assertStringContainsString('summary.missing > 0 ?', $source);
}

private function assert_ServerManagerIndex_testPanelVisibilityUsesHiddenAttribute(): void
{
    $source = $this->source_ServerManagerIndex();
    self::assertStringContainsString('setPanelState', $source);
    self::assertStringContainsString('loadingEl.hidden', $source);
    self::assertStringContainsString('id="error-state" hidden', $source);
    self::assertStringContainsString('id="content-container" hidden', $source);
}

private function assert_ServerManagerIndex_testLoadServerDataWaitsForAdminRequestBridge(): void
{
    $source = $this->source_ServerManagerIndex();
    self::assertStringContainsString('ensureAdminRequest', $source);
    self::assertStringContainsString('showPageLoadingState()', $source);
    self::assertStringContainsString('showError(', $source);
    self::assertStringContainsString("adminRequest('server'", $source);
}

private function assert_ServerManagerMenu_testMenuXmlRegistersServerManagerUnderSystemServiceGroup(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/etc/backend/menu.xml';
    self::assertFileExists($path);
    $xml = (string) file_get_contents($path);
    self::assertStringContainsString('source="Weline_Server::server_manager"', $xml);
    self::assertStringContainsString('name="server_manager"', $xml);
    self::assertStringContainsString('parent="Weline_Backend::system_service_group"', $xml);
    self::assertStringContainsString('action="*/backend/server-manager"', $xml);
}

private function assert_ServerManagerMenu_testControllerDeclaresServerManagerAcl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Controller/Backend/ServerManager.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("Weline_Server::server_manager", $src);
    self::assertStringContainsString("Weline_Server::server_manager_view", $src);
    self::assertStringContainsString('Weline_Backend::system_service_group', $src);
}

private function assert_WelinePanelSecurityProbesTab_testTabsAfterRegistersSecurityProbesTab(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/tabs-after.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('data-tab="wls-probes"', $src);
    self::assertStringContainsString('安全探针', $src);
}

private function assert_WelinePanelSecurityProbesTab_testSearchAreasRegistersMountAndEndpoint(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/search-areas-after.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('wls-security-probes/panel.js', $src);
    self::assertStringContainsString("/server/test/wls-security-probes", $src);
    self::assertStringContainsString('WelineSecurityProbesPanel.mount', $src);
    self::assertStringContainsString("id: TAB_ID", $src);
    self::assertStringContainsString("wls-probes", $src);
}

private function assert_WelinePanelSecurityProbesTab_testPanelJsExposesMountApi(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/view/statics/wls-security-probes/panel.js';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('window.WelineSecurityProbesPanel', $src);
    self::assertStringContainsString('mount: mount', $src);
    self::assertStringContainsString('X-Weline-Security-Probe-Token', $src);
    self::assertStringContainsString('credentials: "omit"', $src);
}

private function hookSource_WlsDevPanelAttackStatsHttp(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/search-areas-after.phtml';
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function assert_WlsDevPanelAttackStatsHttp_testRuntimeConfigExposesWlsProvidedLocation(): void
{
    $src = $this->hookSource_WlsDevPanelAttackStatsHttp();
    self::assertStringContainsString("RequestContext::getWelineArea()", $src);
    self::assertStringContainsString("'location' => \$wlsPanelLocation", $src);
    self::assertStringContainsString("'isBackend' => \$wlsPanelIsBackend", $src);
    self::assertStringContainsString("'area' => \$wlsPanelArea", $src);
    self::assertStringContainsString('function resolveWlsPanelLocation', $src);
    self::assertStringContainsString('function resolveWlsPanelTransport', $src);
    self::assertStringContainsString('isWlsPanelBackendLocation()', $src);
}

private function assert_WlsDevPanelAttackStatsHttp_testFetchWlsPanelJsonUsesLocationAwareTransport(): void
{
    $src = $this->hookSource_WlsDevPanelAttackStatsHttp();
    self::assertStringContainsString('function fetchWlsPanelJson', $src);
    self::assertStringContainsString("operation: 'attack-stats'", $src);
    $fetchPos = strpos($src, 'function fetchWlsPanelJson');
    self::assertNotFalse($fetchPos);
    $fetchSlice = substr($src, (int) $fetchPos, 1600);
    self::assertStringContainsString('resolveWlsPanelTransport(url)', $fetchSlice);
    self::assertStringNotContainsString('return Weline.adminRequest', $fetchSlice);
}

private function assert_WlsDevPanelAttackStatsHttp_testHealthProbeUsesLocationAwareNativeTransport(): void
{
    $src = $this->hookSource_WlsDevPanelAttackStatsHttp();
    $healthPos = strpos($src, 'function requestWlsHealth');
    self::assertNotFalse($healthPos);
    $healthSlice = substr($src, (int) $healthPos, 900);
    self::assertStringContainsString('resolveWlsPanelTransport(healthUrl)', $healthSlice);
    self::assertStringNotContainsString('return Weline.adminRequest', $healthSlice);
}

private function assert_WlsDevPanelAttackStatsHttp_testTransportKeepsTestAndHealthNativeOnBothLocations(): void
{
    $src = $this->hookSource_WlsDevPanelAttackStatsHttp();
    $transportPos = strpos($src, 'function resolveWlsPanelTransport');
    self::assertNotFalse($transportPos);
    $transportSlice = substr($src, (int) $transportPos, 1400);
    self::assertStringContainsString('isWlsNativePanelUrl(url)', $transportSlice);
    self::assertStringContainsString('isWlsPanelBackendLocation()', $transportSlice);
    self::assertStringContainsString("adminRequest('server'", $transportSlice);
    self::assertStringContainsString('serverAdminRequest', $transportSlice);
}

private function assert_WlsDevPanelAttackStatsHttp_testPanelJsBridgesTestAndHealthPathsToNativeFetch(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/view/statics/wls-performance-panel/panel.js';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('W.serverAdminRequest = function', $src);
    self::assertStringContainsString('server/test', $src);
    self::assertStringContainsString('_wls', $src);
    self::assertStringContainsString('return fetch(urlStr, fetchOpts);', $src);
}

private function assert_WlsDevPanelAttackStatsHttp_testSecurityTabAutoloadsAndRefreshIsOnTitleRight(): void
{
    $src = $this->hookSource_WlsDevPanelAttackStatsHttp();
    self::assertStringNotContainsString('加载攻击统计', $src);
    self::assertStringContainsString('data-wls-action="refresh-attack-stats"', $src);
    self::assertStringContainsString('wls-section-title__actions', $src);
    self::assertStringContainsString('wls-section-refresh', $src);
    $activatePos = strpos($src, 'function activateWlsSectionTab');
    self::assertNotFalse($activatePos);
    $activateSlice = substr($src, (int) $activatePos, 900);
    self::assertStringContainsString("active === 'security'", $activateSlice);
    self::assertStringContainsString('loadWlsAttackStats()', $activateSlice);
}

private function assert_WlsDevPanelAttackStatsHttp_testAttackStatsUiRendersScanWarnings(): void
{
    $src = $this->hookSource_WlsDevPanelAttackStatsHttp();
    $pos = strpos($src, 'window.loadWlsAttackStats = function');
    self::assertNotFalse($pos);
    $slice = substr($src, (int) $pos, 2800);
    self::assertStringContainsString('scan_warnings', $slice);
    self::assertStringContainsString('wls-scan-warnings', $slice);
    self::assertStringContainsString('扫描特征警告', $slice);
}

private function moduleRoot_WlsPanelHostEmbed(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Server';
}

private function stylesheet_WlsPanelHostEmbed(string $name): string
{
    // Panel styling is extracted to module stylesheets; contracts follow the
    // template's <css> reference instead of asserting inline <style> blocks.
    $path = $this->moduleRoot_WlsPanelHostEmbed() . '/view/statics/css/backend/' . $name;
    self::assertFileExists($path);
    return (string) \file_get_contents($path);
}

private function assert_WlsPanelHostEmbed_testMenuPointsToHostAction(): void
{
    $path = $this->moduleRoot_WlsPanelHostEmbed() . '/etc/backend/menu.xml';
    self::assertFileExists($path);
    $document = \simplexml_load_file($path);
    self::assertNotFalse($document);
    $action = null;
    foreach ($document->menu as $menu) {
        if ((string) $menu['name'] === 'wls_panel') {
            $action = (string) $menu['action'];
            break;
        }
    }
    self::assertSame('*/backend/wls-panel/host', $action);
}

private function assert_WlsPanelHostEmbed_testHostTemplateHasIframeAndModeHooks(): void
{
    $path = $this->moduleRoot_WlsPanelHostEmbed() . '/view/templates/backend/WlsPanel/host.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('data-testid="wls-panel-host-frame"', $src);
    self::assertStringContainsString('data-wls-panel-host-shell', $src);
    self::assertStringContainsString('data-mode="float"', $src);
    self::assertStringContainsString('data-wls-panel-host-expand', $src);
    self::assertStringContainsString('data-wls-panel-host-collapse', $src);
    self::assertStringContainsString('wls.panel.host.mode', $src);
    self::assertStringContainsString("setMode('float')", $src);
    self::assertStringContainsString('wlsPanelHostFrameUrl', $src);
    self::assertStringContainsString('wlsPanelHostStandaloneUrl', $src);
    self::assertStringContainsString('Weline_Server::css/backend/wls-panel-host.css', $src);
    $css = $this->stylesheet_WlsPanelHostEmbed('wls-panel-host.css');
    self::assertStringContainsString('z-index: 13000', $css);
    self::assertStringContainsString('html:has(.wls-panel-host-shell[data-mode="float"])', $css);
    self::assertStringContainsString('overscroll-behavior: contain', $css);
    self::assertStringContainsString('max-height: 100dvh', $css);
    // 默认布局已渲染 H1 页标题；宿主工具条禁止再叠同名 h1/h2/h3/h4
    self::assertDoesNotMatchRegularExpression('/<h[1-4][^>]*>\s*<\?=\s*__\(\s*[\'"]WLS 面板[\'"]\s*\)/u', $src);
}

private function assert_WlsPanelHostEmbed_testControllerAssignsFrameUrlWithEmbeddedFlag(): void
{
    $path = $this->moduleRoot_WlsPanelHostEmbed() . '/Controller/Backend/WlsPanel.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('function getHost()', $src);
    self::assertStringContainsString("getBackendUrl('*/backend/wls-panel', ['embedded' => '1'])", $src);
    self::assertStringContainsString("assign('wlsPanelHostFrameUrl'", $src);
    self::assertStringContainsString("assign('wlsPanelHostStandaloneUrl'", $src);
    self::assertStringContainsString('function isEmbeddedPanelRequest()', $src);
    self::assertStringContainsString("assign('panelEmbedded'", $src);
    self::assertDoesNotMatchRegularExpression('/function getHost\(\): string\s*\{[^}]*useStandaloneLayout\(\)/s', $src);
}

private function assert_WlsPanelHostEmbed_testStandaloneShellUsesMainColumnScrollport(): void
{
    $path = $this->moduleRoot_WlsPanelHostEmbed() . '/view/templates/backend/WlsPanel/index.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("panelEmbedded ? ' is-embedded' : ''", $src);
    self::assertStringContainsString('Weline_Server::css/backend/wls-panel.css', $src);
    $css = $this->stylesheet_WlsPanelHostEmbed('wls-panel.css');
    self::assertStringContainsString('grid-template-rows: minmax(0, 1fr)', $css);
    self::assertStringContainsString('html:has(.wls-standalone-shell)', $css);
    self::assertStringContainsString('.w-backend-standalone', $css);
    self::assertStringContainsString('#main-content', $css);
    self::assertMatchesRegularExpression('/\.wls-shell-main\s*\{[^}]*overflow-y:\s*auto/s', $css);
    self::assertMatchesRegularExpression('/\.wls-shell-main\s*\{[^}]*min-height:\s*0/s', $css);
}
private function assert_ServerStatics_testPanelScriptsAvoidNativeDialogs(): void
{
    $root = $this->moduleRoot_WlsPanelHostEmbed() . '/view/statics';
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );
    $offenders = [];
    foreach ($iterator as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
            continue;
        }
        if (\strtolower($file->getExtension()) !== 'js') {
            continue;
        }
        $source = (string) \file_get_contents($file->getPathname());
        foreach (['window.alert(', 'window.confirm(', 'window.prompt('] as $needle) {
            if (\str_contains($source, $needle)) {
                $offenders[] = \str_replace($root . '/', '', $file->getPathname()) . ': ' . $needle;
            }
        }
    }
    self::assertSame([], $offenders, \implode("\n", $offenders));
}
}
