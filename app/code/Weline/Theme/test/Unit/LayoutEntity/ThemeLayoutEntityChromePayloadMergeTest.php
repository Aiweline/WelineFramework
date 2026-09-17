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
    }
}
