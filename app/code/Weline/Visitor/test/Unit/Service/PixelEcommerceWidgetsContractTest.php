<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Visitor\Service\PixelDashboardWidgetData;
use Weline\Visitor\Service\VisitorDashboardPageInstaller;

/**
 * F05a：电商三部件契约（注册 + Installer 增量 + 站点作用域下钻；不查库）。
 */
final class PixelEcommerceWidgetsContractTest extends TestCase
{
    public function testWidgetPhpRegistersThreeEcommerceWidgets(): void
    {
        $root = dirname(__DIR__, 3);
        $widgetFile = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        self::assertFileExists($widgetFile);

        /** @var array<string, array<string, mixed>> $widgets */
        $widgets = require $widgetFile;

        $expected = [
            'pixel_ecommerce_funnel' => ['table', 'pixel-ecommerce-funnel.phtml', 84],
            'pixel_ecommerce_revenue' => ['stats', 'pixel-ecommerce-revenue.phtml', 85],
            'pixel_ecommerce_items' => ['table', 'pixel-ecommerce-items.phtml', 86],
        ];
        foreach ($expected as $code => [$type, $tpl, $sort]) {
            self::assertArrayHasKey($code, $widgets, $code);
            $widget = $widgets[$code];
            self::assertSame($code, $widget['code']);
            self::assertSame($type, $widget['type']);
            self::assertStringContainsString($tpl, (string)$widget['template']);
            self::assertSame('dashboard-detail', $widget['slot']);
            self::assertSame('weline_visitor_event_statistics', $widget['default_injections'][0]['default_view']);
            self::assertFalse((bool)($widget['default_injections'][0]['required'] ?? true));
            self::assertSame($sort, (int)$widget['default_injections'][0]['sort_order']);
        }
    }

    public function testInstallerAppendsEcommerceWithoutReplacingCatalog(): void
    {
        self::assertSame(
            [
                'pixel_ecommerce_funnel',
                'pixel_ecommerce_revenue',
                'pixel_ecommerce_items',
            ],
            VisitorDashboardPageInstaller::ECOMMERCE_WIDGET_CODES
        );

        $root = dirname(__DIR__, 3);
        $src = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'replace_layout' => false", $src);
        self::assertStringContainsString('ecommerceWidgets()', $src);
        foreach (VisitorDashboardPageInstaller::ECOMMERCE_WIDGET_CODES as $code) {
            self::assertStringContainsString("'{$code}'", $src);
        }
        foreach (VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
            self::assertStringContainsString("'{$code}'", $src, 'catalog still present');
        }
    }

    public function testTemplatesWireServiceMethodsAndAnchors(): void
    {
        $root = dirname(__DIR__, 3);
        $cases = [
            'pixel-ecommerce-funnel.phtml' => [
                'getEcommerceFunnelReport',
                'pixel_ecommerce_funnel',
                '电商漏斗',
            ],
            'pixel-ecommerce-revenue.phtml' => [
                'getEcommerceRevenueReport',
                'pixel_ecommerce_revenue',
                '购成与收入',
            ],
            'pixel-ecommerce-items.phtml' => [
                'getEcommerceItemsReport',
                'pixel_ecommerce_items',
                '商品表现',
            ],
        ];
        foreach ($cases as $file => [$method, $code, $title]) {
            $tpl = $root . '/view/templates/dashboard/widgets/' . $file;
            self::assertFileExists($tpl);
            $src = (string)\file_get_contents($tpl);
            self::assertStringContainsString($method, $src);
            self::assertStringContainsString('data-pixel-widget="' . $code . '"', $src);
            self::assertStringContainsString($title, $src);
            self::assertStringContainsString('详情报表', $src);
            self::assertStringContainsString('事件列表', $src);
        }

        $dataSrc = (string)\file_get_contents($root . '/Service/PixelDashboardWidgetData.php');
        self::assertStringContainsString("ecommerce-funnel", $dataSrc);
        self::assertStringContainsString("ecommerce-revenue", $dataSrc);
        self::assertStringContainsString("ecommerce-items", $dataSrc);
    }

    public function testEcommerceWidgetBaseRequiresWebsiteAndAnchorsDetail(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturn(null);

        $captured = [];
        $url = $this->createMock(Url::class);
        $url->method('getBackendUrlPath')->willReturnCallback(
            static function (string $path, array $query = []) use (&$captured): string {
                $captured[] = ['path' => $path, 'query' => $query];

                return '/backend/' . $path . '?' . http_build_query($query);
            }
        );

        $service = new PixelDashboardWidgetData($request, $url);

        $noSite = $service->ecommerceWidgetBase(['range' => '7d'], 'ecommerce-funnel');
        self::assertSame('website scope required', $noSite['error']);
        self::assertNull($noSite['website_id']);
        self::assertStringNotContainsString('#', (string)$noSite['detail_url']);

        $withSite = $service->ecommerceWidgetBase(
            ['range' => '7d', 'website_id' => 9],
            'ecommerce-funnel'
        );
        self::assertSame(9, $withSite['website_id']);
        self::assertSame('', $withSite['error']);
        self::assertStringContainsString('#ecommerce-funnel', (string)$withSite['detail_url']);
        self::assertStringContainsString('pixel-dashboard/detail', (string)$withSite['detail_url']);
        self::assertNotSame('', (string)$withSite['start_date']);
        self::assertNotSame('', (string)$withSite['end_date']);

        $paths = array_column($captured, 'path');
        self::assertContains('visitor/backend/pixel-dashboard/detail', $paths);
        self::assertContains('visitor/backend/pixel-dashboard/list', $paths);
    }

    public function testEcommerceCodesStayOutOfReportCatalog(): void
    {
        $catalogFile = dirname(__DIR__, 3) . '/etc/report_catalog.json';
        self::assertFileExists($catalogFile);
        $json = (string)\file_get_contents($catalogFile);
        foreach (VisitorDashboardPageInstaller::ECOMMERCE_WIDGET_CODES as $code) {
            self::assertStringNotContainsString('"' . $code . '"', $json);
        }
    }
}
