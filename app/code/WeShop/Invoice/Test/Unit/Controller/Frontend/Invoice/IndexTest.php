<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Controller\Frontend\Invoice;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Invoice\Controller\Frontend\Invoice\Index;
use WeShop\Invoice\Service\InvoicePageDataService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class IndexTest extends TestCase
{
    public function testIndexRedirectsGuestCustomersToLogin(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $pageDataService = $this->createMock(InvoicePageDataService::class);
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

    public function testIndexAssignsInvoicePageDataForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(9);

        $pageDataService = $this->createMock(InvoicePageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(9, 1, 20)
            ->willReturn([
                'invoices' => [['invoice_id' => 101]],
                'invoice_count' => 1,
                'invoice_pending_count' => 0,
                'invoice_issued_count' => 1,
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(4))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['page', null, 1],
            ['page_size', null, 20],
        ]);
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
