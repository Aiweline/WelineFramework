<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Api\B2BHangPaymentBridgeInterface;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangOrderService;
use Weline\B2B\Service\B2BHangPaymentBridge;

final class B2BHangPaymentBridgeContractTest extends TestCase
{
    public function testEventDocExistsBeforeDispatchContract(): void
    {
        $doc = dirname(__DIR__, 3) . '/doc/event/hang_status_changed.md';
        self::assertFileExists($doc);
        $src = (string) file_get_contents($doc);
        self::assertStringContainsString('Weline_B2B::hang_status_changed', $src);
        self::assertStringContainsString('B2BHangPaymentBridgeInterface', $src);
    }

    public function testModuleProvidesBridgeInterface(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\B2B\Service\B2BHangPaymentBridge::class,
            $module['provides'][B2BHangPaymentBridgeInterface::class] ?? null,
        );
    }

    public function testBridgeReconcileDepositDelegatesToHangService(): void
    {
        $hang = B2BHangOrderService::forTesting(clock: static fn (): int => 1_700_000_000);
        $hang->createAwaitingDeposit([
            'order_ref' => 'ord-bridge-1',
            'customer_id' => '9',
            'website_id' => 1,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 500,
            'is_shipping_owner' => true,
        ]);
        $bridge = new B2BHangPaymentBridge($hang);
        $out = $bridge->reconcilePaymentSuccess('ord-bridge-1', 'deposit', 'pi_d1');
        self::assertIsArray($out);
        self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $out['hang_status'] ?? null);
        self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $bridge->getHangByOrderRef('ord-bridge-1')['hang_status'] ?? null);
    }

    public function testHangStatusChangedEventNameConstantOnService(): void
    {
        self::assertSame(
            'Weline_B2B::hang_status_changed',
            B2BHangOrderService::EVENT_HANG_STATUS_CHANGED,
        );
    }

    public function testCallSitesPreferBridgeInterfaceNotConcreteHang(): void
    {
        $root = dirname(__DIR__, 4);
        $payment = (string) file_get_contents(
            $root . '/Payment/Service/PaymentBrowserReturnDispatcher.php',
        );
        $checkout = (string) file_get_contents(
            $root . '/Checkout/Service/CheckoutOrderPaymentService.php',
        );
        $payable = (string) file_get_contents(
            $root . '/Order/extends/module/Weline_Payment/PayableResolver/OrderPayableResolver.php',
        );
        foreach ([$payment, $checkout, $payable] as $src) {
            self::assertStringContainsString('B2BHangPaymentBridgeInterface', $src);
            self::assertStringNotContainsString(
                'getInstance(\\Weline\\B2B\\Service\\B2BHangOrderService::class)',
                $src,
            );
        }
    }
}
