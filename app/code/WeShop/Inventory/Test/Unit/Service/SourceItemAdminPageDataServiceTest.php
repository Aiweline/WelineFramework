<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Model\SourceItem;
use WeShop\Inventory\Service\SourceItemAdminPageDataService;
use WeShop\Inventory\Service\SourceItemManagementService;
use WeShop\Inventory\Service\SourceManagementService;

class SourceItemAdminPageDataServiceTest extends TestCase
{
    public function testGetListDataNormalizesFiltersAndRows(): void
    {
        $sourceItemDependency = $this->createMock(SourceItem::class);
        $sourceItemManagementService = new class($sourceItemDependency) extends SourceItemManagementService {
            public array $receivedFilters = [];

            public function getSourceItemList(int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                $this->receivedFilters = $filters;

                return [
                    'items' => [[
                        SourceItem::schema_fields_ID => 8,
                        SourceItem::schema_fields_SOURCE_ID => 3,
                        SourceItem::schema_fields_PRODUCT_ID => 16,
                        SourceItem::schema_fields_SKU => 'SKU-001',
                        SourceItem::schema_fields_QUANTITY => 88.5,
                        SourceItem::schema_fields_STATUS => SourceItem::STATUS_IN_STOCK,
                        SourceItem::schema_fields_LOW_STOCK_THRESHOLD => 10,
                        'source_name' => 'Main',
                        'product_name' => 'Sneakers',
                    ]],
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }
        };

        $sourceDependency = $this->createMock(Source::class);
        $sourceManagementService = new class($sourceDependency) extends SourceManagementService {
            public function getEnabledSources(): array
            {
                return [[
                    Source::schema_fields_ID => 3,
                    Source::schema_fields_CODE => 'main',
                    Source::schema_fields_NAME => 'Main',
                ]];
            }
        };

        $service = new SourceItemAdminPageDataService($sourceItemManagementService, $sourceManagementService);
        $result = $service->getListData(1, 20, [
            'source_id' => '3',
            'search' => ' SKU ',
        ]);

        $this->assertSame(['source_id' => 3, 'search' => 'SKU'], $sourceItemManagementService->receivedFilters);
        $this->assertCount(1, $result['sourceItems']);
        $this->assertSame((string) __('In Stock'), $result['sourceItems'][0]['status_label']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame(['source_id' => 3, 'search' => 'SKU'], $result['filters']);
    }

    public function testGetEditDataThrowsWhenSourceItemDoesNotExist(): void
    {
        $sourceItemDependency = $this->createMock(SourceItem::class);
        $sourceItemManagementService = new class($sourceItemDependency) extends SourceItemManagementService {
            public function getSourceItemById(int $sourceItemId): ?array
            {
                return null;
            }
        };

        $sourceDependency = $this->createMock(Source::class);
        $sourceManagementService = new class($sourceDependency) extends SourceManagementService {
        };

        $service = new SourceItemAdminPageDataService($sourceItemManagementService, $sourceManagementService);

        $this->expectException(\InvalidArgumentException::class);
        $service->getEditData(999);
    }
}
