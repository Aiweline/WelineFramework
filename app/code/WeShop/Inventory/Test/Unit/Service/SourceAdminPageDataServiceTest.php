<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Service\SourceAdminPageDataService;
use WeShop\Inventory\Service\SourceManagementService;

class SourceAdminPageDataServiceTest extends TestCase
{
    public function testGetListDataNormalizesRows(): void
    {
        $sourceDependency = $this->createMock(Source::class);
        $sourceManagementService = new class($sourceDependency) extends SourceManagementService {
            public function getSourceList(int $page = 1, int $pageSize = 20): array
            {
                return [
                    'items' => [[
                        Source::schema_fields_ID => 11,
                        Source::schema_fields_CODE => 'main',
                        Source::schema_fields_NAME => 'Main Warehouse',
                        Source::schema_fields_IS_ENABLED => 1,
                        Source::schema_fields_PRIORITY => 2,
                        Source::schema_fields_COUNTRY => 'US',
                        Source::schema_fields_CITY => 'Seattle',
                    ]],
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }
        };

        $service = new SourceAdminPageDataService($sourceManagementService);
        $result = $service->getListData(2, 25);

        $this->assertCount(1, $result['sources']);
        $this->assertSame(11, $result['sources'][0]['source_id']);
        $this->assertSame('main', $result['sources'][0]['code']);
        $this->assertSame('Main Warehouse', $result['sources'][0]['name']);
        $this->assertSame(['current_page' => 2, 'page_size' => 25], $result['pagination']);
        $this->assertIsArray($result['emptySource']);
    }

    public function testGetEditDataThrowsWhenSourceDoesNotExist(): void
    {
        $sourceDependency = $this->createMock(Source::class);
        $sourceManagementService = new class($sourceDependency) extends SourceManagementService {
            public function getSourceById(int $sourceId): ?Source
            {
                return null;
            }
        };

        $service = new SourceAdminPageDataService($sourceManagementService);

        $this->expectException(\InvalidArgumentException::class);
        $service->getEditData(999);
    }
}
