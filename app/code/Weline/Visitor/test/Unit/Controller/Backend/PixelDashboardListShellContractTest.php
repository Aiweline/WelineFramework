<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Acl\Acl;
use Weline\Visitor\Controller\Backend\PixelDashboard;

/**
 * C01–C03：PixelDashboard list 路由 + 分页 + 筛选表单契约。
 */
final class PixelDashboardListShellContractTest extends TestCase
{
    public function testListActionDeclaresPixelDashboardListAcl(): void
    {
        $ref = new ReflectionClass(PixelDashboard::class);
        self::assertTrue($ref->hasMethod('list'));

        $methodAcls = $ref->getMethod('list')->getAttributes(Acl::class);
        self::assertCount(1, $methodAcls);
        /** @var Acl $acl */
        $acl = $methodAcls[0]->newInstance();
        self::assertSame('Weline_Visitor::pixel_dashboard_list', $acl->getData('source_id'));
    }

    public function testListTemplateSupportsPaginationAndChannelFilters(): void
    {
        $root = dirname(__DIR__, 4);
        $listTpl = $root . '/view/templates/Backend/PixelDashboard/list.phtml';
        self::assertFileExists($listTpl);

        $src = (string)\file_get_contents($listTpl);
        self::assertStringContainsString('visitor/backend/pixel-dashboard/list', $src);
        self::assertStringContainsString('visitor/backend/pixel-dashboard/index', $src);
        self::assertStringContainsString('上一页', $src);
        self::assertStringContainsString('下一页', $src);
        self::assertStringContainsString("\$pagination", $src);
        self::assertStringContainsString("'page'", $src);

        // C03：筛选表单
        self::assertStringContainsString('id="pixel-list-filter-form"', $src);
        self::assertStringContainsString('method="get"', $src);
        self::assertStringContainsString('name="channel_code"', $src);
        self::assertStringContainsString('name="traffic_type"', $src);
        self::assertStringContainsString('name="utm_source"', $src);
        self::assertStringContainsString('name="utm_medium"', $src);
        self::assertStringContainsString('name="utm_campaign"', $src);
        self::assertStringContainsString('name="range"', $src);
        self::assertStringContainsString('<lang>应用筛选</lang>', $src);
        // 翻页须保留归因 query
        self::assertStringContainsString("'channel_code' => \$selectedChannel", $src);
        self::assertStringContainsString("'utm_source' => \$selectedUtmSource", $src);
    }

    public function testControllerListWiresPaginationServiceAndFilterOptions(): void
    {
        $root = dirname(__DIR__, 4);
        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
        self::assertStringContainsString('function list', $controller);
        self::assertStringContainsString("fetch('list')", $controller);
        self::assertStringContainsString('getDashboardEventListPage', $controller);
        self::assertStringContainsString("'list_ready', true", $controller);
        self::assertStringContainsString('pixel_dashboard_list', $controller);
        self::assertStringContainsString('traffic_type_options', $controller);
        self::assertStringContainsString('buildListDisplayFilters', $controller);

        $index = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/index.phtml');
        self::assertStringContainsString('pixel-dashboard/list', $index);
        self::assertStringContainsString('<lang>事件列表</lang>', $index);
        self::assertStringContainsString('w:websites:website:select', $index);
        self::assertStringContainsString('index-filter-website', $index);
        self::assertStringNotContainsString('<select name="websiteId"', $index);
        self::assertStringContainsString('assignWebsiteSelectOptions', $controller);
    }

    public function testShortHyphenRouteContractDocumentedInTemplates(): void
    {
        $root = dirname(__DIR__, 4);
        foreach (['index.phtml', 'list.phtml', 'detail.phtml'] as $file) {
            $src = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/' . $file);
            self::assertStringContainsString('pixel-dashboard/', $src, $file);
            self::assertStringNotContainsString('pixel_dashboard/', $src, $file . ' 不得使用下划线路由');
        }
    }
}
