<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderOpsTabsContractTest extends TestCase
{
    public function testEditUsesAsyncOpsTabsNotPlainLinks(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('data-testid="order-edit-ops-tabs"', $src);
        self::assertStringContainsString('data-order-ops-async="1"', $src);
        self::assertStringContainsString('data-testid="order-edit-tab-shipment"', $src);
        self::assertStringContainsString('data-testid="order-edit-tab-refund"', $src);
        self::assertStringContainsString('data-testid="order-edit-tab-comms"', $src);
        self::assertStringContainsString('order/backend/order/panel', $src);
        self::assertStringContainsString('data-w-component="tabs"', $src);
        self::assertStringContainsString('[data-testid^="order-edit-panel-"]', $src);
        self::assertStringNotContainsString("order/backend/shipment/index'", $src);
    }

    public function testPanelActionReturnsTemplateFragments(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Order.php'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString("->template('Weline_Order::templates/Backend/Order/panel/shipment.phtml')", $src);
        self::assertStringContainsString("->template('Weline_Order::templates/Backend/Order/panel/refund.phtml')", $src);
        self::assertStringContainsString("->template('Weline_Order::templates/Backend/Order/panel/comms.phtml')", $src);
        self::assertStringNotContainsString("->fetch('Weline_Order::templates/Backend/Order/panel/", $src);
    }
}
