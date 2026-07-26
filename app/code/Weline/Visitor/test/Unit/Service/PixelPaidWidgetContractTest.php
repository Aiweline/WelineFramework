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
 * E03a：pixel_paid 部件契约（注册 + paid 过滤下钻；不查库）。
 */
final class PixelPaidWidgetContractTest extends TestCase
{
    public function testWidgetPhpRegistersPixelPaid(): void
    {
        $root = dirname(__DIR__, 3);
        $widgetFile = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        self::assertFileExists($widgetFile);

        /** @var array<string, array<string, mixed>> $widgets */
        $widgets = require $widgetFile;
        self::assertArrayHasKey('pixel_paid', $widgets);
        $widget = $widgets['pixel_paid'];
        self::assertSame('pixel_paid', $widget['code']);
        self::assertSame('table', $widget['type']);
        self::assertStringContainsString('pixel-paid.phtml', (string)$widget['template']);
        self::assertSame('dashboard-detail', $widget['slot']);
        self::assertSame('weline_visitor_event_statistics', $widget['default_injections'][0]['default_view']);
        self::assertFalse((bool)($widget['default_injections'][0]['required'] ?? true));
        // 与前序部件并存
        self::assertArrayHasKey('pixel_channels', $widgets);
        self::assertArrayHasKey('pixel_traffic_type', $widgets);
        self::assertArrayHasKey('pixel_social', $widgets);
    }

    public function testWidgetCodeMatchesReportCatalogWithPaidFilter(): void
    {
        $catalog = (new PixelReportCatalog())->require('pixel_paid');
        self::assertSame('pixel_paid', $catalog['widget_code']);
        self::assertSame('utm_campaign', $catalog['dimension']);
        self::assertSame(['traffic_type' => 'paid'], $catalog['filters']);
    }

    public function testTemplateUsesEngineReportAndPaidDrilldown(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = $root . '/view/templates/dashboard/widgets/pixel-paid.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('data-pixel-widget="pixel_paid"', $src);
        self::assertStringContainsString('getPaidReport', $src);
        self::assertStringContainsString('channelDrilldownUrl', $src);
        self::assertStringContainsString("traffic_type' => 'paid'", $src);
        self::assertStringContainsString('详情报表', $src);

        $installer = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'pixel_paid'", $installer);
        self::assertStringContainsString("'replace_layout' => false", $installer);
    }

    public function testPaidDrilldownUrlCarriesTrafficTypeAndCampaign(): void
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
            ['range' => '7d', 'website_id' => 5],
            'utm_campaign',
            'summer',
            ['traffic_type' => 'paid']
        );

        self::assertStringContainsString('pixel-dashboard/list', $href);
        self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
        self::assertSame('paid', $captured['query']['traffic_type'] ?? null);
        self::assertSame('summer', $captured['query']['utm_campaign'] ?? null);
        self::assertSame('5', $captured['query']['websiteId'] ?? null);
        self::assertSame('7d', $captured['query']['range'] ?? null);
    }

    public function testPaidReportCodeIsMountedOnDetailTabs(): void
    {
        self::assertTrue(
            (new PixelDetailReportTabService())->isMounted('pixel_paid')
        );
    }
}
