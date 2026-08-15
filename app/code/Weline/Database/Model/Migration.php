<?php

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Manager\ObjectManager;

/**
 * @deprecated Use the Framework-owned migration model directly.
 *
 * This compatibility subclass deliberately owns no schema declaration. Both
 * runtimes now operate on the single Framework bootstrap table/model.
 */
class Migration extends \Weline\Framework\Setup\Model\Migration implements \Weline\Framework\Database\Schema\SchemaDiffExcludedModelInterface
{
    public function bindOperationIdFailClosed(int $migrationId, string $operationId): void
    {
        $this->assertOperationBindingRequest($migrationId, $operationId, false);
        $state = $this->freshInstalledMigrationState($migrationId);
        $currentOperationId = (string)($state[self::schema_fields_OPERATION_ID] ?? '');

        if ($currentOperationId === $operationId) {
            return;
        }
        if ($currentOperationId !== '') {
            throw $this->operationBindingFailure($migrationId);
        }

        $this->compareAndSwapOperationId(
            $migrationId,
            $state[self::schema_fields_OPERATION_ID] ?? null,
            $operationId,
        );
    }

    public function releaseOperationIdFailClosed(int $migrationId, string $expectedOperationId): void
    {
        $this->transferOperationIdFailClosed($migrationId, $expectedOperationId, '');
    }

    public function assertRecoveredOperationIdFailClosed(string $operationId): void
    {
        $this->assertOperationId($operationId, false);
        $this->assertRecoveredOperation($operationId, 0, $this->getConnection());
    }

    public function transferOperationIdFailClosed(
        int $migrationId,
        string $expectedOperationId,
        string $newOperationId,
    ): void {
        $this->assertOperationBindingRequest($migrationId, $expectedOperationId, false);
        $this->assertOperationBindingRequest($migrationId, $newOperationId, true);
        $connection = $this->getConnection();
        $transactions = ObjectManager::getInstance(TransactionCoordinator::class);
        $transactions->run($connection, function () use (
            $connection,
            $migrationId,
            $expectedOperationId,
            $newOperationId,
        ): void {
            // Always lock the owner first. Bulk release uses the same order, so a
            // concurrent status transition cannot interleave with migration CAS.
            $this->assertRecoveredOperation($expectedOperationId, $migrationId, $connection, true);
            $state = $this->freshInstalledMigrationState($migrationId, $connection, true);
            $currentOperationId = (string)($state[self::schema_fields_OPERATION_ID] ?? '');
            if ($currentOperationId !== $newOperationId) {
                if ($currentOperationId !== $expectedOperationId) {
                    throw $this->operationBindingFailure($migrationId);
                }
                $this->compareAndSwapOperationId(
                    $migrationId,
                    $expectedOperationId,
                    $newOperationId,
                    $connection,
                );
            }

            $verification = $this->freshInstalledMigrationState($migrationId, $connection, true);
            if ((string)($verification[self::schema_fields_OPERATION_ID] ?? '') !== $newOperationId) {
                throw $this->operationBindingFailure($migrationId);
            }
            $this->assertRecoveredOperation($expectedOperationId, $migrationId, $connection, true);
        });
    }

    public function releaseRecoveredOperationBindingsFailClosed(string $operationId): int
    {
        $this->assertOperationId($operationId, false);
        $connection = $this->getConnection();
        $transactions = ObjectManager::getInstance(TransactionCoordinator::class);

        return $transactions->run($connection, function () use ($connection, $operationId): int {
            $this->assertRecoveredOperation($operationId, 0, $connection, true);
            $locked = (clone $this)->reset()->setConnection($connection)
                ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
                ->where(self::schema_fields_OPERATION_ID, $operationId);
            $locked->additional('FOR UPDATE');
            $rows = $locked->select(self::schema_fields_ID)->fetchArray();

            $migrationIds = [];
            foreach ($rows as $row) {
                $migrationId = (int)($row[self::schema_fields_ID] ?? 0);
                if ($migrationId <= 0) {
                    throw new \RuntimeException('operation_id binding record is invalid');
                }
                $migrationIds[$migrationId] = $migrationId;
            }
            $migrationIds = array_values($migrationIds);
            sort($migrationIds, SORT_NUMERIC);

            if ($migrationIds !== []) {
                (clone $this)->reset()->setConnection($connection)
                    ->where(self::schema_fields_ID, $migrationIds, 'IN')
                    ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
                    ->where(self::schema_fields_OPERATION_ID, $operationId)
                    ->update([self::schema_fields_OPERATION_ID => ''])
                    ->fetch();

                $verification = (clone $this)->reset()->setConnection($connection)
                    ->where(self::schema_fields_ID, $migrationIds, 'IN');
                $verification->additional('FOR UPDATE');
                $verifiedRows = $verification->select(
                    implode(',', [
                        self::schema_fields_ID,
                        self::schema_fields_STATUS,
                        self::schema_fields_OPERATION_ID,
                    ])
                )->fetchArray();
                $verified = [];
                foreach ($verifiedRows as $row) {
                    $migrationId = (int)($row[self::schema_fields_ID] ?? 0);
                    if (!in_array($migrationId, $migrationIds, true)
                        || ($row[self::schema_fields_STATUS] ?? null) !== self::STATUS_INSTALLED
                        || (string)($row[self::schema_fields_OPERATION_ID] ?? '') !== '') {
                        throw $this->operationBindingFailure($migrationId);
                    }
                    $verified[$migrationId] = true;
                }
                if (count($verified) !== count($migrationIds)) {
                    throw $this->operationBindingFailure((int)($migrationIds[0] ?? 0));
                }
            }

            // This is deliberately inside the same transaction. Trigger-driven
            // or same-transaction owner mutations therefore roll the whole batch
            // back instead of leaving a partial release.
            $this->assertRecoveredOperation($operationId, 0, $connection, true);
            return count($migrationIds);
        });
    }

    private function compareAndSwapOperationId(
        int $migrationId,
        mixed $expectedOperationId,
        string $newOperationId,
        ?ConnectionFactory $connection = null,
    ): void {
        $connection ??= $this->getConnection();
        $update = (clone $this)->reset()->setConnection($connection)
            ->where(self::schema_fields_ID, $migrationId)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED);
        if ($expectedOperationId === null) {
            $update->where(self::schema_fields_OPERATION_ID, null, 'IS NULL');
        } else {
            $update->where(self::schema_fields_OPERATION_ID, (string)$expectedOperationId);
        }
        $update->update([self::schema_fields_OPERATION_ID => $newOperationId])->fetch();

        $verification = $this->freshInstalledMigrationState($migrationId, $connection);
        if ((string)($verification[self::schema_fields_OPERATION_ID] ?? '') !== $newOperationId) {
            throw $this->operationBindingFailure($migrationId);
        }
    }

    /** @return array<string, mixed> */
    private function freshInstalledMigrationState(
        int $migrationId,
        ?ConnectionFactory $connection = null,
        bool $forUpdate = false,
    ): array
    {
        $connection ??= $this->getConnection();
        $record = (clone $this)->reset()->setConnection($connection);
        $record->where(self::schema_fields_ID, $migrationId);
        if ($forUpdate) {
            $record->additional('FOR UPDATE');
        }
        $record->find()->fetch();
        if ((int)$record->getData(self::schema_fields_ID) !== $migrationId
            || $record->getData(self::schema_fields_STATUS) !== self::STATUS_INSTALLED) {
            throw $this->operationBindingFailure($migrationId);
        }

        return $record->getData();
    }

    private function assertRecoveredOperation(
        string $operationId,
        int $migrationId,
        ConnectionFactory $connection,
        bool $forUpdate = false,
    ): void
    {
        $operation = ObjectManager::getInstance(ModuleVersionOperation::class, [], false)
            ->reset()
            ->setConnection($connection);
        $operation->where(ModuleVersionOperation::schema_fields_OPERATION_ID, $operationId);
        if ($forUpdate) {
            $operation->additional('FOR UPDATE');
        }
        $operation->find()->fetch();
        if ((int)$operation->getData(ModuleVersionOperation::schema_fields_ID) <= 0
            || $operation->getData(ModuleVersionOperation::schema_fields_OPERATION_ID) !== $operationId
            || $operation->getData(ModuleVersionOperation::schema_fields_STATUS)
                !== ModuleVersionOperation::STATUS_FAILED_RECOVERED) {
            throw new \RuntimeException(
                "operation_id release requires failed_recovered owner: migration_id={$migrationId}"
            );
        }
    }

    private function assertOperationBindingRequest(
        int $migrationId,
        string $operationId,
        bool $allowEmpty,
    ): void {
        if ($migrationId <= 0) {
            throw new \InvalidArgumentException('invalid operation_id binding request');
        }
        $this->assertOperationId($operationId, $allowEmpty);
    }

    private function assertOperationId(string $operationId, bool $allowEmpty): void
    {
        if ($allowEmpty && $operationId === '') {
            return;
        }
        if (trim($operationId) !== $operationId
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $operationId) !== 1) {
            throw new \InvalidArgumentException('invalid operation_id binding request');
        }
    }

    private function operationBindingFailure(int $migrationId): \RuntimeException
    {
        return new \RuntimeException("operation_id binding persistence failed: migration_id={$migrationId}");
    }

    /** @return list<string> */
    public function getInstalledMigrationFiles(string $moduleName): array
    {
        $rows = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->where(self::schema_fields_FILE, '%.php', 'LIKE')
            ->select(self::schema_fields_FILE)
            ->fetchArray();

        $files = [];
        foreach ($rows as $row) {
            $file = trim((string)($row[self::schema_fields_FILE] ?? ''));
            if ($file !== '') {
                $files[$file] = true;
            }
        }
        $this->clearData();

        return array_keys($files);
    }

    public function deleteMigration(string $moduleName, string $migrationFile): bool
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($items as $item) {
            $item->delete();
        }

        return true;
    }
}
