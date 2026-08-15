<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Service;

use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalViewChangeInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableKeysetReaderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableSnapshotInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalViewMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewSnapshotInterface;
use Weline\Framework\Database\Connection\Api\Sql\PhysicalTableQueryInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Setup\Model\MigrationBackup;
use Weline\Framework\Output\Cli\Printing;

/**
 * 数据库迁移备份服务（Framework 内置）
 * 表名与 API 与 Weline\Database\Service\BackupService 兼容。
 *
 * @package Weline\Framework\Database\Service
 */
class BackupService
{
    private ConnectionFactory $connectionFactory;
    private MigrationBackup $backupModel;
    private Printing $printing;

    public const DEFAULT_CHUNK_SIZE = 1000;
    public const LARGE_TABLE_THRESHOLD = 10000;

    public function __construct(
        ConnectionFactory $connectionFactory,
        MigrationBackup $backupModel,
        Printing $printing
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->backupModel = $backupModel;
        $this->printing = $printing;
    }

    /**
     * Back up an exact catalog table. Unlike smartBackupTable(), this method
     * never resolves a logical name, adds a prefix, or changes a namespace.
     *
     * @return array{table:string,structure_backed_up:bool,data_backed_up:bool,strategy:string,total_rows:int}
     */
    public function smartBackupPhysicalTable(
        PhysicalTableIdentity $identity,
        int $migrationId,
        string $backupScope = MigrationBackup::SCOPE_UPGRADE,
        string $operationId = '',
        ?ConnectorInterface $physicalConnector = null,
    ): array {
        $connector = $this->requirePhysicalConnector($physicalConnector);
        if ($physicalConnector === null && $connector instanceof AtomicPhysicalTableChangeInterface) {
            return $connector->atomicPhysicalTableChange(
                $identity,
                fn(ConnectorInterface $lockedConnector): array => $this->smartBackupPhysicalTable(
                    $identity,
                    $migrationId,
                    $backupScope,
                    $operationId,
                    $lockedConnector,
                ),
            );
        }
        if ($physicalConnector !== null && $this->connectionFactory->getConnector() !== $connector) {
            throw new \RuntimeException('physical backup connector is not bound to its connection factory');
        }
        $canonical = $identity->canonical();
        $result = [
            'table' => $canonical,
            'structure_backed_up' => false,
            'data_backed_up' => false,
            'strategy' => 'none',
            'total_rows' => 0,
        ];

        $result['structure_backed_up'] = $this->backupPhysicalTableStructureUsing(
            $identity,
            $migrationId,
            $connector,
            $backupScope,
            $operationId,
        );
        if (!$result['structure_backed_up']) {
            throw new \RuntimeException('physical structure backup failed');
        }

        $rowCount = $this->getPhysicalTableRowCountUsing($identity, $connector);
        $result['total_rows'] = $rowCount;
        if ($rowCount === 0) {
            $result['strategy'] = 'empty';
            return $result;
        }

        if ($rowCount > self::LARGE_TABLE_THRESHOLD) {
            $this->backupPhysicalTableDataChunkedUsing(
                $identity,
                $migrationId,
                self::DEFAULT_CHUNK_SIZE,
                $connector,
                $backupScope,
                $operationId,
            );
            $result['strategy'] = 'chunked';
        } else {
            $this->backupPhysicalTableDataUsing(
                $identity,
                $migrationId,
                $connector,
                $backupScope,
                $operationId,
            );
            $result['strategy'] = 'full';
        }
        $result['data_backed_up'] = true;
        return $result;
    }

    public function getPhysicalTableRowCount(PhysicalTableIdentity $identity): int
    {
        return $this->getPhysicalTableRowCountUsing($identity, $this->requirePhysicalConnector());
    }

    public function beginPhysicalBackupOperation(
        PhysicalTableIdentity $identity,
        string $action,
        ?ConnectorInterface $physicalConnector = null,
    ): int {
        $action = strtolower(trim($action));
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $action) !== 1) {
            throw new \InvalidArgumentException('invalid physical backup operation action');
        }
        $connector = $this->requirePhysicalConnector($physicalConnector);
        if ($physicalConnector !== null && $this->connectionFactory->getConnector() !== $connector) {
            throw new \RuntimeException('physical backup connector is not bound to its connection factory');
        }
        $operationToken = 'schema-admin-' . bin2hex(random_bytes(16));
        $anchor = $this->savePhysicalBackup(
            $identity,
            0,
            [
                'action' => $action,
                'target' => $identity->canonical(),
                'operation_id' => $operationToken,
            ],
            MigrationBackup::TYPE_OPERATION,
            '',
            MigrationBackup::SCOPE_UPGRADE,
            $operationToken,
        );
        $operationId = (int)$anchor->getId();
        $anchor->setData(MigrationBackup::schema_fields_MIGRATION_ID, $operationId);
        $saved = $anchor->save();
        if ($saved !== true && (!is_int($saved) || $saved <= 0)) {
            throw new \RuntimeException('physical backup operation anchor update failed');
        }

        $verification = (clone $this->backupModel)
            ->setConnection($this->connectionFactory)
            ->reset();
        $verification->where(MigrationBackup::schema_fields_ID, $operationId)->find()->fetch();
        if ((int)$verification->getData(MigrationBackup::schema_fields_ID) !== $operationId
            || (int)$verification->getData(MigrationBackup::schema_fields_MIGRATION_ID) !== $operationId
            || $verification->getData(MigrationBackup::schema_fields_BACKUP_TYPE)
                !== MigrationBackup::TYPE_OPERATION
            || $verification->getData(MigrationBackup::schema_fields_TABLE_NAME) !== $identity->canonical()) {
            throw new \RuntimeException('physical backup operation anchor verification failed');
        }

        return $operationId;
    }

    public function beginPhysicalViewBackupOperation(
        PhysicalViewIdentity $identity,
        string $action,
        ?ConnectorInterface $physicalConnector = null,
    ): int {
        $action = strtolower(trim($action));
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $action) !== 1) {
            throw new \InvalidArgumentException('invalid physical view backup operation action');
        }
        $connector = $this->requirePhysicalViewConnector($physicalConnector);
        if ($physicalConnector !== null && $this->connectionFactory->getConnector() !== $connector) {
            throw new \RuntimeException('physical view backup connector is not bound to its connection factory');
        }
        $operationToken = 'schema-view-' . bin2hex(random_bytes(16));
        $anchor = $this->savePhysicalViewBackup(
            $identity,
            0,
            [
                'action' => $action,
                'target' => $identity->canonical(),
                'operation_id' => $operationToken,
            ],
            MigrationBackup::TYPE_OPERATION,
            MigrationBackup::SCOPE_UPGRADE,
            $operationToken,
        );
        $operationId = (int)$anchor->getId();
        $anchor->setData(MigrationBackup::schema_fields_MIGRATION_ID, $operationId);
        $saved = $anchor->save();
        if ($saved !== true && (!is_int($saved) || $saved <= 0)) {
            throw new \RuntimeException('physical view backup operation anchor update failed');
        }

        $verification = (clone $this->backupModel)
            ->setConnection($this->connectionFactory)
            ->reset();
        $verification->where(MigrationBackup::schema_fields_ID, $operationId)->find()->fetch();
        if ((int)$verification->getData(MigrationBackup::schema_fields_ID) !== $operationId
            || (int)$verification->getData(MigrationBackup::schema_fields_MIGRATION_ID) !== $operationId
            || $verification->getData(MigrationBackup::schema_fields_BACKUP_TYPE)
                !== MigrationBackup::TYPE_OPERATION
            || $verification->getData(MigrationBackup::schema_fields_TABLE_NAME) !== $identity->canonical()) {
            throw new \RuntimeException('physical view backup operation anchor verification failed');
        }
        return $operationId;
    }

    public function backupPhysicalViewDefinition(
        PhysicalViewIdentity $identity,
        int $migrationId,
        string $backupScope = MigrationBackup::SCOPE_UPGRADE,
        string $operationId = '',
        ?ConnectorInterface $physicalConnector = null,
    ): MigrationBackup {
        $connector = $this->requirePhysicalViewConnector($physicalConnector);
        if ($physicalConnector !== null && $this->connectionFactory->getConnector() !== $connector) {
            throw new \RuntimeException('physical view backup connector is not bound to its connection factory');
        }
        $payload = $connector instanceof PhysicalViewSnapshotInterface
            ? $connector->capturePhysicalViewSnapshot($identity)
            : [
                'existed' => $connector->physicalViewExists($identity),
                'definition' => $connector->physicalViewExists($identity)
                    ? $connector->getPhysicalViewDefinition($identity)
                    : '',
            ];
        return $this->savePhysicalViewBackup(
            $identity,
            $migrationId,
            $payload,
            MigrationBackup::TYPE_VIEW,
            $backupScope,
            $operationId,
        );
    }

    public function restorePhysicalViewDefinition(
        PhysicalViewIdentity $identity,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): bool {
        $backup = $this->getBackupData(
            $migrationId,
            $identity->canonical(),
            MigrationBackup::TYPE_VIEW,
            null,
            $backupScope,
            $operationId,
            $backupId,
        );
        if ($backup === null) {
            return true;
        }
        $payload = json_decode(
            (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA),
            true,
        );
        if (!is_array($payload) || !array_key_exists('existed', $payload)) {
            throw new \RuntimeException('physical view backup payload is invalid');
        }
        $existed = $payload['existed'] === true;
        $definition = trim((string)($payload['definition'] ?? ''));

        $connector = $this->requirePhysicalViewConnector();
        if (!$connector instanceof AtomicPhysicalViewChangeInterface) {
            throw new \RuntimeException('atomic physical view change capability unavailable');
        }
        return $connector->atomicPhysicalViewChange(
            $identity,
            function (ConnectorInterface $locked, bool $currentlyExists) use (
                $connector,
                $identity,
                $backup,
                $payload,
                $existed,
                $definition,
            ): bool {
                if ($locked !== $connector || !$locked instanceof PhysicalViewMetadataInterface) {
                    throw new \RuntimeException('atomic physical view connector changed during restore');
                }
                if (($payload['format'] ?? null) === 'weline.pg.view_snapshot.v1') {
                    if (!$locked instanceof PhysicalViewSnapshotInterface) {
                        throw new \RuntimeException('physical view snapshot restore capability unavailable');
                    }
                    $locked->restorePhysicalViewSnapshot($identity, $payload, $currentlyExists);
                } elseif ($existed) {
                    if (preg_match('/^(?:SELECT|WITH)\b/is', $definition) !== 1) {
                        throw new \RuntimeException('physical view backup definition is invalid');
                    }
                    $locked->createOrReplacePhysicalView($identity, $definition, $currentlyExists);
                } elseif ($currentlyExists) {
                    $locked->dropPhysicalViewIfExists($identity);
                }
                $this->markPhysicalBackupRestoredFailClosed($backup);
                return true;
            },
        );
    }

    public function backupPhysicalTableData(
        PhysicalTableIdentity $identity,
        int $migrationId,
        string $backupScope = MigrationBackup::SCOPE_UPGRADE,
        string $operationId = '',
    ): array {
        return $this->backupPhysicalTableDataUsing(
            $identity,
            $migrationId,
            $this->requirePhysicalConnector(),
            $backupScope,
            $operationId,
        );
    }

    public function backupPhysicalColumnData(
        PhysicalTableIdentity $identity,
        string $columnName,
        int $migrationId,
        ?string $modelClass = null,
        ?string $reason = null,
        ?string $backupScope = null,
        string $operationId = '',
        ?ConnectorInterface $physicalConnector = null,
    ): array {
        $connector = $this->requirePhysicalConnector($physicalConnector);
        if ($physicalConnector !== null && $this->connectionFactory->getConnector() !== $connector) {
            throw new \RuntimeException('physical column backup connector is not bound to its connection factory');
        }
        $columns = $connector->getPhysicalTableColumns($identity);
        $primaryKeys = $this->resolvePrimaryKeyColumnsFromMetadata($columns, $modelClass);
        $query = $this->physicalQuery($connector, $identity)
            ->fields(array_merge($primaryKeys, [$columnName]))
            ->where($columnName, null, 'IS NOT NULL')
            ->select();
        $data = $query->fetch();
        if (empty($data)) {
            return [];
        }

        $scope = $backupScope ?? (
            strtoupper(trim((string)$reason)) === 'ROLLBACK'
                ? MigrationBackup::SCOPE_ROLLBACK
                : MigrationBackup::SCOPE_UPGRADE
        );
        $this->savePhysicalBackup(
            $identity,
            $migrationId,
            $data,
            MigrationBackup::TYPE_COLUMN,
            $columnName,
            $scope,
            $operationId,
        );
        return $data;
    }

    public function backupPhysicalTableStructure(
        PhysicalTableIdentity $identity,
        int $migrationId,
        string $backupScope = MigrationBackup::SCOPE_UPGRADE,
        string $operationId = '',
        ?ConnectorInterface $physicalConnector = null,
    ): bool {
        $connector = $this->requirePhysicalConnector($physicalConnector);
        if ($physicalConnector !== null && $this->connectionFactory->getConnector() !== $connector) {
            throw new \RuntimeException('physical structure backup connector is not bound to its connection factory');
        }
        return $this->backupPhysicalTableStructureUsing(
            $identity,
            $migrationId,
            $connector,
            $backupScope,
            $operationId,
        );
    }

    /** @return array{table:string,total_rows:int,chunks:int,chunk_size:int} */
    public function backupPhysicalTableDataChunked(
        PhysicalTableIdentity $identity,
        int $migrationId,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        string $backupScope = MigrationBackup::SCOPE_UPGRADE,
        string $operationId = '',
    ): array {
        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('chunk size must be positive');
        }
        $connector = $this->requirePhysicalConnector();
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        if ($connector instanceof AtomicPhysicalTableChangeInterface) {
            return $connector->atomicPhysicalTableChange(
                $identity,
                fn(ConnectorInterface $lockedConnector): array => $this->backupPhysicalTableDataChunkedUsing(
                    $identity,
                    $migrationId,
                    $chunkSize,
                    $lockedConnector,
                    $backupScope,
                    $operationId,
                ),
            );
        }
        return $this->backupPhysicalTableDataChunkedUsing(
            $identity,
            $migrationId,
            $chunkSize,
            $connector,
            $backupScope,
            $operationId,
        );
    }

    public function restorePhysicalTableData(
        PhysicalTableIdentity $identity,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): bool {
        $connector = $this->requirePhysicalConnector();
        $backup = $this->getBackupData(
            $migrationId,
            $identity->canonical(),
            MigrationBackup::TYPE_TABLE,
            null,
            $backupScope,
            $operationId,
            $backupId,
        );
        if ($backup === null) {
            return true;
        }
        $rows = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
        if (!is_array($rows)) {
            throw new \RuntimeException('invalid physical table backup payload');
        }
        if ($rows === []) {
            $this->markPhysicalBackupRestoredFailClosed($backup);
            return true;
        }

        $snapshot = $this->physicalTableSnapshotForRestore(
            $identity,
            $migrationId,
            $backupScope,
            $operationId,
            $connector,
        );
        $this->physicalQuery($connector, $identity)->delete()->fetch();
        if ($connector instanceof PhysicalTableSnapshotInterface) {
            $connector->insertPhysicalTableSnapshotRows($identity, $rows, $snapshot);
            $connector->finalizePhysicalTableSnapshotRestore($identity, $snapshot);
        } else {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $this->physicalQuery($connector, $identity)->insert($row)->fetch();
                }
            }
        }
        $this->markPhysicalBackupRestoredFailClosed($backup);
        return true;
    }

    public function restorePhysicalColumnData(
        PhysicalTableIdentity $identity,
        string $columnName,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): bool {
        $connector = $this->requirePhysicalConnector();
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        $backup = $this->getBackupData(
            $migrationId,
            $identity->canonical(),
            MigrationBackup::TYPE_COLUMN,
            $columnName,
            $backupScope,
            $operationId,
            $backupId,
        );
        if ($backup === null) {
            return true;
        }
        $rows = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
        if (!is_array($rows)) {
            throw new \RuntimeException('invalid physical column backup payload');
        }
        if ($rows === []) {
            $this->markPhysicalBackupRestoredFailClosed($backup);
            return true;
        }
        $primaryKeys = $this->resolvePrimaryKeyColumnsFromMetadata(
            $connector->getPhysicalTableColumns($identity),
        );
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($columnName, $row)) {
                continue;
            }
            $update = $this->physicalQuery($connector, $identity);
            foreach ($primaryKeys as $primaryKey) {
                if (!array_key_exists($primaryKey, $row)) {
                    throw new \RuntimeException('physical column restore payload lacks complete primary key');
                }
                $update = $update->where($primaryKey, $row[$primaryKey]);
            }
            $update->update([$columnName => $row[$columnName]])->fetch();
        }
        $this->markPhysicalBackupRestoredFailClosed($backup);
        return true;
    }

    /** @return array{restored:int,unchanged:int,conflicts:int} */
    public function restorePhysicalColumnDataConflictSafe(
        PhysicalTableIdentity $identity,
        string $columnName,
        int $migrationId,
        mixed $defaultValue = null,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): array {
        unset($defaultValue);
        $result = ['restored' => 0, 'unchanged' => 0, 'conflicts' => 0];
        $connector = $this->requirePhysicalConnector();
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        $backup = $this->getBackupData(
            $migrationId,
            $identity->canonical(),
            MigrationBackup::TYPE_COLUMN,
            $columnName,
            $backupScope,
            $operationId,
            $backupId,
        );
        if ($backup === null) {
            return $result;
        }
        $rows = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
        if (!is_array($rows)) {
            throw new \RuntimeException('invalid physical column backup payload');
        }
        if ($rows === []) {
            $this->markPhysicalBackupRestoredFailClosed($backup);
            return $result;
        }
        $primaryKeys = $this->resolvePrimaryKeyColumnsFromMetadata(
            $connector->getPhysicalTableColumns($identity),
        );
        $conflicts = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($columnName, $row)) {
                continue;
            }
            $select = $this->physicalQuery($connector, $identity)
                ->fields(array_merge($primaryKeys, [$columnName]));
            foreach ($primaryKeys as $primaryKey) {
                if (!array_key_exists($primaryKey, $row)) {
                    $conflicts[] = ['reason' => 'missing_primary_key', 'backup' => $row];
                    $result['conflicts']++;
                    continue 2;
                }
                $select = $select->where($primaryKey, $row[$primaryKey]);
            }
            $currentRows = $select->limit(1)->select()->fetch();
            $current = $currentRows[0] ?? null;
            if (!is_array($current)) {
                $conflicts[] = ['reason' => 'row_missing', 'backup' => $row];
                $result['conflicts']++;
                continue;
            }
            $currentValue = $current[$columnName] ?? null;
            $backupValue = $row[$columnName];
            if ($currentValue === $backupValue || (string)$currentValue === (string)$backupValue) {
                $result['unchanged']++;
                continue;
            }
            if ($currentValue !== null && $currentValue !== '') {
                $conflicts[] = ['reason' => 'value_conflict', 'backup' => $row, 'current' => $current];
                $result['conflicts']++;
                continue;
            }
            $update = $this->physicalQuery($connector, $identity);
            foreach ($primaryKeys as $primaryKey) {
                $update = $update->where($primaryKey, $row[$primaryKey]);
            }
            $update->update([$columnName => $backupValue])->fetch();
            $result['restored']++;
        }
        $this->finishPhysicalConflictRestore($identity, $migrationId, $backup, $conflicts, $columnName);
        return $result;
    }

    /** @return array{restored:int,unchanged:int,conflicts:int} */
    public function restorePhysicalTableDataConflictSafe(
        PhysicalTableIdentity $identity,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): array {
        $result = ['restored' => 0, 'unchanged' => 0, 'conflicts' => 0];
        $connector = $this->requirePhysicalConnector();
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        if (!$connector->physicalTableExists($identity)) {
            throw new \RuntimeException('physical table restore target does not exist');
        }
        $backup = $this->getBackupData(
            $migrationId,
            $identity->canonical(),
            MigrationBackup::TYPE_TABLE,
            null,
            $backupScope,
            $operationId,
            $backupId,
        );
        if ($backup === null) {
            return $result;
        }
        $rows = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
        if (!is_array($rows) || $rows === []) {
            $this->markPhysicalBackupRestoredFailClosed($backup);
            return $result;
        }
        $columns = $connector->getPhysicalTableColumns($identity);
        $columnSet = array_fill_keys(array_map(
            static fn(array $column): string => strtolower((string)($column['name'] ?? '')),
            $columns,
        ), true);
        unset($columnSet['']);
        $primaryKeys = $this->resolvePrimaryKeyColumnsFromMetadata($columns);
        $conflicts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = array_filter(
                $row,
                static fn(mixed $value, string|int $column): bool => isset($columnSet[strtolower((string)$column)]),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($candidate === []) {
                $conflicts[] = ['reason' => 'no_compatible_columns', 'backup' => $row];
                $result['conflicts']++;
                continue;
            }
            foreach ($primaryKeys as $primaryKey) {
                if (!array_key_exists($primaryKey, $candidate)) {
                    $conflicts[] = ['reason' => 'missing_primary_key', 'backup' => $row];
                    $result['conflicts']++;
                    continue 2;
                }
            }
            $select = $this->physicalQuery($connector, $identity);
            foreach ($primaryKeys as $primaryKey) {
                $select = $select->where($primaryKey, $candidate[$primaryKey]);
            }
            $currentRows = $select->limit(1)->select()->fetch();
            $current = $currentRows[0] ?? null;
            if (!is_array($current)) {
                if ($connector instanceof PhysicalTableSnapshotInterface) {
                    $connector->insertPhysicalTableSnapshotRows(
                        $identity,
                        [$candidate],
                        $this->physicalTableSnapshotForRestore(
                            $identity,
                            $migrationId,
                            $backupScope,
                            $operationId,
                            $connector,
                        ),
                    );
                } else {
                    $this->physicalQuery($connector, $identity)->insert($candidate)->fetch();
                }
                $result['restored']++;
                continue;
            }
            if ($this->rowValuesEqual($candidate, $current)) {
                $result['unchanged']++;
                continue;
            }
            $conflicts[] = ['reason' => 'row_conflict', 'backup' => $candidate, 'current' => $current];
            $result['conflicts']++;
        }
        $this->finishPhysicalConflictRestore($identity, $migrationId, $backup, $conflicts);
        return $result;
    }

    public function restorePhysicalTableStructure(
        PhysicalTableIdentity $identity,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
    ): bool {
        $connector = $this->requirePhysicalConnector();
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        if ($connector->physicalTableExists($identity)) {
            throw new \RuntimeException('physical structure restore target already exists');
        }
        $backup = $this->getBackupData(
            $migrationId,
            $identity->canonical(),
            MigrationBackup::TYPE_STRUCTURE,
            null,
            $backupScope,
            $operationId,
        );
        if ($backup === null) {
            return false;
        }
        $ddl = trim((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA));
        if ($ddl === '') {
            return false;
        }
        $snapshot = json_decode($ddl, true);
        if (is_array($snapshot) && ($snapshot['format'] ?? null) === 'weline.pg.table_snapshot.v1') {
            if (!$connector instanceof PhysicalTableSnapshotInterface) {
                throw new \RuntimeException('physical table snapshot restore capability unavailable');
            }
            $connector->restorePhysicalTableSnapshot($identity, $snapshot);
        } else {
            $statements = str_contains($ddl, "\n-- WELINE_DDL_STATEMENT\n")
                ? explode("\n-- WELINE_DDL_STATEMENT\n", $ddl)
                : [$ddl];
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $connector->query($statement)->fetch();
                }
            }
        }
        if (!$connector->physicalTableExists($identity)) {
            throw new \RuntimeException('physical structure restore did not create expected table');
        }
        $this->markPhysicalBackupRestoredFailClosed($backup);
        return true;
    }

    public function restorePhysicalTableDataChunked(
        PhysicalTableIdentity $identity,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
    ): bool {
        $connector = $this->requirePhysicalConnector();
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        $query = (clone $this->backupModel)->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->where(MigrationBackup::schema_fields_TABLE_NAME, $identity->canonical())
            ->where(MigrationBackup::schema_fields_BACKUP_TYPE, MigrationBackup::TYPE_CHUNK);
        if ($backupScope !== null && $backupScope !== '') {
            $query = $query->where(
                MigrationBackup::schema_fields_BACKUP_SCOPE,
                $this->normalizeBackupScope($backupScope),
            );
        }
        if ($operationId !== null && $operationId !== '') {
            $query = $query->where(MigrationBackup::schema_fields_OPERATION_ID, $operationId);
        }
        $backups = $query->order(MigrationBackup::schema_fields_ID, 'ASC')->select()->fetch()->getItems();
        if ($backups === []) {
            return false;
        }

        $snapshot = $this->physicalTableSnapshotForRestore(
            $identity,
            $migrationId,
            $backupScope,
            $operationId,
            $connector,
        );
        $this->physicalQuery($connector, $identity)->delete()->fetch();
        $allRows = [];
        foreach ($backups as $backup) {
            $rows = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (!is_array($rows)) {
                throw new \RuntimeException('invalid physical chunk backup payload');
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $allRows[] = $row;
                }
            }
        }
        if ($connector instanceof PhysicalTableSnapshotInterface) {
            $connector->insertPhysicalTableSnapshotRows($identity, $allRows, $snapshot);
            $connector->finalizePhysicalTableSnapshotRestore($identity, $snapshot);
        } else {
            foreach ($allRows as $row) {
                $this->physicalQuery($connector, $identity)->insert($row)->fetch();
            }
        }
        foreach ($backups as $backup) {
            $this->markPhysicalBackupRestoredFailClosed($backup);
        }
        return true;
    }

    public function backupTableData(
        string $tableName,
        int $migrationId,
        string $backupScope = MigrationBackup::SCOPE_UPGRADE,
        string $operationId = '',
    ): array
    {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $query = $this->connectionFactory->getQuery()->clearQuery()->table($rawTable)->select();
            $data = $query->fetch();

            if (empty($data)) {
                $this->printing->info(__("表 %{1} 没有数据需要备份", $tableName));
                return [];
            }

            $backup = (clone $this->backupModel)->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_TABLE,
                MigrationBackup::schema_fields_BACKUP_SCOPE => $this->normalizeBackupScope($backupScope),
                MigrationBackup::schema_fields_OPERATION_ID => $operationId,
                MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ]);
            $this->assertBackupInserted($backup, $backup->save(), 'table');

            $this->printing->info(__("表 %{1} 数据备份完成，共 %{2} 条记录", [$tableName, count($data)]));
            return $data;
        } catch (\Exception $e) {
            $this->printing->error(__("备份表数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }

    /**
     * @param ConnectorInterface|null $connector 若提供则用其获取主键与查询（确保与 Schema 升级同一连接）
     * @param string|null $modelClass 模型类名，用于从 schema_primary_keys/schema_primary_key 解析主键（DB 查询失败时的回退）
     * @param string|null $reason 备份原因前缀，用于日志可读性，如 DROP/ADD/ALTER
     * @param string|null $backupScope upgrade 保存正向迁移前数据；rollback 保存模块回滚前数据
     * @param string $operationId 持久化回滚任务 ID；普通升级留空
     */
    public function backupColumnData(
        string $tableName,
        string $columnName,
        int $migrationId,
        ?ConnectorInterface $connector = null,
        ?string $modelClass = null,
        ?string $reason = null,
        ?string $backupScope = null,
        string $operationId = '',
    ): array {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $conn = $connector ?? $this->connectionFactory->getConnector();
            $pkCols = $this->resolvePrimaryKeyColumns($rawTable, $conn, $modelClass);
            $fields = array_merge($pkCols, [$columnName]);
            $query = $conn->getQuery()->clearQuery()
                ->table($rawTable)
                ->fields($fields)
                ->where($columnName, null, 'IS NOT NULL')
                ->select();
            $data = $query->fetch();

            if (empty($data)) {
                $prefix = $reason !== null && $reason !== '' ? "[{$reason}] " : '';
                $this->printing->info($prefix . __("表 %{1} 的列 %{2} 没有数据需要备份", [$tableName, $columnName]));
                return [];
            }

            $scope = $backupScope ?? (
                strtoupper(trim((string)$reason)) === 'ROLLBACK'
                    ? MigrationBackup::SCOPE_ROLLBACK
                    : MigrationBackup::SCOPE_UPGRADE
            );
            $backup = (clone $this->backupModel)->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode($data, JSON_UNESCAPED_UNICODE),
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_COLUMN,
                MigrationBackup::schema_fields_COLUMN_NAME => $columnName,
                MigrationBackup::schema_fields_BACKUP_SCOPE => $this->normalizeBackupScope($scope),
                MigrationBackup::schema_fields_OPERATION_ID => $operationId,
                MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ]);
            $this->assertBackupInserted($backup, $backup->save(), 'column');

            $prefix = $reason !== null && $reason !== '' ? "[{$reason}] " : '';
            $this->printing->info($prefix . __("表 %{1} 的列 %{2} 数据备份完成，共 %{3} 条记录", [$tableName, $columnName, count($data)]));
            return $data;
        } catch (\Exception $e) {
            $this->printing->error(__("备份列数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }

    public function restoreTableData(
        string $tableName,
        int $migrationId,
        bool $clearBeforeRestore = true,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): bool {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $query = $this->connectionFactory->getQuery();
            $backup = $this->getBackupData(
                $migrationId,
                $tableName,
                MigrationBackup::TYPE_TABLE,
                null,
                $backupScope,
                $operationId,
                $backupId,
            );
            if (empty($backup)) {
                $this->printing->warning(__("没有找到表 %{1} 的备份数据", $tableName));
                return true;
            }

            $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (empty($data)) {
                $this->printing->warning(__("表 %{1} 的备份数据为空", $tableName));
                return true;
            }

            if ($clearBeforeRestore) {
                $query->clearQuery()->table($rawTable)->delete()->fetch();
                $this->printing->info(__("表 %{1} 数据已清空", $tableName));
            }

            foreach ($data as $row) {
                $query->clearQuery()->table($rawTable)->insert($row)->fetch();
            }
            $this->backupModel->markRestored((int)$backup->getId());
            $this->printing->info(__("表 %{1} 数据恢复完成，共 %{2} 条记录", [$tableName, count($data)]));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复表数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function restoreColumnData(
        string $tableName,
        string $columnName,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): bool {
        try {
            $backup = $this->getBackupData(
                $migrationId,
                $tableName,
                MigrationBackup::TYPE_COLUMN,
                $columnName,
                $backupScope,
                $operationId,
                $backupId,
            );
            if (empty($backup)) {
                $this->printing->warning(__("没有找到表 %{1} 列 %{2} 的备份数据", [$tableName, $columnName]));
                return true;
            }

            $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (empty($data)) {
                $this->printing->warning(__("表 %{1} 列 %{2} 的备份数据为空", [$tableName, $columnName]));
                return true;
            }

            $rawTable = $this->toRawTableName($tableName);
            foreach ($data as $row) {
                $pkCols = $this->inferPrimaryKeyColumnsFromRow($row, $columnName);
                if ($pkCols === []) {
                    continue;
                }
                $query = $this->connectionFactory->getQuery()->clearQuery()->table($rawTable);
                foreach ($pkCols as $pkCol) {
                    $query = $query->where($pkCol, $row[$pkCol]);
                }
                $query->update([$columnName => $row[$columnName]])->fetch();
            }
            $this->backupModel->markRestored((int)$backup->getId());
            $this->printing->info(__("表 %{1} 列 %{2} 数据恢复完成，共 %{3} 条记录", [$tableName, $columnName, count($data)]));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复列数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    /**
     * Restore a column backup without overwriting values written after the
     * column was recreated. Conflicting and missing rows remain recoverable as
     * TYPE_CONFLICT backup records.
     *
     * @return array{restored: int, unchanged: int, conflicts: int}
     */
    public function restoreColumnDataConflictSafe(
        string $tableName,
        string $columnName,
        int $migrationId,
        ?ConnectorInterface $connector = null,
        ?string $modelClass = null,
        mixed $defaultValue = null,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): array {
        $result = ['restored' => 0, 'unchanged' => 0, 'conflicts' => 0];
        $backup = $this->getBackupData(
            $migrationId,
            $tableName,
            MigrationBackup::TYPE_COLUMN,
            $columnName,
            $backupScope,
            $operationId,
            $backupId,
        );
        if ($backup === null) {
            return $result;
        }

        $rows = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
        if (!is_array($rows) || $rows === []) {
            return $result;
        }

        $conn = $connector ?? $this->connectionFactory->getConnector();
        $rawTable = $this->toRawTableName($tableName);
        $conflicts = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($columnName, $row)) {
                continue;
            }
            $pkColumns = $this->inferPrimaryKeyColumnsFromRow($row, $columnName);
            if ($pkColumns === []) {
                $conflicts[] = ['reason' => 'missing_primary_key', 'backup' => $row];
                $result['conflicts']++;
                continue;
            }

            $select = $conn->getQuery()->clearQuery()->table($rawTable)->fields(array_merge($pkColumns, [$columnName]));
            foreach ($pkColumns as $primaryKey) {
                if (!array_key_exists($primaryKey, $row)) {
                    continue 2;
                }
                $select = $select->where($primaryKey, $row[$primaryKey]);
            }
            $currentRows = $select->limit(1)->select()->fetch();
            $current = $currentRows[0] ?? null;
            if (!is_array($current)) {
                $conflicts[] = ['reason' => 'row_missing', 'backup' => $row];
                $result['conflicts']++;
                continue;
            }

            $currentValue = $current[$columnName] ?? null;
            $backupValue = $row[$columnName];
            if ($currentValue === $backupValue || (string)$currentValue === (string)$backupValue) {
                $result['unchanged']++;
                continue;
            }
            $isEmptyTarget = $currentValue === null || $currentValue === '';
            if (!$isEmptyTarget) {
                $conflicts[] = [
                    'reason' => 'value_conflict',
                    'backup' => $row,
                    'current' => $current,
                ];
                $result['conflicts']++;
                continue;
            }

            $update = $conn->getQuery()->clearQuery()->table($rawTable);
            foreach ($pkColumns as $primaryKey) {
                $update = $update->where($primaryKey, $row[$primaryKey]);
            }
            $update->update([$columnName => $backupValue])->fetch();
            $result['restored']++;
        }

        if ($conflicts !== []) {
            (clone $this->backupModel)->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode(
                    ['column' => $columnName, 'items' => $conflicts],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
                ),
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_CONFLICT,
                MigrationBackup::schema_fields_COLUMN_NAME => $columnName,
                MigrationBackup::schema_fields_BACKUP_SCOPE => $this->normalizeBackupScope(
                    (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE)
                ),
                MigrationBackup::schema_fields_OPERATION_ID => (string)$backup->getData(
                    MigrationBackup::schema_fields_OPERATION_ID
                ),
                MigrationBackup::schema_fields_SOURCE_BACKUP_ID => (int)$backup->getId(),
                MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        } else {
            $this->backupModel->markRestored((int)$backup->getId());
        }

        $this->printing->info(__(
            '表 %{1} 列 %{2} 安全恢复完成：恢复 %{3}，无变化 %{4}，冲突 %{5}',
            [$tableName, $columnName, $result['restored'], $result['unchanged'], $result['conflicts']]
        ));

        return $result;
    }

    /**
     * Restore a table snapshot without overwriting rows that already exist in
     * the recreated schema. Missing rows are inserted; different rows with the
     * same primary key are retained and recorded as conflicts.
     *
     * @return array{restored: int, unchanged: int, conflicts: int}
     */
    public function restoreTableDataConflictSafe(
        string $tableName,
        int $migrationId,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): array {
        $result = ['restored' => 0, 'unchanged' => 0, 'conflicts' => 0];
        $backup = $this->getBackupData(
            $migrationId,
            $tableName,
            MigrationBackup::TYPE_TABLE,
            null,
            $backupScope,
            $operationId,
            $backupId,
        );
        if ($backup === null) {
            return $result;
        }

        $rows = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
        if (!is_array($rows) || $rows === []) {
            $this->backupModel->markRestored((int)$backup->getId());
            return $result;
        }

        $connector = $this->connectionFactory->getConnector();
        $rawTable = $this->toRawTableName($tableName);
        if (!$connector->tableExist($rawTable)) {
            throw new \RuntimeException(__('恢复表 %{1} 数据时目标表不存在', $tableName));
        }
        $columns = $connector->getTableColumns($rawTable);
        $columnNames = array_values(array_filter(array_map(
            static fn(array $column): string => (string)($column['name'] ?? ''),
            $columns,
        )));
        $columnSet = array_fill_keys(array_map('strtolower', $columnNames), true);
        $primaryKeys = $this->resolvePrimaryKeyColumns($rawTable, $connector);
        $conflicts = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = array_filter(
                $row,
                static fn(mixed $value, string|int $column): bool => isset($columnSet[strtolower((string)$column)]),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($candidate === []) {
                $conflicts[] = ['reason' => 'no_compatible_columns', 'backup' => $row];
                $result['conflicts']++;
                continue;
            }
            foreach ($primaryKeys as $primaryKey) {
                if (!array_key_exists($primaryKey, $candidate)) {
                    $conflicts[] = ['reason' => 'missing_primary_key', 'backup' => $row];
                    $result['conflicts']++;
                    continue 2;
                }
            }

            $select = $connector->getQuery()->clearQuery()->table($rawTable);
            foreach ($primaryKeys as $primaryKey) {
                $select = $select->where($primaryKey, $candidate[$primaryKey]);
            }
            $currentRows = $select->limit(1)->select()->fetch();
            $current = $currentRows[0] ?? null;
            if (!is_array($current)) {
                $connector->getQuery()->clearQuery()->table($rawTable)->insert($candidate)->fetch();
                $result['restored']++;
                continue;
            }
            if ($this->rowValuesEqual($candidate, $current)) {
                $result['unchanged']++;
                continue;
            }

            $conflicts[] = [
                'reason' => 'row_conflict',
                'backup' => $candidate,
                'current' => $current,
            ];
            $result['conflicts']++;
        }

        if ($conflicts !== []) {
            (clone $this->backupModel)->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => json_encode(
                    ['items' => $conflicts],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
                ),
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_CONFLICT,
                MigrationBackup::schema_fields_BACKUP_SCOPE => $this->normalizeBackupScope(
                    (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE)
                ),
                MigrationBackup::schema_fields_OPERATION_ID => (string)$backup->getData(
                    MigrationBackup::schema_fields_OPERATION_ID
                ),
                MigrationBackup::schema_fields_SOURCE_BACKUP_ID => (int)$backup->getId(),
                MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        } else {
            $this->backupModel->markRestored((int)$backup->getId());
        }

        $this->printing->info(__(
            '表 %{1} 安全恢复完成：恢复 %{2}，无变化 %{3}，冲突 %{4}',
            [$tableName, $result['restored'], $result['unchanged'], $result['conflicts']]
        ));
        return $result;
    }

    public function markBackupRestored(int $backupId): bool
    {
        $backup = (clone $this->backupModel)
            ->setConnection($this->connectionFactory)
            ->reset()
            ->where(MigrationBackup::schema_fields_ID, $backupId);
        $backup->find()->fetch();
        if ((int)$backup->getId() !== $backupId) {
            throw new \RuntimeException('physical backup restore marker target is missing');
        }
        $this->markPhysicalBackupRestoredFailClosed($backup);
        return true;
    }

    private function getBackupData(
        int $migrationId,
        string $tableName,
        string $backupType,
        ?string $columnName = null,
        ?string $backupScope = null,
        ?string $operationId = null,
        ?int $backupId = null,
    ): ?MigrationBackup {
        $query = (clone $this->backupModel)->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->where(MigrationBackup::schema_fields_TABLE_NAME, $tableName)
            ->where(MigrationBackup::schema_fields_BACKUP_TYPE, $backupType);
        if ($backupScope !== null && $backupScope !== '') {
            $query = $query->where(
                MigrationBackup::schema_fields_BACKUP_SCOPE,
                $this->normalizeBackupScope($backupScope)
            );
        }
        if ($operationId !== null && $operationId !== '') {
            $query = $query->where(MigrationBackup::schema_fields_OPERATION_ID, $operationId);
        }
        if ($backupId !== null && $backupId > 0) {
            $query = $query->where(MigrationBackup::schema_fields_ID, $backupId);
        }
        $items = $query
            ->order(MigrationBackup::schema_fields_ID, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
        foreach ($items as $item) {
            if (!$item instanceof MigrationBackup) {
                continue;
            }
            if ($columnName === null || $columnName === '') {
                return $item;
            }
            $storedColumn = trim((string)$item->getData(MigrationBackup::schema_fields_COLUMN_NAME));
            if ($storedColumn !== '' && strcasecmp($storedColumn, $columnName) === 0) {
                return $item;
            }
            // Compatibility for records created before column_name existed.
            if ($storedColumn === '' && $this->backupContainsColumn($item, $columnName)) {
                return $item;
            }
        }
        return null;
    }

    public function cleanupBackupData(int $migrationId): bool
    {
        try {
            $backups = $this->backupModel->reset()
                ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($backups as $backup) {
                $backup->delete();
            }
            $this->printing->info(__("迁移 %{1} 的备份数据清理完成", $migrationId));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("清理备份数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function restoreByBackupId(int $backupId): bool
    {
        try {
            $backup = (clone $this->backupModel)
                ->setConnection($this->connectionFactory)
                ->reset()
                ->where(MigrationBackup::schema_fields_ID, $backupId);
            $backup->find()->fetch();
            if ((int)$backup->getId() !== $backupId) {
                throw new \Exception(__("备份记录不存在: %{1}", (string) $backupId));
            }

            $tableName = $backup->getData(MigrationBackup::schema_fields_TABLE_NAME);
            $backupType = $backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            $migrationId = (int) $backup->getData(MigrationBackup::schema_fields_MIGRATION_ID);
            $backupScope = trim((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_SCOPE)) ?: null;
            $operationId = trim((string)$backup->getData(MigrationBackup::schema_fields_OPERATION_ID)) ?: null;
            $canonical = trim((string)$tableName);

            if ($backupType === MigrationBackup::TYPE_VIEW) {
                return $this->restorePhysicalViewDefinition(
                    PhysicalViewIdentity::fromCanonical($canonical),
                    $migrationId,
                    $backupScope,
                    $operationId,
                    $backupId,
                );
            }

            $physicalIdentity = null;
            if (str_contains($canonical, '.')) {
                try {
                    $physicalIdentity = PhysicalTableIdentity::fromCanonical($canonical);
                } catch (\InvalidArgumentException) {
                    $physicalIdentity = null;
                }
            }

            if ($physicalIdentity !== null && in_array($backupType, [
                MigrationBackup::TYPE_TABLE,
                MigrationBackup::TYPE_COLUMN,
                MigrationBackup::TYPE_STRUCTURE,
                MigrationBackup::TYPE_CHUNK,
            ], true)) {
                $connector = $this->requirePhysicalConnector();
                $this->assertNotBackupRepositoryTarget($physicalIdentity, $connector);
                if (!$connector instanceof AtomicPhysicalTableChangeInterface) {
                    throw new \RuntimeException('atomic physical table change capability unavailable');
                }
                $previewDigest = hash(
                    'sha256',
                    (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA),
                );
                return $connector->atomicPhysicalTableChange(
                    $physicalIdentity,
                    function (ConnectorInterface $lockedConnector) use (
                        $connector,
                        $physicalIdentity,
                        $backupId,
                        $backupType,
                        $migrationId,
                        $backupScope,
                        $operationId,
                        $canonical,
                        $previewDigest,
                    ): bool {
                        if ($lockedConnector !== $connector
                            || $this->connectionFactory->getConnector() !== $lockedConnector) {
                            throw new \RuntimeException('physical restore connector changed');
                        }
                        $fresh = (clone $this->backupModel)
                            ->setConnection($this->connectionFactory)
                            ->reset();
                        $fresh->where(MigrationBackup::schema_fields_ID, $backupId)->find()->fetch();
                        if ((int)$fresh->getData(MigrationBackup::schema_fields_ID) !== $backupId
                            || (int)$fresh->getData(MigrationBackup::schema_fields_MIGRATION_ID) !== $migrationId
                            || (string)$fresh->getData(MigrationBackup::schema_fields_TABLE_NAME) !== $canonical
                            || (string)$fresh->getData(MigrationBackup::schema_fields_BACKUP_TYPE) !== $backupType
                            || !hash_equals(
                                $previewDigest,
                                hash('sha256', (string)$fresh->getData(MigrationBackup::schema_fields_BACKUP_DATA)),
                            )) {
                            throw new \RuntimeException('physical backup changed before restore lock');
                        }

                        $restored = match ($backupType) {
                            MigrationBackup::TYPE_TABLE => $this->restorePhysicalTableData(
                                $physicalIdentity,
                                $migrationId,
                                $backupScope,
                                $operationId,
                                $backupId,
                            ),
                            MigrationBackup::TYPE_COLUMN => $this->restorePhysicalColumnData(
                                $physicalIdentity,
                                $this->requiredBackupColumn($fresh),
                                $migrationId,
                                $backupScope,
                                $operationId,
                                $backupId,
                            ),
                            MigrationBackup::TYPE_STRUCTURE => $lockedConnector->physicalTableExists($physicalIdentity)
                                ? $this->markExistingPhysicalStructureRestored($fresh)
                                : $this->restorePhysicalTableStructure(
                                    $physicalIdentity,
                                    $migrationId,
                                    $backupScope,
                                    $operationId,
                                ),
                            MigrationBackup::TYPE_CHUNK => $this->restorePhysicalTableDataChunked(
                                $physicalIdentity,
                                $migrationId,
                                $backupScope,
                                $operationId,
                            ),
                            default => false,
                        };
                        if (!$restored) {
                            throw new \RuntimeException('physical backup restore failed');
                        }
                        return true;
                    },
                );
            }

            if ($physicalIdentity !== null && $backupType === MigrationBackup::TYPE_TABLE) {
                return $this->restorePhysicalTableData(
                    $physicalIdentity,
                    $migrationId,
                    $backupScope,
                    $operationId,
                    $backupId,
                );
            }

            if ($physicalIdentity !== null && $backupType === MigrationBackup::TYPE_COLUMN) {
                $column = trim((string)$backup->getData(MigrationBackup::schema_fields_COLUMN_NAME));
                if ($column === '') {
                    throw new \RuntimeException('physical column backup lacks column identity');
                }
                return $this->restorePhysicalColumnData(
                    $physicalIdentity,
                    $column,
                    $migrationId,
                    $backupScope,
                    $operationId,
                    $backupId,
                );
            }

            if ($physicalIdentity !== null && $backupType === MigrationBackup::TYPE_STRUCTURE) {
                $connector = $this->requirePhysicalConnector();
                if ($connector->physicalTableExists($physicalIdentity)) {
                    return $this->markBackupRestored($backupId);
                }
                return $this->restorePhysicalTableStructure(
                    $physicalIdentity,
                    $migrationId,
                    $backupScope,
                    $operationId,
                );
            }

            if ($physicalIdentity !== null && $backupType === MigrationBackup::TYPE_CHUNK) {
                return $this->restorePhysicalTableDataChunked(
                    $physicalIdentity,
                    $migrationId,
                    $backupScope,
                    $operationId,
                );
            }

            if ($backupType === MigrationBackup::TYPE_TABLE) {
                return $this->restoreTableData(
                    $tableName,
                    $migrationId,
                    true,
                    $backupScope,
                    $operationId,
                    $backupId,
                );
            }

            if ($backupType === MigrationBackup::TYPE_COLUMN) {
                $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
                if (!empty($data) && is_array($data)) {
                    $firstRow = reset($data);
                    $conn = $this->connectionFactory->getConnector();
                    $pkCols = $this->resolvePrimaryKeyColumns($this->toRawTableName($tableName), $conn, null);
                    $pkSet = array_fill_keys(array_map('strtolower', $pkCols), true);
                    $columns = array_filter(
                        array_keys($firstRow),
                        fn (string $c) => !isset($pkSet[strtolower($c)])
                    );
                    foreach ($columns as $column) {
                        $this->restoreColumnData(
                            $tableName,
                            $column,
                            $migrationId,
                            $backupScope,
                            $operationId,
                            $backupId,
                        );
                    }
                }
                return true;
            }

            if ($backupType === MigrationBackup::TYPE_STRUCTURE) {
                $rawTable = $this->toRawTableName((string)$tableName);
                if ($this->connectionFactory->getConnector()->tableExist($rawTable)) {
                    return $this->backupModel->markRestored($backupId);
                }
                return $this->restoreTableStructure(
                    (string)$tableName,
                    $migrationId,
                    false,
                    $backupScope,
                    $operationId,
                );
            }

            $this->printing->warning(__("未知的备份类型: %{1}", $backupType));
            return false;
        } catch (\Throwable $e) {
            $this->printing->error(__("按 backup_id 恢复失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    /** @return MigrationBackup[] */
    public function getBackupsByMigrationId(int $migrationId): array
    {
        return $this->backupModel->getMigrationBackups($migrationId);
    }

    public function getBackupStats(int $migrationId): array
    {
        $backups = $this->backupModel->reset()
            ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
            ->select()
            ->fetch()
            ->getItems();
        $stats = [
            'total' => count($backups),
            'tables' => 0,
            'columns' => 0,
            'structures' => 0,
            'chunks' => 0,
            'total_records' => 0
        ];

        foreach ($backups as $backup) {
            $backupType = $backup->getData(MigrationBackup::schema_fields_BACKUP_TYPE);
            switch ($backupType) {
                case MigrationBackup::TYPE_TABLE:
                    $stats['tables']++;
                    break;
                case MigrationBackup::TYPE_COLUMN:
                    $stats['columns']++;
                    break;
                case MigrationBackup::TYPE_STRUCTURE:
                    $stats['structures']++;
                    break;
                case MigrationBackup::TYPE_CHUNK:
                    $stats['chunks']++;
                    break;
            }
            $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
            if (is_array($data)) {
                $stats['total_records'] += count($data);
            }
        }
        return $stats;
    }

    public function backupTableStructure(
        string $tableName,
        int $migrationId,
        string $backupScope = MigrationBackup::SCOPE_UPGRADE,
        string $operationId = '',
    ): bool {
        try {
            $connector = $this->connectionFactory->getConnector();
            $rawTable = $this->toRawTableName($tableName);
            $ddl = $connector->getCreateTableSql($rawTable);
            if (empty($ddl)) {
                $this->printing->warning(__("表 %{1} 的 DDL 为空", $tableName));
                return false;
            }

            $backup = (clone $this->backupModel)->reset()->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $tableName,
                MigrationBackup::schema_fields_BACKUP_DATA => $ddl,
                MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_STRUCTURE,
                MigrationBackup::schema_fields_BACKUP_SCOPE => $this->normalizeBackupScope($backupScope),
                MigrationBackup::schema_fields_OPERATION_ID => $operationId,
                MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
            ]);
            $this->assertBackupInserted($backup, $backup->save(), 'structure');

            $this->printing->info(__("表 %{1} 结构备份完成", $tableName));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("备份表结构失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function restoreTableStructure(
        string $tableName,
        int $migrationId,
        bool $dropIfExists = false,
        ?string $backupScope = null,
        ?string $operationId = null,
    ): bool {
        try {
            $connection = $this->connectionFactory->getConnector();
            $backup = $this->getBackupData(
                $migrationId,
                $tableName,
                MigrationBackup::TYPE_STRUCTURE,
                null,
                $backupScope,
                $operationId,
            );
            if (empty($backup)) {
                $this->printing->warning(__("没有找到表 %{1} 的结构备份", $tableName));
                return false;
            }

            $ddl = $backup->getData(MigrationBackup::schema_fields_BACKUP_DATA);
            if (empty($ddl)) {
                $this->printing->warning(__("表 %{1} 的结构备份为空", $tableName));
                return false;
            }

            if ($dropIfExists) {
                $rawTable = $this->toRawTableName($tableName);
                $connection->dropTableIfExists($rawTable);
            }
            $statements = str_contains($ddl, "\n-- WELINE_DDL_STATEMENT\n")
                ? explode("\n-- WELINE_DDL_STATEMENT\n", $ddl)
                : [$ddl];
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $connection->query($statement)->fetch();
                }
            }
            $this->backupModel->markRestored((int)$backup->getId());
            $this->printing->info(__("表 %{1} 结构恢复完成", $tableName));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复表结构失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function backupTableDataChunked(string $tableName, int $migrationId, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        try {
            $offset = 0;
            $totalRows = 0;
            $chunkIndex = 0;

            $rawTable = $this->toRawTableName($tableName);
            while (true) {
                $query = $this->connectionFactory->getQuery()->clearQuery()
                    ->table($rawTable)
                    ->limit($chunkSize, $offset)
                    ->select();
                $chunk = $query->fetch();

                if (empty($chunk)) {
                    break;
                }
                $this->saveBackupChunk($tableName, $chunk, $migrationId, $chunkIndex);
                $totalRows += count($chunk);
                $offset += $chunkSize;
                $chunkIndex++;
                unset($chunk);
                gc_collect_cycles();
            }

            $this->printing->info(__("表 %{1} 分批备份完成，共 %{2} 条记录，%{3} 个分块", [$tableName, $totalRows, $chunkIndex]));
            return [
                'table' => $tableName,
                'total_rows' => $totalRows,
                'chunks' => $chunkIndex,
                'chunk_size' => $chunkSize,
            ];
        } catch (\Exception $e) {
            $this->printing->error(__("分批备份表数据失败: %{1}", $e->getMessage()));
            throw $e;
        }
    }

    /**
     * 从 connector 解析主键列名（支持单主键与复合主键）。
     * 优先用 connector->getTableColumns 获取当前表的主键；无主键时从实际存在的列中选取：
     * 1) modelClass.schema_primary_key 仅当该列存在于表中时使用；
     * 2) 否则尝试 'id'；
     * 3) 否则用第一列（用于无主键表如 m_cache）。
     *
     * @return list<string>
     */
    private function resolvePrimaryKeyColumns(string $rawTable, ConnectorInterface $connector, ?string $modelClass = null): array
    {
        $columns = $connector->getTableColumns($rawTable);
        $pkCols = [];
        $allNames = [];
        foreach ($columns as $col) {
            $name = $col['name'] ?? '';
            if ($name !== '') {
                $allNames[] = $name;
                if (!empty($col['primary_key'])) {
                    $pkCols[] = $name;
                }
            }
        }
        if ($pkCols !== []) {
            return $pkCols;
        }
        $colSet = array_fill_keys(array_map('strtolower', $allNames), true);
        $exists = fn (string $c) => isset($colSet[strtolower($c)]);
        if ($modelClass !== null && class_exists($modelClass)) {
            if (defined($modelClass . '::schema_primary_key') && $exists((string) $modelClass::schema_primary_key)) {
                return [$modelClass::schema_primary_key];
            }
            if (defined($modelClass . '::schema_primary_keys')) {
                $pks = $modelClass::schema_primary_keys;
                if (is_array($pks)) {
                    $valid = array_filter($pks, fn ($c) => is_string($c) && $exists($c));
                    if ($valid !== []) {
                        return array_values($valid);
                    }
                }
            }
        }
        if ($exists('id')) {
            return ['id'];
        }
        return $allNames !== [] ? [reset($allNames)] : ['id'];
    }

    /**
     * 从备份行推断主键列名（该行仅含 pk 与被备份列）。
     *
     * @return list<string>
     */
    private function inferPrimaryKeyColumnsFromRow(array $row, string $columnName): array
    {
        $result = [];
        foreach (array_keys($row) as $k) {
            if (strcasecmp((string) $k, $columnName) !== 0) {
                $result[] = $k;
            }
        }
        return $result;
    }

    private function backupContainsColumn(MigrationBackup $backup, string $columnName): bool
    {
        $decoded = json_decode((string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
        if (!is_array($decoded) || $decoded === []) {
            return false;
        }
        if ((string)($decoded['column'] ?? '') === $columnName) {
            return true;
        }
        $first = reset($decoded);
        return is_array($first) && array_key_exists($columnName, $first);
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private function rowValuesEqual(array $expected, array $actual): bool
    {
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $actual)) {
                return false;
            }
            $current = $actual[$column];
            if ($current === $value) {
                continue;
            }
            if ($current === null || $value === null || (string)$current !== (string)$value) {
                return false;
            }
        }
        return true;
    }

    private function normalizeBackupScope(string $backupScope): string
    {
        return $backupScope === MigrationBackup::SCOPE_ROLLBACK
            ? MigrationBackup::SCOPE_ROLLBACK
            : MigrationBackup::SCOPE_UPGRADE;
    }

    /** @return ConnectorInterface&PhysicalTableMetadataInterface */
    private function requirePhysicalConnector(?ConnectorInterface $connector = null): ConnectorInterface
    {
        $connector ??= $this->connectionFactory->getConnector();
        if (!$connector instanceof PhysicalTableMetadataInterface) {
            throw new \RuntimeException('exact physical table capability unavailable');
        }
        return $connector;
    }

    /** @return ConnectorInterface&PhysicalViewMetadataInterface */
    private function requirePhysicalViewConnector(?ConnectorInterface $connector = null): ConnectorInterface
    {
        $connector ??= $this->connectionFactory->getConnector();
        if (!$connector instanceof PhysicalViewMetadataInterface) {
            throw new \RuntimeException('exact physical view capability unavailable');
        }
        return $connector;
    }

    private function physicalQuery(ConnectorInterface $connector, PhysicalTableIdentity $identity): QueryInterface
    {
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        $query = $connector->getQuery();
        if (!$query instanceof PhysicalTableQueryInterface) {
            throw new \RuntimeException('exact physical table query capability unavailable');
        }
        $query->clearQuery();
        return $query->tablePhysical($identity);
    }

    private function getPhysicalTableRowCountUsing(
        PhysicalTableIdentity $identity,
        ConnectorInterface $connector,
    ): int {
        return (int)$this->physicalQuery($connector, $identity)->total();
    }

    private function backupPhysicalTableDataUsing(
        PhysicalTableIdentity $identity,
        int $migrationId,
        ConnectorInterface $connector,
        string $backupScope,
        string $operationId,
    ): array {
        $data = $this->physicalQuery($connector, $identity)->select()->fetch();
        if (empty($data)) {
            return [];
        }
        $this->savePhysicalBackup(
            $identity,
            $migrationId,
            $data,
            MigrationBackup::TYPE_TABLE,
            '',
            $backupScope,
            $operationId,
        );
        return $data;
    }

    private function backupPhysicalTableStructureUsing(
        PhysicalTableIdentity $identity,
        int $migrationId,
        ConnectorInterface $connector,
        string $backupScope,
        string $operationId,
    ): bool {
        if (!$connector instanceof PhysicalTableMetadataInterface) {
            throw new \RuntimeException('exact physical table capability unavailable');
        }
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        $payload = $connector instanceof PhysicalTableSnapshotInterface
            ? $connector->capturePhysicalTableSnapshot($identity)
            : trim($connector->getPhysicalCreateTableSql($identity));
        if ($payload === '' || (is_array($payload) && empty($payload['existed']))) {
            return false;
        }
        $this->savePhysicalBackup(
            $identity,
            $migrationId,
            $payload,
            MigrationBackup::TYPE_STRUCTURE,
            '',
            $backupScope,
            $operationId,
        );
        return true;
    }

    /** @return array{table:string,total_rows:int,chunks:int,chunk_size:int} */
    private function backupPhysicalTableDataChunkedUsing(
        PhysicalTableIdentity $identity,
        int $migrationId,
        int $chunkSize,
        ConnectorInterface $connector,
        string $backupScope,
        string $operationId,
    ): array {
        if (!$connector instanceof PhysicalTableKeysetReaderInterface) {
            throw new \RuntimeException('physical table keyset capability unavailable');
        }
        if (!$connector instanceof PhysicalTableMetadataInterface) {
            throw new \RuntimeException('exact physical table capability unavailable');
        }
        $this->assertNotBackupRepositoryTarget($identity, $connector);
        $primaryKeys = $this->resolveCompletePrimaryKeyColumnsFromMetadata(
            $connector->getPhysicalTableColumns($identity),
        );
        if ($primaryKeys === []) {
            throw new \RuntimeException('physical chunk backup requires a complete primary key');
        }

        $afterPrimaryKey = null;
        $totalRows = 0;
        $chunks = 0;
        while (true) {
            $chunk = $connector->readPhysicalTableKeysetChunk(
                $identity,
                $primaryKeys,
                $afterPrimaryKey,
                $chunkSize,
            );
            if (empty($chunk)) {
                break;
            }
            $this->savePhysicalBackup(
                $identity,
                $migrationId,
                $chunk,
                MigrationBackup::TYPE_CHUNK,
                '',
                $backupScope,
                $operationId,
            );
            $count = count($chunk);
            $totalRows += $count;
            $chunks++;
            $last = $chunk[$count - 1] ?? null;
            if (!is_array($last)) {
                throw new \RuntimeException('physical chunk backup returned an invalid row');
            }
            $afterPrimaryKey = [];
            foreach ($primaryKeys as $primaryKey) {
                if (!array_key_exists($primaryKey, $last)) {
                    throw new \RuntimeException('physical chunk backup row lacks primary key');
                }
                if ($last[$primaryKey] === null) {
                    throw new \RuntimeException('physical chunk backup primary key is null');
                }
                $afterPrimaryKey[$primaryKey] = $last[$primaryKey];
            }
            unset($chunk);
            if ($count < $chunkSize) {
                break;
            }
        }
        return [
            'table' => $identity->canonical(),
            'total_rows' => $totalRows,
            'chunks' => $chunks,
            'chunk_size' => $chunkSize,
        ];
    }

    private function savePhysicalBackup(
        PhysicalTableIdentity $identity,
        int $migrationId,
        array|string $payload,
        string $backupType,
        string $columnName,
        string $backupScope,
        string $operationId,
        int $sourceBackupId = 0,
    ): MigrationBackup {
        $encoded = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : $payload;
        $backup = (clone $this->backupModel)
            ->setConnection($this->connectionFactory)
            ->reset()
            ->setData([
            MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
            MigrationBackup::schema_fields_TABLE_NAME => $identity->canonical(),
            MigrationBackup::schema_fields_BACKUP_DATA => $encoded,
            MigrationBackup::schema_fields_BACKUP_TYPE => $backupType,
            MigrationBackup::schema_fields_COLUMN_NAME => $columnName,
            MigrationBackup::schema_fields_BACKUP_SCOPE => $this->normalizeBackupScope($backupScope),
            MigrationBackup::schema_fields_OPERATION_ID => $operationId,
            MigrationBackup::schema_fields_SOURCE_BACKUP_ID => $sourceBackupId,
            MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
            MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ]);
        $this->assertBackupInserted($backup, $backup->save(), $backupType);
        return $backup;
    }

    private function savePhysicalViewBackup(
        PhysicalViewIdentity $identity,
        int $migrationId,
        array|string $payload,
        string $backupType,
        string $backupScope,
        string $operationId,
    ): MigrationBackup {
        $encoded = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : $payload;
        $backup = (clone $this->backupModel)
            ->setConnection($this->connectionFactory)
            ->reset()
            ->setData([
                MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
                MigrationBackup::schema_fields_TABLE_NAME => $identity->canonical(),
                MigrationBackup::schema_fields_BACKUP_DATA => $encoded,
                MigrationBackup::schema_fields_BACKUP_TYPE => $backupType,
                MigrationBackup::schema_fields_COLUMN_NAME => '',
                MigrationBackup::schema_fields_BACKUP_SCOPE => $this->normalizeBackupScope($backupScope),
                MigrationBackup::schema_fields_OPERATION_ID => $operationId,
                MigrationBackup::schema_fields_SOURCE_BACKUP_ID => 0,
                MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
                MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ]);
        $this->assertBackupInserted($backup, $backup->save(), $backupType);
        return $backup;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return list<string>
     */
    private function resolveCompletePrimaryKeyColumnsFromMetadata(array $columns): array
    {
        $primaryKeys = [];
        foreach ($columns as $column) {
            $name = trim((string)($column['name'] ?? ''));
            if ($name !== '' && !empty($column['primary_key'])) {
                $primaryKeys[] = $name;
            }
        }
        return $primaryKeys;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return list<string>
     */
    private function resolvePrimaryKeyColumnsFromMetadata(array $columns, ?string $modelClass = null): array
    {
        unset($modelClass);
        $primaryKeys = $this->resolveCompletePrimaryKeyColumnsFromMetadata($columns);
        if ($primaryKeys === []) {
            throw new \RuntimeException('physical column backup requires a complete primary key');
        }
        return $primaryKeys;
    }

    /** @param list<array<string, mixed>> $conflicts */
    private function finishPhysicalConflictRestore(
        PhysicalTableIdentity $identity,
        int $migrationId,
        MigrationBackup $source,
        array $conflicts,
        string $columnName = '',
    ): void {
        if ($conflicts === []) {
            $this->markPhysicalBackupRestoredFailClosed($source);
            return;
        }
        $payload = $columnName === ''
            ? ['items' => $conflicts]
            : ['column' => $columnName, 'items' => $conflicts];
        $this->savePhysicalBackup(
            $identity,
            $migrationId,
            $payload,
            MigrationBackup::TYPE_CONFLICT,
            $columnName,
            (string)$source->getData(MigrationBackup::schema_fields_BACKUP_SCOPE),
            (string)$source->getData(MigrationBackup::schema_fields_OPERATION_ID),
            (int)$source->getId(),
        );
    }

    private function assertBackupInserted(MigrationBackup $backup, mixed $saved, string $backupType): void
    {
        if (!is_int($saved)
            || $saved <= 0
            || (string)$backup->getId() !== (string)$saved) {
            throw new \RuntimeException("{$backupType} backup persistence failed");
        }
        $verification = (clone $this->backupModel)
            ->setConnection($this->connectionFactory)
            ->reset();
        $verification->where(MigrationBackup::schema_fields_ID, $saved)->find()->fetch();
        $fields = [
            MigrationBackup::schema_fields_ID,
            MigrationBackup::schema_fields_MIGRATION_ID,
            MigrationBackup::schema_fields_TABLE_NAME,
            MigrationBackup::schema_fields_BACKUP_TYPE,
            MigrationBackup::schema_fields_COLUMN_NAME,
            MigrationBackup::schema_fields_BACKUP_SCOPE,
            MigrationBackup::schema_fields_OPERATION_ID,
            MigrationBackup::schema_fields_SOURCE_BACKUP_ID,
        ];
        foreach ($fields as $field) {
            if ((string)$verification->getData($field) !== (string)$backup->getData($field)) {
                throw new \RuntimeException("{$backupType} backup persistence verification failed");
            }
        }
        $expectedPayload = hash('sha256', (string)$backup->getData(MigrationBackup::schema_fields_BACKUP_DATA));
        $actualPayload = hash('sha256', (string)$verification->getData(MigrationBackup::schema_fields_BACKUP_DATA));
        if (!hash_equals($expectedPayload, $actualPayload)) {
            throw new \RuntimeException("{$backupType} backup persistence payload verification failed");
        }
    }

    private function assertNotBackupRepositoryTarget(
        PhysicalTableIdentity $identity,
        ConnectorInterface $connector,
    ): void {
        if (!$connector instanceof PhysicalTableIdentityProviderInterface) {
            throw new \RuntimeException('exact physical table identity provider unavailable');
        }
        $repository = $connector->resolvePhysicalTableIdentity(MigrationBackup::schema_table);
        if ($repository->canonical() === $identity->canonical()) {
            throw new \RuntimeException('physical backup repository cannot be its own backup target');
        }
    }

    private function markPhysicalBackupRestoredFailClosed(MigrationBackup $backup): void
    {
        $backupId = (int)$backup->getId();
        if ($backupId <= 0 || !$this->backupModel->markRestored($backupId)) {
            throw new \RuntimeException('physical backup restore marker failed');
        }
        $verification = (clone $this->backupModel)
            ->setConnection($this->connectionFactory)
            ->reset();
        $verification->where(MigrationBackup::schema_fields_ID, $backupId)->find()->fetch();
        foreach ([
            MigrationBackup::schema_fields_ID,
            MigrationBackup::schema_fields_MIGRATION_ID,
            MigrationBackup::schema_fields_TABLE_NAME,
            MigrationBackup::schema_fields_BACKUP_TYPE,
            MigrationBackup::schema_fields_BACKUP_SCOPE,
            MigrationBackup::schema_fields_OPERATION_ID,
        ] as $field) {
            if ((string)$verification->getData($field) !== (string)$backup->getData($field)) {
                throw new \RuntimeException('physical backup restore marker identity mismatch');
            }
        }
        if ($verification->getData(MigrationBackup::schema_fields_RETENTION_STATE)
                !== MigrationBackup::RETENTION_EXPIRING
            || trim((string)$verification->getData(MigrationBackup::schema_fields_RESTORED_AT)) === '') {
            throw new \RuntimeException('physical backup restore marker verification failed');
        }
    }

    private function requiredBackupColumn(MigrationBackup $backup): string
    {
        $column = trim((string)$backup->getData(MigrationBackup::schema_fields_COLUMN_NAME));
        if ($column === '') {
            throw new \RuntimeException('physical column backup lacks column identity');
        }
        return $column;
    }

    private function markExistingPhysicalStructureRestored(MigrationBackup $backup): bool
    {
        $this->markPhysicalBackupRestoredFailClosed($backup);
        return true;
    }

    /** @return array<string, mixed> */
    private function physicalTableSnapshotForRestore(
        PhysicalTableIdentity $identity,
        int $migrationId,
        ?string $backupScope,
        ?string $operationId,
        ConnectorInterface $connector,
    ): array {
        if (!$connector instanceof PhysicalTableSnapshotInterface) {
            return [];
        }
        $structure = $this->getBackupData(
            $migrationId,
            $identity->canonical(),
            MigrationBackup::TYPE_STRUCTURE,
            null,
            $backupScope,
            $operationId,
        );
        if ($structure !== null) {
            $snapshot = json_decode(
                (string)$structure->getData(MigrationBackup::schema_fields_BACKUP_DATA),
                true,
            );
            if (is_array($snapshot) && ($snapshot['format'] ?? null) === 'weline.pg.table_snapshot.v1') {
                return $snapshot;
            }
        }
        $snapshot = $connector->capturePhysicalTableSnapshot($identity);
        if (($snapshot['format'] ?? null) !== 'weline.pg.table_snapshot.v1'
            || empty($snapshot['existed'])) {
            throw new \RuntimeException('physical table snapshot is unavailable for row restore');
        }
        return $snapshot;
    }

    /**
     * 去除表名的方言引号，得到 Query::table() 可用的逻辑名。
     * PostgreSQL: "public"."m_acl_xxx" -> public.m_acl_xxx
     * MySQL: `m_acl_xxx` -> m_acl_xxx
     */
    private function toRawTableName(string $tableName): string
    {
        return trim(str_replace(['`', '"'], '', $tableName));
    }

    private function saveBackupChunk(string $tableName, array $chunk, int $migrationId, int $chunkIndex): void
    {
        $backup = (clone $this->backupModel)->reset()->setData([
            MigrationBackup::schema_fields_MIGRATION_ID => $migrationId,
            MigrationBackup::schema_fields_TABLE_NAME => "{$tableName}:chunk:{$chunkIndex}",
            MigrationBackup::schema_fields_BACKUP_DATA => json_encode($chunk, JSON_UNESCAPED_UNICODE),
            MigrationBackup::schema_fields_BACKUP_TYPE => MigrationBackup::TYPE_CHUNK,
            MigrationBackup::schema_fields_BACKUP_SCOPE => MigrationBackup::SCOPE_UPGRADE,
            MigrationBackup::schema_fields_OPERATION_ID => '',
            MigrationBackup::schema_fields_RETENTION_STATE => MigrationBackup::RETENTION_PROTECTED,
            MigrationBackup::schema_fields_CREATED_AT => date('Y-m-d H:i:s')
        ]);
        $this->assertBackupInserted($backup, $backup->save(), 'chunk');
    }

    public function restoreTableDataChunked(string $tableName, int $migrationId, bool $clearBeforeRestore = true): bool
    {
        try {
            $rawTable = $this->toRawTableName($tableName);
            $query = $this->connectionFactory->getQuery();
            $backups = $this->backupModel->reset()
                ->where(MigrationBackup::schema_fields_MIGRATION_ID, $migrationId)
                ->where(MigrationBackup::schema_fields_TABLE_NAME, "{$tableName}:chunk:%", 'LIKE')
                ->where(MigrationBackup::schema_fields_BACKUP_TYPE, MigrationBackup::TYPE_CHUNK)
                ->order(MigrationBackup::schema_fields_TABLE_NAME, 'ASC')
                ->select()
                ->fetch()
                ->getItems();

            if (empty($backups)) {
                $this->printing->warning(__("没有找到表 %{1} 的分块备份数据", $tableName));
                return false;
            }

            if ($clearBeforeRestore) {
                $query->clearQuery()->table($rawTable)->delete()->fetch();
                $this->printing->info(__("表 %{1} 数据已清空", $tableName));
            }

            $totalRows = 0;
            foreach ($backups as $backup) {
                $data = json_decode($backup->getData(MigrationBackup::schema_fields_BACKUP_DATA), true);
                if (empty($data)) {
                    continue;
                }
                foreach ($data as $row) {
                    $query->clearQuery()->table($rawTable)->insert($row)->fetch();
                }
                $this->backupModel->markRestored((int)$backup->getId());
                $totalRows += count($data);
                unset($data);
            }
            $this->printing->info(__("表 %{1} 分块数据恢复完成，共 %{2} 条记录", [$tableName, $totalRows]));
            return true;
        } catch (\Exception $e) {
            $this->printing->error(__("恢复分块表数据失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    public function smartBackupTable(string $tableName, int $migrationId, bool $includeStructure = true): array
    {
        $result = [
            'table' => $tableName,
            'structure_backed_up' => false,
            'data_backed_up' => false,
            'strategy' => 'none',
            'total_rows' => 0,
        ];

        try {
            if ($includeStructure) {
                $result['structure_backed_up'] = $this->backupTableStructure($tableName, $migrationId);
                if (!$result['structure_backed_up']) {
                    throw new \RuntimeException('structure backup failed');
                }
            }

            $rawTable = $this->toRawTableName($tableName);
            $rowCount = $this->connectionFactory->getQuery()->clearQuery()->table($rawTable)->total();
            $result['total_rows'] = $rowCount;

            if ($rowCount === 0) {
                $this->printing->info(__("表 %{1} 没有数据需要备份", $tableName));
                $result['strategy'] = 'empty';
                return $result;
            }

            if ($rowCount > self::LARGE_TABLE_THRESHOLD) {
                $this->printing->info(__("表 %{1} 数据量较大 (%{2} 行)，使用分批备份", [$tableName, $rowCount]));
                $this->backupTableDataChunked($tableName, $migrationId);
                $result['strategy'] = 'chunked';
            } else {
                $this->backupTableData($tableName, $migrationId);
                $result['strategy'] = 'full';
            }
            $result['data_backed_up'] = true;
        } catch (\Exception $e) {
            $this->printing->error(__("智能备份失败: %{1}", $e->getMessage()));
            throw $e;
        }
        return $result;
    }
}
