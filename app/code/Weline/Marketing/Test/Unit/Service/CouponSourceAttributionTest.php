<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CouponSourceAttributionTest extends TestCase
{
    public function testAttributionServiceMapsKnownSources(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/CouponSourceAttribution.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("__('维护等待礼金')", $src);
        self::assertStringContainsString("__('后台手工')", $src);
        self::assertStringContainsString('SOURCE_TYPE_MAINTENANCE_WAIT_GIFT', $src);
        self::assertStringContainsString('fromIssueContext', $src);
        self::assertStringContainsString('backfillMissing', $src);
        self::assertStringContainsString('listFilterOptions', $src);
    }

    public function testIssueProviderPersistsSourceFields(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/RandomCouponCampaignProvider.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('CouponSourceAttribution', $src);
        self::assertStringContainsString('schema_fields_SOURCE_TYPE', $src);
        self::assertStringContainsString('fromIssueContext', $src);
    }

    public function testCouponModelDeclaresSourceColumns(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/Coupon/Coupon.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("schema_fields_SOURCE_MODULE = 'source_module'", $src);
        self::assertStringContainsString("schema_fields_SOURCE_TYPE = 'source_type'", $src);
        self::assertStringContainsString('SOURCE_TYPE_MAINTENANCE_WAIT_GIFT', $src);
    }

    public function testListTemplateShowsSourceColumn(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/coupon/index.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('marketing-coupon-source', $src);
        self::assertStringContainsString('来源', $src);
        self::assertStringContainsString('折扣值（基准货币', $src);
        self::assertStringContainsString('marketing-coupon-filter', $src);
        self::assertStringContainsString('name="source_type"', $src);
        self::assertStringContainsString('{{pagination}}', $src);
        self::assertStringNotContainsString("getChildHtml('pagination')", $src);
    }

    public function testWaitGiftPassesSourceContext(): void
    {
        $path = dirname(__DIR__, 4) . '/Maintenance/Service/WaitGiftService.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WaitGiftCampaignSyncService::SOURCE_TYPE', $src);
        self::assertStringContainsString('WaitGiftCampaignSyncService::SOURCE_MODULE', $src);
    }
}
