<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\Report\PixelDetailReportTabService;
use Weline\Visitor\Service\Report\PixelReportCatalog;

/**
 * D07a–D07f：detail 逐个挂载引擎 Tab 的契约（catalog 六个预设全部挂完）。
 */
final class PixelDashboardDetailReportTabContractTest extends TestCase
{
    public function testDetailTemplateMountsOnlyEngineReportTabsShell(): void
    {
        $root = dirname(__DIR__, 4);
        $tpl = $root . '/view/templates/Backend/PixelDashboard/detail.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('data-pixel-report-tabs', $src);
        self::assertStringContainsString('data-report-tab', $src);
        self::assertStringContainsString('report_tabs', $src);
        self::assertStringContainsString('active_report_tab', $src);
        self::assertStringContainsString('report_tab', $src);
        self::assertStringContainsString('引擎报表', $src);
        self::assertStringContainsString('drilldownExtras', $src);
        // 下钻须带 Tab 的 catalog filters（paid/social Tab 需 traffic_type）
        self::assertStringContainsString("\$tab['filters']", $src);
        // 事件价值 Tab 的派生均值列按行数据存在与否显示
        self::assertStringContainsString('avg_value', $src);
        // 不得一次硬编码挂满 catalog code（由 service 列表驱动）
        self::assertStringNotContainsString("'pixel_event_value'", $src);
        self::assertStringNotContainsString("'pixel_paid'", $src);
        self::assertStringNotContainsString("'pixel_social'", $src);
        self::assertStringNotContainsString("'pixel_value_by_channel'", $src);
    }

    public function testControllerWiresDetailReportTabsViaService(): void
    {
        $root = dirname(__DIR__, 4);
        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');

        self::assertStringContainsString('PixelDetailReportTabService', $controller);
        self::assertStringContainsString('buildDetailReportTabs', $controller);
        self::assertStringContainsString("'report_tabs'", $controller);
        self::assertStringContainsString("'active_report_tab'", $controller);
        self::assertStringContainsString('fetchHotReportEventRows', $controller);
        self::assertStringContainsString('FIRST_TAB_CODE', $controller);
    }

    public function testMountedCodesCoverFullEnabledCatalog(): void
    {
        self::assertSame(
            [
                'pixel_channels',
                'pixel_traffic_type',
                'pixel_paid',
                'pixel_social',
                'pixel_event_value',
                'pixel_value_by_channel',
            ],
            PixelDetailReportTabService::MOUNTED_REPORT_CODES
        );
        self::assertSame('pixel_channels', PixelDetailReportTabService::FIRST_TAB_CODE);

        $catalogCodes = (new PixelReportCatalog())->codes();
        self::assertSame([], array_diff($catalogCodes, PixelDetailReportTabService::MOUNTED_REPORT_CODES));
        self::assertSame([], array_diff(PixelDetailReportTabService::MOUNTED_REPORT_CODES, $catalogCodes));
    }
}
