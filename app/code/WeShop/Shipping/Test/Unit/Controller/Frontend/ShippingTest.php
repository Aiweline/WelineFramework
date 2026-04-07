<?php

declare(strict_types=1);

namespace WeShop\Shipping\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Shipping\Controller\Frontend\Shipping;
use WeShop\Shipping\Service\ShippingService;
use Weline\Framework\Http\Request;

final class ShippingTest extends TestCase
{
    public function testIndexReturnsShippingMethods(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('getCheckoutShippingMethods')
            ->with([
                'area' => 'frontend',
                'currency' => 'USD',
                'subtotal' => 0.0,
            ])
            ->willReturn([
                ['code' => 'flat_rate', 'name' => 'Flat Rate'],
                ['code' => 'free_shipping', 'name' => 'Free Shipping'],
            ]);
        $shippingService->expects($this->once())
            ->method('getAvailableShippingMethods')
            ->with([
                'area' => 'frontend',
                'currency' => 'USD',
                'subtotal' => 0.0,
            ])
            ->willReturn([
                'flat_rate' => 'Flat Rate',
                'free_shipping' => 'Free Shipping',
            ]);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = $this->getMockBuilder(Shipping::class)
            ->setConstructorArgs([$shippingService, $customerSession])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Shipping::templates/Frontend/Shipping/index.phtml')
            ->willReturn('shipping-page');

        $this->setControllerRequest($controller, $this->createRequestMock());

        self::assertSame('shipping-page', $controller->index());
        self::assertSame('Shipping Methods', $assignments['page_title'] ?? null);
        self::assertSame('USD', $assignments['currency'] ?? null);
        self::assertCount(2, $assignments['shipping_methods'] ?? []);
        self::assertArrayHasKey('flat_rate', $assignments['available_methods'] ?? []);
    }

    public function testCalculateReturnsShippingFee(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $shippingService->expects($this->once())
            ->method('calculateShipping')
            ->with([
                'area' => 'frontend',
                'currency' => 'USD',
                'subtotal' => 0.0,
                'shipping_method' => 'flat_rate',
            ], 'flat_rate')
            ->willReturn(5.00);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = new Shipping($shippingService, $customerSession);
        $this->setControllerRequest($controller, $this->createRequestMock([
            'shipping_method' => 'flat_rate',
        ]));

        $result = $controller->calculate();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('flat_rate', $data['data']['shipping_method']);
        self::assertEquals(5.00, $data['data']['shipping_fee']);
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
        $this->setControllerRequest($controller, $this->createRequestMock([
            'shipping_method' => 'dhl',
        ]));

        $result = $controller->calculate();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Unsupported shipping method', (string) ($data['message'] ?? ''));
    }

    public function testBuildContextReturnsCorrectStructure(): void
    {
        $shippingService = $this->createMock(ShippingService::class);
        $customerSession = $this->createMock(CustomerSession::class);

        $controller = new Shipping($shippingService, $customerSession);
        $this->setControllerRequest($controller, $this->createRequestMock([
            'shipping_address' => [
                'country_id' => 'US',
                'region' => 'CA',
                'city' => 'San Jose',
            ],
            'cart_items' => [['sku' => 'demo']],
            'subtotal' => '99.50',
        ]));

        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('buildContext');
        $method->setAccessible(true);

        $result = $method->invoke($controller, null);

        self::assertIsArray($result);
        self::assertSame('frontend', $result['area']);
        self::assertSame('USD', $result['currency']);
        self::assertSame('US', $result['country']);
        self::assertSame('CA', $result['region']);
        self::assertSame('San Jose', $result['city']);
        self::assertSame(99.50, $result['subtotal']);
        self::assertCount(1, $result['items']);
    }

    private function createRequestMock(array $params = []): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }
}
