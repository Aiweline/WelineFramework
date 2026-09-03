<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CheckoutLegacyPaymentServiceDeprecationContractTest extends TestCase
{
    public function testCheckoutPaymentServiceIsDeprecated(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentService.php');
        self::assertStringContainsString('@deprecated', $src);
        self::assertStringContainsString('GAP-PAY-007', $src);
    }
}
