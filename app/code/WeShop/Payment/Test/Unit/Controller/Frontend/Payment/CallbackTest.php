<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Controller\Frontend\Payment;

use PHPUnit\Framework\TestCase;
use WeShop\Payment\Controller\Frontend\Payment\Callback;
use WeShop\Payment\Service\PaymentService;
use Weline\Framework\Http\Request;

class CallbackTest extends TestCase
{
    public function testIndexReturnsValidationErrorWhenPaymentMethodIsMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getParam')
            ->with('payment_method')
            ->willReturn('');

        $controller = $this->createController($request, $this->createMock(PaymentService::class));

        $result = json_decode($controller->index(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($result['success']);
        $this->assertContains(
            $result['message'],
            ['Payment method is required.', '支付方式不能为空。']
        );
    }

    public function testIndexDelegatesCallbackHandlingToPaymentService(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getParam')
            ->with('payment_method')
            ->willReturn('paypal');
        $request->expects($this->once())
            ->method('getParams')
            ->willReturn([
                'payment_method' => 'paypal',
                'token' => 'callback-token',
            ]);

        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('handleCallback')
            ->with('paypal', [
                'payment_method' => 'paypal',
                'token' => 'callback-token',
            ])
            ->willReturn(true);

        $controller = $this->createController($request, $paymentService);

        $result = json_decode($controller->index(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($result['success']);
        $this->assertContains(
            $result['message'],
            ['Payment callback handled.', '支付回调已处理。']
        );
    }

    private function createController(Request $request, PaymentService $paymentService): Callback
    {
        return new class($request, $paymentService) extends Callback {
            public function __construct(
                Request $request,
                private readonly PaymentService $paymentService
            ) {
                $this->request = $request;
            }

            protected function getPaymentService(): PaymentService
            {
                return $this->paymentService;
            }

            protected function fetchJson(array $data): string
            {
                return (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        };
    }
}
