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
 * E01：pixel_channels 部件契约（注册 + 下钻；不查库）。
 */
final class PixelChannelsWidgetContractTest extends TestCase
{
    public function testWidgetPhpRegistersPixelChannels(): void
    {
        $root = dirname(__DIR__, 3);
        $widgetFile = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        self::assertFileExists($widgetFile);

        /** @var array<string, array<string, mixed>> $widgets */
        $widgets = require $widgetFile;
        self::assertArrayHasKey('pixel_channels', $widgets);
        $widget = $widgets['pixel_channels'];
        self::assertSame('pixel_channels', $widget['code']);
        self::assertSame('table', $widget['type']);
        self::assertStringContainsString('pixel-channels.phtml', (string)$widget['template']);
        self::assertSame('dashboard-detail', $widget['slot']);
        self::assertSame('weline_visitor_event_statistics', $widget['default_injections'][0]['default_view']);
        self::assertFalse((bool)($widget['default_injections'][0]['required'] ?? true));
    }

    public function testWidgetCodeMatchesReportCatalog(): void
    {
        $catalog = (new PixelReportCatalog())->require('pixel_channels');
        self::assertSame('pixel_channels', $catalog['widget_code']);
        self::assertSame('channel_code', $catalog['dimension']);
    }

    public function testTemplateUsesEngineReportAndDrilldown(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = $root . '/view/templates/dashboard/widgets/pixel-channels.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('data-pixel-widget="pixel_channels"', $src);
        self::assertStringContainsString('getChannelsReport', $src);
        self::assertStringContainsString('channelDrilldownUrl', $src);
        self::assertStringContainsString('listUrl', $src);
        self::assertStringContainsString('详情报表', $src);
        // E05：已增量写入 Installer 种子布局
        $installer = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'pixel_channels'", $installer);
        self::assertStringContainsString("'replace_layout' => false", $installer);
    }

    public function testChannelDrilldownUrlCarriesChannelCode(): void
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
            ['range' => '7d', 'website_id' => 3],
            'channel_code',
            'summer_sale'
        );

        self::assertStringContainsString('pixel-dashboard/list', $href);
        self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
        self::assertSame('summer_sale', $captured['query']['channel_code'] ?? null);
        self::assertSame('3', $captured['query']['websiteId'] ?? null);
        self::assertSame('7d', $captured['query']['range'] ?? null);
    }

    public function testChannelsReportCodeIsMountedOnDetailTabs(): void
    {
        self::assertTrue(
            (new PixelDetailReportTabService())->isMounted('pixel_channels')
        );
    }
}
