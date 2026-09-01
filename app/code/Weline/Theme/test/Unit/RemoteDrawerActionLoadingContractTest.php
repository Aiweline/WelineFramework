<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class RemoteDrawerActionLoadingContractTest extends TestCase
{
    public function testRemoteDrawerSetsThemeButtonLoadingOnToolbarActions(): void
    {
        $root = dirname(__DIR__, 2);
        $js = $root . '/view/ui/js/weline-ui.js';
        $css = $root . '/view/ui/css/foundation.css';
        self::assertFileExists($js);
        self::assertFileExists($css);

        $jsSrc = (string) file_get_contents($js);
        $cssSrc = (string) file_get_contents($css);

        self::assertStringContainsString("define('remote-drawer'", $jsSrc);
        self::assertStringContainsString('setActionBusy', $jsSrc);
        self::assertStringContainsString("setAttribute('aria-busy', 'true')", $jsSrc);
        self::assertStringContainsString('removeAttribute(\'aria-busy\')', $jsSrc);
        self::assertStringContainsString('setFrameBusy', $jsSrc);
        self::assertStringContainsString('w-remote-drawer__frame-host', $jsSrc);
        self::assertStringContainsString('w-remote-drawer__loading', $jsSrc);
        self::assertStringContainsString('pointerEvents', $jsSrc);
        self::assertStringContainsString("listen(frame, 'load', () => clearBusy())", $jsSrc);
        self::assertStringContainsString("listen(element, 'weline:ui:drawer:close', () => clearBusy())", $jsSrc);
        self::assertStringContainsString("action === 'reload'", $jsSrc);
        self::assertStringContainsString("action === 'submit'", $jsSrc);

        self::assertStringContainsString('.w-button[aria-busy="true"]', $cssSrc);
        self::assertStringContainsString('.w-button[aria-busy="true"]::after', $cssSrc);
        self::assertStringContainsString('animation: w-spin 700ms linear infinite', $cssSrc);
        self::assertStringContainsString('.w-remote-drawer__frame-host', $cssSrc);
        self::assertStringContainsString('.w-remote-drawer__loading', $cssSrc);
        self::assertStringContainsString('.w-remote-drawer__loading-backdrop', $cssSrc);
    }

    public function testWebsiteRemoteDrawerToolbarExposesReloadAndSubmitActions(): void
    {
        $template = dirname(__DIR__, 3) . '/Websites/view/templates/Admin/Website/index.phtml';
        self::assertFileExists($template);
        $source = (string) file_get_contents($template);

        self::assertStringContainsString('data-w-component="drawer remote-drawer"', $source);
        self::assertStringContainsString('data-w-remote-action="reload"', $source);
        self::assertStringContainsString('data-w-remote-action="submit"', $source);
        self::assertStringContainsString('data-w-remote-save="true"', $source);
    }
}
