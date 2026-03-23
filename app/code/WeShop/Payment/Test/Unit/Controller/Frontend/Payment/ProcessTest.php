<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Controller\Frontend\Payment;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\Payment\Controller\Frontend\Payment\Process;
use WeShop\Payment\Service\PaymentService;
use Weline\Framework\Http\Request;

class ProcessTest extends TestCase
{
    public function testIndexReturnsValidationErrorWhenOrderIdIsMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['order_id', 0],
                ['payment_method', 'paypal'],
            ]);

        $controller = $this->createController(
            $request,
            $this->createMock(PaymentService::class),
            $this->createMock(OrderService::class)
        );

        $result = json_decode($controller->index(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($result['success']);
        $this->assertSame('Order ID is required.', $result['message']);
    }

    public function testIndexProcessesPaymentWithResolvedOrder(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(3))
            ->method('getParam')
            ->willReturnMap([
                ['order_id', 91],
                ['payment_method', 'paypal'],
                ['payment', ['return_url' => 'https://example.com/payment/return']],
            ]);

        $order = new class extends Order {
            public function __construct()
            {
            }
        };

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrder')
            ->with(91)
            ->willReturn($order);

        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('processPayment')
            ->with($order, 'paypal', ['return_url' => 'https://example.com/payment/return'])
            ->willReturn([
                'status' => 'pending',
                'redirect_url' => 'https://paypal.test/checkout',
            ]);

        $controller = $this->createController($request, $paymentService, $orderService);

        $result = json_decode($controller->index(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['data']['status']);
        $this->assertSame('https://paypal.test/checkout', $result['data']['redirect_url']);
    }

    private function createController(
        Request $request,
        PaymentService $paymentService,
        OrderService $orderService
    ): Process {
        return new class($request, $paymentService, $orderService) extends Process {
            public function __construct(
                Request $request,
                private readonly PaymentService $paymentService,
                private readonly OrderService $orderService
            ) {
                $this->request = $request;
            }

            protected function getPaymentService(): PaymentService
            {
                return $this->paymentService;
            }

            protected function getOrderService(): OrderService
            {
                return $this->orderService;
            }

            protected function fetchJson(array $data): string
            {
                return (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        };
    }
}
