<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller\Frontend\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Frontend\Checkout\PlaceOrder;
use WeShop\Checkout\Service\CheckoutService;
use WeShop\Customer\Model\Customer;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;

class PlaceOrderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['WELINE_USER_CURRENCY']);
        Context::leave();
        parent::tearDown();
    }

    public function testIndexPersistsCheckoutContextIntoSessionWhenOrderIsPlaced(): void
    {
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';
        WelineEnv::setCurrency('USD');

        $customer = $this->createCustomer(9);
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
                'is_retry_payment' => false,
            ]);

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->once())
            ->method('placeOrder')
            ->with([
                'customer_id' => 9,
                'order_id' => 0,
                'shipping_address_id' => 3,
                'billing_address_id' => 0,
                'shipping_address' => ['firstname' => 'Ada'],
                'shipping_method' => 'flat_rate',
                'payment_method' => 'paypal',
                'currency' => 'USD',
                'client_ip' => '203.0.113.9',
            ])
            ->willReturn([
                'order_id' => 88,
                'order_increment_id' => 'WS000088',
                'payment' => [
                    'redirect_url' => 'https://paypal.test/checkout',
                ],
                'payment_method' => [
                    'code' => 'paypal',
                    'title' => 'PayPal',
                ],
                'order_summary' => [
                    'grand_total' => 63.5,
                ],
                'is_retry_payment' => false,
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $default);
        $request->method('getPost')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $default);
        $request->method('getParam')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'order_id' => 0,
                'shipping_address_id' => 3,
                'billing_address_id' => 0,
                'shipping_address' => ['firstname' => 'Ada'],
                'shipping' => null,
                'shipping_method' => 'flat_rate',
                'payment_method' => 'paypal',
                default => $default,
            };
        });
        $request->method('getServer')
            ->willReturnMap([
                ['REMOTE_ADDR', '203.0.113.9'],
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
                    && (string) ($payload['data']['increment_id'] ?? '') === 'WS000088'
                    && (string) ($payload['data']['redirect_url'] ?? '') === 'https://paypal.test/checkout'
                    && ($payload['data']['is_retry_payment'] ?? null) === false;
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexPassesRetryOrderIdThroughToCheckoutService(): void
    {
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';
        WelineEnv::setCurrency('USD');

        $customer = $this->createCustomer(9);
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('getCustomer')->willReturn($customer);
        $customerSession->expects($this->once())
            ->method('set')
            ->with($this->equalTo('weshop_checkout_last_order_context'), $this->callback(static function (array $context): bool {
                return (int) ($context['order_id'] ?? 0) === 77
                    && ($context['is_retry_payment'] ?? null) === true;
            }));

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->once())
            ->method('placeOrder')
            ->with($this->callback(static function (array $payload): bool {
                return (int) ($payload['order_id'] ?? 0) === 77
                    && (string) ($payload['payment_method'] ?? '') === 'paypal';
            }))
            ->willReturn([
                'order_id' => 77,
                'order_increment_id' => 'WS000077',
                'payment' => [
                    'redirect_url' => 'https://paypal.test/retry',
                ],
                'payment_method' => [
                    'code' => 'paypal',
                    'title' => 'PayPal',
                ],
                'order_summary' => [
                    'grand_total' => 59.5,
                ],
                'is_retry_payment' => true,
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $default);
        $request->method('getPost')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $default);
        $request->method('getParam')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'order_id' => 77,
                'shipping_address_id' => 3,
                'billing_address_id' => 0,
                'shipping_address' => ['firstname' => 'Ada'],
                'shipping' => null,
                'shipping_method' => 'flat_rate',
                'payment_method' => 'paypal',
                default => $default,
            };
        });
        $request->method('getServer')->willReturnMap([['REMOTE_ADDR', '203.0.113.9']]);

        $controller = $this->getMockBuilder(PlaceOrder::class)
            ->setConstructorArgs([$checkoutService, $customerSession])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['order_id'] ?? 0) === 77
                    && ($payload['data']['is_retry_payment'] ?? null) === true;
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexReadsBodyAndPostPayloadWithRetryOrderFallback(): void
    {
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';
        WelineEnv::setCurrency('USD');

        $customer = $this->createCustomer(9);
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($customer);
        $customerSession->expects($this->once())
            ->method('set')
            ->with($this->equalTo('weshop_checkout_last_order_context'), $this->callback(static function (array $context): bool {
                return (int) ($context['order_id'] ?? 0) === 71
                    && ($context['is_retry_payment'] ?? null) === true;
            }));

        $checkoutService = $this->createMock(CheckoutService::class);
        $checkoutService->expects($this->once())
            ->method('placeOrder')
            ->with($this->callback(static function (array $payload): bool {
                return (int) ($payload['order_id'] ?? 0) === 71
                    && (int) ($payload['shipping_address_id'] ?? 0) === 4
                    && (int) ($payload['billing_address_id'] ?? 0) === 12
                    && (string) ($payload['shipping_method'] ?? '') === 'dhl'
                    && (string) ($payload['payment_method'] ?? '') === 'paypal'
                    && (string) (($payload['shipping_address']['country_id'] ?? '')) === 'GB';
            }))
            ->willReturn([
                'order_id' => 71,
                'order_increment_id' => 'WS000071',
                'payment' => [],
                'payment_method' => ['code' => 'paypal', 'title' => 'PayPal'],
                'order_summary' => ['grand_total' => 59.5],
                'is_retry_payment' => true,
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')
            ->willReturnMap([
                ['order_id', null, null],
                ['retry_order_id', null, null],
                ['shipping_address_id', null, 4],
                ['billing_address_id', null, null],
                ['shipping_address', null, null],
                ['shipping', null, null],
                ['shipping_method', null, null],
                ['payment_method', null, 'paypal'],
            ]);
        $request->method('getPost')
            ->willReturnMap([
                ['order_id', null, null],
                ['retry_order_id', null, 71],
                ['shipping_address_id', null, null],
                ['billing_address_id', null, 12],
                ['shipping_address', null, null],
                ['shipping', null, ['country_id' => 'GB']],
                ['shipping_method', null, 'dhl'],
                ['payment_method', null, null],
            ]);
        $request->method('getParam')->willReturn(null);
        $request->method('getServer')->willReturnMap([['REMOTE_ADDR', '203.0.113.9']]);

        $controller = $this->getMockBuilder(PlaceOrder::class)
            ->setConstructorArgs([$checkoutService, $customerSession])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['order_id'] ?? 0) === 71
                    && ($payload['data']['is_retry_payment'] ?? null) === true;
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
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
