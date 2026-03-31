<?php

declare(strict_types=1);

namespace WeShop\Membership\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Membership\Model\Membership;
use WeShop\Membership\Service\MembershipAdminPageDataService;
use WeShop\Membership\Service\MembershipService;

class MembershipAdminPageDataServiceTest extends TestCase
{
    public function testGetPageDataNormalizesFiltersAndRecords(): void
    {
        $membershipService = new class() extends MembershipService {
            public array $receivedFilters = [];

            public function getMembershipList(int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                $this->receivedFilters = $filters;

                return [
                    'items' => [[
                        Membership::schema_fields_ID => 4,
                        Membership::schema_fields_CUSTOMER_ID => 21,
                        Membership::schema_fields_LEVEL => 'gold',
                        Membership::schema_fields_POINTS => 680,
                        Membership::schema_fields_CREATED_AT => '2026-03-24 10:00:00',
                        Membership::schema_fields_UPDATED_AT => '2026-03-24 11:00:00',
                    ]],
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }

            public function getMembershipSummary(): array
            {
                return [
                    'total' => 1,
                    'bronze' => 0,
                    'silver' => 0,
                    'gold' => 1,
                    'platinum' => 0,
                    'total_points' => 680,
                ];
            }
        };

        $service = new MembershipAdminPageDataService($membershipService);
        $data = $service->getPageData(1, 20, [
            'customer_id' => '21',
            'level' => 'gold',
        ]);

        $this->assertSame([
            'customer_id' => 21,
            'level' => 'gold',
        ], $membershipService->receivedFilters);
        $this->assertCount(1, $data['memberships']);
        $this->assertSame('gold', $data['memberships'][0]['level']);
        $this->assertSame($membershipService->getLevelOptions()['gold'], $data['memberships'][0]['level_label']);
        $this->assertSame(0, $data['editingRecord']['membership_id']);
        $this->assertSame('bronze', $data['editingRecord']['level']);
        $this->assertSame(680, $data['summary']['total_points']);
    }

    public function testGetMembershipRecordReturnsNormalizedArray(): void
    {
        $mockMembership = $this->createMock(Membership::class);
        $mockMembership->method('getId')->willReturn(5);
        $mockMembership->method('getData')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    Membership::schema_fields_CUSTOMER_ID => 42,
                    Membership::schema_fields_LEVEL => 'platinum',
                    Membership::schema_fields_POINTS => 1500,
                    Membership::schema_fields_CREATED_AT => '2026-01-15 09:30:00',
                    Membership::schema_fields_UPDATED_AT => '2026-03-20 14:00:00',
                    default => null,
                };
            });

        $membershipService = $this->createMock(MembershipService::class);
        $membershipService->method('getMembershipRecord')->willReturn($mockMembership);
        $membershipService->method('getLevelOptions')->willReturn([
            'bronze' => 'Bronze',
            'silver' => 'Silver',
            'gold' => 'Gold',
            'platinum' => 'Platinum',
        ]);

        $service = new MembershipAdminPageDataService($membershipService);
        $result = $service->getMembershipRecord(5);

        $this->assertIsArray($result);
        $this->assertSame(5, $result['membership_id']);
        $this->assertSame(42, $result['customer_id']);
        $this->assertSame('platinum', $result['level']);
        $this->assertSame('Platinum', $result['level_label']);
        $this->assertSame(1500, $result['points']);
    }

    public function testGetMembershipRecordReturnsNullForNonexistent(): void
    {
        $membershipService = $this->createMock(MembershipService::class);
        $membershipService->method('getMembershipRecord')->willReturn(null);

        $service = new MembershipAdminPageDataService($membershipService);
        $result = $service->getMembershipRecord(999);

        $this->assertNull($result);
    }

    public function testGetLevelOptionsReturnsServiceOptions(): void
    {
        $expectedOptions = [
            'bronze' => 'Bronze',
            'silver' => 'Silver',
            'gold' => 'Gold',
            'platinum' => 'Platinum',
        ];

        $membershipService = $this->createMock(MembershipService::class);
        $membershipService->method('getLevelOptions')->willReturn($expectedOptions);

        $service = new MembershipAdminPageDataService($membershipService);
        $result = $service->getLevelOptions();

        $this->assertSame($expectedOptions, $result);
    }

    public function testGetLevelBenefitsReturnsTranslatedBenefits(): void
    {
        $membershipService = $this->createMock(MembershipService::class);

        $service = new MembershipAdminPageDataService($membershipService);
        $benefits = $service->getLevelBenefits();

        $this->assertIsArray($benefits);
        $this->assertArrayHasKey('bronze', $benefits);
        $this->assertArrayHasKey('silver', $benefits);
        $this->assertArrayHasKey('gold', $benefits);
        $this->assertArrayHasKey('platinum', $benefits);
        $this->assertNotEmpty($benefits['bronze']);
        $this->assertNotEmpty($benefits['silver']);
        $this->assertNotEmpty($benefits['gold']);
        $this->assertNotEmpty($benefits['platinum']);
    }
}
