<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Theme UI container busy overlay: section-level loading that blocks interaction.
 */
final class ContainerBusyOverlayContractTest extends TestCase
{
    public function testFoundationExposesLocalLoadingAndBusyOverlay(): void
    {
        $root = dirname(__DIR__, 2);
        $css = $root . '/view/ui/css/foundation.css';
        $published = $root . '/view/statics/ui/weline-foundation.css';
        self::assertFileExists($css);
        self::assertFileExists($published);

        $cssSrc = (string) file_get_contents($css);
        $publishedSrc = (string) file_get_contents($published);

        foreach ([$cssSrc, $publishedSrc] as $src) {
            self::assertStringContainsString('.w-loading.w-loading--local', $src);
            self::assertStringContainsString('.w-busy', $src);
            self::assertStringContainsString('.w-busy[data-w-busy="true"]', $src);
            self::assertStringContainsString('.w-busy__overlay', $src);
            self::assertStringContainsString('.w-busy__backdrop', $src);
            self::assertStringContainsString('.w-busy__content', $src);
            self::assertStringContainsString('pointer-events: none', $src);
        }
    }

    public function testUiRuntimeExposesSetBusyApiSyncedToStatics(): void
    {
        $root = dirname(__DIR__, 2);
        $js = $root . '/view/ui/js/weline-ui.js';
        $published = $root . '/view/statics/ui/weline-ui.js';
        self::assertFileExists($js);
        self::assertFileExists($published);

        $jsSrc = (string) file_get_contents($js);
        $publishedSrc = (string) file_get_contents($published);

        foreach ([$jsSrc, $publishedSrc] as $src) {
            self::assertStringContainsString('function setBusy(', $src);
            self::assertStringContainsString('data-w-busy-overlay', $src);
            self::assertStringContainsString('w-busy__overlay', $src);
            self::assertStringContainsString("host.dataset.wBusy = 'true'", $src);
            self::assertStringContainsString("setAttribute('aria-busy', 'true')", $src);
            self::assertStringContainsString('setBusy,', $src);
            self::assertStringContainsString('UI.setBusy = setBusy', $src);
            self::assertStringContainsString('busy: {', $src);
        }
    }
}
