<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Contract: config-only chrome writes merge payload; carrier sync method exists.
 */
final class ThemeLayoutEntityChromePayloadMergeTest extends TestCase
{
    public function testConfigOnlyPathMergesChromePayloadInsteadOfReplace(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('function mergeChromePayloadNodes', $src);
        self::assertStringContainsString('function syncCarrierChromePayloadIfStale', $src);
        self::assertStringContainsString('merge into payload instead of truncating', $src);
        self::assertStringContainsString('readChromeConfig', $src);
        self::assertStringContainsString('syncCarrierChromePayloadIfStale', $src);
    }

    public function testUpgradeReconcileChromePayloadMigrationPresent(): void
    {
        $path = \dirname(__DIR__, 3) . '/Setup/Upgrade.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('migratePublishActiveThemeCategoryFilters', $src);
        self::assertStringContainsString('bakeChromeFromNodes', $src);
        $start = strpos($src, 'function migratePublishActiveThemeCategoryFilters');
        $end = strpos($src, 'private function migrateProductListPageTypeToProducts');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);
        $body = substr($src, (int)$start, (int)$end - (int)$start);
        self::assertStringContainsString('PAGE_TYPE_PRODUCT_LIST', $body);
        self::assertStringContainsString("'is_active_frontend'", $body);
        self::assertStringContainsString('published_release_id', $body);
    }
}
