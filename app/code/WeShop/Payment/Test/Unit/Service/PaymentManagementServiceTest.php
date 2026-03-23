<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Payment\Service\PaymentManagementService;
use WeShop\Payment\Service\PaymentService;

class PaymentManagementServiceTest extends TestCase
{
    public function testGetManagementDataBuildsSummaryFromManagementMethods(): void
    {
        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('getManagementPaymentMethods')
            ->willReturn([
                ['code' => 'manual_transfer', 'title' => 'Manual Transfer', 'enabled' => true, 'is_default' => true],
                ['code' => 'paypal', 'title' => 'PayPal', 'enabled' => true, 'is_default' => false],
                ['code' => 'alipay', 'title' => 'Alipay', 'enabled' => false, 'is_default' => false],
            ]);

        $service = new PaymentManagementService($paymentService);
        $result = $service->getManagementData();

        $this->assertCount(3, $result['methods']);
        $this->assertSame(3, $result['stats']['total_methods']);
        $this->assertSame(2, $result['stats']['enabled_methods']);
        $this->assertSame(1, $result['stats']['reserved_methods']);
        $this->assertSame('manual_transfer', $result['stats']['default_method_code']);
    }

    public function testSaveNormalizesMethodFlagsAndConfigBeforePersisting(): void
    {
        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('getManagementPaymentMethods')
            ->willReturn([
                [
                    'code' => 'manual_transfer',
                    'enabled' => true,
                    'is_default' => true,
                    'sort_order' => 10,
                    'config' => ['instructions' => 'Old note'],
                    'config_fields' => [
                        ['key' => 'instructions', 'type' => 'textarea'],
                    ],
                ],
                [
                    'code' => 'paypal',
                    'enabled' => true,
                    'is_default' => false,
                    'sort_order' => 30,
                    'config' => ['sandbox' => true, 'client_id' => ''],
                    'config_fields' => [
                        ['key' => 'sandbox', 'type' => 'checkbox'],
                        ['key' => 'client_id', 'type' => 'text'],
                    ],
                ],
            ]);

        $stored = [];

        $service = new class($paymentService, $stored) extends PaymentManagementService {
            public function __construct(
                PaymentService $paymentService,
                private array &$stored
            ) {
                parent::__construct($paymentService);
            }

            protected function persistMethodConfig(array $config): void
            {
                $this->stored = $config;
            }
        };

        $result = $service->save([
            'default_method' => 'paypal',
            'methods' => [
                'manual_transfer' => [
                    'enabled' => '0',
                    'sort_order' => '50',
                    'config' => [
                        'instructions' => 'Transfer within 48 hours',
                    ],
                ],
                'paypal' => [
                    'enabled' => '1',
                    'sort_order' => '5',
                    'config' => [
                        'sandbox' => '0',
                        'client_id' => 'client-id-123',
                    ],
                ],
            ],
        ]);

        $this->assertSame('paypal', $result['default_method']);
        $this->assertFalse($stored['manual_transfer']['enabled']);
        $this->assertSame(50, $stored['manual_transfer']['sort_order']);
        $this->assertSame('Transfer within 48 hours', $stored['manual_transfer']['config']['instructions']);
        $this->assertTrue($stored['paypal']['enabled']);
        $this->assertTrue($stored['paypal']['is_default']);
        $this->assertSame(5, $stored['paypal']['sort_order']);
        $this->assertFalse($stored['paypal']['config']['sandbox']);
        $this->assertSame('client-id-123', $stored['paypal']['config']['client_id']);
    }
}
