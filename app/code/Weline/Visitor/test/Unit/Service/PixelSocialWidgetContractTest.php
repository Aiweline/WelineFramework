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
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        $tpl = 'Weline_Visitor::templates/dashboard/widgets/pixel-social.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Visitor::templates/dashboard/widgets/pixel-paid.phtml'
        ));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Visitor::templates/dashboard/widgets/pixel-channels.phtml'
        ));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Visitor::templates/dashboard/widgets/pixel-event-value.phtml'
        ));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Visitor::templates/dashboard/widgets/pixel-value-by-channel.phtml'
        ));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/dashboard/widgets/pixel-social.phtml');
        self::assertStringContainsString('@widget.code {pixel_social}', $src);
        self::assertStringContainsString('@widget.type {table}', $src);
        self::assertStringContainsString('@widget.slot {dashboard-detail}', $src);
        self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src);
        self::assertStringContainsString('"required":false', $src);
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
