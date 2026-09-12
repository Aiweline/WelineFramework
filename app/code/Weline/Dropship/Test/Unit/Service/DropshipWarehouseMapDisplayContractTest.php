<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DropshipWarehouseMapDisplayContractTest extends TestCase
{
    public function testEnrichForDisplayContractInServiceSource(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DropshipWarehouseMapService.php'
        );
        self::assertStringContainsString('function enrichForDisplay', $src);
        self::assertStringContainsString('provider_label', $src);
        self::assertStringContainsString('website_label', $src);
        self::assertStringContainsString('store_label', $src);
        self::assertStringContainsString('remote_label', $src);
        self::assertStringContainsString('local_label', $src);
        self::assertStringContainsString('channel_label', $src);
        self::assertStringContainsString("'—'", $src);
        self::assertStringContainsString('WebsiteCatalogInterface', $src);
        self::assertStringContainsString('StoreCatalogInterface', $src);
        self::assertStringContainsString('DropshipRemoteWarehouseService', $src);
        self::assertStringContainsString('listAll', $src);
        self::assertStringContainsString('enrichForDisplay($rows)', $src);
        self::assertStringContainsString('usort', $src);
        self::assertStringContainsString('schema_fields_WEBSITE_ID', $src);
        self::assertStringContainsString('storeId < 0', $src);
        self::assertStringNotContainsString('storeId <= 0', $src);
        self::assertStringContainsString('function syncCountryPairs', $src);
        self::assertStringContainsString('function coverageBoard', $src);
        self::assertStringContainsString('function providerSummaries', $src);
        self::assertStringContainsString('string $countryCode = \'\'', $src);
        self::assertStringContainsString('REMOTE_COUNTRY_CODE', $src);
    }

    public function testControllerAllowsDefaultWebsiteIdZero(): void
    {
        $ctrl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Warehouse.php'
        );
        self::assertStringContainsString('websiteId === null', $ctrl);
        self::assertStringNotContainsString('websiteId === 0', $ctrl);
        self::assertStringNotContainsString('websiteId <= 0', $ctrl);
    }
}
