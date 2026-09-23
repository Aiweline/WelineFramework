<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 沙盒初始化韧性：telemetry / PageBuilder 印象失败不得阻断 WelinePixelSandbox；
 * 半截加载可重入；checkout_success 去重仍写监视流；recentEvents 可回放。
 */
final class PixelSandboxInitResilienceContractTest extends TestCase
{
    public function testPixelJsDefersLoadedFlagAndGuardsTelemetry(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
        $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
        $monitor = (string) \file_get_contents($root . '/view/statics/js/event-sandbox-monitor.js');
        $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');

        foreach ([$pixel, $phtml] as $source) {
            self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.09.23-r2d-param2'", $source);
            self::assertStringContainsString('检测到半截初始化（缺沙盒），重入补建', $source);
            self::assertStringContainsString('function __ensurePixelSandboxBus', $source);
            self::assertStringContainsString('function __seedSandboxFromRecentEvents', $source);
            self::assertStringContainsString('__WelineVisitorForwardersBridgeWrapped', $source);
            self::assertStringContainsString('__WelinePixelSeedSandboxFromRecent', $source);
            self::assertStringContainsString('behavior telemetry init failed', $source);
            self::assertStringContainsString('pagebuilder impressions init failed', $source);
            // 行为遥测须在沙盒对象创建之后调用
            $sandboxPos = \strpos($source, 'window.WelinePixelSandbox = {');
            $telemetryPos = \strpos($source, '__initBehaviorTelemetry();');
            self::assertNotFalse($sandboxPos);
            self::assertNotFalse($telemetryPos);
            self::assertGreaterThan($sandboxPos, $telemetryPos);
            // __WelinePixelLoaded=true 仅在沙盒就绪尾部
            self::assertSame(1, \preg_match_all('/window\.__WelinePixelLoaded\s*=\s*true/', $source));
            self::assertStringContainsString("hit_kind: 'dedupe'", $source);
            self::assertStringContainsString('__ensurePixelSandboxBus()', $source);
            self::assertStringContainsString('__seedSandboxFromRecentEvents(true)', $source);
        }

        self::assertStringContainsString('__WelinePixelSeedSandboxFromRecent', $monitor);
        self::assertStringContainsString('runtime.recentEvents', $pixel);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260923-r2d-param2'", $bootstrap);

        $module = (string) \file_get_contents($root . '/etc/module.php');
        self::assertStringContainsString("'1.1.43'", $module);
    }
}
