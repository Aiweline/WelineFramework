<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\Migration;
use Weline\Database\Model\ModuleVersionOperation;
use Weline\Database\Service\BackupService;
use Weline\Database\Service\MigrationService;
use Weline\Database\Service\SchemaRollbackService;
use Weline\Database\Service\VersionService;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

final class OperationBindingPersistenceTest extends TestCase
{
    /**
     * @dataProvider failedBindingProvider
     */
    public function testSchemaRollbackStopsBeforeBackupAndDdlWhenOperationBindingIsNotDurable(
        bool|int $saveResult,
        bool $persistExpectedOperation,
        array $statusSequence,
    ): void {
        $state = new OperationBindingState($saveResult, $persistExpectedOperation, $statusSequence);
        $migration = new OperationBindingMigration($state);
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects(self::never())->method('query');
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->expects(self::once())->method('getConnector')->willReturn($connector);
        $backup = $this->createMock(BackupService::class);
        $backup->expects(self::never())->method('backupTableStructure');
        $backup->expects(self::never())->method('backupTableData');
        $backup->expects(self::never())->method('backupColumnData');
        $service = new SchemaRollbackService($factory, $migration, $backup);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation_id binding persistence failed');

        $service->executeRollbackPlan([[
            'migration_id' => 501,
            'operation_kind' => SchemaDiffOp::KIND_CREATE_TABLE,
            'table_name' => 'analytics.unit_probe',
            'model_class' => 'Weline\\Unit\\Model\\Probe',
            'rollback_ddl' => 'DROP TABLE analytics.unit_probe',
            'payload' => [],
        ]], 'operation-expected');
    }

    /**
     * @dataProvider failedBindingProvider
     */
    public function testScriptRollbackStopsBeforeRollbackWhenOperationBindingIsNotDurable(
        bool|int $saveResult,
        bool $persistExpectedOperation,
        array $statusSequence,
    ): void {
        $state = new OperationBindingState($saveResult, $persistExpectedOperation, $statusSequence);
        $migration = new OperationBindingMigration($state);
        $factory = $this->createMock(ConnectionFactory::class);
        $backup = $this->createMock(BackupService::class);
        $service = $this->getMockBuilder(MigrationService::class)
            ->setConstructorArgs([
                $factory,
                $migration,
                $backup,
                $this->createMock(VersionService::class),
                $this->createMock(Printing::class),
            ])
            ->onlyMethods(['rollbackMigration'])
            ->getMock();
        $service->expects(self::never())->method('rollbackMigration');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation_id binding persistence failed');

        $service->executeRollbackPlan('Weline_Unit', [[
            'migration_id' => 501,
            'file' => '/tmp/never-executed.php',
            'filename' => 'never-executed.php',
            'rollback_backup_strategy' => [],
        ]], 'operation-expected');
    }

    /** @return iterable<string, array{bool|int, bool, list<string>}> */
    public static function failedBindingProvider(): iterable
    {
        yield 'save returned false' => [false, false, []];
        yield 'save returned zero' => [0, false, []];
        yield 'write succeeded but fresh read mismatched' => [1, false, []];
        yield 'write succeeded but installed status changed before verification' => [
            1,
            true,
            [Migration::STATUS_INSTALLED, Migration::STATUS_INSTALLED, Migration::STATUS_ROLLED_BACK],
        ];
    }

    public function testBooleanTrueSaveResultContinuesOnlyAfterMatchingFreshRead(): void
    {
        $state = new OperationBindingState(true, true);
        (new OperationBindingMigration($state))->bindOperationIdFailClosed(501, 'operation-expected');

        self::assertSame('operation-expected', $state->persistedOperationId);
    }

    public function testFreshReadRejectsStatusChangedAfterOperationBindingWrite(): void
    {
        $state = new OperationBindingState(
            1,
            true,
            [Migration::STATUS_INSTALLED, Migration::STATUS_ROLLED_BACK],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation_id binding persistence failed');

        (new OperationBindingMigration($state))->bindOperationIdFailClosed(501, 'operation-expected');
    }

    public function testRealPgsqlOperationBindingPersistsAndFreshLoadMatches(): void
    {
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $migrationId = $migration->recordMigration([
            'module_name' => 'Weline_OperationBindingTest',
            'version' => '1.0.0',
            'migration_file' => 'operation-binding-' . bin2hex(random_bytes(6)) . '.php',
            'description' => 'operation binding persistence probe',
            'status' => Migration::STATUS_INSTALLED,
        ]);
        self::assertGreaterThan(0, $migrationId);

        try {
            $migration->bindOperationIdFailClosed($migrationId, 'operation-real-pgsql');
            $verification = ObjectManager::getInstance(Migration::class, [], false);
            $verification->load($migrationId);
            self::assertSame($migrationId, (int)$verification->getId());
            self::assertSame(
                'operation-real-pgsql',
                (string)$verification->getData(Migration::schema_fields_OPERATION_ID),
            );
        } finally {
            (clone $migration)->reset()
                ->where(Migration::schema_fields_ID, $migrationId)
                ->delete()
                ->fetch();
        }
    }

    public function testRealPgsqlBindingIsIdempotentButRejectsDifferentActiveOwner(): void
    {
        $migrationId = $this->createRealMigration('cas-owner');
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        try {
            $migration->bindOperationIdFailClosed($migrationId, 'operation-a');
            $migration->bindOperationIdFailClosed($migrationId, 'operation-a');

            $rejected = null;
            try {
                $migration->bindOperationIdFailClosed($migrationId, 'operation-b');
            } catch (\RuntimeException $exception) {
                $rejected = $exception;
            }
            self::assertInstanceOf(\RuntimeException::class, $rejected);
            self::assertStringContainsString('operation_id', $rejected->getMessage());

            $verification = ObjectManager::getInstance(Migration::class, [], false);
            $verification->load($migrationId);
            self::assertSame('operation-a', $verification->getData(Migration::schema_fields_OPERATION_ID));
        } finally {
            $this->deleteRealMigration($migrationId);
        }
    }

    public function testRealPgsqlBindingRejectsMigrationWhoseInstalledStatusWasLost(): void
    {
        $migrationId = $this->createRealMigration('cas-status');
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        try {
            $migration->bindOperationIdFailClosed($migrationId, 'operation-a');
            (clone $migration)->reset()
                ->where(Migration::schema_fields_ID, $migrationId)
                ->update([Migration::schema_fields_STATUS => Migration::STATUS_ROLLED_BACK])
                ->fetch();

            $this->expectException(\RuntimeException::class);
            $migration->bindOperationIdFailClosed($migrationId, 'operation-a');
        } finally {
            $this->deleteRealMigration($migrationId);
        }
    }

    public function testRealPgsqlReleaseAndTransferRequireRecoveredTerminalOwner(): void
    {
        $migrationId = $this->createRealMigration('cas-transfer');
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $operationA = $this->createRealOperation('operation-a', ModuleVersionOperation::STATUS_RUNNING);
        $operationB = 0;
        try {
            $migration->bindOperationIdFailClosed($migrationId, 'operation-a');
            foreach ([
                ModuleVersionOperation::STATUS_RUNNING,
                ModuleVersionOperation::STATUS_MANUAL_RECOVERY,
            ] as $blockedStatus) {
                $this->setOperationStatus('operation-a', $blockedStatus);
                $rejected = null;
                try {
                    $migration->releaseOperationIdFailClosed($migrationId, 'operation-a');
                } catch (\RuntimeException $exception) {
                    $rejected = $exception;
                }
                self::assertInstanceOf(\RuntimeException::class, $rejected);
                self::assertStringContainsString('failed_recovered', $rejected->getMessage());
            }

            $this->setOperationStatus('operation-a', ModuleVersionOperation::STATUS_FAILED_RECOVERED);
            $migration->transferOperationIdFailClosed($migrationId, 'operation-a', 'operation-b');
            self::assertSame('operation-b', $this->realMigrationOperationId($migrationId));
            $migration->transferOperationIdFailClosed($migrationId, 'operation-a', 'operation-b');
            self::assertSame('operation-b', $this->realMigrationOperationId($migrationId));

            $operationB = $this->createRealOperation('operation-b', ModuleVersionOperation::STATUS_FAILED_RECOVERED);
            $migration->releaseOperationIdFailClosed($migrationId, 'operation-b');
            $migration->releaseOperationIdFailClosed($migrationId, 'operation-b');
            self::assertSame('', $this->realMigrationOperationId($migrationId));
        } finally {
            $this->deleteRealOperation($operationA);
            if ($operationB > 0) {
                $this->deleteRealOperation($operationB);
            }
            $this->deleteRealMigration($migrationId);
        }
    }

    public function testRealPgsqlRecoveredOperationReleasesEveryInstalledMigrationBinding(): void
    {
        $firstMigrationId = $this->createRealMigration('release-all-a');
        $secondMigrationId = $this->createRealMigration('release-all-b');
        $operation = $this->createRealOperation(
            'operation-release-all',
            ModuleVersionOperation::STATUS_FAILED_RECOVERED,
        );
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        try {
            $migration->bindOperationIdFailClosed($firstMigrationId, 'operation-release-all');
            $migration->bindOperationIdFailClosed($secondMigrationId, 'operation-release-all');
            $service = new MigrationService(
                $this->createMock(ConnectionFactory::class),
                $migration,
                $this->createMock(BackupService::class),
                $this->createMock(VersionService::class),
                $this->createMock(Printing::class),
            );

            self::assertSame(2, $service->releaseRecoveredOperationBindings('operation-release-all'));
            self::assertSame('', $this->realMigrationOperationId($firstMigrationId));
            self::assertSame('', $this->realMigrationOperationId($secondMigrationId));
            self::assertSame(0, $service->releaseRecoveredOperationBindings('operation-release-all'));
        } finally {
            $this->deleteRealOperation($operation);
            $this->deleteRealMigration($firstMigrationId);
            $this->deleteRealMigration($secondMigrationId);
        }
    }

    public function testRecoveredOperationReleaseValidatesTerminalOwnerEvenWithoutBindings(): void
    {
        $operation = $this->createRealOperation('operation-empty-release', ModuleVersionOperation::STATUS_RUNNING);
        try {
            $service = new MigrationService(
                $this->createMock(ConnectionFactory::class),
                ObjectManager::getInstance(Migration::class, [], false),
                $this->createMock(BackupService::class),
                $this->createMock(VersionService::class),
                $this->createMock(Printing::class),
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('failed_recovered');
            $service->releaseRecoveredOperationBindings('operation-empty-release');
        } finally {
            $this->deleteRealOperation($operation);
        }
    }

    public function testTransferRejectsWhitespaceNewOperationIdWithoutChangingBinding(): void
    {
        $migrationId = $this->createRealMigration('cas-whitespace');
        $operation = $this->createRealOperation(
            'operation-whitespace-owner',
            ModuleVersionOperation::STATUS_FAILED_RECOVERED,
        );
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        try {
            $migration->bindOperationIdFailClosed($migrationId, 'operation-whitespace-owner');

            $rejected = null;
            try {
                $migration->transferOperationIdFailClosed(
                    $migrationId,
                    'operation-whitespace-owner',
                    '   ',
                );
            } catch (\InvalidArgumentException $exception) {
                $rejected = $exception;
            }

            self::assertInstanceOf(\InvalidArgumentException::class, $rejected);
            self::assertSame('operation-whitespace-owner', $this->realMigrationOperationId($migrationId));
        } finally {
            $this->deleteRealOperation($operation);
            $this->deleteRealMigration($migrationId);
        }
    }

    public function testRecoveredOperationBulkReleaseRollsBackEveryBindingWhenSecondRowFails(): void
    {
        $operationId = 'operation-release-fault-' . bin2hex(random_bytes(4));
        $firstMigrationId = $this->createRealMigration('release-fault-a');
        $secondMigrationId = $this->createRealMigration('release-fault-b');
        $operation = $this->createRealOperation($operationId, ModuleVersionOperation::STATUS_FAILED_RECOVERED);
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $trigger = 'weline_release_fault_' . bin2hex(random_bytes(4));
        $function = $trigger . '_fn';
        try {
            $migration->bindOperationIdFailClosed($firstMigrationId, $operationId);
            $migration->bindOperationIdFailClosed($secondMigrationId, $operationId);
            $this->createMigrationOperationUpdateFailureTrigger(
                $trigger,
                $function,
                $secondMigrationId,
            );
            $service = $this->migrationService($migration);

            $failure = null;
            try {
                $service->releaseRecoveredOperationBindings($operationId);
            } catch (\Throwable $exception) {
                $failure = $exception;
            } finally {
                $this->dropMigrationTrigger($trigger, $function);
            }

            self::assertInstanceOf(\Throwable::class, $failure);
            self::assertSame($operationId, $this->realMigrationOperationId($firstMigrationId));
            self::assertSame($operationId, $this->realMigrationOperationId($secondMigrationId));
            self::assertSame(ModuleVersionOperation::STATUS_FAILED_RECOVERED, $this->realOperationStatus($operationId));

            self::assertSame(2, $service->releaseRecoveredOperationBindings($operationId));
            self::assertSame('', $this->realMigrationOperationId($firstMigrationId));
            self::assertSame('', $this->realMigrationOperationId($secondMigrationId));
        } finally {
            $this->dropMigrationTrigger($trigger, $function);
            $this->deleteRealOperation($operation);
            $this->deleteRealMigration($firstMigrationId);
            $this->deleteRealMigration($secondMigrationId);
        }
    }

    public function testOwnerStatusRaceRollsBackBindingTransferAndOwnerStatus(): void
    {
        $operationId = 'operation-owner-race-' . bin2hex(random_bytes(4));
        $migrationId = $this->createRealMigration('owner-race');
        $operation = $this->createRealOperation($operationId, ModuleVersionOperation::STATUS_FAILED_RECOVERED);
        $migration = ObjectManager::getInstance(Migration::class, [], false);
        $trigger = 'weline_owner_race_' . bin2hex(random_bytes(4));
        $function = $trigger . '_fn';
        try {
            $migration->bindOperationIdFailClosed($migrationId, $operationId);
            $this->createOwnerStatusRaceTrigger($trigger, $function, $migrationId, $operationId);

            $failure = null;
            try {
                $migration->releaseOperationIdFailClosed($migrationId, $operationId);
            } catch (\Throwable $exception) {
                $failure = $exception;
            } finally {
                $this->dropMigrationTrigger($trigger, $function);
            }

            self::assertInstanceOf(\Throwable::class, $failure);
            self::assertSame($operationId, $this->realMigrationOperationId($migrationId));
            self::assertSame(ModuleVersionOperation::STATUS_FAILED_RECOVERED, $this->realOperationStatus($operationId));
        } finally {
            $this->dropMigrationTrigger($trigger, $function);
            $this->deleteRealOperation($operation);
            $this->deleteRealMigration($migrationId);
        }
    }

    private function createRealMigration(string $suffix): int
    {
        return ObjectManager::getInstance(Migration::class, [], false)->recordMigration([
            'module_name' => 'Weline_OperationBindingTest',
            'version' => '1.0.0',
            'migration_file' => $suffix . '-' . bin2hex(random_bytes(6)) . '.php',
            'description' => 'operation CAS probe',
            'status' => Migration::STATUS_INSTALLED,
        ]);
    }

    private function migrationService(Migration $migration): MigrationService
    {
        return new MigrationService(
            $this->createMock(ConnectionFactory::class),
            $migration,
            $this->createMock(BackupService::class),
            $this->createMock(VersionService::class),
            $this->createMock(Printing::class),
        );
    }

    private function createMigrationOperationUpdateFailureTrigger(
        string $trigger,
        string $function,
        int $migrationId,
    ): void {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $table = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $quotedTrigger = $connector->quoteIdentifier($trigger);
        $quotedFunction = $connector->quoteIdentifier($function);
        $connector->getWrappedConnection()->execute(
            "CREATE FUNCTION {$quotedFunction}() RETURNS trigger LANGUAGE plpgsql AS "
            . "\$\$ BEGIN RAISE EXCEPTION 'forced second binding release failure'; END \$\$"
        );
        $connector->getWrappedConnection()->execute(
            "CREATE TRIGGER {$quotedTrigger} BEFORE UPDATE OF "
            . $connector->quoteIdentifier(Migration::schema_fields_OPERATION_ID)
            . " ON {$table} FOR EACH ROW WHEN (OLD."
            . $connector->quoteIdentifier(Migration::schema_fields_ID)
            . " = {$migrationId} AND NEW."
            . $connector->quoteIdentifier(Migration::schema_fields_OPERATION_ID)
            . " = '') EXECUTE FUNCTION {$quotedFunction}()"
        );
    }

    private function createOwnerStatusRaceTrigger(
        string $trigger,
        string $function,
        int $migrationId,
        string $operationId,
    ): void {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $migrationTable = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $operationTable = $connector->quoteTable($connector->formatTableName(ModuleVersionOperation::schema_table));
        $quotedTrigger = $connector->quoteIdentifier($trigger);
        $quotedFunction = $connector->quoteIdentifier($function);
        $quotedOperationId = str_replace("'", "''", $operationId);
        $connector->getWrappedConnection()->execute(
            "CREATE FUNCTION {$quotedFunction}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN UPDATE "
            . "{$operationTable} SET "
            . $connector->quoteIdentifier(ModuleVersionOperation::schema_fields_STATUS)
            . " = '" . ModuleVersionOperation::STATUS_MANUAL_RECOVERY . "' WHERE "
            . $connector->quoteIdentifier(ModuleVersionOperation::schema_fields_OPERATION_ID)
            . " = '{$quotedOperationId}'; RETURN NEW; END \$\$"
        );
        $connector->getWrappedConnection()->execute(
            "CREATE TRIGGER {$quotedTrigger} BEFORE UPDATE OF "
            . $connector->quoteIdentifier(Migration::schema_fields_OPERATION_ID)
            . " ON {$migrationTable} FOR EACH ROW WHEN (OLD."
            . $connector->quoteIdentifier(Migration::schema_fields_ID)
            . " = {$migrationId}) EXECUTE FUNCTION {$quotedFunction}()"
        );
    }

    private function dropMigrationTrigger(string $trigger, string $function): void
    {
        $connector = ObjectManager::getInstance(Migration::class, [], false)->getConnection()->getConnector();
        $table = $connector->quoteTable($connector->formatTableName(Migration::schema_table));
        $connector->getWrappedConnection()->execute(
            'DROP TRIGGER IF EXISTS ' . $connector->quoteIdentifier($trigger) . " ON {$table}"
        );
        $connector->getWrappedConnection()->execute(
            'DROP FUNCTION IF EXISTS ' . $connector->quoteIdentifier($function) . '()'
        );
    }

    private function deleteRealMigration(int $migrationId): void
    {
        ObjectManager::getInstance(Migration::class, [], false)->reset()
            ->where(Migration::schema_fields_ID, $migrationId)
            ->delete()
            ->fetch();
    }

    private function createRealOperation(string $operationId, string $status): int
    {
        $operation = ObjectManager::getInstance(ModuleVersionOperation::class, [], false)->reset()->setData([
            ModuleVersionOperation::schema_fields_OPERATION_ID => $operationId,
            ModuleVersionOperation::schema_fields_ROOT_MODULE => 'Weline_OperationBindingTest',
            ModuleVersionOperation::schema_fields_TARGET_VERSION => '1.0.0',
            ModuleVersionOperation::schema_fields_PLAN_HASH => hash('sha256', $operationId),
            ModuleVersionOperation::schema_fields_PLAN_JSON => '{}',
            ModuleVersionOperation::schema_fields_STATUS => $status,
            ModuleVersionOperation::schema_fields_PHASE => 'test',
        ]);
        $saved = $operation->save();
        self::assertIsInt($saved);
        self::assertGreaterThan(0, $saved);
        return $saved;
    }

    private function setOperationStatus(string $operationId, string $status): void
    {
        ObjectManager::getInstance(ModuleVersionOperation::class, [], false)->reset()
            ->where(ModuleVersionOperation::schema_fields_OPERATION_ID, $operationId)
            ->update([ModuleVersionOperation::schema_fields_STATUS => $status])
            ->fetch();
    }

    private function realOperationStatus(string $operationId): string
    {
        $operation = ObjectManager::getInstance(ModuleVersionOperation::class, [], false)->reset();
        $operation->where(ModuleVersionOperation::schema_fields_OPERATION_ID, $operationId)->find()->fetch();
        return (string)$operation->getData(ModuleVersionOperation::schema_fields_STATUS);
    }

    private function deleteRealOperation(int $id): void
    {
        ObjectManager::getInstance(ModuleVersionOperation::class, [], false)->reset()
            ->where(ModuleVersionOperation::schema_fields_ID, $id)
            ->delete()
            ->fetch();
    }

    private function realMigrationOperationId(int $migrationId): string
    {
        $migration = ObjectManager::getInstance(Migration::class, [], false)->reset();
        $migration->where(Migration::schema_fields_ID, $migrationId)->find()->fetch();
        return (string)$migration->getData(Migration::schema_fields_OPERATION_ID);
    }
}

final class OperationBindingState
{
    public string $persistedOperationId = 'operation-other';

    public function __construct(
        public readonly bool|int $saveResult,
        public readonly bool $persistExpectedOperation,
        private readonly array $statusSequence = [],
    ) {
    }

    private int $statusRead = 0;

    public function nextStatus(): string
    {
        if ($this->statusSequence === []) {
            return Migration::STATUS_INSTALLED;
        }
        $index = min($this->statusRead, count($this->statusSequence) - 1);
        $this->statusRead++;
        return (string)$this->statusSequence[$index];
    }
}

final class OperationBindingMigration extends Migration
{
    private int $loadedId = 0;
    private string $pendingOperationId = '';

    public function __construct(private readonly OperationBindingState $state)
    {
    }

    public function __clone()
    {
        $this->loadedId = 0;
        $this->pendingOperationId = '';
    }

    public function bindOperationIdFailClosed(int $migrationId, string $operationId): void
    {
        if ($migrationId <= 0 || trim($operationId) === '' || strlen($operationId) > 64) {
            throw new \InvalidArgumentException('invalid operation_id binding request');
        }
        if ($this->state->nextStatus() !== self::STATUS_INSTALLED) {
            throw new \RuntimeException("operation_id binding persistence failed: migration_id={$migrationId}");
        }

        $saved = $this->state->saveResult;
        if ($saved === false || $saved === 0 || (is_int($saved) && $saved < 0)) {
            throw new \RuntimeException("operation_id binding persistence failed: migration_id={$migrationId}");
        }
        if ($this->state->persistExpectedOperation) {
            $this->state->persistedOperationId = $operationId;
        }

        if ($this->state->nextStatus() !== self::STATUS_INSTALLED
            || $this->state->persistedOperationId !== $operationId) {
            throw new \RuntimeException("operation_id binding persistence failed: migration_id={$migrationId}");
        }
    }

    public function reset(): static
    {
        $this->loadedId = 0;
        return $this;
    }

    public function setConnection(ConnectionFactory $connection): static
    {
        return $this;
    }

    public function where(mixed $field, mixed $value = null, mixed ...$unused): static
    {
        if ($field === self::schema_fields_ID && is_numeric($value)) {
            $this->loadedId = (int)$value;
        }
        return $this;
    }

    public function find(mixed ...$unused): static
    {
        return $this;
    }

    public function fetch(mixed ...$unused): static
    {
        return $this;
    }

    public function load(int|string $field_or_pk_value, $value = null): AbstractModel
    {
        $this->loadedId = (int)$field_or_pk_value;
        return $this;
    }

    public function getId(mixed $default = 0)
    {
        return $this->loadedId > 0 ? $this->loadedId : $default;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        $forwardDdl = 'CREATE TABLE analytics.unit_probe (id integer)';
        $rollbackDdl = 'DROP TABLE analytics.unit_probe';
        return match ($key) {
            self::schema_fields_STATUS => $this->state->nextStatus(),
            self::schema_fields_FORWARD_DDL => $forwardDdl,
            self::schema_fields_ROLLBACK_DDL => $rollbackDdl,
            self::schema_fields_CHECKSUM => hash('sha256', $forwardDdl . "\0" . $rollbackDdl),
            self::schema_fields_OPERATION_ID => $this->state->persistedOperationId,
            default => null,
        };
    }

    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if ($key === self::schema_fields_OPERATION_ID) {
            $this->pendingOperationId = (string)$value;
        }
        return $this;
    }

    public function save(
        string|array|bool|AbstractModel $data = [],
        string|array $sequence = '',
    ): bool|int {
        if ($this->state->persistExpectedOperation) {
            $this->state->persistedOperationId = $this->pendingOperationId;
        }
        return $this->state->saveResult;
    }
}
