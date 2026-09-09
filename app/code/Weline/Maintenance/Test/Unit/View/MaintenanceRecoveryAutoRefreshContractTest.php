<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class MaintenanceRecoveryAutoRefreshContractTest extends TestCase
{
    public function testStandaloneTemplateRecoveryScriptReloadsOnNon503AndHardRefresh(): void
    {
        $template = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/maintenance.phtml'
        );

        self::assertStringContainsString('function isRecovered(response)', $template);
        self::assertStringContainsString("redirect: 'manual'", $template);
        self::assertStringContainsString('scheduleHardReload()', $template);
        self::assertStringContainsString('hardReloadDelay = 30000', $template);
        self::assertStringContainsString('/maintenance/frontend/recovery-check', $template);
        self::assertStringContainsString('_maintenance_recovery_probe', $template);
        self::assertStringContainsString('X-Maintenance-Recovery-Check', $template);
        self::assertStringNotContainsString('response.status === 200', $template);
        self::assertStringNotContainsString("redirect: 'follow'", $template);
    }

    public function testCanonicalMaintenanceJsMatchesRecoveryContract(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/maintenance.js'
        );

        self::assertStringContainsString('function isRecovered(response)', $js);
        self::assertStringContainsString("redirect: 'manual'", $js);
        self::assertStringContainsString('scheduleHardReload()', $js);
        self::assertStringContainsString('RECOVERY_CHECK_PATH', $js);
        self::assertStringContainsString('/maintenance/frontend/recovery-check', $js);
        self::assertStringContainsString('_maintenance_recovery_probe', $js);
        self::assertStringContainsString('X-Maintenance-Recovery-Check', $js);
        self::assertStringNotContainsString('response.status === 200', $js);
    }

    public function testThemeUiMirrorMatchesCanonicalRecoveryHelpers(): void
    {
        $canonical = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/maintenance.js'
        );
        $themeMirror = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-maintenance.js'
        );

        $normalize = static function (string $source): string {
            $source = \preg_replace('/^(?:\\/\\*[\\s\\S]*?\\*\\/\\s*)+/', '', $source) ?? $source;

            return \preg_replace('/\\s+/', ' ', \trim($source)) ?? $source;
        };

        self::assertSame($normalize($canonical), $normalize($themeMirror));
    }
}
