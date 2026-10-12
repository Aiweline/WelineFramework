<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 8) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Model\MigrationBackup;

/**
 * C-RA-1 / C-RA-2: non-empty table candidates forbid column-only soft match.
 */
final class HasQualifiedColumnBackupTableKeyContractTest extends TestCase
{
    public function testRejectsColumnOnlyHitWhenTableCandidatesProvided(): void
    {
        $service = $this->serviceWithBackupRow(
            migrationId: 9001,
            tableName: 'other_schema.w_foreign_table',
            columnName: 'sample_payload',
            scope: MigrationBackup::SCOPE_UPGRADE,
        );

        self::assertFalse($service->hasQualifiedColumnBackup(
            9001,
            'sample_payload',
            MigrationBackup::SCOPE_UPGRADE,
            ['w_demo_table', 'public.w_demo_table'],
        ));
    }

    public function testAcceptsNormalizedTableKeyMatch(): void
    {
        $service = $this->serviceWithBackupRow(
            migrationId: 9002,
            tableName: '"public"."w_demo_table"',
            columnName: 'sample_payload',
            scope: MigrationBackup::SCOPE_UPGRADE,
        );

        self::assertTrue($service->hasQualifiedColumnBackup(
            9002,
            'sample_payload',
            MigrationBackup::SCOPE_UPGRADE,
            ['w_demo_table'],
        ));
    }

    public function testEmptyCandidatesStillAllowColumnOnly(): void
    {
        $service = $this->serviceWithBackupRow(
            migrationId: 9003,
            tableName: 'any_table',
            columnName: 'sample_payload',
            scope: MigrationBackup::SCOPE_ROLLBACK,
        );

        self::assertTrue($service->hasQualifiedColumnBackup(
            9003,
            'sample_payload',
            MigrationBackup::SCOPE_ROLLBACK,
            [],
        ));
    }

    private function serviceWithBackupRow(
        int $migrationId,
        string $tableName,
        string $columnName,
        string $scope,
    ): BackupService {
        $row = new class ($migrationId, $tableName, $columnName, $scope) extends MigrationBackup {
            public function __construct(
                private readonly int $migrationId,
                private readonly string $tableName,
                private readonly string $columnName,
                private readonly string $scope,
            ) {
            }

            public function getData(string $key = '', $index = null): mixed
            {
                unset($index);
                return match ($key) {
                    MigrationBackup::schema_fields_MIGRATION_ID => $this->migrationId,
                    MigrationBackup::schema_fields_TABLE_NAME => $this->tableName,
                    MigrationBackup::schema_fields_COLUMN_NAME => $this->columnName,
                    MigrationBackup::schema_fields_BACKUP_SCOPE => $this->scope,
                    MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_COLUMN,
                    MigrationBackup::schema_fields_BACKUP_DATA => '[]',
                    default => null,
                };
            }
        };

        $model = new class ($row) extends MigrationBackup {
            /** @param object $row */
            public function __construct(private readonly object $row)
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where(mixed $field, mixed $value = true, string $condition = '=', string $op = 'AND'): static
            {
                return $this;
            }

            public function order(string $field, string $direction = 'ASC'): static
            {
                return $this;
            }

            public function select(string $fields = '*'): static
            {
                return $this;
            }

            public function fetch(string $model_class = ''): static
            {
                return $this;
            }

            /** @return list<object> */
            public function getItems(): array
            {
                return [$this->row];
            }
        };

        return new BackupService(
            $this->createMock(ConnectionFactory::class),
            $model,
            $this->createMock(Printing::class),
        );
    }
}
