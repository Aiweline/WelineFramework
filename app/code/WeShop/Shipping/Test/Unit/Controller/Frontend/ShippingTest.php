<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use WeShop\Shipping\Controller\Frontend\Shipping;
use WeShop\Shipping\Service\ShippingService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Customer\Model\Customer;

class ShippingTest extends TestCase
{
    public function testIndexReturnsShippingMethods(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('getCheckoutShippingMethods')
            ->willReturn([
                [
                    'code' => 'flat_rate',
                    'name' => 'Flat Rate',
                    'description' => 'Standard delivery with a fixed shipping fee.',
                    'enabled' => true,
                    'is_default' => true,
                    'sort_order' => 10,
                    'price' => 5.00,
                    'areas' => ['frontend'],
                ],
                [
                    'code' => 'free_shipping',
                    'name' => 'Free Shipping',
                    'description' => 'Free delivery for eligible orders.',
                    'enabled' => true,
                    'is_default' => false,
                    'sort_order' => 20,
                    'price' => 0.00,
                    'areas' => ['frontend'],
                ],
            ]);

        $shippingService->expects($this->once())
            ->method('getAvailableShippingMethods')
            ->willReturn([
                'flat_rate' => 'Flat Rate',
                'free_shipping' => 'Free Shipping',
            ]);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = new Shipping($shippingService, $customerSession);

        $this->assertIsArray($controller->index());
    }

    public function testCalculateReturnsShippingFee(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('calculateShipping')
            ->willReturn(5.00);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = new Shipping($shippingService, $customerSession);

        $result = $controller->calculate();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(5.00, $data['data']['shipping_fee']);
    }

    public function testCalculateWithUnsupportedMethodThrowsException(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('calculateShipping')
            ->willThrowException(new \InvalidArgumentException('Unsupported shipping method: dhl'));

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = new Shipping($shippingService, $customerSession);

        $result = $controller->calculate();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Unsupported shipping method', $data['message']);
    }

    public function testBuildContextReturnsCorrectStructure(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $customerSession = $this->createMock(CustomerSession::class);

        $controller = new Shipping($shippingService, $customerSession);

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('buildContext');
        $method->setAccessible(true);

        $result = $method->invoke($controller, null);

        $this->assertIsArray($result);
        $this->assertEquals('frontend', $result['area']);
        $this->assertArrayHasKey('currency', $result);
    }
}
