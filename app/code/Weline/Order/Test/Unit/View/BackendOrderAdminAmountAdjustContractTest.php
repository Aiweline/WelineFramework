<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderAdminAmountAdjustContractTest extends TestCase
{
    public function testEditTemplateDeclaresAdminAmountAdjustFormHooks(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/backend/Order/edit.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="order-edit-admin-amount-adjust"', $src);
        self::assertStringContainsString('data-testid="order-edit-admin-amount-form"', $src);
        self::assertStringContainsString('data-testid="order-edit-admin-amount-label"', $src);
        self::assertStringContainsString('data-testid="order-edit-admin-amount-value"', $src);
        self::assertStringContainsString("order/backend/order/adjust-amount", $src);
        self::assertStringContainsString('admin_amount_adjust_enabled', $src);
    }

    public function testControllerDeclaresAdjustAmountAcl(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Order.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("Weline_Order::order_admin_amount_adjust", $src);
        self::assertStringContainsString('function adjustAmount', $src);
        self::assertStringContainsString('OrderAdminAmountAdjustmentShell', $src);
    }
}
