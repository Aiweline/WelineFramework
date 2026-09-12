<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Backend HTTP paths must use canonical router frontName `order` (etc/env.php),
 * never the retired `weline_order/backend` prefix (generated routers: order/backend/*).
 */
final class BackendOrderRouterPrefixContractTest extends TestCase
{
    /** @return list<string> */
    private function scannedRelativePaths(): array
    {
        return [
            'etc/backend/menu.xml',
            'Controller/Backend/Payment.php',
            'Controller/Backend/Shipment.php',
            'Controller/Backend/Refund.php',
            'Controller/Backend/Invoice.php',
            'view/templates/Backend/Records/index.phtml',
            'view/templates/Backend/Invoice/index.phtml',
            'view/templates/Backend/Refund/index.phtml',
            'view/templates/Backend/Shipment/index.phtml',
            'view/templates/Backend/Status/edit.phtml',
            'view/templates/Backend/Status/index.phtml',
            'Test/e2e/backend/Weline_Order-r43-menu.spec.js',
        ];
    }

    public function testMenuPaymentActionUsesCanonicalOrderRouter(): void
    {
        $menu = dirname(__DIR__, 3) . '/etc/backend/menu.xml';
        self::assertFileExists($menu);
        $xml = (string)file_get_contents($menu);

        self::assertStringContainsString(
            'action="order/backend/records/payment"',
            $xml,
            '订单收款记录菜单必须指向 order/backend/records/payment'
        );
        self::assertStringNotContainsString(
            'weline_order/backend',
            $xml,
            '后台菜单不得再写死已退役的 weline_order 路由前缀'
        );
    }

    public function testBackendSurfaceFilesRejectRetiredWelineOrderPrefix(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ($this->scannedRelativePaths() as $relative) {
            $path = $root . '/' . $relative;
            self::assertFileExists($path, $relative);
            $body = (string)file_get_contents($path);
            self::assertStringNotContainsString(
                'weline_order/backend',
                $body,
                $relative . ' 不得包含已退役前缀 weline_order/backend'
            );
        }
    }
}
