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

        $capturedPayloads = [];
        $pageDataService = $this->createMock(CheckoutPageDataService::class);
        $pageDataService->expects($this->exactly(2))
            ->method('buildDynamicMethodData')
            ->willReturnCallback(function (int $customerId, array $payload) use (&$capturedPayloads): array {
                $this->assertSame(12, $customerId);
                $capturedPayloads[] = $payload;

                return [
                    'selected_shipping_address_id' => 3,
                    'shipping_methods' => [
                        ['code' => 'dhl', 'is_default' => true],
                    ],
                    'payment_methods' => [
                        ['code' => 'paypal', 'is_default' => true],
                    ],
                    'cart_summary' => [
                        'shipping' => 12.0,
                        'tax' => 4.76,
                        'grand_total' => 76.26,
                    ],
                ];
            });

        $request = new Request();
        $request->setPost('shipping_address_id', 3);
        $request->setPost('shipping_address', ['country_id' => 'GB']);
        $request->setPost('shipping_method', 'dhl');
        $request->setPost('payment_method', 'paypal');
        $request->setPost('order_id', 0);

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
                    && (string) ($payload['data']['payment_methods'][0]['code'] ?? '') === 'paypal'
                    && (float) ($payload['data']['cart_summary']['grand_total'] ?? 0) === 76.26;
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
        $this->assertSame('json', $controller->post());
        $this->assertCount(2, $capturedPayloads);
        foreach ($capturedPayloads as $payload) {
            $this->assertSame([
                'shipping_address_id' => 3,
                'shipping_address' => [
                    'country_id' => 'GB',
                ],
                'shipping_method' => 'dhl',
                'payment_method' => 'paypal',
                'order_id' => 0,
            ], $payload);
        }
    }

    public function testIndexFallsBackToRetryOrderIdFromRealRequestPayload(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($this->createCustomer(12));

        $capturedPayloads = [];
        $pageDataService = $this->createMock(CheckoutPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('buildDynamicMethodData')
            ->willReturnCallback(function (int $customerId, array $payload) use (&$capturedPayloads): array {
                $this->assertSame(12, $customerId);
                $capturedPayloads[] = $payload;

                return [
                    'selected_shipping_address_id' => 3,
                    'shipping_methods' => [
                        ['code' => 'flat_rate', 'is_default' => true],
                    ],
                    'payment_methods' => [],
                ];
            });

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')
            ->willReturnMap([
                ['shipping_address_id', null, 3],
                ['shipping_address', null, null],
                ['shipping', null, ['country_id' => 'GB']],
                ['shipping_method', null, 'flat_rate'],
                ['payment_method', null, ''],
                ['order_id', null, null],
                ['retry_order_id', null, 18],
            ]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Methods::class)
            ->setConstructorArgs([$pageDataService, $customerSession])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? false) === true
                    && (string) ($payload['data']['shipping_methods'][0]['code'] ?? '') === 'flat_rate';
            }))
            ->willReturn('retry-json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('retry-json', $controller->index());
        $this->assertSame([
            [
                'shipping_address_id' => 3,
                'shipping_address' => [
                    'country_id' => 'GB',
                ],
                'shipping_method' => 'flat_rate',
                'payment_method' => '',
                'order_id' => 18,
            ],
        ], $capturedPayloads);
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
