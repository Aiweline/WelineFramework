<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Backend order detail/edit breadcrumbs must use canonical router frontName `order`
 * (see etc/env.php), never the retired `weline_order` prefix.
 */
final class BackendOrderBreadcrumbRouteContractTest extends TestCase
{
    public function testOrderDetailBreadcrumbPointsToCanonicalOrderListRoute(): void
    {
        $template = dirname(__DIR__, 3) . '/view/templates/Backend/Order/view.phtml';
        self::assertFileExists($template);
        $html = (string)file_get_contents($template);

        self::assertStringContainsString(
            "@backend-url{'order/backend/order/index'}",
            $html,
            '订单详情面包屑「订单管理」必须指向 order/backend/order/index'
        );
        self::assertStringNotContainsString(
            'weline_order/backend/order',
            $html,
            '订单详情模板不得再写死已退役的 weline_order 路由前缀'
        );
    }

    public function testOrderEditBreadcrumbPointsToCanonicalOrderListRoute(): void
    {
        $template = dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml';
        self::assertFileExists($template);
        $html = (string)file_get_contents($template);

        self::assertStringContainsString(
            "@backend-url{'order/backend/order/index'}",
            $html,
            '订单编辑面包屑「订单管理」必须指向 order/backend/order/index'
        );
        self::assertStringNotContainsString(
            'weline_order/backend/order',
            $html,
            '订单编辑模板不得再写死已退役的 weline_order 路由前缀'
        );
    }
}
