<?php

declare(strict_types=1);

namespace Weline\StorageOss\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\StorageOss\Model\MultipartCleanupTask;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllStorageOssModelContractsPass().
 */
final class StorageOssModelContractsTest extends TestCase
{
    public function testAllStorageOssModelContractsPass(): void
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
            'MultipartCleanupTaskSchema::testCleanupDebtTableUsesAPostCheckpointModuleVersion' => function () {
            $this->assert_MultipartCleanupTaskSchema_testCleanupDebtTableUsesAPostCheckpointModuleVersion();
        },
        ];
    }

private function assert_MultipartCleanupTaskSchema_testCleanupDebtTableUsesAPostCheckpointModuleVersion(): void
{
    $schema = (new SchemaParser())->parse(MultipartCleanupTask::class);
    self::assertNotNull($schema);
    $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
    foreach ([MultipartCleanupTask::schema_fields_DISK_CODE, MultipartCleanupTask::schema_fields_CONFIG_REVISION, MultipartCleanupTask::schema_fields_CONFIG_SNAPSHOT_REF, MultipartCleanupTask::schema_fields_UPLOAD_ID, MultipartCleanupTask::schema_fields_STATUS] as $column) {
        self::assertContains($column, $columnNames);
    }
    $module = require BP . 'app/code/Weline/StorageOss/etc/module.php';
    self::assertTrue(version_compare((string) $module['version'], '1.0.1', '>='));
    self::assertSame('>=1.2.1', $module['requires']['Weline_Storage']);
}
}
