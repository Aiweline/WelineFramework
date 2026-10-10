<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentEntry;

final class PaymentEntryTest extends TestCase
{
    public function testResolvePrefersContinuePayOverOrderCheckoutEntry(): void
    {
        $entry = PaymentEntry::resolveFromContext([
            'checkout_entry' => PaymentEntry::EXPRESS,
            'payment_mode' => 'continue_pay',
            'continue_pay' => true,
        ]);
        self::assertSame(PaymentEntry::CONTINUE_PAY, $entry);
    }

    public function testResolveExpressFromFlag(): void
    {
        self::assertSame(
            PaymentEntry::EXPRESS,
            PaymentEntry::resolveFromContext(['express_checkout' => true]),
        );
    }

    public function testResolveHelpPayFromModeAndTags(): void
    {
        self::assertSame(
            PaymentEntry::QUICK_BUY,
            PaymentEntry::resolveFromContext([
                'metadata' => ['mode' => 'quick_pay_self'],
            ]),
        );
        self::assertSame(
            PaymentEntry::HELP_PAY,
            PaymentEntry::resolveFromContext([
                'business_tags' => ['helppay', 'help_pay'],
            ]),
        );
    }

    public function testStampContextWritesTopLevelAndMetadata(): void
    {
        $stamped = PaymentEntry::stampContext(
            ['express_checkout' => true],
            PaymentEntry::CHECKOUT,
        );
        self::assertSame(PaymentEntry::EXPRESS, $stamped['payment_entry']);
        self::assertSame(PaymentEntry::EXPRESS, $stamped['metadata']['payment_entry']);
    }

    public function testToneCoverContinuePay(): void
    {
        self::assertSame('warning', PaymentEntry::tone(PaymentEntry::CONTINUE_PAY));
        self::assertSame('primary', PaymentEntry::tone(PaymentEntry::EXPRESS));
        self::assertSame('info', PaymentEntry::tone(PaymentEntry::CHECKOUT));
    }

    public function testLabelMethodExistsInSource(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentEntry.php');
        self::assertStringContainsString("self::CONTINUE_PAY => (string) __('继续支付')", $src);
        self::assertStringContainsString("self::EXPRESS => (string) __('快捷支付')", $src);
    }
}
