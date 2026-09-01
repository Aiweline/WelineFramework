<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Extends\Module\Weline_Framework\Query\AffiliateAdminQueryProvider;
use Weline\Affiliate\Extends\Module\Weline_Framework\Query\AffiliateQueryProvider;
use Weline\Affiliate\Service\AffiliateAdminPageDataService;
use Weline\Affiliate\Service\AffiliateService;

final class AffiliateQueryProviderContractTest extends TestCase
{
    public function testFrontendProviderNameAndModule(): void
    {
        $provider = new AffiliateQueryProvider(
            $this->createMock(AffiliateService::class),
            $this->createMock(\Weline\Framework\Http\Url::class),
        );

        $descriptor = $provider->getDescriptor();

        $this->assertSame('affiliate', $provider->getProviderName());
        $this->assertSame('Weline_Affiliate', $descriptor['module'] ?? null);
        $this->assertSame('getMySummary', $descriptor['operations'][3]['name'] ?? null);
    }

    public function testAdminProviderUsesCommerceAclSource(): void
    {
        $provider = new AffiliateAdminQueryProvider(
            $this->createMock(AffiliateService::class),
            $this->createMock(AffiliateAdminPageDataService::class),
        );

        $descriptor = $provider->getDescriptor();

        $this->assertSame('affiliate_admin', $provider->getProviderName());
        $this->assertSame(
            AffiliateAdminQueryProvider::ACL_SOURCE,
            $descriptor['operations'][0]['backend_acl']['source_id'] ?? null
        );
        $this->assertSame('listAffiliates', $descriptor['operations'][0]['name'] ?? null);
    }

    public function testAdminProviderRejectsUnknownOperation(): void
    {
        $provider = new AffiliateAdminQueryProvider(
            $this->createMock(AffiliateService::class),
            $this->createMock(AffiliateAdminPageDataService::class),
        );

        $result = $provider->execute('unknown');

        $this->assertFalse($result['success'] ?? true);
    }
}
