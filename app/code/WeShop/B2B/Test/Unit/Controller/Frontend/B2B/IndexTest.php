<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Controller\Frontend\B2B;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Controller\Frontend\B2B\Index;
use WeShop\B2B\Service\CompanyPageDataService;
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

        $pageDataService = $this->createMock(CompanyPageDataService::class);
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

    public function testIndexAssignsCompanyPageDataForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(5);
        $customerContext->expects($this->once())
            ->method('getEmail')
            ->willReturn('buyer@example.com');

        $pageDataService = $this->createMock(CompanyPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(5, 'buyer@example.com')
            ->willReturn([
                'company_summary' => ['total' => 1],
                'company_list' => [['company_id' => 1]],
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(2))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

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
