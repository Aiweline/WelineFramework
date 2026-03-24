<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Model\Company;
use WeShop\B2B\Service\CompanyAdminPageDataService;
use WeShop\B2B\Service\CompanyService;

class CompanyAdminPageDataServiceTest extends TestCase
{
    public function testGetPageDataSanitizesFiltersAndReturnsEmptyEditingRecord(): void
    {
        $companyService = $this->createMock(CompanyService::class);

        $items = [
            [
                Company::schema_fields_ID => 11,
                Company::schema_fields_NAME => 'Acme Corporation',
                Company::schema_fields_EMAIL => 'buyer@example.com',
                Company::schema_fields_TAX_ID => 'TAX-001',
                Company::schema_fields_PHONE => '+1 555 0001',
                Company::schema_fields_ADDRESS => '1 Commerce Street',
                Company::schema_fields_STATUS => CompanyService::STATUS_PENDING,
                Company::schema_fields_CREATED_AT => '2026-03-24 12:00:00',
            ],
        ];

        $companyService->expects(self::once())
            ->method('isValidStatus')
            ->with(CompanyService::STATUS_PENDING)
            ->willReturn(true);

        $companyService->expects(self::once())
            ->method('getCompanyList')
            ->with(
                2,
                15,
                self::callback(fn (array $filters) => $filters === [
                    'name' => 'Acme',
                    'email' => 'buyer@example.com',
                    'status' => CompanyService::STATUS_PENDING,
                ])
            )
            ->willReturn([
                'items' => $items,
                'pagination' => ['page' => 2, 'page_count' => 3, 'total' => 25],
            ]);

        $companyService->expects(self::once())
            ->method('getCompanySummary')
            ->willReturn([
                'total' => 2,
                CompanyService::STATUS_ACTIVE => 1,
                CompanyService::STATUS_PENDING => 1,
                CompanyService::STATUS_APPROVED => 0,
                CompanyService::STATUS_REJECTED => 0,
                CompanyService::STATUS_DISABLED => 0,
                'status_breakdown' => [
                    CompanyService::STATUS_ACTIVE => 1,
                    CompanyService::STATUS_PENDING => 1,
                ],
                'primary_status' => CompanyService::STATUS_ACTIVE,
            ]);

        $statusOptions = [
            CompanyService::STATUS_ACTIVE => 'Active',
            CompanyService::STATUS_PENDING => 'Pending Review',
            CompanyService::STATUS_APPROVED => 'Approved',
            CompanyService::STATUS_REJECTED => 'Rejected',
            CompanyService::STATUS_DISABLED => 'Disabled',
        ];

        $companyService->expects(self::once())
            ->method('getStatusOptions')
            ->willReturn($statusOptions);

        $companyService->expects(self::never())
            ->method('getCompanyRecord');

        $service = new CompanyAdminPageDataService($companyService);
        $result = $service->getPageData(2, 15, [
            'name' => '  Acme ',
            'email' => ' BUYER@example.com ',
            'status' => CompanyService::STATUS_PENDING,
            'extra' => 'value',
        ]);

        $this->assertSame([
            'name' => 'Acme',
            'email' => 'buyer@example.com',
            'status' => CompanyService::STATUS_PENDING,
        ], $result['filters']);

        $this->assertSame(2, $result['companySummary']['total']);
        $this->assertSame(0, $result['editingRecord']['company_id']);
        $this->assertSame(CompanyService::STATUS_ACTIVE, $result['editingRecord']['status']);

        $record = $result['companyRecords'][0];
        $this->assertSame(11, $record['company_id']);
        $this->assertSame('Acme Corporation', $record['name']);
        $this->assertSame('buyer@example.com', $record['email']);
        $this->assertSame('Pending Review', $record['status_label']);
    }

    public function testGetPageDataLoadsEditingRecord(): void
    {
        $companyService = $this->createMock(CompanyService::class);

        $companyService->expects(self::never())
            ->method('isValidStatus');

        $companyService->expects(self::once())
            ->method('getCompanyList')
            ->with(1, 20, [])
            ->willReturn([
                'items' => [],
                'pagination' => ['page' => 1, 'page_count' => 1, 'total' => 0],
            ]);

        $companyService->expects(self::once())
            ->method('getCompanySummary')
            ->willReturn([
                'total' => 0,
                CompanyService::STATUS_ACTIVE => 0,
                CompanyService::STATUS_PENDING => 0,
                CompanyService::STATUS_APPROVED => 0,
                CompanyService::STATUS_REJECTED => 0,
                CompanyService::STATUS_DISABLED => 0,
                'status_breakdown' => [],
                'primary_status' => '',
            ]);

        $statusOptions = [
            CompanyService::STATUS_ACTIVE => 'Active',
            CompanyService::STATUS_PENDING => 'Pending Review',
            CompanyService::STATUS_APPROVED => 'Approved',
            CompanyService::STATUS_REJECTED => 'Rejected',
            CompanyService::STATUS_DISABLED => 'Disabled',
        ];

        $companyService->expects(self::once())
            ->method('getStatusOptions')
            ->willReturn($statusOptions);

        $companyModel = $this->getMockBuilder(Company::class)
            ->disableOriginalConstructor()
            ->getMock();

        $companyModel->expects(self::any())
            ->method('getId')
            ->willReturn(7);

        $dataMap = [
            Company::schema_fields_NAME => 'Northwind Trading',
            Company::schema_fields_EMAIL => 'ops@northwind.test',
            Company::schema_fields_TAX_ID => 'VAT-009',
            Company::schema_fields_PHONE => '+1 555 0100',
            Company::schema_fields_ADDRESS => '99 Trade Road',
            Company::schema_fields_STATUS => CompanyService::STATUS_APPROVED,
        ];

        $companyModel->expects(self::any())
            ->method('getData')
            ->willReturnCallback(fn (string $key): mixed => $dataMap[$key] ?? null);

        $companyService->expects(self::once())
            ->method('getCompanyRecord')
            ->with(7)
            ->willReturn($companyModel);

        $service = new CompanyAdminPageDataService($companyService);
        $result = $service->getPageData(1, 20, [], 7);

        $this->assertSame(7, $result['editingRecord']['company_id']);
        $this->assertSame('Northwind Trading', $result['editingRecord']['name']);
        $this->assertSame('ops@northwind.test', $result['editingRecord']['email']);
        $this->assertSame('Approved', $result['editingRecord']['status_label']);
    }
}
