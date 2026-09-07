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
        self::assertStringContainsString("if (/^\\/pub\\/errors\\/maintenance\\//.test(url.pathname))", $template);
        self::assertStringContainsString("url.pathname = '/'", $template);
        self::assertStringContainsString('social-login', $template);
        self::assertStringContainsString('searchParams.delete', $template);
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
        self::assertStringContainsString("if (/^\\/pub\\/errors\\/maintenance\\//.test(url.pathname))", $js);
        self::assertStringContainsString('social-login', $js);
        self::assertStringContainsString("searchParams.delete(key)", $js);
        self::assertStringContainsString("'code'", $js);
        self::assertStringContainsString("'state'", $js);
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
            $source = \preg_replace('/^\\/\\*[\\s\\S]*?\\*\\/\\s*/', '', $source) ?? $source;

            return \preg_replace('/\\s+/', ' ', \trim($source)) ?? $source;
        };

        self::assertSame($normalize($canonical), $normalize($themeMirror));
    }
}
