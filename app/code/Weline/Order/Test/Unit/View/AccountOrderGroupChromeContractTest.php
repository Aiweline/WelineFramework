<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountOrderGroupChromeContractTest extends TestCase
{
    public function testAccountOrdersTemplateUsesSplitVsShipmentCopy(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="account-orders-hint"', $source);
        self::assertStringContainsString('多仓会拆成多个子订单；同一子订单也可分批发货', $source);
        self::assertStringContainsString('data-testid="account-order-group-label"', $source);
        self::assertStringContainsString('data-testid="account-order-child-badge"', $source);
        self::assertStringNotContainsString('状态分叉', $source);
        self::assertStringNotContainsString('OrderShipment::', $source);
    }
}
