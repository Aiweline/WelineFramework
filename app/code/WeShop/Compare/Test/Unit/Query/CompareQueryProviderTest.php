<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Compare\Extends\Module\Weline_Framework\Query\CompareQueryProvider;
use WeShop\Compare\Model\Compare;
use WeShop\Compare\Service\CompareService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Url;

class CompareQueryProviderTest extends TestCase
{
    public function testAddFallsBackToCustomerSessionWhenContextIsEmpty(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())->method('getUserId')->willReturn(77);

        $compareItem = $this->getMockBuilder(Compare::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $compareItem->method('getId')->willReturn(12);

        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->once())
            ->method('addToCompare')
            ->with(77, 652)
            ->willReturn($compareItem);
        $compareService->expects($this->once())
            ->method('getCompareCount')
            ->with(77)
            ->willReturn(1);

        $provider = new CompareQueryProvider(
            $customerContext,
            $compareService,
            $this->createMock(Url::class),
            $customerSession
        );

        $result = $provider->execute('add', ['product_id' => 652]);

        $this->assertTrue($result['success']);
        $this->assertSame(12, $result['data']['item_id']);
        $this->assertSame(1, $result['data']['compare_count']);
    }

    public function testAddReturnsLoginRequiredWhenNoCustomerCanBeResolved(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())->method('getUserId')->willReturn(null);

        $compareService = $this->createMock(CompareService::class);
        $compareService->expects($this->never())->method('addToCompare');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('/customer/account/login');

        $provider = new CompareQueryProvider(
            $customerContext,
            $compareService,
            $url,
            $customerSession
        );

        $result = $provider->execute('add', ['product_id' => 652]);

        $this->assertFalse($result['success']);
        $this->assertSame('/customer/account/login', $result['data']['redirect_url']);
    }
}
