<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Checkout\Service\CheckoutSuccessUrlBuilder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Http\Url;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\Data\PaymentTransactionRecord;
use Weline\Payment\Api\PaymentFacadeInterface;

final class CheckoutOrderPaymentServiceTest extends TestCase
{
    public function testItPaysFromServerOwnedOrderSnapshotAndNotifiesSuccessfulPayment(): void
    {
        $order = new OrderReadResult(
            orderUuid: 'order-uuid-1',
            checkoutGroupUuid: 'group-uuid-1',
            status: 'pending',
            currency: 'USD',
            websiteId: 3,
            storeId: 8,
            items: [['sku' => 'BIKE-1', 'quantity' => 1]],
            money: ['grand_total_minor' => 289500],
            scope: ['website_id' => 3, 'store_id' => 8, 'locale' => 'zh_Hans_CN'],
            customerId: 42,
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->expects(self::once())->method('get')->with('order-uuid-1')->willReturn($order);
        $orders->expects(self::once())->method('notifyOrderPaid')->with(
            'order-uuid-1',
            self::callback(static fn(array $context): bool =>
                $context['payment_method'] === 'fake_card'
                && $context['payment_transaction_id'] === 91
                && $context['payment_transaction_no'] === 'FAKE-ORDER-1'
            ),
        );

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::once())->method('tryCreatePayment')->with(
            'fake_card',
            self::callback(function (array $context): bool {
                self::assertSame('weline_order', $context['payable_type']);
                self::assertSame('order-uuid-1', $context['payable_id']);
                self::assertSame(289500, $context['amount_minor']);
                self::assertStringContainsString('checkout/success', (string) ($context['browser_landing_url'] ?? ''));
                self::assertStringContainsString('checkout_token=qt-success-1', (string) ($context['browser_landing_url'] ?? ''));
                self::assertSame('group-uuid-1', $context['browser_landing_params']['checkout_group_uuid'] ?? '');
                self::assertSame('qt-success-1', $context['browser_landing_params']['checkout_token'] ?? '');

                return true;
            }),
        )->willReturn(new PaymentTransactionRecord(
            id: 91,
            transactionNumber: 'FAKE-ORDER-1',
            methodCode: 'fake_card',
            status: PaymentTransactionRecord::STATUS_SUCCESS,
            response: ['message' => 'Fake payment completed.'],
        ));

        $result = $this->createService($orders, $payments)->pay(
            ['order-uuid-1'],
            'fake_card',
            'checkout-ui-key',
            [
                'amount' => 0.01,
                'amount_minor' => 1,
                'currency' => 'CNY',
                'checkout_token' => 'qt-success-1',
            ],
        );

        self::assertTrue($result['paid']);
        self::assertSame('success', $result['status']);
        self::assertSame('FAKE-ORDER-1', $result['transactions'][0]['transaction_no']);
    }

    public function testItRejectsUnavailablePaymentMethod(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-uuid-2',
            checkoutGroupUuid: 'group-uuid-2',
            status: 'pending',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            money: ['grand_total_minor' => 100],
        ));
        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->method('tryCreatePayment')->willReturn(null);

        $this->expectExceptionMessage('checkout_payment_method_unavailable');
        $this->createService($orders, $payments)->pay(
            ['order-uuid-2'],
            'disabled_method',
            'checkout-ui-key',
        );
    }

    public function testItReturnsPendingRedirectWithoutReportingTheOrderAsPaid(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-pending-1',
            checkoutGroupUuid: 'group-pending-1',
            status: 'pending',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            money: ['grand_total_minor' => 249900],
        ));
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->method('tryCreatePayment')->willReturn(new PaymentTransactionRecord(
            id: 101,
            transactionNumber: 'PAY-PENDING-1',
            methodCode: 'provider_checkout',
            status: PaymentTransactionRecord::STATUS_PENDING,
            response: ['redirect_url' => 'https://payments.example.test/continue/101'],
        ));

        $result = $this->createService($orders, $payments)->pay(
            ['order-pending-1'],
            'provider_checkout',
            'checkout-pending-key',
        );

        self::assertFalse($result['paid']);
        self::assertSame('pending', $result['outcome']);
        self::assertSame('pending', $result['status']);
        self::assertTrue($result['requires_action']);
        self::assertTrue($result['recoverable']);
        self::assertSame('https://payments.example.test/continue/101', $result['redirect_url']);
    }
    public function testItExtractsRedirectUrlFromProviderPayload(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-payload-1',
            checkoutGroupUuid: 'group-payload-1',
            status: 'pending',
            currency: 'CNY',
            websiteId: 1,
            storeId: 1,
            money: ['grand_total_minor' => 20190],
        ));
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->method('tryCreatePayment')->willReturn(new PaymentTransactionRecord(
            id: 104,
            transactionNumber: 'PAY-PAYLOAD-1',
            methodCode: 'paypal',
            status: PaymentTransactionRecord::STATUS_PENDING,
            response: [
                'status' => 'requires_action',
                'action_type' => 'redirect',
                'payload' => [
                    'redirect_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=TESTTOKEN',
                    'environment' => 'sandbox',
                ],
                'provider_secret' => 'must-not-leak',
            ],
        ));

        $result = $this->createService($orders, $payments)->pay(
            ['order-payload-1'],
            'paypal',
            'checkout-payload-key',
        );

        self::assertFalse($result['paid']);
        self::assertTrue($result['requires_action']);
        self::assertSame(
            'https://www.sandbox.paypal.com/checkoutnow?token=TESTTOKEN',
            $result['redirect_url'],
        );
        self::assertSame(
            ['redirect_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=TESTTOKEN'],
            $result['transactions'][0]['response'],
        );
    }


    public function testItReturnsRecoverableFailedOutcomeWithoutLosingTransactionEvidence(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-failed-1',
            checkoutGroupUuid: 'group-failed-1',
            status: 'pending',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            money: ['grand_total_minor' => 19900],
        ));
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->method('tryCreatePayment')->willReturn(new PaymentTransactionRecord(
            id: 102,
            transactionNumber: 'PAY-FAILED-1',
            methodCode: 'fake_decline',
            status: PaymentTransactionRecord::STATUS_FAILED,
            response: ['message' => 'Card declined.'],
        ));

        $result = $this->createService($orders, $payments)->pay(
            ['order-failed-1'],
            'fake_decline',
            'checkout-failed-key',
        );

        self::assertFalse($result['paid']);
        self::assertSame('failed', $result['outcome']);
        self::assertSame('failed', $result['status']);
        self::assertFalse($result['requires_action']);
        self::assertTrue($result['recoverable']);
        self::assertNull($result['redirect_url']);
        self::assertSame('PAY-FAILED-1', $result['transactions'][0]['transaction_no']);
    }

    public function testItDoesNotExposeUnapprovedProviderResponseFields(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-secret-1',
            checkoutGroupUuid: 'group-secret-1',
            status: 'pending',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            money: ['grand_total_minor' => 100],
        ));

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->method('tryCreatePayment')->willReturn(new PaymentTransactionRecord(
            id: 103,
            transactionNumber: 'PAY-SECRET-1',
            methodCode: 'provider_checkout',
            status: PaymentTransactionRecord::STATUS_PENDING,
            response: [
                'redirect_url' => 'https://payments.example.test/continue/103',
                'provider_secret' => 'must-not-leak',
                'raw_request' => ['credential' => 'must-not-leak'],
            ],
        ));

        $result = $this->createService($orders, $payments)->pay(
            ['order-secret-1'],
            'provider_checkout',
            'checkout-secret-key',
        );

        self::assertSame(
            ['redirect_url' => 'https://payments.example.test/continue/103'],
            $result['transactions'][0]['response'],
        );
    }

    public function testRecoveryDoesNotChargeAnAlreadyPaidOrderAgain(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-paid-1',
            checkoutGroupUuid: 'group-paid-1',
            status: 'paid',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            money: ['grand_total_minor' => 100],
        ));
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::never())->method('tryCreatePayment');

        $result = $this->createService($orders, $payments)->pay(
            ['order-paid-1'],
            'fake_card',
            'checkout-recovery-key',
        );

        self::assertTrue($result['paid']);
        self::assertSame('paid', $result['outcome']);
        self::assertSame('already_paid', $result['transactions'][0]['status']);
    }

    public function testSuccessfulChargeWithOrderNotificationFailureCannotBeRetriedAsFailedPayment(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-notify-1',
            checkoutGroupUuid: 'group-notify-1',
            status: 'pending',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            money: ['grand_total_minor' => 100],
        ));
        $orders->method('notifyOrderPaid')->willThrowException(new \RuntimeException('order write unavailable'));

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->method('tryCreatePayment')->willReturn(new PaymentTransactionRecord(
            id: 104,
            transactionNumber: 'PAY-CAPTURED-1',
            methodCode: 'fake_card',
            status: PaymentTransactionRecord::STATUS_SUCCESS,
            response: [],
        ));

        $result = $this->createService($orders, $payments)->pay(
            ['order-notify-1'],
            'fake_card',
            'checkout-notify-key',
        );

        self::assertFalse($result['paid']);
        self::assertSame('pending', $result['outcome']);
        self::assertSame('processing', $result['status']);
        self::assertFalse($result['recoverable']);
        self::assertSame('checkout_order_payment_notification_pending', $result['error_code']);
    }

    private function createService(
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
        $builder = new CheckoutSuccessUrlBuilder($url);
        $sessions = new InMemoryCheckoutSessionStore();
        $sessions->put('qt-success-1', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'customer_id' => null,
            'submitted_result' => [
                'order_uuids' => ['order-uuid-1'],
            ],
        ]);

        return new CheckoutOrderPaymentService($orders, $payments, $builder, $sessions);
    }
}
