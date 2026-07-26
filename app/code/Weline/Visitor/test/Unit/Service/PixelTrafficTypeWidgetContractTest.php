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
        $root = dirname(__DIR__, 3);
        $widgetFile = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        self::assertFileExists($widgetFile);

        /** @var array<string, array<string, mixed>> $widgets */
        $widgets = require $widgetFile;
        self::assertArrayHasKey('pixel_traffic_type', $widgets);
        $widget = $widgets['pixel_traffic_type'];
        self::assertSame('pixel_traffic_type', $widget['code']);
        self::assertSame('table', $widget['type']);
        self::assertStringContainsString('pixel-traffic-type.phtml', (string)$widget['template']);
        self::assertSame('dashboard-detail', $widget['slot']);
        self::assertSame('weline_visitor_event_statistics', $widget['default_injections'][0]['default_view']);
        // E02 注入保持可选；种子布局由 E05 写入 Installer
        self::assertFalse((bool)($widget['default_injections'][0]['required'] ?? true));
        // 与 E01 并存，互不覆盖
        self::assertArrayHasKey('pixel_channels', $widgets);
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
