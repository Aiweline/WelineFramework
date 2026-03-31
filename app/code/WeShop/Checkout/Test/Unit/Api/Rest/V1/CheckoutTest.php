<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Api\Rest\V1\Checkout;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;

class CheckoutTest extends TestCase
{
    public function testPostMethodsReturnsUnauthorizedPayloadForGuest(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $checkoutPageDataService = $this->createMock(CheckoutPageDataService::class);
        $checkoutPageDataService->expects($this->never())->method('buildDynamicMethodData');

        $controller = $this->getMockBuilder(Checkout::class)
            ->setConstructorArgs([$customerContext, $checkoutPageDataService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (int) ($payload['code'] ?? 0) === 401
                    && ($payload['data']['shipping_methods'] ?? []) === []
                    && ($payload['data']['payment_methods'] ?? []) === [];
            }))
            ->willReturn('guest-json');

        $this->assertSame('guest-json', $controller->postMethods());
    }

    public function testMethodsReadsRequestPayloadAndReturnsResolvedData(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->exactly(2))
            ->method('getUserId')
            ->willReturn(27);

        $capturedPayloads = [];
        $checkoutPageDataService = $this->createMock(CheckoutPageDataService::class);
        $checkoutPageDataService->expects($this->exactly(2))
            ->method('buildDynamicMethodData')
            ->willReturnCallback(function (int $customerId, array $payload) use (&$capturedPayloads): array {
                $this->assertSame(27, $customerId);
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
                        'subtotal' => 99.0,
                        'shipping' => 12.0,
                        'discount' => 0.0,
                        'tax' => 4.2,
                        'grand_total' => 115.2,
                    ],
                ];
            });

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')
            ->willReturnMap([
                ['shipping_address_id', null, null],
                ['shipping_address', null, null],
                ['shipping', null, null],
                ['shipping_method', null, null],
                ['payment_method', null, null],
                ['order_id', null, null],
                ['retry_order_id', null, null],
            ]);
        $request->method('getPost')
            ->willReturnMap([
                ['shipping_address_id', null, 3],
                ['shipping_address', null, null],
                ['shipping', null, ['country_id' => 'GB', 'region' => 'LND']],
                ['shipping_method', null, 'dhl'],
                ['payment_method', null, 'paypal'],
                ['order_id', null, null],
                ['retry_order_id', null, 18],
            ]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Checkout::class)
            ->setConstructorArgs([$customerContext, $checkoutPageDataService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->exactly(2))
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (int) ($payload['code'] ?? 0) === 200
                    && (int) ($payload['data']['selected_shipping_address_id'] ?? 0) === 3
                    && (string) ($payload['data']['shipping_methods'][0]['code'] ?? '') === 'dhl'
                    && (string) ($payload['data']['payment_methods'][0]['code'] ?? '') === 'paypal'
                    && (float) ($payload['data']['cart_summary']['grand_total'] ?? 0) === 115.2;
            }))
            ->willReturn('resolved-json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('resolved-json', $controller->postMethods());
        $this->assertSame('resolved-json', $controller->getMethods());
        $this->assertCount(2, $capturedPayloads);
        foreach ($capturedPayloads as $payload) {
            $this->assertSame([
                'shipping_address_id' => 3,
                'shipping_address' => [
                    'country_id' => 'GB',
                    'region' => 'LND',
                ],
                'shipping_method' => 'dhl',
                'payment_method' => 'paypal',
                'order_id' => 18,
            ], $payload);
        }
    }

    public function testPostMethodsReturnsValidationErrorPayloadWhenServiceThrows(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(12);

        $checkoutPageDataService = $this->createMock(CheckoutPageDataService::class);
        $checkoutPageDataService->expects($this->once())
            ->method('buildDynamicMethodData')
            ->willThrowException(new \InvalidArgumentException('Bad checkout context.'));

        $request = new Request();

        $controller = $this->getMockBuilder(Checkout::class)
            ->setConstructorArgs([$customerContext, $checkoutPageDataService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (int) ($payload['code'] ?? 0) === 422
                    && (string) ($payload['msg'] ?? '') === 'Bad checkout context.'
                    && ($payload['data']['shipping_methods'] ?? []) === [];
            }))
            ->willReturn('error-json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('error-json', $controller->postMethods());
    }

    public function testPostMethodsRendersGuestPayloadWithTransport200(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $controller = new Checkout(
            $customerContext,
            $this->createMock(CheckoutPageDataService::class)
        );

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('setHeader')
            ->with('Content-Type', 'application/json; charset=utf-8')
            ->willReturnSelf();

        $request = $this->createMock(Request::class);
        $request->method('getResponse')->willReturn($response);
        $this->setProtectedProperty($controller, 'request', $request);

        $payload = json_decode($controller->postMethods(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(401, $payload['code'] ?? null);
        $this->assertNotEmpty($payload['msg'] ?? null);
        $this->assertSame([], $payload['data']['shipping_methods'] ?? null);
        $this->assertSame([], $payload['data']['payment_methods'] ?? null);
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
