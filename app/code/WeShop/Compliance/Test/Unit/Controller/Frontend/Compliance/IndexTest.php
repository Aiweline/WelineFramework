<?php

declare(strict_types=1);

namespace WeShop\Compliance\Test\Unit\Controller\Frontend\Compliance;

use PHPUnit\Framework\TestCase;
use WeShop\Compliance\Controller\Frontend\Compliance\Index;
use WeShop\Compliance\Service\CompliancePageDataService;
use WeShop\Customer\Api\CustomerContextInterface;

class IndexTest extends TestCase
{
    public function testIndexRedirectsGuestsToLogin(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $pageDataService = $this->createMock(CompliancePageDataService::class);
        $pageDataService->expects($this->never())->method('buildConsentPage');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->once())->method('redirect')->with('customer/account/login');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsConsentPageDataForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(12);

        $pageDataService = $this->createMock(CompliancePageDataService::class);
        $pageDataService->expects($this->once())
            ->method('buildConsentPage')
            ->with(12)
            ->willReturn([
                'consent_items' => [['code' => 'cookie']],
                'save_url' => '/compliance/consent/save',
                'privacy_url' => '/compliance/privacy',
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(3))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->assertSame('page', $controller->index());
    }
}

