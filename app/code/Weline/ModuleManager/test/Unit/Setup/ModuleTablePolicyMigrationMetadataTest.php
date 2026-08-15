<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Database\Service\HistoricalMigrationMetadataRegistry;
use Weline\Framework\Setup\Model\ModuleTable;
use Weline\ModuleManager\Model\ModuleUninstallAudit;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class ModuleTablePolicyMigrationMetadataTest extends TestCase
{
    public function testModifyTableMigrationDeclaresEveryPhysicalTableItChanges(): void
    {
        $migration = BP
            . 'app/code/Weline/ModuleManager/Setup/Db/Migration/'
            . 'add_module_table_policy_and_audit_20250318-v1.0.2.php';
        $registry = new HistoricalMigrationMetadataRegistry();

        self::assertSame(
            '6cd9c760d10a80438bffedb150d3abf2507629ce2b2156f4c6a4e7f2233788cb',
            hash_file('sha256', $migration),
            'Historical migration bytes are immutable because installed records bind this checksum.',
        );
        self::assertSame(
            [ModuleTable::schema_table, ModuleUninstallAudit::schema_table],
            $registry->affectedTables($migration),
        );
    }
}
