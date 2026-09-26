<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 后台订单详情/编辑：面包屑只由顶栏壳（菜单 PageHeader）负责；
 * 正文不得再画第二套「系统 / 订单详情」面包屑。
 */
final class BackendOrderBreadcrumbRouteContractTest extends TestCase
{
    public function testOrderDetailBodyHasNoDuplicateBreadcrumb(): void
    {
        $template = dirname(__DIR__, 3) . '/view/templates/Backend/Order/view.phtml';
        self::assertFileExists($template);
        $html = (string) file_get_contents($template);

        self::assertStringNotContainsString(
            'w-breadcrumb',
            $html,
            '订单详情正文不得再画 w-breadcrumb（顶栏壳已有菜单面包屑）'
        );
        self::assertStringNotContainsString(
            "__('系统')",
            $html,
            '正文不得再以「系统」为面包屑根'
        );
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/index'}",
            $html,
            '返回列表须指向 order/backend/order/index'
        );
        self::assertStringNotContainsString(
            'weline_order/backend/order',
            $html,
            '不得再写死已退役的 weline_order 路由前缀'
        );
    }

    public function testOrderEditBodyHasNoDuplicateBreadcrumb(): void
    {
        $template = dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml';
        self::assertFileExists($template);
        $html = (string) file_get_contents($template);

        self::assertStringNotContainsString(
            'w-breadcrumb',
            $html,
            '订单编辑正文不得再画 w-breadcrumb（顶栏壳已有菜单面包屑）'
        );
        self::assertStringNotContainsString(
            "__('系统')",
            $html,
            '正文不得再以「系统」为面包屑根'
        );
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/index'}",
            $html,
            '返回列表须指向 order/backend/order/index'
        );
        self::assertStringNotContainsString(
            'weline_order/backend/order',
            $html,
            '不得再写死已退役的 weline_order 路由前缀'
        );
    }
}
