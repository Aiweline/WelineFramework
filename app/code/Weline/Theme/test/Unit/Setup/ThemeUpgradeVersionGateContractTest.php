<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Theme Upgrade 历史重迁移必须按 from_setup gate，禁止每次 bump 全量重 bake。
 */
final class ThemeUpgradeVersionGateContractTest extends TestCase
{
    public function testSetupGatesHistoricalMigrationsByFromSetupVersion(): void
    {
        $path = dirname(__DIR__, 3) . '/Setup/Upgrade.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("VERSION = '2.2.480'", $src);
        self::assertStringContainsString('getFromSetupVersion()', $src);
        self::assertStringContainsString("version_compare(\$from, self::VERSION, '>=')", $src);
        self::assertStringContainsString('migrateThemeLayoutEntitiesCutover', $src);
        self::assertStringContainsString('migratePublishActiveThemeCategoryFilters', $src);

        $setupStart = strpos($src, 'public function setup(');
        self::assertNotFalse($setupStart);
        $cutoverPos = strpos($src, 'migrateThemeLayoutEntitiesCutover', $setupStart);
        $gatePos = strpos($src, "version_compare(\$from, self::VERSION, '>=')", $setupStart);
        self::assertNotFalse($gatePos);
        self::assertNotFalse($cutoverPos);
        self::assertLessThan(
            $cutoverPos,
            $gatePos,
            'from-gate must run before heavy cutover call in setup()',
        );
    }
}
