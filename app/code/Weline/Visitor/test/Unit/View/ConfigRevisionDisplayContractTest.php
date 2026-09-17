<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 后台 / 沙盒监视 / 助手 展示 configRevision 对齐契约。
 */
final class ConfigRevisionDisplayContractTest extends TestCase
{
    public function test_admin_tracking_vendor_shows_config_revision(): void
    {
        $php = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        $tpl = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml');
        self::assertStringContainsString("assign('config_revision'", $php);
        self::assertStringContainsString('配置版本 %{2}', $php);
        self::assertStringContainsString('tv-config-revision', $tpl);
        self::assertStringContainsString('setConfigRevisionDisplay', $tpl);
        self::assertStringContainsString('formatScopeVersionLabel', $tpl);
        self::assertStringContainsString('站点 ', $tpl);
        self::assertStringContainsString('店铺 ', $tpl);
        self::assertStringContainsString('渠道 ', $tpl);
    }

    public function test_monitor_and_assistant_show_config_revision(): void
    {
        $mon = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/event-sandbox-monitor.js');
        $alias = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/lifecycle-event-assistant.js');
        $cfg = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/VisitorTrackingConfig.php');
        self::assertStringContainsString('wesm-config-revision', $mon);
        self::assertStringContainsString('formatScopeVersionLabel', $mon);
        self::assertStringContainsString('站点 ', $mon);
        self::assertStringContainsString('店铺 ', $mon);
        self::assertStringContainsString('渠道 ', $mon);
        self::assertStringContainsString('getConfigRevision', $mon);
        self::assertStringContainsString('configScope', $cfg);
        self::assertStringContainsString('buildConfigScopeContext', $cfg);
        self::assertStringContainsString('20260916-event-sandbox-monitor10', $mon);
        self::assertStringContainsString('weline:pixel-sandbox:event', $mon);
        self::assertStringContainsString('_earlyBuffer', $mon);
        self::assertStringContainsString('getConfigRevision', $alias);
        self::assertStringContainsString('monitor10', $alias);
    }
}
