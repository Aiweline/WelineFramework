<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\WebsiteSslCertificateStatusService;

class WebsiteSslCertificateStatusServiceTest extends TestCase
{
    private WebsiteSslCertificateStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WebsiteSslCertificateStatusService();
    }

    public function testMapToPoolHttpsStatusReturnsNoneWhenCertificateMissing(): void
    {
        $this->assertSame(
            DomainPool::HTTPS_STATUS_NONE,
            $this->service->mapToPoolHttpsStatus(null)
        );
    }

    public function testMapToDomainHttpsStatusReturnsNoneWhenCertificateMissing(): void
    {
        $this->assertSame(
            Domain::HTTPS_STATUS_NONE,
            $this->service->mapToDomainHttpsStatus(null)
        );
    }

    public function testMapToPoolHttpsStatusPreservesPendingCertificate(): void
    {
        $this->assertSame(
            DomainPool::HTTPS_STATUS_PENDING,
            $this->service->mapToPoolHttpsStatus([
                'cert_id' => 12,
                'status' => 'pending',
                'is_expired' => false,
            ])
        );
    }

    public function testMapToDomainHttpsStatusPreservesActiveCertificate(): void
    {
        $this->assertSame(
            Domain::HTTPS_STATUS_VALID,
            $this->service->mapToDomainHttpsStatus([
                'cert_id' => 34,
                'status' => 'active',
                'is_expired' => false,
            ])
        );
    }
}
