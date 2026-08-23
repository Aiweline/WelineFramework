<?php

declare(strict_types=1);

namespace Weline\StorageOss\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\StorageOss\Model\MultipartCleanupTask;

final class MultipartCleanupTaskSchemaContractTest extends TestCase
{
    public function testCleanupDebtTableUsesAPostCheckpointModuleVersion(): void
    {
        $schema = (new SchemaParser())->parse(MultipartCleanupTask::class);
        self::assertNotNull($schema);

        $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
        foreach ([
            MultipartCleanupTask::schema_fields_DISK_CODE,
            MultipartCleanupTask::schema_fields_CONFIG_REVISION,
            MultipartCleanupTask::schema_fields_CONFIG_SNAPSHOT_REF,
            MultipartCleanupTask::schema_fields_UPLOAD_ID,
            MultipartCleanupTask::schema_fields_STATUS,
        ] as $column) {
            self::assertContains($column, $columnNames);
        }

        $module = require BP . 'app/code/Weline/StorageOss/etc/module.php';
        self::assertTrue(version_compare((string)$module['version'], '1.0.1', '>='));
        self::assertSame('>=1.2.1', $module['requires']['Weline_Storage']);
    }
}
