<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Frontend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Controller\Frontend\Order\RetryPayment;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class RetryPaymentTest extends TestCase
{
    public function testIndexRedirectsGuestsToCanonicalLoginRoute(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->method('getUserId')->willReturn(null);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->never())->method('getRetryPaymentContext');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'order_id' => 77,
        ]));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError');

        $controller = $this->getMockBuilder(RetryPayment::class)
            ->setConstructorArgs([$customerContext, $orderService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/customer/account/login');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('', $controller->index());
    }

    public function testIndexRedirectsToCheckoutWhenRetryIsAllowed(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->method('getUserId')->willReturn(9);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getRetryPaymentContext')
            ->with(77, 9)
            ->willReturn([
                'order_id' => 77,
                'items' => [['item_id' => 1]],
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'order_id' => 77,
        ]));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess');

        $controller = $this->getMockBuilder(RetryPayment::class)
            ->setConstructorArgs([$customerContext, $orderService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('checkout', ['order_id' => 77]);

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('', $controller->index());
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

    /**
     * @param array<string,mixed> $params
     */
    private function requestParams(array $params): \Closure
    {
        return static fn(string $key, mixed $default = null): mixed => \array_key_exists($key, $params)
            ? $params[$key]
            : $default;
    }
}
