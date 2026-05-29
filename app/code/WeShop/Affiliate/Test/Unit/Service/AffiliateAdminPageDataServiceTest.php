<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Model\Affiliate;
use WeShop\Affiliate\Service\AffiliateAdminPageDataService;
use WeShop\Affiliate\Service\AffiliateService;

class AffiliateAdminPageDataServiceTest extends TestCase
{
    public function testGetPageDataSanitizesFiltersAndReturnsEmptyEditingRecord(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);

        $items = [
            [
                Affiliate::schema_fields_ID => 11,
                Affiliate::schema_fields_CUSTOMER_ID => 321,
                Affiliate::schema_fields_REFERRAL_CODE => 'REF00000321X',
                Affiliate::schema_fields_COMMISSION_RATE => 0.2,
                Affiliate::schema_fields_TOTAL_COMMISSION => 150.0,
                Affiliate::schema_fields_PAID_COMMISSION => 50.0,
                Affiliate::schema_fields_STATUS => 'active',
                Affiliate::schema_fields_CREATED_AT => '2026-03-24 12:00:00',
            ],
        ];

        $affiliateService->expects(self::once())
            ->method('isValidStatus')
            ->with(AffiliateService::STATUS_DISABLED)
            ->willReturn(true);

        $affiliateService->expects(self::once())
            ->method('getAffiliateList')
            ->with(
                2,
                15,
                self::callback(fn (array $filters) => $filters === [
                    'customer_id' => 123,
                    'referral_code' => 'REF',
                    'status' => AffiliateService::STATUS_DISABLED,
                ])
            )
            ->willReturn([
                'items' => $items,
                'pagination' => ['page' => 2, 'page_count' => 3, 'total' => 25],
            ]);

        $statusOptions = [
            AffiliateService::STATUS_ACTIVE => 'Active',
            AffiliateService::STATUS_DISABLED => 'Disabled',
        ];

        $affiliateService->expects(self::once())
            ->method('getStatusOptions')
            ->willReturn($statusOptions);

        $affiliateService->expects(self::never())
            ->method('getAffiliateRecord');

        $service = $this->createService($affiliateService);
        $result = $service->getPageData(2, 15, [
            'customer_id' => '123',
            'referral_code' => '  REF ',
            'status' => AffiliateService::STATUS_DISABLED,
            'extra' => 'value',
        ]);

        $this->assertSame([
            'customer_id' => 123,
            'referral_code' => 'REF',
            'status' => AffiliateService::STATUS_DISABLED,
        ], $result['filters']);

        $this->assertSame(0, $result['editingRecord']['affiliate_id']);
        $this->assertSame('', $result['editingRecord']['customer_id']);
        $this->assertSame(AffiliateService::STATUS_ACTIVE, $result['editingRecord']['status']);

        $record = $result['affiliateRecords'][0];
        $this->assertSame(11, $record['affiliate_id']);
        $this->assertSame(321, $record['customer_id']);
        $this->assertSame('REF00000321X', $record['referral_code']);
        $this->assertSame(100.0, $record['pending_commission']);
    }

    public function testGetPageDataNormalizesPaginationTotalFromListResult(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);

        $items = [
            [
                Affiliate::schema_fields_ID => 11,
                Affiliate::schema_fields_CUSTOMER_ID => 321,
                Affiliate::schema_fields_REFERRAL_CODE => 'REF00000321X',
                Affiliate::schema_fields_COMMISSION_RATE => 0.2,
                Affiliate::schema_fields_TOTAL_COMMISSION => 150.0,
                Affiliate::schema_fields_PAID_COMMISSION => 50.0,
                Affiliate::schema_fields_STATUS => AffiliateService::STATUS_ACTIVE,
            ],
            [
                Affiliate::schema_fields_ID => 12,
                Affiliate::schema_fields_CUSTOMER_ID => 322,
                Affiliate::schema_fields_REFERRAL_CODE => 'REF00000322X',
                Affiliate::schema_fields_COMMISSION_RATE => 0.1,
                Affiliate::schema_fields_TOTAL_COMMISSION => 0.0,
                Affiliate::schema_fields_PAID_COMMISSION => 0.0,
                Affiliate::schema_fields_STATUS => AffiliateService::STATUS_ACTIVE,
            ],
        ];

        $affiliateService->expects(self::once())
            ->method('getAffiliateList')
            ->with(1, 20, [])
            ->willReturn([
                'items' => $items,
                'total' => 2,
                'pagination' => ['page' => 1, 'page_count' => 1, 'total' => 0],
            ]);

        $affiliateService->expects(self::once())
            ->method('getStatusOptions')
            ->willReturn([
                AffiliateService::STATUS_ACTIVE => 'Active',
                AffiliateService::STATUS_DISABLED => 'Disabled',
            ]);

        $affiliateService->expects(self::never())
            ->method('getAffiliateRecord');

        $service = $this->createService($affiliateService);
        $result = $service->getPageData();

        $this->assertCount(2, $result['affiliateRecords']);
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['page_count']);
    }

    public function testGetPageDataLoadsEditingRecord(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);

        $affiliateService->expects(self::never())
            ->method('isValidStatus');

        $affiliateService->expects(self::once())
            ->method('getAffiliateList')
            ->with(1, 20, [])
            ->willReturn([
                'items' => [],
                'pagination' => ['page' => 1, 'page_count' => 1, 'total' => 0],
            ]);

        $statusOptions = [
            AffiliateService::STATUS_ACTIVE => 'Active',
            AffiliateService::STATUS_DISABLED => 'Disabled',
        ];

        $affiliateService->expects(self::once())
            ->method('getStatusOptions')
            ->willReturn($statusOptions);

        $affiliateModel = $this->getMockBuilder(Affiliate::class)
            ->disableOriginalConstructor()
            ->getMock();

        $affiliateModel->expects(self::any())
            ->method('getId')
            ->willReturn(7);

        $dataMap = [
            Affiliate::schema_fields_CUSTOMER_ID => 444,
            Affiliate::schema_fields_REFERRAL_CODE => 'REF00000044Z',
            Affiliate::schema_fields_COMMISSION_RATE => 0.18,
            Affiliate::schema_fields_STATUS => 'disabled',
            Affiliate::schema_fields_TOTAL_COMMISSION => 500.0,
            Affiliate::schema_fields_PAID_COMMISSION => 250.0,
        ];

        $affiliateModel->expects(self::any())
            ->method('getData')
            ->willReturnCallback(fn (string $key): mixed => $dataMap[$key] ?? null);

        $affiliateService->expects(self::once())
            ->method('getAffiliateRecord')
            ->with(7)
            ->willReturn($affiliateModel);

        $service = $this->createService($affiliateService);
        $result = $service->getPageData(1, 20, [], 7);

        $this->assertSame(7, $result['editingRecord']['affiliate_id']);
        $this->assertSame(444, $result['editingRecord']['customer_id']);
        $this->assertSame('REF00000044Z', $result['editingRecord']['referral_code']);
        $this->assertSame('Disabled', $result['editingRecord']['status_label']);
    }

    public function testEditingRecordIsNormalizedBeforeSummaryCanClearSharedModel(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);

        $affiliateService->expects(self::once())
            ->method('getAffiliateList')
            ->with(1, 20, [])
            ->willReturn([
                'items' => [],
                'pagination' => ['page' => 1, 'page_count' => 1, 'total' => 0],
            ]);

        $statusOptions = [
            AffiliateService::STATUS_ACTIVE => 'Active',
            AffiliateService::STATUS_DISABLED => 'Disabled',
        ];

        $affiliateService->expects(self::once())
            ->method('getStatusOptions')
            ->willReturn($statusOptions);

        $holder = (object) [
            'id' => 7,
            'data' => [
                Affiliate::schema_fields_CUSTOMER_ID => 444,
                Affiliate::schema_fields_REFERRAL_CODE => 'REF00000044Z',
                Affiliate::schema_fields_COMMISSION_RATE => 0.18,
                Affiliate::schema_fields_STATUS => 'disabled',
                Affiliate::schema_fields_TOTAL_COMMISSION => 500.0,
                Affiliate::schema_fields_PAID_COMMISSION => 250.0,
            ],
        ];

        $affiliateModel = $this->getMockBuilder(Affiliate::class)
            ->disableOriginalConstructor()
            ->getMock();

        $affiliateModel->expects(self::any())
            ->method('getId')
            ->willReturnCallback(fn (): int => (int) $holder->id);

        $affiliateModel->expects(self::any())
            ->method('getData')
            ->willReturnCallback(fn (string $key): mixed => $holder->data[$key] ?? null);

        $affiliateService->expects(self::once())
            ->method('getAffiliateRecord')
            ->with(7)
            ->willReturn($affiliateModel);

        $service = new class($affiliateService, $holder) extends AffiliateAdminPageDataService {
            public function __construct(AffiliateService $affiliateService, private readonly object $holder)
            {
                parent::__construct($affiliateService);
            }

            public function getSummary(): array
            {
                $this->holder->id = 0;
                $this->holder->data = [];

                return [
                    'total' => 0,
                    'active' => 0,
                    'disabled' => 0,
                    'total_commission' => 0.0,
                ];
            }
        };

        $result = $service->getPageData(1, 20, [], 7);

        $this->assertSame(7, $result['editingRecord']['affiliate_id']);
        $this->assertSame(444, $result['editingRecord']['customer_id']);
        $this->assertSame('REF00000044Z', $result['editingRecord']['referral_code']);
        $this->assertSame('Disabled', $result['editingRecord']['status_label']);
    }

    private function createService(AffiliateService $affiliateService): AffiliateAdminPageDataService
    {
        return new class($affiliateService) extends AffiliateAdminPageDataService {
            public function getSummary(): array
            {
                return [
                    'total' => 0,
                    'active' => 0,
                    'disabled' => 0,
                    'total_commission' => 0.0,
                ];
            }
        };
    }
}
