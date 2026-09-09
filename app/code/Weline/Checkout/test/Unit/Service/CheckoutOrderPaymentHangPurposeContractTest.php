<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Checkout\Service\CheckoutSuccessUrlBuilder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\Framework\Http\Url;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\Data\PaymentTransactionRecord;
use Weline\Payment\Api\PaymentFacadeInterface;

final class CheckoutOrderPaymentHangPurposeContractTest extends TestCase
{
    public function testDepositPurposeChargesDepositAndDoesNotNotifyFullPaid(): void
    {
        $order = new OrderReadResult(
            orderUuid: 'order-tob-deposit-1',
            checkoutGroupUuid: 'group-tob-1',
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
                'hang_status' => 'awaiting_deposit',
            ],
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->expects(self::once())->method('get')->with('order-tob-deposit-1')->willReturn($order);
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::once())->method('tryCreatePayment')->with(
            'fake_card',
            self::callback(static function (array $context): bool {
                return (int)$context['amount_minor'] === 3000
                    && ($context['metadata']['purpose'] ?? '') === 'deposit';
            }),
        )->willReturn(new PaymentTransactionRecord(
            id: 201,
            transactionNumber: 'FAKE-DEP-1',
            methodCode: 'fake_card',
            status: PaymentTransactionRecord::STATUS_SUCCESS,
            response: [],
        ));

        $result = $this->service($orders, $payments)->pay(
            ['order-tob-deposit-1'],
            'fake_card',
            'dep-key-1',
            [
                'purpose' => 'deposit',
                'deposit_amount_minor' => 3000,
            ],
        );

        self::assertFalse($result['paid']);
        self::assertSame('partial', $result['outcome']);
        self::assertSame('deposit', $result['purpose'] ?? null);
    }

    public function testBalancePurposeChargesBalanceAndNotifiesPaid(): void
    {
        $order = new OrderReadResult(
            orderUuid: 'order-tob-balance-1',
            checkoutGroupUuid: 'group-tob-2',
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
            ],
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->expects(self::once())->method('get')->willReturn($order);
        $orders->expects(self::once())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::once())->method('tryCreatePayment')->with(
            'fake_card',
            self::callback(static function (array $context): bool {
                return (int)$context['amount_minor'] === 8500
                    && ($context['metadata']['purpose'] ?? '') === 'balance';
            }),
        )->willReturn(new PaymentTransactionRecord(
            id: 202,
            transactionNumber: 'FAKE-BAL-1',
            methodCode: 'fake_card',
            status: PaymentTransactionRecord::STATUS_SUCCESS,
            response: [],
        ));

        $result = $this->service($orders, $payments)->pay(
            ['order-tob-balance-1'],
            'fake_card',
            'bal-key-1',
            [
                'purpose' => 'balance',
                'balance_amount_minor' => 8500,
            ],
        );

        self::assertTrue($result['paid']);
        self::assertSame('paid', $result['outcome']);
        self::assertSame('balance', $result['purpose'] ?? null);
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
