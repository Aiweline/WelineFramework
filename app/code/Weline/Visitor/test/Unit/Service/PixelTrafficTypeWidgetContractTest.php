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
 * E02：pixel_traffic_type 部件契约（注册 + 下钻；不查库）。
 */
final class PixelTrafficTypeWidgetContractTest extends TestCase
{
    public function testWidgetPhpRegistersPixelTrafficType(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-traffic-type.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Visitor::templates/dashboard/widgets/pixel-channels.phtml'
        ));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/dashboard/widgets/pixel-traffic-type.phtml');
        self::assertStringContainsString('@widget.code {pixel_traffic_type}', $src);
        self::assertStringContainsString('@widget.type {table}', $src);
        self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
        self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
        self::assertStringContainsString('"required":false', $src);
    }

    public function testWidgetCodeMatchesReportCatalog(): void
    {
        $catalog = (new PixelReportCatalog())->require('pixel_traffic_type');
        self::assertSame('pixel_traffic_type', $catalog['widget_code']);
        self::assertSame('traffic_type', $catalog['dimension']);
    }

    public function testTemplateUsesEngineReportAndDrilldown(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = $root . '/view/templates/dashboard/widgets/pixel-traffic-type.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('data-pixel-widget="pixel_traffic_type"', $src);
        self::assertStringContainsString('getTrafficTypeReport', $src);
        self::assertStringContainsString('channelDrilldownUrl', $src);
        self::assertStringContainsString('listUrl', $src);
        self::assertStringContainsString('详情报表', $src);

        $installer = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'pixel_traffic_type'", $installer);
        self::assertStringContainsString("'replace_layout' => false", $installer);
    }

    public function testTrafficTypeDrilldownUrlCarriesTrafficType(): void
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
            ['range' => '7d', 'website_id' => 2],
            'traffic_type',
            'paid'
        );

        self::assertStringContainsString('pixel-dashboard/list', $href);
        self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
        self::assertSame('paid', $captured['query']['traffic_type'] ?? null);
        self::assertSame('2', $captured['query']['websiteId'] ?? null);
        self::assertSame('7d', $captured['query']['range'] ?? null);
        self::assertArrayNotHasKey('channel_code', $captured['query'] ?? []);
    }

    public function testTrafficTypeReportCodeIsMountedOnDetailTabs(): void
    {
        self::assertTrue(
            (new PixelDetailReportTabService())->isMounted('pixel_traffic_type')
        );
    }
}
