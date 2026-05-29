<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Controller\Frontend\Notification;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Notification\Controller\Frontend\Notification\Index;
use WeShop\Notification\Service\NotificationPageDataService;
use Weline\Framework\Manager\ObjectManager;

class IndexTest extends TestCase
{
    public function testIndexRedirectsGuestCustomersToLogin(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $pageDataService = $this->createMock(NotificationPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('customer/account/login');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $this->assertSame('', $controller->index());
    }

    public function testIndexRedirectsLoggedInCustomerToAccountNotificationSection(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(7);

        $pageDataService = $this->createMock(NotificationPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('customer/account/index#notification-preferences');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

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
}
