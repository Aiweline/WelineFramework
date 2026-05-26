<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Extends\Module\Weline_Framework\Query\AffiliateQueryProvider;
use WeShop\Affiliate\Service\AffiliateService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Url;

class AffiliateQueryProviderTest extends TestCase
{
    public function testProductShareLinksFallsBackToCustomerSessionWhenContextIsEmpty(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())->method('getUserId')->willReturn(77);

        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->once())
            ->method('getProductShareLinks')
            ->with(77, 652, 'product_detail')
            ->willReturn([
                'share_code' => 'AFF-TEST',
                'tracking_url' => '/affiliate/redirect?code=AFF-TEST',
            ]);

        $provider = new AffiliateQueryProvider(
            $customerContext,
            $affiliateService,
            $this->createMock(Url::class),
            $customerSession
        );

        $result = $provider->execute('getProductShareLinks', [
            'product_id' => 652,
            'channel' => 'product_detail',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('AFF-TEST', $result['data']['share_code']);
    }

    public function testProductShareLinksReturnsLoginRequiredWhenNoCustomerCanBeResolved(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())->method('getUserId')->willReturn(null);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('/customer/account/login');

        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->never())->method('getProductShareLinks');

        $provider = new AffiliateQueryProvider(
            $customerContext,
            $affiliateService,
            $url,
            $customerSession
        );

        $result = $provider->execute('getProductShareLinks', ['product_id' => 652]);

        $this->assertFalse($result['success']);
        $this->assertSame('/customer/account/login', $result['data']['redirect_url']);
    }
}
