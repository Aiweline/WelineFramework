<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderEditCompactContractTest extends TestCase
{
    public function testEditCustomerAdjustLivesInOpsTabNotScrollStub(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('data-testid="order-edit-ops-panel-customer"', $src);
        self::assertStringContainsString('customer-adjust-form.phtml', $src);
        self::assertStringContainsString('data-testid="order-edit-tab-customer"', $src);
        self::assertStringNotContainsString('前往客户调整', $src);
        self::assertStringNotContainsString('data-order-ops-scroll-writable', $src);
        self::assertStringNotContainsString('在下方「客户调整」区编辑', $src);

        $tabPos = strpos($src, 'data-testid="order-edit-ops-panel-customer"');
        $formFetchPos = strpos($src, 'customer-adjust-form.phtml');
        self::assertNotFalse($tabPos);
        self::assertNotFalse($formFetchPos);
        self::assertLessThan($formFetchPos, $tabPos);
    }

    public function testCreateStillUsesCollapsedWritableDisclosure(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml'
        );
        self::assertStringContainsString('data-testid="order-edit-writable-toggle"', $src);
        self::assertStringContainsString('data-testid="order-edit-writable-summary"', $src);
        self::assertStringContainsString('if (!$isEdit):', $src);
        self::assertStringContainsString('data-testid="order-edit-more-toggle"', $src);
        self::assertStringContainsString('--w-stat-tile-min:9rem', $src);
    }

    public function testCustomerAdjustFormPartialHasCoreFields(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/partial/customer-adjust-form.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('data-testid="order-edit-customer-select"', $src);
        self::assertStringContainsString('data-testid="order-edit-notes"', $src);
        self::assertStringContainsString('data-testid="order-edit-save"', $src);
        self::assertStringContainsString("rows=\"2\" data-testid=\"order-edit-notes\"", $src);
    }
}
