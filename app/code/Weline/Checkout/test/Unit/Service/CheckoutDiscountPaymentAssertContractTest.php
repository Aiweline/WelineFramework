<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CheckoutDiscountPaymentAssertContractTest extends TestCase
{
    public function testAssertSessionDiscountQuoteSkipsGhostActionsWithoutEffect(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php'
        );
        self::assertStringContainsString('hasDiscountEffect', $source);
        self::assertStringContainsString('!$hasDiscountEffect', $source);
        self::assertStringContainsString('ghost actions', $source);
        self::assertStringContainsString(
            "__('当前支付方式「%{1}」不支持已选优惠方式：%{2}', [\$resolvedPayment, \$actionCode])",
            $source,
        );
    }
}
