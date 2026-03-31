<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Payment\Provider\ManualTransfer;

class ManualTransferTest extends TestCase
{
    public function testProcessPaymentReturnsPendingStatusWithoutRedirect(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280001', 299.99);

        $result = $provider->processPayment($order, [], [
            'payment_method' => [
                'code' => 'manual_transfer',
                'config' => [
                    'instructions' => 'Please transfer to account 123456',
                ],
            ],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertFalse((bool) ($result['requires_action'] ?? false));
        $this->assertSame('', $result['redirect_url'] ?? '');
        $this->assertNotEmpty($result['instructions'] ?? '');
    }

    public function testProcessPaymentIncludesOrderReference(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280002', 150.00);

        $result = $provider->processPayment($order, [], [
            'payment_method' => [
                'code' => 'manual_transfer',
                'config' => [],
            ],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertArrayHasKey('order_number', $result);
        $this->assertSame('WS202603280002', $result['order_number']);
    }

    public function testProcessPaymentReadsInstructionsFromConfig(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280003', 500.00);

        $customInstructions = 'Custom transfer instructions for test';
        $result = $provider->processPayment($order, [], [
            'payment_method' => [
                'code' => 'manual_transfer',
                'config' => [
                    'instructions' => $customInstructions,
                ],
            ],
        ]);

        $this->assertStringContainsString($customInstructions, (string) ($result['instructions'] ?? ''));
    }

    public function testHandleCallbackReturnsTrueForManualConfirmation(): void
    {
        $provider = new ManualTransfer();

        $result = $provider->handleCallback([
            'order_number' => 'WS202603280001',
            'status' => 'confirmed',
        ], [
            'payment_method' => [
                'code' => 'manual_transfer',
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testHandleCallbackAcceptsAdminConfirmedStatus(): void
    {
        $provider = new ManualTransfer();

        $result = $provider->handleCallback([
            'order_number' => 'WS202603280002',
            'trade_status' => 'ADMIN_CONFIRMED',
        ], [
            'payment_method' => [
                'code' => 'manual_transfer',
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testHandleCallbackReturnsTrueForAnyCallback(): void
    {
        $provider = new ManualTransfer();

        // Manual transfer requires admin confirmation, so callback always returns true
        // to indicate the callback was received (actual confirmation happens in admin)
        $result = $provider->handleCallback([
            'random_data' => 'value',
            'another_field' => 'more_data',
        ], []);

        $this->assertTrue($result);
    }

    public function testQueryPaymentStatusReturnsPendingByDefault(): void
    {
        $provider = new ManualTransfer();

        $status = $provider->queryPaymentStatus('WS202603280001', [
            'payment_method' => [
                'code' => 'manual_transfer',
            ],
        ]);

        $this->assertSame('pending', $status);
    }

    public function testQueryPaymentStatusReturnsPendingWhenNoContextProvided(): void
    {
        $provider = new ManualTransfer();

        $status = $provider->queryPaymentStatus('WS202603280002', []);

        $this->assertSame('pending', $status);
    }

    public function testQueryPaymentStatusMapsConfirmedStatus(): void
    {
        $provider = new ManualTransfer();

        $status = $provider->queryPaymentStatus('WS202603280001', [
            'trade_status' => 'CONFIRMED',
            'payment_method' => [
                'code' => 'manual_transfer',
            ],
        ]);

        $this->assertSame('paid', $status);
    }

    public function testQueryPaymentStatusMapsFailedStatus(): void
    {
        $provider = new ManualTransfer();

        $status = $provider->queryPaymentStatus('WS202603280001', [
            'status' => 'failed',
            'payment_method' => [
                'code' => 'manual_transfer',
            ],
        ]);

        $this->assertSame('failed', $status);
    }

    public function testQueryPaymentStatusMapsRefundedStatus(): void
    {
        $provider = new ManualTransfer();

        $status = $provider->queryPaymentStatus('WS202603280001', [
            'trade_status' => 'REFUNDED',
            'payment_method' => [
                'code' => 'manual_transfer',
            ],
        ]);

        $this->assertSame('refunded', $status);
    }

    public function testProcessPaymentReturnsEmptyRedirectUrl(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280004', 99.99);

        $result = $provider->processPayment($order, [], []);

        $this->assertArrayHasKey('redirect_url', $result);
        $this->assertSame('', $result['redirect_url']);
    }

    public function testProcessPaymentDoesNotRequireAction(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280005', 250.00);

        $result = $provider->processPayment($order, [], []);

        $this->assertArrayHasKey('requires_action', $result);
        $this->assertFalse((bool) $result['requires_action']);
    }

    public function testProcessPaymentWithCustomPaymentData(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280006', 399.99);

        $result = $provider->processPayment($order, [
            'customer_note' => 'Please process quickly',
            'amount' => 399.99,
        ], []);

        $this->assertSame('pending', $result['status']);
        $this->assertArrayHasKey('instructions', $result);
    }

    public function testProcessPaymentIncludesAmount(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280007', 199.50);

        $result = $provider->processPayment($order, [], []);

        $this->assertArrayHasKey('amount', $result);
        $this->assertSame(199.50, $result['amount']);
    }

    public function testProcessPaymentIncludesCurrency(): void
    {
        $provider = new ManualTransfer();
        $order = $this->createOrder('WS202603280008', 100.00);

        $result = $provider->processPayment($order, [], [
            'currency' => 'USD',
        ]);

        $this->assertArrayHasKey('currency', $result);
        $this->assertSame('USD', $result['currency']);
    }

    private function createOrder(string $incrementId, float $total): Order
    {
        return new class($incrementId, $total) extends Order {
            public function __construct(
                private readonly string $incrementId,
                private readonly float $total
            ) {
            }

            public function getIncrementId(): string
            {
                return $this->incrementId;
            }

            public function getId(mixed $default = 0)
            {
                return 1;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return match ($key) {
                    self::schema_fields_total => $this->total,
                    self::schema_fields_increment_id => $this->incrementId,
                    default => $index,
                };
            }
        };
    }
}
