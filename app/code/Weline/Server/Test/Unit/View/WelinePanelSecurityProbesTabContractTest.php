<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Frontend WelinePanel「安全探针」Tab 契约：主 Tab + PanelAccess API + panel.js mount。
 */
final class WelinePanelSecurityProbesTabContractTest extends TestCase
{
    public function testTabsAfterRegistersSecurityProbesTab(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/tabs-after.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('data-tab="wls-probes"', $src);
        self::assertStringContainsString('安全探针', $src);
    }

    public function testSearchAreasRegistersMountAndEndpoint(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/search-areas-after.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('wls-security-probes/panel.js', $src);
        self::assertStringContainsString("/server/test/wls-security-probes", $src);
        self::assertStringContainsString('WelineSecurityProbesPanel.mount', $src);
        self::assertStringContainsString("id: TAB_ID", $src);
        self::assertStringContainsString("wls-probes", $src);
    }

    public function testPanelJsExposesMountApi(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/statics/wls-security-probes/panel.js';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('window.WelineSecurityProbesPanel', $src);
        self::assertStringContainsString('mount: mount', $src);
        self::assertStringContainsString('X-Weline-Security-Probe-Token', $src);
        self::assertStringContainsString('credentials: "omit"', $src);
    }
}
