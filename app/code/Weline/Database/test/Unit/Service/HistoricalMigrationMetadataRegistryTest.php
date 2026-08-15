<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\Migration;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\HistoricalMigrationMetadataRegistry;
use Weline\Database\Service\MigrationService;
use Weline\Database\Service\VersionService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Output\Cli\Printing;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class HistoricalMigrationMetadataRegistryTest extends TestCase
{
    public function testMigrationServiceUsesCanonicalRegistryOnlyForLegacyEmptyMetadata(): void
    {
        $migrationFile = BP
            . 'app/code/Weline/ModuleManager/Setup/Db/Migration/'
            . 'add_module_table_policy_and_audit_20250318-v1.0.2.php';
        $migration = new class extends AbstractMigration {
            public function install(): bool
            {
                return true;
            }

            public function uninstall(): bool
            {
                return true;
            }
        };
        $service = new MigrationService(
            $this->createMock(ConnectionFactory::class),
            $this->createMock(Migration::class),
            $this->createMock(BackupService::class),
            $this->createMock(VersionService::class),
            $this->createMock(Printing::class),
            new HistoricalMigrationMetadataRegistry(),
        );

        $method = (new \ReflectionClass($service))->getMethod('requiredAffectedTables');
        self::assertSame(
            ['weline_module_table', 'module_uninstall_audit'],
            $method->invoke($service, $migration, $migrationFile),
        );
    }

    public function testRegistryNeverExecutesOrTrustsAAdjacentSidecarOutsideCanonicalAllowlist(): void
    {
        $root = sys_get_temp_dir() . '/weline-historical-migration-' . bin2hex(random_bytes(8));
        $migrationDirectory = $root . '/Setup/Db/Migration';
        self::assertTrue(mkdir($migrationDirectory, 0700, true));
        $filename = 'create_table__ai_models_20250101-v1.0.0.php';
        $migration = $migrationDirectory . '/' . $filename;
        self::assertTrue(copy(
            BP . 'app/code/Weline/Ai/Setup/Db/Migration/' . $filename,
            $migration,
        ));
        $executionMarker = $root . '/sidecar-executed';
        $executionMarkerLiteral = var_export($executionMarker, true);
        $checksum = hash_file('sha256', $migration);
        file_put_contents($root . '/Setup/Db/migration-metadata.php', <<<PHP
<?php
file_put_contents({$executionMarkerLiteral}, 'executed');
return [
    '{$filename}' => [
        'script_sha256' => '{$checksum}',
        'affected_tables' => ['attacker_selected_table'],
    ],
];
PHP);

        try {
            self::assertSame([], (new HistoricalMigrationMetadataRegistry())->affectedTables($migration));
            self::assertFileDoesNotExist($executionMarker);
        } finally {
            @unlink($executionMarker);
            @unlink($root . '/Setup/Db/migration-metadata.php');
            @unlink($migration);
            @rmdir($migrationDirectory);
            @rmdir($root . '/Setup/Db');
            @rmdir($root . '/Setup');
            @rmdir($root);
        }
    }
}
