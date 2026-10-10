<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Visitor\Service\PixelDashboardWidgetData;
use Weline\Visitor\Service\Report\PixelDetailReportTabService;
use Weline\Visitor\Service\Report\PixelReportCatalog;

/**
 * E04b：pixel_value_by_channel 部件契约（注册 + 渠道下钻；不查库）。
 */
final class PixelValueByChannelWidgetContractTest extends TestCase
{
    public function testWidgetPhpRegistersPixelValueByChannel(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-value-by-channel.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/dashboard/widgets/pixel-value-by-channel.phtml');
        self::assertStringContainsString('@widget.code {pixel_value_by_channel}', $src);
        self::assertStringContainsString('@widget.type {table}', $src);
        self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
        self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
        self::assertStringContainsString('"required":false', $src);
    }

    public function testWidgetCodeMatchesReportCatalogNarrowMetrics(): void
    {
        $catalog = (new PixelReportCatalog())->require('pixel_value_by_channel');
        self::assertSame('pixel_value_by_channel', $catalog['widget_code']);
        self::assertSame('channel_code', $catalog['dimension']);
        self::assertSame(['events', 'value_sum', 'valued_events'], $catalog['metrics']);
        self::assertSame([], $catalog['filters']);
    }

    public function testTemplateUsesEngineReportAndChannelDrilldown(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = $root . '/view/templates/dashboard/widgets/pixel-value-by-channel.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('data-pixel-widget="pixel_value_by_channel"', $src);
        self::assertStringContainsString('getValueByChannelReport', $src);
        self::assertStringContainsString('channelDrilldownUrl', $src);
        self::assertStringContainsString('valued_events', $src);
        self::assertStringContainsString('详情报表', $src);

        $installer = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'pixel_value_by_channel'", $installer);
        self::assertStringContainsString("'replace_layout' => false", $installer);
    }

    public function testValueByChannelDrilldownUrlCarriesChannelCode(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturn(null);

        $captured = null;
        $url = $this->createMock(Url::class);
        $url->method('getBackendUrlPath')->willReturnCallback(
            static function (string $path, array $query = []) use (&$captured): string {
                $captured = ['path' => $path, 'query' => $query];

                return '/backend/' . $path . '?' . http_build_query($query);
            }
        );

        $service = new PixelDashboardWidgetData($request, $url);
        $href = $service->channelDrilldownUrl(
            ['range' => '7d', 'website_id' => 9],
            'channel_code',
            'summer_sale'
        );

        self::assertStringContainsString('pixel-dashboard/list', $href);
        self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
        self::assertSame('summer_sale', $captured['query']['channel_code'] ?? null);
        self::assertSame('9', $captured['query']['websiteId'] ?? null);
        self::assertSame('7d', $captured['query']['range'] ?? null);
    }

    public function testValueByChannelReportCodeIsMountedOnDetailTabs(): void
    {
        self::assertTrue(
            (new PixelDetailReportTabService())->isMounted('pixel_value_by_channel')
        );
    }
}
