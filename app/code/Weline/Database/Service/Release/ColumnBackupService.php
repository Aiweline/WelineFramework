<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * Bounded column-level backup and conflict-safe restore.
 *
 * Backup rows are stored as append-only BackupObject chunks keyed by the table
 * primary key. Restore only fills NULL/empty cells, records conflicts instead
 * of overwriting non-empty data, and never deletes previously written objects.
 */
final class ColumnBackupService
{
    public const OBJECT_TYPE_CONFLICT = 'conflict';

    public const DEFAULT_CHUNK_ROWS = 1000;
    public const DEFAULT_CHUNK_BYTES = 1048576;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly BackupCatalog $backupCatalog,
        private readonly DbSchemaReader $schemaReader,
    ) {
    }

    /**
     * @param array<string, mixed> $options chunk_rows, chunk_bytes, source_version, target_version
     * @return array{set_id: int, objects: int, rows: int, checksum: string}
     */
    public function backupColumn(
        string $releaseId,
        string $scopeKey,
        string $table,
        string $column,
        string $operationId,
        array $options = []
    ): array {
        [$releaseId, $scopeKey, $table, $column, $operationId] = $this->validateIdentity(
            $releaseId,
            $scopeKey,
            $table,
            $column,
            $operationId
        );

        $connector = $this->connectionFactory->getConnector();
        $schema = $this->schemaReader->readTable($connector, $table);
        if ($schema === null) {
            throw new RuntimeException('table_not_found: ' . $table);
        }

        $table = $schema->tableName;
        $targetColumn = $this->resolveColumn($schema, $column);
        $primaryColumns = $this->primaryColumns($schema, $table);

        $setId = $this->backupCatalog->createSet([
            'release_id' => $releaseId,
            'operation_id' => $operationId,
            'scope_key' => $scopeKey,
            'kind' => BackupSet::KIND_OPERATION,
            'status' => BackupSet::STATUS_READY,
        ]);

        $chunkRows = max(1, (int) ($options['chunk_rows'] ?? self::DEFAULT_CHUNK_ROWS));
        $chunkBytes = max(1, (int) ($options['chunk_bytes'] ?? self::DEFAULT_CHUNK_BYTES));
        $sourceVersion = isset($options['source_version']) ? (string) $options['source_version'] : null;
        $targetVersion = isset($options['target_version']) ? (string) $options['target_version'] : null;

        $sql = $this->buildSelectSql($connector, $table, $targetColumn, $primaryColumns, true);

        $rows = [];
        $chunkBytesUsed = 0;
        $objectCount = 0;
        $rowCount = 0;
        $chunkChecksums = [];

        foreach ($connector->query($sql)->fetchIterator() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $identity = [];
            foreach ($primaryColumns as $primaryColumn) {
                $identity[$primaryColumn] = $row[$primaryColumn] ?? null;
            }

            $item = ['pk' => $identity, 'value' => $row[$targetColumn] ?? null];
            $encodedItem = $this->encodeJson($item, 'column_backup_encode_failed');
            $rows[] = $item;
            $chunkBytesUsed += strlen($encodedItem);
            ++$rowCount;

            if (count($rows) >= $chunkRows || $chunkBytesUsed >= $chunkBytes) {
                $chunkChecksums[] = $this->writeColumnChunk(
                    $setId,
                    $table,
                    $targetColumn,
                    $rows,
                    $sourceVersion,
                    $targetVersion
                );
                ++$objectCount;
                $rows = [];
                $chunkBytesUsed = 0;
            }
        }

        if ($rows !== []) {
            $chunkChecksums[] = $this->writeColumnChunk(
                $setId,
                $table,
                $targetColumn,
                $rows,
                $sourceVersion,
                $targetVersion
            );
            ++$objectCount;
        }

        return [
            'set_id' => $setId,
            'objects' => $objectCount,
            'rows' => $rowCount,
            'checksum' => hash('sha256', implode('', $chunkChecksums)),
        ];
    }

    /**
     * @param array<string, mixed> $options source_version, target_version
     * @return array{restored: int, unchanged: int, conflicts: int}
     */
    public function restoreColumn(
        string $releaseId,
        string $scopeKey,
        string $table,
        string $column,
        string $operationId,
        array $options = []
    ): array {
        [$releaseId, $scopeKey, $table, $column, $operationId] = $this->validateIdentity(
            $releaseId,
            $scopeKey,
            $table,
            $column,
            $operationId
        );

        $connector = $this->connectionFactory->getConnector();
        $schema = $this->schemaReader->readTable($connector, $table);
        if ($schema === null) {
            throw new RuntimeException('table_not_found: ' . $table);
        }

        $table = $schema->tableName;
        $targetColumn = $this->resolveColumn($schema, $column);
        $primaryColumns = $this->primaryColumns($schema, $table);

        $set = $this->findOperationSet($connector, $releaseId, $operationId, $scopeKey);
        if ($set === null) {
            throw new RuntimeException(
                'backup_set_not_found: ' . $releaseId . ' / ' . $operationId . ' / ' . $scopeKey
            );
        }

        $setId = (int) $set['id'];
        $objects = $this->backupCatalog->objects($setId, [
            'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
            'table_name' => $table,
            'column_name' => $targetColumn,
        ]);
        $readyObjects = array_values(array_filter(
            $objects,
            static fn(array $object): bool => (string) ($object[BackupObject::schema_fields_STATUS] ?? '')
                === BackupObject::STATUS_READY
        ));
        if ($readyObjects === []) {
            throw new RuntimeException('column_backup_not_found: ' . $table . '.' . $targetColumn);
        }

        $sourceVersion = isset($options['source_version']) ? (string) $options['source_version'] : null;
        $targetVersion = isset($options['target_version']) ? (string) $options['target_version'] : null;
        $existingConflicts = $this->existingConflictKeys($setId, $table, $targetColumn);

        $restored = 0;
        $unchanged = 0;
        $conflicts = 0;

        foreach ($readyObjects as $object) {
            $payload = $this->decodeColumnPayload($object);
            $sourceBackupId = (int) ($object[BackupObject::schema_fields_ID] ?? 0);

            foreach ($payload['rows'] as $row) {
                $identity = $row['pk'];
                $backupValue = $row['value'];

                $current = $this->readCurrentValue(
                    $connector,
                    $table,
                    $targetColumn,
                    $primaryColumns,
                    $identity
                );

                if ($current['found'] === false) {
                    ++$conflicts;
                    $this->writeConflict(
                        $setId,
                        $table,
                        $targetColumn,
                        $sourceBackupId,
                        'row_missing',
                        $identity,
                        $backupValue,
                        null,
                        $sourceVersion,
                        $targetVersion,
                        $existingConflicts
                    );
                    continue;
                }

                $currentValue = $current['value'];
                if ($currentValue === null || $currentValue === '') {
                    $this->writeCurrentValue(
                        $connector,
                        $table,
                        $targetColumn,
                        $primaryColumns,
                        $identity,
                        $backupValue
                    );
                    ++$restored;
                    continue;
                }

                if ($this->valuesEqual($currentValue, $backupValue)) {
                    ++$unchanged;
                    continue;
                }

                ++$conflicts;
                $this->writeConflict(
                    $setId,
                    $table,
                    $targetColumn,
                    $sourceBackupId,
                    'value_mismatch',
                    $identity,
                    $backupValue,
                    $currentValue,
                    $sourceVersion,
                    $targetVersion,
                    $existingConflicts
                );
            }
        }

        return [
            'restored' => $restored,
            'unchanged' => $unchanged,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function validateIdentity(
        string $releaseId,
        string $scopeKey,
        string $table,
        string $column,
        string $operationId
    ): array {
        $values = [
            'release id' => $releaseId,
            'scope key' => $scopeKey,
            'table' => $table,
            'column' => $column,
            'operation id' => $operationId,
        ];
        foreach ($values as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Column backup requires a non-empty ' . $label);
            }
        }

        return [
            trim($releaseId),
            trim($scopeKey),
            trim($table),
            trim($column),
            trim($operationId),
        ];
    }

    private function resolveColumn(TableSchema $schema, string $column): string
    {
        foreach ($schema->columns as $definition) {
            if (strcasecmp($definition->name, $column) === 0) {
                return $definition->name;
            }
        }

        throw new RuntimeException('column_not_found: ' . $schema->tableName . '.' . $column);
    }

    /**
     * @return list<string>
     */
    private function primaryColumns(TableSchema $schema, string $table): array
    {
        $primaryColumns = [];
        foreach ($schema->columns as $definition) {
            if ($definition->primaryKey) {
                $primaryColumns[] = $definition->name;
            }
        }

        if ($primaryColumns === []) {
            throw new RuntimeException('no_stable_row_identity: ' . $table . ' has no primary key');
        }

        return $primaryColumns;
    }

    /**
     * @param list<string> $primaryColumns
     */
    private function buildSelectSql(
        ConnectorInterface $connector,
        string $table,
        string $column,
        array $primaryColumns,
        bool $ordered
    ): string {
        $fields = $primaryColumns;
        if (!in_array($column, $fields, true)) {
            $fields[] = $column;
        }

        $sql = 'SELECT ' . implode(', ', array_map($connector->quoteIdentifier(...), $fields))
            . ' FROM ' . $connector->quoteTable($table);
        if ($ordered) {
            $sql .= ' ORDER BY ' . implode(', ', array_map($connector->quoteIdentifier(...), $primaryColumns));
        }

        return $sql;
    }

    /**
     * @param list<array{pk: array<string, mixed>, value: mixed}> $rows
     */
    private function writeColumnChunk(
        int $setId,
        string $table,
        string $column,
        array $rows,
        ?string $sourceVersion,
        ?string $targetVersion
    ): string {
        $dataJson = $this->encodeJson(
            ['table' => $table, 'column' => $column, 'rows' => $rows],
            'column_backup_encode_failed'
        );
        $checksum = hash('sha256', $dataJson);

        $objectId = $this->backupCatalog->addObject($setId, [
            'object_type' => BackupObject::OBJECT_TYPE_COLUMN,
            'table_name' => $table,
            'column_name' => $column,
            'source_version' => $sourceVersion,
            'target_version' => $targetVersion,
            'data_json' => $dataJson,
            'data_checksum' => $checksum,
            'row_count' => count($rows),
        ]);
        $this->markObjectReady($objectId);

        return $checksum;
    }

    private function findOperationSet(
        ConnectorInterface $connector,
        string $releaseId,
        string $operationId,
        string $scopeKey
    ): ?array {
        $latest = $this->backupCatalog->findSet($releaseId, $scopeKey);
        if (
            $latest !== null
            && (string) ($latest['operation_id'] ?? '') === $operationId
            && (string) ($latest['kind'] ?? '') === BackupSet::KIND_OPERATION
        ) {
            return $latest;
        }

        $rows = $connector->getQuery()
            ->clearQuery()
            ->table(BackupSet::schema_table)
            ->where(BackupSet::schema_fields_RELEASE_ID, $releaseId)
            ->where(BackupSet::schema_fields_OPERATION_ID, $operationId)
            ->where(BackupSet::schema_fields_SCOPE_KEY, $scopeKey)
            ->where(BackupSet::schema_fields_KIND, BackupSet::KIND_OPERATION)
            ->limit(1)
            ->select()
            ->fetchArray();
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $setId = (int) ($row[BackupSet::schema_fields_ID] ?? 0);
        if ($setId <= 0) {
            return null;
        }

        return ['id' => $setId];
    }

    /**
     * @return array{table: string, column: string, rows: list<array{pk: array<string, mixed>, value: mixed}>}
     */
    private function decodeColumnPayload(array $object): array
    {
        $objectId = (int) ($object[BackupObject::schema_fields_ID] ?? 0);
        $dataJson = $object[BackupObject::schema_fields_DATA_JSON] ?? null;
        if (!is_string($dataJson) || $dataJson === '') {
            throw new RuntimeException('column_backup_payload_missing: object ' . $objectId);
        }

        $expectedChecksum = (string) ($object[BackupObject::schema_fields_DATA_CHECKSUM] ?? '');
        if ($expectedChecksum === '' || !hash_equals($expectedChecksum, hash('sha256', $dataJson))) {
            throw new RuntimeException('column_backup_checksum_mismatch: object ' . $objectId);
        }

        $decoded = json_decode($dataJson, true);
        if (!is_array($decoded) || !isset($decoded['rows']) || !is_array($decoded['rows'])) {
            throw new RuntimeException('column_backup_payload_invalid: object ' . $objectId);
        }

        $rows = [];
        foreach ($decoded['rows'] as $row) {
            if (
                !is_array($row)
                || !isset($row['pk'])
                || !is_array($row['pk'])
                || !array_key_exists('value', $row)
            ) {
                throw new RuntimeException('column_backup_payload_invalid: object ' . $objectId);
            }
            $rows[] = ['pk' => $row['pk'], 'value' => $row['value']];
        }

        return [
            'table' => (string) ($decoded['table'] ?? ''),
            'column' => (string) ($decoded['column'] ?? ''),
            'rows' => $rows,
        ];
    }

    /**
     * @param list<string> $primaryColumns
     * @param array<string, mixed> $identity
     * @return array{found: bool, value: mixed}
     */
    private function readCurrentValue(
        ConnectorInterface $connector,
        string $table,
        string $column,
        array $primaryColumns,
        array $identity
    ): array {
        $conditions = [];
        $params = [];
        foreach ($primaryColumns as $primaryColumn) {
            if (!array_key_exists($primaryColumn, $identity)) {
                throw new RuntimeException(
                    'column_backup_payload_invalid: missing primary key ' . $primaryColumn
                );
            }
            $conditions[] = $connector->quoteIdentifier($primaryColumn) . ' = ?';
            $params[] = $identity[$primaryColumn];
        }

        $sql = 'SELECT ' . $connector->quoteIdentifier($column)
            . ' FROM ' . $connector->quoteTable($table)
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' LIMIT 1';
        $statement = $connector->getWrappedConnection()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        if ($row === false) {
            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $row[$column] ?? null];
    }

    /**
     * @param list<string> $primaryColumns
     * @param array<string, mixed> $identity
     */
    private function writeCurrentValue(
        ConnectorInterface $connector,
        string $table,
        string $column,
        array $primaryColumns,
        array $identity,
        mixed $value
    ): void {
        $conditions = [];
        $params = [$value];
        foreach ($primaryColumns as $primaryColumn) {
            if (!array_key_exists($primaryColumn, $identity)) {
                throw new RuntimeException(
                    'column_backup_payload_invalid: missing primary key ' . $primaryColumn
                );
            }
            $conditions[] = $connector->quoteIdentifier($primaryColumn) . ' = ?';
            $params[] = $identity[$primaryColumn];
        }

        $sql = 'UPDATE ' . $connector->quoteTable($table)
            . ' SET ' . $connector->quoteIdentifier($column) . ' = ?'
            . ' WHERE ' . implode(' AND ', $conditions);
        $statement = $connector->getWrappedConnection()->prepare($sql);
        $statement->execute($params);
        $statement->closeCursor();
    }

    private function valuesEqual(mixed $current, mixed $backup): bool
    {
        if ($current === null || $backup === null) {
            return $current === $backup;
        }

        return (string) $current === (string) $backup;
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, true> $existingKeys
     */
    private function writeConflict(
        int $setId,
        string $table,
        string $column,
        int $sourceBackupId,
        string $reason,
        array $identity,
        mixed $backupValue,
        mixed $currentValue,
        ?string $sourceVersion,
        ?string $targetVersion,
        array &$existingKeys
    ): void {
        $key = $this->conflictKey($identity, $reason, $backupValue, $currentValue);
        if (isset($existingKeys[$key])) {
            return;
        }

        $dataJson = $this->encodeJson([
            'reason' => $reason,
            'pk' => $identity,
            'source_backup_id' => $sourceBackupId,
            'backup_value' => $backupValue,
            'current_value' => $currentValue,
        ], 'column_backup_conflict_encode_failed');

        $objectId = $this->backupCatalog->addObject($setId, [
            'object_type' => self::OBJECT_TYPE_CONFLICT,
            'table_name' => $table,
            'column_name' => $column,
            'source_version' => $sourceVersion,
            'target_version' => $targetVersion,
            'data_json' => $dataJson,
            'data_checksum' => hash('sha256', $dataJson),
            'row_count' => 1,
        ]);
        $this->markObjectReady($objectId);
        $existingKeys[$key] = true;
    }

    /**
     * @return array<string, true>
     */
    private function existingConflictKeys(int $setId, string $table, string $column): array
    {
        $keys = [];
        $objects = $this->backupCatalog->objects($setId, [
            'object_type' => self::OBJECT_TYPE_CONFLICT,
            'table_name' => $table,
            'column_name' => $column,
        ]);
        foreach ($objects as $object) {
            $dataJson = $object[BackupObject::schema_fields_DATA_JSON] ?? null;
            if (!is_string($dataJson) || $dataJson === '') {
                continue;
            }
            $decoded = json_decode($dataJson, true);
            if (
                !is_array($decoded)
                || !isset($decoded['reason'], $decoded['pk'])
                || !is_array($decoded['pk'])
            ) {
                continue;
            }
            $keys[$this->conflictKey(
                $decoded['pk'],
                (string) $decoded['reason'],
                $decoded['backup_value'] ?? null,
                $decoded['current_value'] ?? null
            )] = true;
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $identity
     */
    private function conflictKey(
        array $identity,
        string $reason,
        mixed $backupValue,
        mixed $currentValue
    ): string {
        ksort($identity);

        return $reason . '|' . $this->encodeJson([
            'pk' => $identity,
            'backup' => $backupValue,
            'current' => $currentValue,
        ], 'column_backup_conflict_key_failed');
    }

    private function markObjectReady(int $objectId): void
    {
        $connector = $this->connectionFactory->getConnector();
        $sql = 'UPDATE ' . $connector->quoteTable(BackupObject::schema_table)
            . ' SET ' . $connector->quoteIdentifier(BackupObject::schema_fields_STATUS) . ' = ?'
            . ' WHERE ' . $connector->quoteIdentifier(BackupObject::schema_fields_ID) . ' = ?';
        $statement = $connector->getWrappedConnection()->prepare($sql);
        $statement->execute([BackupObject::STATUS_READY, $objectId]);
        $statement->closeCursor();
    }

    private function encodeJson(mixed $value, string $error): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException($error);
        }

        return $encoded;
    }
}
