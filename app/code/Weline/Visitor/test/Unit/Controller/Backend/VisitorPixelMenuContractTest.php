<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 像素分析后台菜单契约：数据工具下必须可发现看板 / 热表 / 冷归档 / 渠道。
 */
final class VisitorPixelMenuContractTest extends TestCase
{
    public function testMenuXmlRegistersPixelFeatureEntriesUnderDataTools(): void
    {
        $menu = (string)\file_get_contents(
            dirname(__DIR__, 4) . '/etc/backend/menu.xml'
        );

        self::assertStringContainsString('Weline_Backend::data_tools_group', $menu);
        self::assertStringContainsString('Weline_Visitor::pixel_dashboard', $menu);
        self::assertStringContainsString('title="像素分析"', $menu);

        self::assertStringContainsString('Weline_Visitor::pixel_dashboard_index', $menu);
        self::assertStringContainsString('visitor/backend/pixel-dashboard/index', $menu);
        self::assertStringContainsString('title="事件看板"', $menu);

        self::assertStringContainsString('Weline_Visitor::pixel_dashboard_list', $menu);
        self::assertStringContainsString('visitor/backend/pixel-dashboard/list', $menu);
        self::assertStringContainsString('title="热表明细"', $menu);

        self::assertStringContainsString('Weline_Visitor::pixel_dashboard_archive_list', $menu);
        self::assertStringContainsString('visitor/backend/pixel-dashboard/archive-list', $menu);
        self::assertStringContainsString('title="冷归档明细"', $menu);

        self::assertStringContainsString('Weline_Visitor::traffic_channel', $menu);
        self::assertStringContainsString('visitor/backend/traffic-channel/index', $menu);
        self::assertStringContainsString('title="流量渠道"', $menu);

        // 禁止漏掉 backend area 段
        self::assertStringNotContainsString('action="visitor/pixel-dashboard/', $menu);
        self::assertStringNotContainsString('action="visitor/traffic-channel/', $menu);
    }
}
