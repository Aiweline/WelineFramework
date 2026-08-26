<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * DevTool WLS tab is shared by frontend and backend. Location comes from WLS
 * WELINE_AREA. Frontend must not use the backend-auth adminRequest bridge for
 * /server/test attack-stats; /_wls and /server/test stay native on both sides.
 */
final class WlsDevPanelAttackStatsHttpContractTest extends TestCase
{
    private function hookSource(): string
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/search-areas-after.phtml';
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testRuntimeConfigExposesWlsProvidedLocation(): void
    {
        $src = $this->hookSource();
        self::assertStringContainsString("RequestContext::getWelineArea()", $src);
        self::assertStringContainsString("'location' => \$wlsPanelLocation", $src);
        self::assertStringContainsString("'isBackend' => \$wlsPanelIsBackend", $src);
        self::assertStringContainsString("'area' => \$wlsPanelArea", $src);
        self::assertStringContainsString('function resolveWlsPanelLocation', $src);
        self::assertStringContainsString('function resolveWlsPanelTransport', $src);
        self::assertStringContainsString('isWlsPanelBackendLocation()', $src);
    }

    public function testFetchWlsPanelJsonUsesLocationAwareTransport(): void
    {
        $src = $this->hookSource();
        self::assertStringContainsString('function fetchWlsPanelJson', $src);
        self::assertStringContainsString("operation: 'attack-stats'", $src);

        $fetchPos = strpos($src, 'function fetchWlsPanelJson');
        self::assertNotFalse($fetchPos);
        $fetchSlice = substr($src, (int)$fetchPos, 1600);
        self::assertStringContainsString('resolveWlsPanelTransport(url)', $fetchSlice);
        self::assertStringNotContainsString('return Weline.adminRequest', $fetchSlice);
    }

    public function testHealthProbeUsesLocationAwareNativeTransport(): void
    {
        $src = $this->hookSource();
        $healthPos = strpos($src, 'function requestWlsHealth');
        self::assertNotFalse($healthPos);
        $healthSlice = substr($src, (int)$healthPos, 900);
        self::assertStringContainsString('resolveWlsPanelTransport(healthUrl)', $healthSlice);
        self::assertStringNotContainsString('return Weline.adminRequest', $healthSlice);
    }

    public function testTransportKeepsTestAndHealthNativeOnBothLocations(): void
    {
        $src = $this->hookSource();
        $transportPos = strpos($src, 'function resolveWlsPanelTransport');
        self::assertNotFalse($transportPos);
        $transportSlice = substr($src, (int)$transportPos, 1400);
        self::assertStringContainsString('isWlsNativePanelUrl(url)', $transportSlice);
        self::assertStringContainsString('isWlsPanelBackendLocation()', $transportSlice);
        self::assertStringContainsString("adminRequest('server'", $transportSlice);
        self::assertStringContainsString('serverAdminRequest', $transportSlice);
    }

    public function testPanelJsBridgesTestAndHealthPathsToNativeFetch(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/wls-performance-panel/panel.js';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('W.serverAdminRequest = function', $src);
        self::assertStringContainsString('server/test', $src);
        self::assertStringContainsString('_wls', $src);
        self::assertStringContainsString('return fetch(urlStr, fetchOpts);', $src);
    }

    public function testSecurityTabAutoloadsAndRefreshIsOnTitleRight(): void
    {
        $src = $this->hookSource();
        self::assertStringNotContainsString('加载攻击统计', $src);
        self::assertStringContainsString('data-wls-action="refresh-attack-stats"', $src);
        self::assertStringContainsString('wls-section-title__actions', $src);
        self::assertStringContainsString('wls-section-refresh', $src);

        $activatePos = strpos($src, 'function activateWlsSectionTab');
        self::assertNotFalse($activatePos);
        $activateSlice = substr($src, (int)$activatePos, 900);
        self::assertStringContainsString("active === 'security'", $activateSlice);
        self::assertStringContainsString('loadWlsAttackStats()', $activateSlice);
    }
}
