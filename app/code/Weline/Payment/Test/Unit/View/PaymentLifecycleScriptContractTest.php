<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PaymentLifecycleScriptContractTest extends TestCase
{
    public function testPaymentLifecycleOwnsUnifiedOutcomeEvents(): void
    {
        $root = \dirname(__DIR__, 3);
        $js = (string) \file_get_contents($root . '/view/statics/js/payment-lifecycle.js');
        $modules = (string) \file_get_contents($root . '/view/statics/frontend/weline.modules.js');
        $success = (string) \file_get_contents($root . '/view/templates/Frontend/checkout/payment-success.phtml');
        $handoff = (string) \file_get_contents($root . '/view/templates/Frontend/checkout/handoff.phtml');

        self::assertStringContainsString('weline:payment:outcome', $js);
        self::assertStringContainsString('weline:payment:paid', $js);
        self::assertStringContainsString('weline:payment:pending', $js);
        self::assertStringContainsString('emitOutcome', $js);
        self::assertStringContainsString('bootFromDom', $js);
        self::assertStringContainsString('console.info', $js);
        self::assertStringContainsString('[WelinePayment]', $js);
        self::assertStringContainsString('isDevMode', $js);
        self::assertStringContainsString('validatePayload', $js);
        self::assertStringContainsString('weline:payment:anomaly', $js);
        self::assertStringContainsString('跳过派发', $js);
        self::assertStringContainsString('payment-lifecycle.js', $modules);
        self::assertStringContainsString('load: "eager"', $modules);
        self::assertStringContainsString('data-payment-lifecycle="paid"', $success);
        self::assertStringContainsString('data-weline-load="cart,paymentLifecycle"', $success);
        self::assertStringContainsString('data-payment-outcome="paid"', $success);
        self::assertStringContainsString('data-payment-lifecycle="pending"', $handoff);
        self::assertStringContainsString('data-weline-load="cart,paymentLifecycle"', $handoff);
        self::assertStringContainsString('data-payment-outcome="pending"', $handoff);

        $returnTpl = (string) \file_get_contents($root . '/view/templates/Frontend/checkout/return.phtml');
        self::assertStringContainsString('data-weline-load="cart,paymentLifecycle"', $returnTpl);
        self::assertStringContainsString('data-payment-lifecycle', $returnTpl);
        self::assertStringContainsString('payment-return-page', $returnTpl);
    }
}
