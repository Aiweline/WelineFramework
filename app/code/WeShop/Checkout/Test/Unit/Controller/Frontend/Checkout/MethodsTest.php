<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller\Frontend\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Frontend\Checkout\Methods;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Model\Customer;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Request;

class MethodsTest extends TestCase
{
    public function testIndexReturnsGuestErrorWhenCustomerIsMissing(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $pageDataService = $this->createMock(CheckoutPageDataService::class);
        $pageDataService->expects($this->never())->method('buildDynamicMethodData');

        $controller = $this->getMockBuilder(Methods::class)
            ->setConstructorArgs([$pageDataService, $customerSession])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && (string) ($payload['message'] ?? '') !== '';
            }))
            ->willReturn('json');

        $this->assertSame('json', $controller->index());
    }

    public function testIndexBuildsDynamicMethodPayloadForLoggedInCustomer(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->exactly(2))
            ->method('getCustomer')
            ->willReturn($this->createCustomer(12));

        $pageDataService = $this->createMock(CheckoutPageDataService::class);
        $pageDataService->expects($this->exactly(2))
            ->method('buildDynamicMethodData')
            ->with(12, [
                'shipping_address_id' => 3,
                'shipping_address' => [
                    'country_id' => 'GB',
                ],
                'shipping_method' => 'dhl',
                'payment_method' => 'paypal',
            ])
            ->willReturn([
                'selected_shipping_address_id' => 3,
                'shipping_methods' => [
                    ['code' => 'dhl', 'is_default' => true],
                ],
                'payment_methods' => [
                    ['code' => 'paypal', 'is_default' => true],
                ],
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnMap([
                ['shipping_address_id', null, 3],
                ['shipping_address', null, ['country_id' => 'GB']],
                ['shipping', null, null],
                ['shipping_method', null, 'dhl'],
                ['payment_method', null, 'paypal'],
            ]);

        $controller = $this->getMockBuilder(Methods::class)
            ->setConstructorArgs([$pageDataService, $customerSession])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->exactly(2))
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? false) === true
                    && (int) ($payload['data']['selected_shipping_address_id'] ?? 0) === 3
                    && (string) ($payload['data']['shipping_methods'][0]['code'] ?? '') === 'dhl'
                    && (string) ($payload['data']['payment_methods'][0]['code'] ?? '') === 'paypal';
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
        $this->assertSame('json', $controller->post());
    }

    private function createCustomer(int $id): Customer
    {
        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $customer->method('getId')->willReturn($id);

        return $customer;
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
