<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * DEV 部件 HTML 健康 toast：定位上层槽位容器，并强制弹窗暴露错误明细。
 */
final class WidgetHtmlHealthLocateToastContractTest extends TestCase
{
    public function testToastBridgeExposesLocatePopupContract(): void
    {
        $bridge = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SlotRendererService.php'
        );

        self::assertStringContainsString('data-w-widget-health-bridge', $bridge);
        self::assertStringContainsString('w-widget-health-locate-pop', $bridge);
        self::assertStringContainsString('w-widget-health-locate-backdrop', $bridge);
        self::assertStringContainsString("textContent = '定位'", $bridge);
        self::assertStringContainsString('locateHealthWidget', $bridge);
        self::assertStringContainsString('buildHealthToastMessage', $bridge);
        self::assertStringContainsString('resolveLocateHost', $bridge);
        self::assertStringContainsString('w-widget-health-locate-panel', $bridge);
        self::assertStringContainsString('w-widget-health-locate-host', $bridge);
        self::assertStringContainsString('data-wslot', $bridge);
        self::assertStringContainsString('forceExposeHealthPanel', $bridge);
    }

    public function testPreviewAndEditorModeExposeLocatePopupContract(): void
    {
        $preview = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-preview.js'
        );
        $editor = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/editor-mode.js'
        );
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/editor-mode.css'
        );

        foreach ([$preview, $editor] as $source) {
            self::assertStringContainsString('w-widget-health-locate-pop', $source);
            self::assertStringContainsString('locateHealthWidget', $source);
            self::assertStringContainsString("textContent = '定位'", $source);
            self::assertStringContainsString('resolveLocateHost', $source);
            self::assertStringContainsString('forceExposeHealthPanel', $source);
            self::assertStringContainsString('w-widget-health-locate-panel', $source);
            self::assertStringContainsString('w-widget-health-locate-host', $source);
        }
        self::assertStringContainsString('w-widget-health-locate-pop', $css);
        self::assertStringContainsString('w-widget-health-locate-backdrop', $css);
        self::assertStringContainsString('w-widget-health-locate-host', $css);
        self::assertStringContainsString('w-widget-health-locate-panel', $css);
    }
}
