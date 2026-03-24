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
}
