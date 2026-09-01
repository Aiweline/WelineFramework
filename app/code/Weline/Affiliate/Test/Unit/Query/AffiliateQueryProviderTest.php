<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Extends\Module\Weline_Framework\Query\AffiliateQueryProvider;
use Weline\Affiliate\Service\AffiliateService;
use Weline\Framework\Http\Url;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

final class AffiliateQueryProviderTest extends TestCase
{
    public function testProductShareLinksUsesFrontendSessionCustomer(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())->method('getUserId')->willReturn(77);

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects($this->once())->method('createFrontendSession')->willReturn($session);

        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->once())
            ->method('getProductShareLinks')
            ->with(77, 652, 'product_detail')
            ->willReturn([
                'share_code' => 'AFF-TEST',
                'tracking_url' => '/affiliate/redirect?code=AFF-TEST',
            ]);

        $provider = new AffiliateQueryProvider(
            $affiliateService,
            $this->createMock(Url::class),
            $sessionFactory,
        );

        $result = $provider->execute('getProductShareLinks', [
            'product_id' => 652,
            'channel' => 'product_detail',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('AFF-TEST', $result['data']['share_code']);
    }

    public function testProductShareLinksReturnsLoginRequiredWhenSessionIsGuest(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())->method('getUserId')->willReturn(null);

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects($this->once())->method('createFrontendSession')->willReturn($session);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('/customer/account/login');

        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->never())->method('getProductShareLinks');

        $provider = new AffiliateQueryProvider(
            $affiliateService,
            $url,
            $sessionFactory,
        );

        $result = $provider->execute('getProductShareLinks', ['product_id' => 652]);

        $this->assertFalse($result['success']);
        $this->assertSame('/customer/account/login', $result['data']['redirect_url']);
    }

    public function testDescriptorExposesShareLinkOperation(): void
    {
        $provider = new AffiliateQueryProvider(
            $this->createMock(AffiliateService::class),
            $this->createMock(Url::class),
            $this->createMock(SessionFactory::class),
        );

        $operationNames = array_column($provider->getDescriptor()['operations'], 'name');

        $this->assertContains('getShareLink', $operationNames);
        $this->assertContains('getMySummary', $operationNames);
        $this->assertContains('requestWithdrawal', $operationNames);
    }
}
