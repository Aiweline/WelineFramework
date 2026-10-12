<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Schema;

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
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Model\MigrationBackup;

/**
 * Locks Lane R1 (SCOPE_ROLLBACK) vs Lane R2 (SCOPE_UPGRADE) selection by migration_id,
 * and C1 rework: newest DROP without TYPE_COLUMN backup must not suppress older qualified Lane.
 */
final class ColumnRestoreLaneSelectContractTest extends TestCase
{
    public function testSelectsNewestUpgradeDropColumnOverOlderRollbackLane(): void
    {
        // Quoted/qualified vs bare format-2 both normalize to public.w_demo_table
        // when runtimeQualifiers are empty (connector without formatTableName probe).
        $migration = $this->migrationReturning([
            Migration::STATUS_ROLLED_BACK => [
                $this->item(100, SchemaDiffOp::KIND_ADD_COLUMN, 'public.w_demo_table', 'sample_payload'),
            ],
            Migration::STATUS_INSTALLED => [
                $this->item(200, SchemaDiffOp::KIND_DROP_COLUMN, '"public"."w_demo_table"', 'sample_payload'),
            ],
        ]);
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->backupServiceProving([
                200 => MigrationBackup::SCOPE_UPGRADE,
                100 => MigrationBackup::SCOPE_ROLLBACK,
            ]),
        );

        $selected = $this->invokeSelect(
            $executor,
            'Weline_Demo',
            '"public"."w_demo_table"',
            'sample_payload',
            999,
            $this->createMock(ConnectorInterface::class),
        );

        self::assertSame(200, $selected['migration_id'] ?? null);
        self::assertSame(MigrationBackup::SCOPE_UPGRADE, $selected['backup_scope'] ?? null);
    }

    public function testSelectsRollbackLaneWhenItIsNewerThanUpgradeDrop(): void
    {
        $migration = $this->migrationReturning([
            Migration::STATUS_ROLLED_BACK => [
                $this->item(300, SchemaDiffOp::KIND_ADD_COLUMN, 'w_demo_table', 'sample_payload'),
            ],
            Migration::STATUS_INSTALLED => [
                $this->item(200, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload'),
            ],
        ]);
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->backupServiceProving([
                300 => MigrationBackup::SCOPE_ROLLBACK,
                200 => MigrationBackup::SCOPE_UPGRADE,
            ]),
        );

        $selected = $this->invokeSelect(
            $executor,
            'Weline_Demo',
            'w_demo_table',
            'sample_payload',
            999,
            $this->createMock(ConnectorInterface::class),
        );

        self::assertSame(300, $selected['migration_id'] ?? null);
        self::assertSame(MigrationBackup::SCOPE_ROLLBACK, $selected['backup_scope'] ?? null);
    }

    public function testIgnoresMismatchedColumnName(): void
    {
        $migration = $this->migrationReturning([
            Migration::STATUS_INSTALLED => [
                $this->item(200, SchemaDiffOp::KIND_DROP_COLUMN, 'demo_table', 'other_col'),
            ],
        ]);
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->backupServiceProving([
                200 => MigrationBackup::SCOPE_UPGRADE,
            ]),
        );

        $selected = $this->invokeSelect(
            $executor,
            'Weline_Demo',
            'demo_table',
            'sample_payload',
            999,
            $this->createMock(ConnectorInterface::class),
        );

        self::assertNull($selected);
    }

    /**
     * Escalate C1 / option C: newest installed DROP lacks TYPE_COLUMN+SCOPE_UPGRADE backup;
     * older R1 with SCOPE_ROLLBACK backup must win — must not silent-pick 400 then restored:0.
     */
    public function testSkipsNewestDropWithoutBackupAndSelectsOlderRollbackLane(): void
    {
        $migration = $this->migrationReturning([
            Migration::STATUS_ROLLED_BACK => [
                $this->item(100, SchemaDiffOp::KIND_ADD_COLUMN, 'w_demo_table', 'sample_payload'),
            ],
            Migration::STATUS_INSTALLED => [
                $this->item(400, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload'),
            ],
        ]);
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            // Only R1 migration 100 has a qualified backup; newest DROP 400 does not.
            $this->backupServiceProving([
                100 => MigrationBackup::SCOPE_ROLLBACK,
            ]),
        );

        $selected = $this->invokeSelect(
            $executor,
            'Weline_Demo',
            'w_demo_table',
            'sample_payload',
            999,
            $this->createMock(ConnectorInterface::class),
        );

        self::assertSame(100, $selected['migration_id'] ?? null);
        self::assertSame(MigrationBackup::SCOPE_ROLLBACK, $selected['backup_scope'] ?? null);
    }

    /**
     * Multi-generation R2: newest DROP without backup must not block an older DROP that has
     * TYPE_COLUMN+SCOPE_UPGRADE backup.
     */
    public function testSkipsNewestDropWithoutBackupAndSelectsOlderUpgradeDropLane(): void
    {
        $migration = $this->migrationReturning([
            Migration::STATUS_INSTALLED => [
                $this->item(150, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload'),
                $this->item(400, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload'),
            ],
        ]);
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->backupServiceProving([
                150 => MigrationBackup::SCOPE_UPGRADE,
            ]),
        );

        $selected = $this->invokeSelect(
            $executor,
            'Weline_Demo',
            'w_demo_table',
            'sample_payload',
            999,
            $this->createMock(ConnectorInterface::class),
        );

        self::assertSame(150, $selected['migration_id'] ?? null);
        self::assertSame(MigrationBackup::SCOPE_UPGRADE, $selected['backup_scope'] ?? null);
    }

    public function testReturnsNullWhenNoCandidateHasQualifiedBackup(): void
    {
        $migration = $this->migrationReturning([
            Migration::STATUS_INSTALLED => [
                $this->item(400, SchemaDiffOp::KIND_DROP_COLUMN, 'w_demo_table', 'sample_payload'),
            ],
        ]);
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class),
            $migration,
            $this->backupServiceProving([]),
        );

        $selected = $this->invokeSelect(
            $executor,
            'Weline_Demo',
            'w_demo_table',
            'sample_payload',
            999,
            $this->createMock(ConnectorInterface::class),
        );

        self::assertNull($selected);
    }

    /**
     * @param array<int, string> $qualifiedByMigrationId migration_id => expected backup_scope
     */
    private function backupServiceProving(array $qualifiedByMigrationId): BackupService
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('hasQualifiedColumnBackup')->willReturnCallback(
            static function (
                int $migrationId,
                string $columnName,
                string $backupScope,
                array $tableNameCandidates = [],
            ) use ($qualifiedByMigrationId): bool {
                unset($columnName, $tableNameCandidates);
                return ($qualifiedByMigrationId[$migrationId] ?? null) === $backupScope;
            },
        );

        return $backup;
    }

    /**
     * @param array<string, list<object>> $byStatus
     */
    private function migrationReturning(array $byStatus): Migration
    {
        return new class ($byStatus) extends Migration {
            private ?string $statusFilter = null;

            /** @param array<string, list<object>> $byStatus */
            public function __construct(private readonly array $byStatus)
            {
                // Skip Model construction / DI.
            }

            public function reset(): static
            {
                $this->statusFilter = null;
                return $this;
            }

            public function where(mixed $field, mixed $value = true, string $condition = '=', string $op = 'AND'): static
            {
                if ($field === Migration::schema_fields_STATUS) {
                    $this->statusFilter = (string)$value;
                }
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
                return $this->byStatus[$this->statusFilter ?? ''] ?? [];
            }
        };
    }

    private function item(int $id, string $kind, string $table, string $column): object
    {
        return new class ($id, $kind, $table, $column) {
            public function __construct(
                private readonly int $id,
                private readonly string $kind,
                private readonly string $table,
                private readonly string $column,
            ) {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function getData(string $key): mixed
            {
                return match ($key) {
                    Migration::schema_fields_OPERATION_KIND => $this->kind,
                    Migration::schema_fields_SCHEMA_TABLE_NAME => $this->table,
                    Migration::schema_fields_OPERATION_PAYLOAD => json_encode(
                        ['name' => $this->column],
                        JSON_UNESCAPED_UNICODE,
                    ),
                    default => null,
                };
            }
        };
    }

    /** @return array{migration_id: int, backup_scope: string}|null */
    private function invokeSelect(
        SchemaMigrationExecutor $executor,
        string $module,
        string $table,
        string $column,
        int $currentId,
        ConnectorInterface $connector,
    ): ?array {
        $method = new \ReflectionMethod(SchemaMigrationExecutor::class, 'selectEligibleColumnRestoreCandidate');
        $method->setAccessible(true);
        /** @var array{migration_id: int, backup_scope: string}|null $result */
        $result = $method->invoke($executor, $module, $table, $column, $currentId, $connector);
        return $result;
    }
}
