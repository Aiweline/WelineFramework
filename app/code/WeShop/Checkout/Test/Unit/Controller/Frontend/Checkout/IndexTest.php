<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller\Frontend\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Frontend\Checkout\Index;
use WeShop\Checkout\Service\CheckoutPageDataService;
use WeShop\Customer\Model\Customer;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class IndexTest extends TestCase
{
    public function testIndexBuildsRetryCheckoutPageWhenOrderIdIsPresent(): void
    {
        $customer = $this->createCustomer(9);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($customer);

        $pageDataService = $this->createMock(CheckoutPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(9, 1, 77)
            ->willReturn([
                'cart_items' => [['qty' => 1]],
                'is_retry_payment' => true,
                'retry_order_id' => 77,
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnMap([
                ['step', null, 1],
                ['order_id', null, 77],
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerSession, $pageDataService])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();
        $controller->expects($this->atLeastOnce())->method('assign');
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Checkout::templates/frontend/checkout/index.phtml')
            ->willReturn('html');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('html', $controller->index());
    }

    public function testIndexRedirectsToOrderListWhenRetryOrderIsInvalid(): void
    {
        $customer = $this->createCustomer(9);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('getCustomer')->willReturn($customer);

        $pageDataService = $this->createMock(CheckoutPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(9, 1, 77)
            ->willReturn([
                'cart_items' => [],
                'is_retry_payment' => false,
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnMap([
                ['step', null, 1],
                ['order_id', null, 77],
            ]);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerSession, $pageDataService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/order/list');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertNull($controller->index());
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
