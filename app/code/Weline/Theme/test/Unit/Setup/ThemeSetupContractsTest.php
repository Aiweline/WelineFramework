<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeSetupContractsPass().
 */
final class ThemeSetupContractsTest extends TestCase
{
    public function testAllThemeSetupContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ThemeUpgradeVersionGate::testSetupGatesHistoricalMigrationsByFromSetupVersion' => function () {
            $this->assert_ThemeUpgradeVersionGate_testSetupGatesHistoricalMigrationsByFromSetupVersion();
        },
        ];
    }

private function assert_ThemeUpgradeVersionGate_testSetupGatesHistoricalMigrationsByFromSetupVersion(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Setup/Upgrade.php';
    $src = (string) file_get_contents($path);
    // Upgrade::VERSION 记录脚本已覆盖的最高历史迁移版；断言仍 ≥ 冰点 2.2.480，
    // 不钉死字面量——版本正常推进（已到 2.2.631）会让钉死断言腐化。
    preg_match("/public const VERSION = '(\\d+\\.\\d+\\.\\d+)';/", $src, $versionMatch);
    self::assertNotEmpty($versionMatch[1] ?? '', 'Upgrade::VERSION 必须存在且为语义化版本。');
    self::assertTrue(version_compare($versionMatch[1], '2.2.480', '>='), 'Upgrade::VERSION 不得低于历史迁移冰点 2.2.480，当前 ' . $versionMatch[1]);
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
    self::assertLessThan($cutoverPos, $gatePos, 'from-gate must run before heavy cutover call in setup()');
}
}
