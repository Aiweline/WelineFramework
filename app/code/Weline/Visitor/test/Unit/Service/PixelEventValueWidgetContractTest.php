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
        $root = dirname(__DIR__, 3);
        $widgetFile = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        self::assertFileExists($widgetFile);

        /** @var array<string, array<string, mixed>> $widgets */
        $widgets = require $widgetFile;
        self::assertArrayHasKey('pixel_event_value', $widgets);
        $widget = $widgets['pixel_event_value'];
        self::assertSame('pixel_event_value', $widget['code']);
        self::assertSame('table', $widget['type']);
        self::assertStringContainsString('pixel-event-value.phtml', (string)$widget['template']);
        self::assertSame('dashboard-detail', $widget['slot']);
        self::assertSame('weline_visitor_event_statistics', $widget['default_injections'][0]['default_view']);
        self::assertFalse((bool)($widget['default_injections'][0]['required'] ?? true));
        self::assertArrayHasKey('pixel_social', $widgets);
        self::assertArrayHasKey('pixel_value_by_channel', $widgets);
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
