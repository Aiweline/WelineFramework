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
 * E03b：pixel_social 部件契约（注册 + social 过滤下钻；不查库）。
 */
final class PixelSocialWidgetContractTest extends TestCase
{
    public function testWidgetPhpRegistersPixelSocial(): void
    {
        $root = dirname(__DIR__, 3);
        $widgetFile = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        self::assertFileExists($widgetFile);

        /** @var array<string, array<string, mixed>> $widgets */
        $widgets = require $widgetFile;
        self::assertArrayHasKey('pixel_social', $widgets);
        $widget = $widgets['pixel_social'];
        self::assertSame('pixel_social', $widget['code']);
        self::assertSame('table', $widget['type']);
        self::assertStringContainsString('pixel-social.phtml', (string)$widget['template']);
        self::assertSame('dashboard-detail', $widget['slot']);
        self::assertSame('weline_visitor_event_statistics', $widget['default_injections'][0]['default_view']);
        self::assertFalse((bool)($widget['default_injections'][0]['required'] ?? true));
        // E03a/b 并存；E04a 已挂载
        self::assertArrayHasKey('pixel_paid', $widgets);
        self::assertArrayHasKey('pixel_channels', $widgets);
        self::assertArrayHasKey('pixel_event_value', $widgets);
        self::assertArrayHasKey('pixel_value_by_channel', $widgets);
    }

    public function testWidgetCodeMatchesReportCatalogWithSocialFilter(): void
    {
        $catalog = (new PixelReportCatalog())->require('pixel_social');
        self::assertSame('pixel_social', $catalog['widget_code']);
        self::assertSame('channel_code', $catalog['dimension']);
        self::assertSame(['traffic_type' => 'social'], $catalog['filters']);
    }

    public function testTemplateUsesEngineReportAndSocialDrilldown(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = $root . '/view/templates/dashboard/widgets/pixel-social.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('data-pixel-widget="pixel_social"', $src);
        self::assertStringContainsString('getSocialReport', $src);
        self::assertStringContainsString('channelDrilldownUrl', $src);
        self::assertStringContainsString("traffic_type' => 'social'", $src);
        self::assertStringContainsString('详情报表', $src);

        $installer = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'pixel_social'", $installer);
        self::assertStringContainsString("'replace_layout' => false", $installer);
    }

    public function testSocialDrilldownUrlCarriesTrafficTypeAndChannel(): void
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
            ['range' => '7d', 'website_id' => 4],
            'channel_code',
            'wechat',
            ['traffic_type' => 'social']
        );

        self::assertStringContainsString('pixel-dashboard/list', $href);
        self::assertSame('visitor/backend/pixel-dashboard/list', $captured['path'] ?? null);
        self::assertSame('social', $captured['query']['traffic_type'] ?? null);
        self::assertSame('wechat', $captured['query']['channel_code'] ?? null);
        self::assertSame('4', $captured['query']['websiteId'] ?? null);
        self::assertSame('7d', $captured['query']['range'] ?? null);
    }

    public function testSocialReportCodeIsMountedOnDetailTabs(): void
    {
        self::assertTrue(
            (new PixelDetailReportTabService())->isMounted('pixel_social')
        );
    }
}
