<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductInfoPurchaseFailsafeContractTest extends TestCase
{
    public function testFailsafeCreatesActionsSlotWhenMissing(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml'
        );
        self::assertStringContainsString('data-testid="product-add-to-cart"', $src);
        self::assertStringContainsString('ensurePurchaseActionsFromFailsafe', $src);
        self::assertStringContainsString('coalesceBuyboxPurchaseActions', $src);
        self::assertStringContainsString('data-purchase-failsafe', $src);
        self::assertStringContainsString("createElement('div')", $src);
        self::assertStringContainsString('product-native-detail__actions', $src);
        self::assertStringContainsString('data-testid', $src);
        self::assertStringContainsString('product-purchase-actions', $src);
    }
}
