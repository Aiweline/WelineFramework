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

    public function testGetShareLinkGeneratesCustomLandingLinkForCurrentCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(88);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->never())->method('getUserId');

        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->once())
            ->method('getShareLink')
            ->with(88, '/product/view?id=652', 'account')
            ->willReturn([
                'share_code' => 'AFF-CUSTOM',
                'tracking_url' => '/affiliate/redirect?code=AFF-CUSTOM',
                'target_url' => '/product/view?id=652',
            ]);

        $provider = new AffiliateQueryProvider(
            $customerContext,
            $affiliateService,
            $this->createMock(Url::class),
            $customerSession
        );

        $result = $provider->execute('getShareLink', [
            'target_url' => '/product/view?id=652',
            'channel' => 'account',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('AFF-CUSTOM', $result['data']['share_code']);
        $this->assertSame('/affiliate/redirect?code=AFF-CUSTOM', $result['data']['tracking_url']);
    }

    public function testRequestWithdrawalUsesCurrentCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(91);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->never())->method('getUserId');

        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->once())
            ->method('requestWithdrawal')
            ->with(
                91,
                12.5,
                'manual',
                'bank 6222',
                $this->callback(static fn (array $metadata): bool => ($metadata['source'] ?? '') === 'account_center')
            )
            ->willReturn([
                'withdrawal_id' => 7,
                'amount' => 12.5,
                'status' => 'requested',
            ]);

        $provider = new AffiliateQueryProvider(
            $customerContext,
            $affiliateService,
            $this->createMock(Url::class),
            $customerSession
        );

        $result = $provider->execute('requestWithdrawal', [
            'amount' => 12.5,
            'account_label' => 'bank 6222',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['data']['withdrawal_id']);
    }

    public function testDescriptorExposesShareLinkOperation(): void
    {
        $provider = new AffiliateQueryProvider(
            $this->createMock(CustomerContextInterface::class),
            $this->createMock(AffiliateService::class),
            $this->createMock(Url::class),
            $this->createMock(CustomerSession::class)
        );

        $operationNames = array_column($provider->getDescriptor()['operations'], 'name');

        $this->assertContains('getShareLink', $operationNames);
        $this->assertContains('getMySummary', $operationNames);
        $this->assertContains('requestWithdrawal', $operationNames);
    }
}
