<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Setup\Db\Migration;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllEavMigrationContractsPass().
 */
final class EavMigrationContractsTest extends TestCase
{
    public function testAllEavMigrationContractsPass(): void
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
            'CompareModeMigration::testMigrationTargetsCompareModeColumn' => function () {
            $this->assert_CompareModeMigration_testMigrationTargetsCompareModeColumn();
        },
            'FixEavLocalDescriptionCompositePk::testMigrationUsesPrefixedModelTablesAndCompositePk' => function () {
            $this->assert_FixEavLocalDescriptionCompositePk_testMigrationUsesPrefixedModelTablesAndCompositePk();
        },
        ];
    }

private function assert_CompareModeMigration_testMigrationTargetsCompareModeColumn(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Setup/Db/Migration/add_eav_attribute_compare_mode_20260826-v1.2.0.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('compare_mode', $source);
    self::assertStringContainsString('schema_fields_compare_mode', $source);
    self::assertStringContainsString('schema_table', $source);
    self::assertStringContainsString("DEFAULT 'none'", $source);
}

private function assert_FixEavLocalDescriptionCompositePk_testMigrationUsesPrefixedModelTablesAndCompositePk(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Setup/Db/Migration/fix_eav_local_description_composite_pk_20260827-v1.2.3.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('GroupLocalDescription::class', $source);
    self::assertStringContainsString('getTable()', $source);
    self::assertStringContainsString('PRIMARY KEY (id, local_code)', $source);
    self::assertStringContainsString('DROP DEFAULT', $source);
    self::assertStringContainsString('hasCompositePrimaryKey', $source);
    // Must not target unprefixed bare names that skip m_ tables on prod.
    self::assertStringNotContainsString("'eav_attribute_group_local_description'", $source);
}
}
