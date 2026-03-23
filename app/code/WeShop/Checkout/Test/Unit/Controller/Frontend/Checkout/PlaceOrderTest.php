<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller\Frontend\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Frontend\Checkout\PlaceOrder;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Model\Customer;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Request;

class PlaceOrderTest extends TestCase
{
    public function testIndexPersistsCheckoutContextIntoSessionWhenOrderIsPlaced(): void
    {
        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $customer->method('getId')->willReturn(9);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($customer);
        $customerSession->expects($this->once())
            ->method('set')
            ->with('weshop_checkout_last_order_context', [
                'order_id' => 88,
                'order_increment_id' => 'WS000088',
                'shipping_address' => [
                    'firstname' => 'Ada',
                ],
                'shipping_method' => 'flat_rate',
                'payment_method' => [
                    'code' => 'paypal',
                    'title' => 'PayPal',
                ],
                'cart_summary' => [
                    'grand_total' => 63.5,
                ],
            ]);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->once())
            ->method('placeOrder')
            ->with([
                'customer_id' => 9,
                'shipping_address_id' => 3,
                'billing_address_id' => 0,
                'shipping_address' => ['firstname' => 'Ada'],
                'shipping_method' => 'flat_rate',
                'payment_method' => 'paypal',
            ])
            ->willReturn([
                'order_id' => 88,
                'order_increment_id' => 'WS000088',
                'payment_method' => [
                    'code' => 'paypal',
                    'title' => 'PayPal',
                ],
                'order_summary' => [
                    'grand_total' => 63.5,
                ],
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnMap([
                ['shipping_address_id', null, 3],
                ['billing_address_id', null, 0],
                ['shipping_address', null, ['firstname' => 'Ada']],
                ['shipping', null, null],
                ['shipping_method', null, 'flat_rate'],
                ['payment_method', null, 'paypal'],
            ]);

        $controller = $this->getMockBuilder(PlaceOrder::class)
            ->setConstructorArgs([$checkoutService, $customerSession])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['order_id'] ?? 0) === 88
                    && (string) ($payload['data']['increment_id'] ?? '') === 'WS000088';
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
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
