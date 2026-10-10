<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CheckoutPaymentEntryContractTest extends TestCase
{
    public function testResumePaymentStampsContinuePayEntry(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
        self::assertStringContainsString(
            "PaymentEntry::CONTINUE_PAY",
            $src
        );
        self::assertStringContainsString(
            "'payment_mode' => 'continue_pay'",
            $src
        );
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'resumePaymentV2'[\\s\\S]*?'payment_mode'\\s*=>/",
            $src
        );
    }

    public function testCheckoutPayServiceStampsPaymentEntry(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutOrderPaymentService.php'
        );
        self::assertStringContainsString('PaymentEntry::stampContext', $src);
        self::assertStringContainsString('continue_pay', $src);
    }

    public function testStorefrontResumePassesContinuePayMode(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        self::assertStringContainsString("payment_mode: 'continue_pay'", $src);
        self::assertStringContainsString('continue_pay: true', $src);
    }
}
