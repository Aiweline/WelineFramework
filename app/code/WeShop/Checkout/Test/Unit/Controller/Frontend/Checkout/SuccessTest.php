<?php

declare(strict_types=1);

namespace WeShop\Checkout\Test\Unit\Controller\Frontend\Checkout;

use PHPUnit\Framework\TestCase;
use WeShop\Checkout\Controller\Frontend\Checkout\Success;
use WeShop\Checkout\Service\OrderSuccessPageDataService;
use WeShop\Customer\Model\Customer;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class SuccessTest extends TestCase
{
    public function testIndexRedirectsGuestsToCanonicalLoginRoute(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->never())->method('getOrder');

        $pageDataService = $this->createMock(OrderSuccessPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getParam')
            ->with('order_id')
            ->willReturn(88);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError');

        $controller = $this->getMockBuilder(Success::class)
            ->setConstructorArgs([$customerSession, $orderService, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/customer/account/login');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsSuccessPageDataForMatchingCustomerOrder(): void
    {
        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $customer->method('getId')->willReturn(7);

        $order = new class() extends Order {
            public function __construct()
            {
            }

            public function getId(mixed $default = 0)
            {
                return 88;
            }
        };
        $order->setData(Order::schema_fields_customer_id, 7);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($customer);
        $customerSession->expects($this->once())
            ->method('get')
            ->with('weshop_checkout_last_order_context')
            ->willReturn([
                'order_id' => 88,
            ]);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrder')
            ->with(88)
            ->willReturn($order);

        $pageDataService = $this->createMock(OrderSuccessPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with($order, ['order_id' => 88])
            ->willReturn([
                'order' => ['increment_id' => 'WS000088'],
                'recommendations' => [['product_id' => 3]],
            ]);

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getParam')
            ->with('order_id')
            ->willReturn(88);

        $controller = $this->getMockBuilder(Success::class)
            ->setConstructorArgs([$customerSession, $orderService, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();
        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(2))->method('assign');
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Checkout::templates/frontend/checkout/success.phtml')
            ->willReturn('page');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('page', $controller->index());
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
