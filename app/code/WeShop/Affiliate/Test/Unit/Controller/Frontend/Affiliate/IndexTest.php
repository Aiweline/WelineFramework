<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Controller\Frontend\Affiliate;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Controller\Frontend\Affiliate\Index;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Manager\ObjectManager;

class IndexTest extends TestCase
{
    public function testIndexRedirectsGuestCustomersToLogin(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext])
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

    public function testIndexRedirectsLoggedInCustomerToAccountAffiliateSection(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(33);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext])
            ->onlyMethods(['assign', 'fetch', 'redirect', 'getUrl'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/index')
            ->willReturn('/customer/account/index');
        $controller->expects($this->once())
            ->method('redirect')
            ->with('/customer/account/index#affiliate');
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
