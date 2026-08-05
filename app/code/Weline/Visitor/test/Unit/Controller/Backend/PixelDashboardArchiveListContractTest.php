<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Acl\Acl;
use Weline\Visitor\Controller\Backend\PixelDashboard;
use Weline\Visitor\Service\PixelColdArchiveQueryService;

/**
 * G09：冷归档 list 路由 / ACL / 模板强约束契约。
 */
final class PixelDashboardArchiveListContractTest extends TestCase
{
    public function testArchiveListActionDeclaresAcl(): void
    {
        $ref = new ReflectionClass(PixelDashboard::class);
        self::assertTrue($ref->hasMethod('archiveList'));

        $methodAcls = $ref->getMethod('archiveList')->getAttributes(Acl::class);
        self::assertCount(1, $methodAcls);
        $args = $methodAcls[0]->getArguments();
        self::assertSame('Weline_Visitor::pixel_dashboard_archive_list', $args[0] ?? null);
    }

    public function testControllerWiresColdQueryServiceAndTemplate(): void
    {
        $root = dirname(__DIR__, 4);
        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
        self::assertStringContainsString('function archiveList', $controller);
        self::assertStringContainsString("fetch('archive_list')", $controller);
        self::assertStringContainsString('PixelColdArchiveQueryService', $controller);
        self::assertStringContainsString('queryPage', $controller);
        self::assertStringContainsString('translateColdArchiveError', $controller);
        self::assertStringContainsString('max_window_days', $controller);
        self::assertStringContainsString('PixelColdArchiveQueryService::MAX_WINDOW_DAYS', $controller);
        self::assertSame(31, PixelColdArchiveQueryService::MAX_WINDOW_DAYS);
    }

    public function testArchiveListTemplateEnforcesWebsiteAndWindowHints(): void
    {
        $root = dirname(__DIR__, 4);
        $tpl = $root . '/view/templates/Backend/PixelDashboard/archive_list.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

        self::assertStringContainsString('visitor/backend/pixel-dashboard/archive-list', $src);
        self::assertStringContainsString('visitor/backend/pixel-dashboard/list', $src);
        self::assertStringNotContainsString('pixel_dashboard/', $src);
        self::assertStringContainsString('id="pixel-archive-list-filter-form"', $src);
        self::assertStringContainsString('name="websiteId"', $src);
        self::assertStringContainsString('w:websites:website:select', $src);
        self::assertStringContainsString('id="archive-filter-website"', $src);
        self::assertStringContainsString('allow-empty="false"', $src);
        self::assertStringContainsString('站点（必填）', $src);
        self::assertStringNotContainsString("'90d'", $src);
        self::assertStringContainsString('上一页', $src);
        self::assertStringContainsString('下一页', $src);
        self::assertStringContainsString('pageSize', $src);
        self::assertStringContainsString('不会静默扫描热表', $src);
    }

    public function testHotListLinksToArchiveList(): void
    {
        $root = dirname(__DIR__, 4);
        $listTpl = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/list.phtml');
        self::assertStringContainsString('visitor/backend/pixel-dashboard/archive-list', $listTpl);
        self::assertStringContainsString('冷归档明细', $listTpl);
    }
}
