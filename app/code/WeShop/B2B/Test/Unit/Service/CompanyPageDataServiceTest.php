<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Service\CompanyPageDataService;
use WeShop\B2B\Service\CompanyService;

class CompanyPageDataServiceTest extends TestCase
{
    public function testBuildReturnsSummaryAndCompanyList(): void
    {
        $companyService = $this->createMock(CompanyService::class);
        $companyService->expects($this->once())
            ->method('getCompanySummaryByContactEmail')
            ->with('buyer@example.com')
            ->willReturn([
                'total' => 1,
                'status_breakdown' => ['active' => 1],
                'primary_status' => 'active',
            ]);
        $companyService->expects($this->once())
            ->method('getCompaniesByContactEmail')
            ->with('buyer@example.com', 6)
            ->willReturn([
                [
                    'company_id' => 1,
                    'name' => 'Acme Corporation',
                    'status' => 'active',
                    'tax_id' => 'TAX-001',
                    'created_at' => '2026-03-20 12:00:00',
                ],
            ]);

        $service = new CompanyPageDataService($companyService);
        $result = $service->build(123, 'buyer@example.com');

        $this->assertSame(1, $result['company_summary']['total']);
        $this->assertSame('active', $result['company_summary']['primary_status']);
        $this->assertSame('buyer@example.com', $result['company_contact_email']);
        $this->assertCount(1, $result['company_list']);
        $this->assertSame('Acme Corporation', $result['company_list'][0]['name']);
        $this->assertSame('TAX-001', $result['company_list'][0]['tax_id']);
    }

    public function testBuildReturnsEmptyStateWhenContactEmailIsMissing(): void
    {
        $companyService = $this->createMock(CompanyService::class);
        $companyService->expects($this->never())->method('getCompanySummaryByContactEmail');
        $companyService->expects($this->never())->method('getCompaniesByContactEmail');

        $service = new CompanyPageDataService($companyService);
        $result = $service->build(123, '');

        $this->assertSame(0, $result['company_summary']['total']);
        $this->assertSame([], $result['company_summary']['status_breakdown']);
        $this->assertSame('', $result['company_summary']['primary_status']);
        $this->assertSame([], $result['company_list']);
    }
}
