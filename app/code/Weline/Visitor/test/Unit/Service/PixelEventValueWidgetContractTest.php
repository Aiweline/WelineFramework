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
 * E04a：pixel_event_value 部件契约（注册 + event 下钻；不查库）。
 */
final class PixelEventValueWidgetContractTest extends TestCase
{
    public function testWidgetPhpRegistersPixelEventValue(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-event-value.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Visitor::templates/dashboard/widgets/pixel-social.phtml'
        ));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Visitor::templates/dashboard/widgets/pixel-value-by-channel.phtml'
        ));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/dashboard/widgets/pixel-event-value.phtml');
        self::assertStringContainsString('@widget.code {pixel_event_value}', $src);
        self::assertStringContainsString('@widget.type {table}', $src);
        self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
        self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
        self::assertStringContainsString('"required":false', $src);
    }

    public function testWidgetCodeMatchesReportCatalog(): void
    {
        $catalog = (new PixelReportCatalog())->require('pixel_event_value');
        self::assertSame('pixel_event_value', $catalog['widget_code']);
        self::assertSame('event_name', $catalog['dimension']);
        self::assertSame([], $catalog['filters']);
        self::assertContains('value_sum', $catalog['metrics']);
    }

    public function testTemplateUsesEngineReportAvgValueAndEventDrilldown(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = $root . '/view/templates/dashboard/widgets/pixel-event-value.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('data-pixel-widget="pixel_event_value"', $src);
        self::assertStringContainsString('getEventValueReport', $src);
        self::assertStringContainsString('channelDrilldownUrl', $src);
        self::assertStringContainsString('avg_value', $src);
        self::assertStringContainsString('详情报表', $src);

        $installer = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'pixel_event_value'", $installer);
        self::assertStringContainsString("'replace_layout' => false", $installer);
    }

    public function testEventValueDrilldownUrlCarriesEvent(): void
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
            ['range' => '7d', 'website_id' => 1],
            'event_name',
            'purchase'
        );

        self::assertStringContainsString('pixel-dashboard/list', $href);
        self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
        self::assertSame('purchase', $captured['query']['event'] ?? null);
        self::assertSame('1', $captured['query']['websiteId'] ?? null);
        self::assertSame('7d', $captured['query']['range'] ?? null);
    }

    public function testEventValueReportCodeIsMountedOnDetailTabs(): void
    {
        self::assertTrue(
            (new PixelDetailReportTabService())->isMounted('pixel_event_value')
        );
    }
}
