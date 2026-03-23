<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Extends\Module\Weline_Framework\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Payment\Extends\Module\Weline_Framework\Query\PaymentQueryProvider;
use WeShop\Payment\Service\PaymentService;

class PaymentQueryProviderTest extends TestCase
{
    public function testExecuteReturnsCheckoutPaymentMethodsFromService(): void
    {
        $expected = [
            ['code' => 'manual_transfer', 'title' => 'Manual Transfer'],
            ['code' => 'paypal', 'title' => 'PayPal'],
        ];

        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('getCheckoutPaymentMethods')
            ->with([
                'customer_id' => 12,
                'area' => 'frontend',
            ])
            ->willReturn($expected);

        $provider = new PaymentQueryProvider($paymentService);

        $result = $provider->execute('getCheckoutPaymentMethods', [
            'customer_id' => 12,
            'area' => 'frontend',
        ]);

        $this->assertSame($expected, $result);
    }
}
