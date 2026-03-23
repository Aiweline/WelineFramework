<?php

declare(strict_types=1);

namespace WeShop\Logistics\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Logistics\Model\Tracking;
use WeShop\Logistics\Service\TrackingAdminPageDataService;
use WeShop\Logistics\Service\TrackingService;

class TrackingAdminPageDataServiceTest extends TestCase
{
    public function testGetPageDataNormalizesFiltersAndRecords(): void
    {
        $trackingService = new class extends TrackingService {
            public array $receivedFilters = [];

            public function getTrackingList(int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                $this->receivedFilters = $filters;

                return [
                    'items' => [[
                        Tracking::schema_fields_ID => 5,
                        Tracking::schema_fields_order_id => 91,
                        Tracking::schema_fields_tracking_number => 'DHL-1005',
                        Tracking::schema_fields_carrier => 'DHL',
                        Tracking::schema_fields_status => 'in_transit',
                        Tracking::schema_fields_tracked_at => '2026-03-24 12:00:00',
                    ]],
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }
        };

        $service = new TrackingAdminPageDataService($trackingService);
        $data = $service->getPageData(1, 20, [
            'order_id' => '91',
            'tracking_number' => 'DHL',
            'status' => 'in_transit',
        ]);

        $this->assertSame([
            'order_id' => 91,
            'tracking_number' => 'DHL',
            'status' => 'in_transit',
        ], $trackingService->receivedFilters);
        $this->assertCount(1, $data['trackingRecords']);
        $this->assertSame('In Transit', $data['trackingRecords'][0]['status_label']);
        $this->assertSame('DHL', $data['editingRecord']['carrier']);
    }
}
