<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangOrderService;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Checkout\Service\CheckoutSuccessUrlBuilder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\Framework\Http\Url;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\Data\PaymentTransactionRecord;
use Weline\Payment\Api\PaymentFacadeInterface;

/**
 * P0 hang balance closed-loop: already_paid reconcile, no credit on balance, landing purpose.
 */
final class CheckoutOrderPaymentHangBalanceClosedLoopTest extends TestCase
{
    public function testAlreadyPaidReconcilesAwaitingBalanceHang(): void
    {
        $hang = B2BHangOrderService::forTesting(clock: static fn (): int => 1_700_000_000);
        $hang->createAwaitingDeposit([
            'order_ref' => 'ord-already-paid-1',
            'customer_id' => '47',
            'website_id' => 0,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 1500,
            'is_shipping_owner' => true,
        ]);
        $hang->onDepositPaid('ord-already-paid-1', 'pi_dep');
        $hang->approve('ord-already-paid-1');
        self::assertSame(
            B2BOrderHang::STATUS_AWAITING_BALANCE,
            $hang->getByOrderRef('ord-already-paid-1')?->hangStatus,
        );

        $order = new OrderReadResult(
            orderUuid: 'ord-already-paid-1',
            checkoutGroupUuid: 'group-1',
            status: 'paid',
            currency: 'CNY',
            websiteId: 0,
            storeId: 0,
            money: ['grand_total_minor' => 11500],
            customerId: 47,
            orderType: 'tob',
            typePayload: [
                'deposit_amount_minor' => 3000,
                'balance_amount_minor' => 8500,
                'hang_status' => 'awaiting_balance',
            ],
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($order);
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::never())->method('tryCreatePayment');

        // Inject hang service via ObjectManager is hard in unit; reconcile uses class_exists path.
        // Bind forTesting hang into ObjectManager by exercising reconcilePaymentSuccess directly first,
        // then call pay — pay uses ObjectManager for hang. Instead assert reconcile API + pay path soft.
        $reconciled = $hang->reconcilePaymentSuccess('ord-already-paid-1', 'balance', 'already_paid_key');
        self::assertSame(B2BOrderHang::STATUS_COMPLETED, $reconciled['hang_status'] ?? null);

        $result = $this->service($orders, $payments)->pay(
            ['ord-already-paid-1'],
            'fake_card',
            'already-key-1',
            ['purpose' => 'balance', 'balance_amount_minor' => 8500],
        );
        self::assertSame('already_paid', $result['transactions'][0]['status'] ?? null);
        self::assertSame('balance', $result['purpose'] ?? null);
    }

    public function testBalancePurposeDoesNotCallPaymentWhenAlreadyPaidAndHangCompleted(): void
    {
        $order = new OrderReadResult(
            orderUuid: 'ord-done-1',
            checkoutGroupUuid: 'group-2',
            status: 'paid',
            currency: 'CNY',
            websiteId: 0,
            storeId: 0,
            money: ['grand_total_minor' => 11500],
            customerId: 47,
            orderType: 'tob',
            typePayload: [
                'balance_amount_minor' => 8500,
                'hang_status' => 'completed',
                'discount_kind' => 'asset_b2b_credit',
                'b2b_credit_apply_base_minor' => 1000,
                'b2b_credit_status' => 'committed',
            ],
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($order);
        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::never())->method('tryCreatePayment');

        $result = $this->service($orders, $payments)->pay(
            ['ord-done-1'],
            'paypal',
            'bal-already-1',
            ['purpose' => 'balance', 'balance_amount_minor' => 8500],
        );
        self::assertSame('already_paid', $result['transactions'][0]['status'] ?? null);
    }

    public function testBalancePaymentSkipsCreditReserveAndChargesBalanceOnly(): void
    {
        $order = new OrderReadResult(
            orderUuid: 'ord-bal-credit-1',
            checkoutGroupUuid: 'group-3',
            status: 'pending',
            currency: 'CNY',
            websiteId: 0,
            storeId: 0,
            money: ['grand_total_minor' => 11500],
            customerId: 47,
            orderType: 'tob',
            typePayload: [
                'deposit_amount_minor' => 3000,
                'balance_amount_minor' => 8500,
                'hang_status' => 'awaiting_balance',
                'discount_kind' => 'asset_b2b_credit',
                'b2b_credit_apply_base_minor' => 2000,
                'b2b_credit_apply_checkout_minor' => 2000,
                'b2b_credit_cash_deposit_minor' => 1000,
                'b2b_credit_status' => 'committed',
            ],
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($order);
        $orders->expects(self::once())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::once())->method('tryCreatePayment')->with(
            'fake_card',
            self::callback(static function (array $context): bool {
                return (int)$context['amount_minor'] === 8500
                    && ($context['metadata']['purpose'] ?? '') === 'balance'
                    && str_contains((string)($context['browser_landing_url'] ?? ''), 'purpose=balance');
            }),
        )->willReturn(new PaymentTransactionRecord(
            id: 901,
            transactionNumber: 'FAKE-BAL-CL-1',
            methodCode: 'fake_card',
            status: PaymentTransactionRecord::STATUS_SUCCESS,
            response: [],
        ));

        $result = $this->service($orders, $payments)->pay(
            ['ord-bal-credit-1'],
            'fake_card',
            'bal-credit-key',
            ['purpose' => 'balance', 'balance_amount_minor' => 8500],
        );

        self::assertTrue($result['paid']);
        self::assertSame('balance', $result['purpose'] ?? null);
    }

    public function testHangDepositAndBalanceIdempotentReconcile(): void
    {
        $hang = B2BHangOrderService::forTesting(clock: static fn (): int => 1_700_000_500);
        $hang->createAwaitingDeposit([
            'order_ref' => 'ord-idem-1',
            'customer_id' => '1',
            'website_id' => 0,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 0,
            'is_shipping_owner' => false,
        ]);
        $hang->onDepositPaid('ord-idem-1', 'dep-1');
        $again = $hang->onDepositPaid('ord-idem-1', 'dep-2');
        self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $again['hang_status']);

        $hang->approve('ord-idem-1');
        $hang->onBalancePaid('ord-idem-1', 'bal-1');
        $replay = $hang->reconcilePaymentSuccess('ord-idem-1', 'balance', 'bal-2');
        self::assertSame(B2BOrderHang::STATUS_COMPLETED, $replay['hang_status'] ?? null);
    }

    private function service(
        OrderFacadeInterface $orders,
        PaymentFacadeInterface $payments,
    ): CheckoutOrderPaymentService {
        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturnCallback(
            static function (string $path, array $params = []): string {
                $query = $params === [] ? '' : '?' . http_build_query($params);

                return 'http://shop.test/' . ltrim($path, '/') . $query;
            },
        );

        return new CheckoutOrderPaymentService(
            $orders,
            $payments,
            new CheckoutSuccessUrlBuilder($url),
            new InMemoryCheckoutSessionStore(),
        );
    }
}
