<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Frontend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Controller\Frontend\Order\Cancel;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class CancelTest extends TestCase
{
    public function testPostIndexRedirectsGuestsToCanonicalLoginRoute(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->never())->method('canCancelOrder');

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getPost')
            ->with('order_id')
            ->willReturn(77);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError');

        $controller = $this->getMockBuilder(Cancel::class)
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

        $this->assertSame('', $controller->postIndex());
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
