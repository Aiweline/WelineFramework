<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Release\Api\DatabaseSnapshotInterface;
use Weline\Framework\Database\Release\Api\SchemaScopeInterface;

/**
 * Whole-database release snapshots.
 *
 * SQLite snapshots are produced with VACUUM INTO and restored by replacing the
 * database file while the connection is closed. PostgreSQL and MySQL use their
 * native dump/restore binaries; passwords are passed through the environment.
 */
final class DatabaseSnapshotService implements DatabaseSnapshotInterface
{
    public const ENGINE_SQLITE = 'sqlite';
    public const ENGINE_PGSQL = 'pgsql';
    public const ENGINE_MYSQL = 'mysql';

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaSnapshot $schemaSnapshot,
        private readonly BackupCatalog $backupCatalog,
    ) {
        $this->schemaSnapshot->setConnection($this->connectionFactory);
    }

    public function create(string $releaseId, SchemaScopeInterface $scope, array $options = []): array
    {
        if ($releaseId === '') {
            throw new InvalidArgumentException('Snapshot release id must not be empty');
        }

        $scopeKey = $scope->key();
        $backupSetId = $this->backupCatalog->createSet([
            'release_id' => $releaseId,
            'operation_id' => (string) ($options['operation_id'] ?? 'snapshot'),
            'scope_key' => $scopeKey,
            'kind' => BackupSet::KIND_RELEASE,
            'status' => 'creating',
        ]);

        $connector = $this->connectionFactory->getConnector();
        $config = $connector->getConfigProvider();
        $engine = $this->normalizeEngine((string) $config->getDbType());

        $snapshotKey = 'snap-' . $this->safeKeyName($releaseId) . '-' . bin2hex(random_bytes(4));
        $path = $this->snapshotPath($releaseId, $snapshotKey, $engine);
        $createdAt = date('Y-m-d H:i:s');
        $snapshotId = 0;

        try {
            $this->createArtifact($engine, $connector, $config, $path);

            if (!is_file($path)) {
                throw new RuntimeException('Snapshot artifact was not created: ' . $path);
            }
            $size = (int) filesize($path);
            $checksum = hash_file('sha256', $path);
            if ($size <= 0 || !is_string($checksum) || $checksum === '') {
                throw new RuntimeException('Snapshot artifact is empty or unreadable: ' . $path);
            }

            $snapshotId = $this->insertSnapshotRow([
                SchemaSnapshot::schema_fields_SNAPSHOT_KEY => $snapshotKey,
                SchemaSnapshot::schema_fields_BACKUP_SET_ID => $backupSetId,
                SchemaSnapshot::schema_fields_RELEASE_ID => $releaseId,
                SchemaSnapshot::schema_fields_SCOPE_KEY => $scopeKey,
                SchemaSnapshot::schema_fields_ENGINE => $engine,
                SchemaSnapshot::schema_fields_PATH => $path,
                SchemaSnapshot::schema_fields_CHECKSUM => $checksum,
                SchemaSnapshot::schema_fields_POSITION => (string) ($options['position'] ?? ''),
                SchemaSnapshot::schema_fields_SIZE_BYTES => $size,
                SchemaSnapshot::schema_fields_STATUS => SchemaSnapshot::STATUS_READY,
                SchemaSnapshot::schema_fields_CREATED_AT => $createdAt,
            ]);

            $this->backupCatalog->addObject($backupSetId, [
                'object_type' => 'snapshot',
                'table_name' => '',
                'data_json' => (string) json_encode(
                    ['path' => $path],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'data_checksum' => $checksum,
                'row_count' => 0,
            ]);
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw $exception;
        }

        $row = $this->findSnapshotRow((string) $snapshotId);
        if ($row === null) {
            throw new RuntimeException('Snapshot metadata was not persisted: ' . $snapshotId);
        }

        return $this->normalizeSnapshot($row);
    }

    public function restore(string $snapshotId, array $options = []): void
    {
        $row = $this->findSnapshotRow($snapshotId);
        if ($row === null) {
            throw new InvalidArgumentException('Snapshot not found: ' . $snapshotId);
        }

        $status = (string) ($row[SchemaSnapshot::schema_fields_STATUS] ?? '');
        if (!in_array($status, [SchemaSnapshot::STATUS_READY, SchemaSnapshot::STATUS_RESTORED], true)) {
            throw new RuntimeException('Snapshot is not restorable: status ' . $status);
        }

        $path = (string) ($row[SchemaSnapshot::schema_fields_PATH] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Snapshot artifact not found: ' . $path);
        }

        $checksum = (string) ($row[SchemaSnapshot::schema_fields_CHECKSUM] ?? '');
        $actualChecksum = hash_file('sha256', $path);
        if ($checksum === '' || !is_string($actualChecksum) || !hash_equals($checksum, $actualChecksum)) {
            throw new RuntimeException('Snapshot checksum mismatch: ' . $path);
        }

        $engine = $this->normalizeEngine((string) ($row[SchemaSnapshot::schema_fields_ENGINE] ?? ''));
        match ($engine) {
            self::ENGINE_SQLITE => $this->restoreSqlite($path, $options),
            self::ENGINE_PGSQL => $this->restorePgsql($path),
            self::ENGINE_MYSQL => $this->restoreMysql($path),
        };

        $this->reconcileCatalog($row, $path, $checksum);

        // After a PG/MySQL restore, sequences are restored to their snapshot-time
        // values. Any rows inserted after the snapshot (and then erased by the restore)
        // left the sequence behind. Reset all RSSE table sequences to avoid PK collisions.
        if ($engine === self::ENGINE_PGSQL) {
            $this->resetRsseSequences();
        }
    }

    /**
     * Build an engine-appropriate upsert statement for reconciling restored
     * snapshot metadata. PG uses ON CONFLICT, MySQL/MariaDB use ON DUPLICATE KEY.
     *
     * @param array<int, string> $cols
     */
    private function prepareReconcileUpsert(\PDO $pdo, string $table, array $cols): \PDOStatement
    {
        $engine = $this->normalizeEngine(
            (string) ($this->connectionFactory->getConnector()->getConfigProvider()->getDbType() ?? ''),
        );
        if ($engine === self::ENGINE_MYSQL) {
            // MySQL/MariaDB: backtick identifiers + ON DUPLICATE KEY UPDATE.
            // The model table name already carries backtick-qualified db + table.
            $colList = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
            $placeholders = implode(', ', array_fill(0, count($cols) + 1, '?'));
            $set = implode(', ', array_map(
                static fn (string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)',
                $cols,
            ));

            return $pdo->prepare(
                'INSERT INTO ' . $table . ' (`snapshot_id`, ' . $colList . ') VALUES (' . $placeholders . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $set,
            );
        }

        // PostgreSQL / SQLite: double-quote identifiers + ON CONFLICT.
        $colList = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $cols));
        $placeholders = implode(', ', array_fill(0, count($cols) + 1, '?'));
        $set = implode(', ', array_map(
            static fn (string $c): string => '"' . $c . '" = EXCLUDED."' . $c . '"',
            $cols,
        ));

        return $pdo->prepare(
            'INSERT INTO ' . $table . ' ("snapshot_id", ' . $colList . ') VALUES (' . $placeholders . ')'
            . ' ON CONFLICT ("snapshot_id") DO UPDATE SET ' . $set,
        );
    }

    private function resetRsseSequences(): void
    {
        $connector = $this->connectionFactory->getConnector();
        $pdo = $connector->getWrappedConnection()->getPdo();
        $rsseTables = [
            'weline_schema_release' => 'release_id',
            'weline_schema_snapshot' => 'snapshot_id',
            'weline_schema_backup_set' => 'set_id',
            'weline_schema_backup_object' => 'object_id',
            'weline_schema_change' => 'change_id',
            'weline_schema_operation_event' => 'event_id',
            'weline_schema_checkpoint' => 'checkpoint_id',
            'weline_schema_scope' => 'scope_id',
            'weline_schema_table_owner' => 'owner_id',
            'weline_schema_data_transform_progress' => 'progress_id',
            'weline_schema_physical_state' => 'state_id',
        ];
        foreach ($rsseTables as $tableName => $idColumn) {
            try {
                $seqName = $tableName . '_' . $idColumn . '_seq';
                $maxId = $pdo->query("SELECT COALESCE(MAX(\"{$idColumn}\"), 0) FROM \"{$tableName}\"")->fetchColumn();
                $pdo->exec("SELECT setval('{$seqName}', " . (int)$maxId . ', true)');
            } catch (\Throwable) {
                // Table might not exist or not have a sequence; skip silently
            }
        }
    }

    public function verify(string $snapshotId): bool
    {
        $row = $this->findSnapshotRow($snapshotId);
        if ($row === null) {
            return false;
        }

        $status = (string) ($row[SchemaSnapshot::schema_fields_STATUS] ?? '');
        if (!in_array($status, [SchemaSnapshot::STATUS_READY, SchemaSnapshot::STATUS_RESTORED], true)) {
            return false;
        }

        $path = (string) ($row[SchemaSnapshot::schema_fields_PATH] ?? '');
        if ($path === '' || !is_file($path)) {
            return false;
        }

        $size = filesize($path);
        $recordedSize = (int) ($row[SchemaSnapshot::schema_fields_SIZE_BYTES] ?? 0);
        if (!is_int($size) || $size <= 0 || $recordedSize <= 0) {
            return false;
        }

        $checksum = (string) ($row[SchemaSnapshot::schema_fields_CHECKSUM] ?? '');
        $actualChecksum = hash_file('sha256', $path);
        if ($checksum === '' || !is_string($actualChecksum)) {
            return false;
        }

        return hash_equals($checksum, $actualChecksum);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertSnapshotRow(array $data): int
    {
        $model = $this->newSnapshotModel();
        $model->setData($data);
        $result = $model->save();
        $snapshotId = is_int($result) && $result > 0 ? $result : (int) $model->getId();
        if ($snapshotId <= 0) {
            throw new RuntimeException('Snapshot metadata registration failed for release ' . (string) ($data[SchemaSnapshot::schema_fields_RELEASE_ID] ?? ''));
        }

        return $snapshotId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSnapshotRow(string $snapshotId): ?array
    {
        if ($snapshotId === '') {
            return null;
        }

        $model = $this->newSnapshotModel();
        if (ctype_digit($snapshotId)) {
            $model->where(SchemaSnapshot::schema_fields_ID, (int) $snapshotId);
        } elseif (preg_match('/^snap-[A-Za-z0-9-]+$/', $snapshotId) === 1) {
            $model->where(SchemaSnapshot::schema_fields_SNAPSHOT_KEY, $snapshotId);
        } else {
            return null;
        }

        $rows = $model->limit(1)->select()->fetchArray();
        if ($rows !== []) {
            return $rows[0];
        }

        // A restored PostgreSQL dump may contain the row while the model's
        // primary-key query path is still stale (sequence/schema cache). Fall
        // back to a raw lookup before declaring metadata reconciliation failed.
        if (ctype_digit($snapshotId)) {
            $connector = $this->connectionFactory->getConnector();
            $table = $model->getTable();
            $sql = 'SELECT * FROM ' . $connector->formatTableName($table)
                . ' WHERE ' . $connector->quoteIdentifier(SchemaSnapshot::schema_fields_ID)
                . ' = ' . (int) $snapshotId;
            $rawRows = $connector->query($sql)->fetchArray();
            if (is_array($rawRows) && $rawRows !== []) {
                return $rawRows[0];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSnapshot(array $row): array
    {
        $path = (string) ($row[SchemaSnapshot::schema_fields_PATH] ?? '');
        $snapshotKey = (string) ($row[SchemaSnapshot::schema_fields_SNAPSHOT_KEY] ?? '');
        if ($snapshotKey === '' && $path !== '') {
            $snapshotKey = pathinfo($path, PATHINFO_FILENAME);
        }

        return [
            'snapshot_id' => (int) ($row[SchemaSnapshot::schema_fields_ID] ?? 0),
            'id' => (int) ($row[SchemaSnapshot::schema_fields_ID] ?? 0),
            'snapshot_key' => $snapshotKey,
            'backup_set_id' => (int) ($row[SchemaSnapshot::schema_fields_BACKUP_SET_ID] ?? 0),
            'release_id' => (string) ($row[SchemaSnapshot::schema_fields_RELEASE_ID] ?? ''),
            'scope_key' => (string) ($row[SchemaSnapshot::schema_fields_SCOPE_KEY] ?? ''),
            'engine' => (string) ($row[SchemaSnapshot::schema_fields_ENGINE] ?? ''),
            'path' => $path,
            'checksum' => (string) ($row[SchemaSnapshot::schema_fields_CHECKSUM] ?? ''),
            'position' => (string) ($row[SchemaSnapshot::schema_fields_POSITION] ?? ''),
            'size_bytes' => (int) ($row[SchemaSnapshot::schema_fields_SIZE_BYTES] ?? 0),
            'status' => (string) ($row[SchemaSnapshot::schema_fields_STATUS] ?? ''),
            'created_at' => $row[SchemaSnapshot::schema_fields_CREATED_AT] ?? null,
            'restored_at' => $row[SchemaSnapshot::schema_fields_RESTORED_AT] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reconcileCatalog(array $row, string $path, string $checksum): void
    {
        $snapshotId = (int) ($row[SchemaSnapshot::schema_fields_ID] ?? 0);
        if ($snapshotId <= 0) {
            throw new RuntimeException('Restored snapshot has no id');
        }

        $size = filesize($path);
        if (!is_int($size) || $size <= 0) {
            throw new RuntimeException('Restored snapshot artifact is unreadable: ' . $path);
        }

        $backupSetId = (int) ($row[SchemaSnapshot::schema_fields_BACKUP_SET_ID] ?? 0);
        $releaseId = (string) ($row[SchemaSnapshot::schema_fields_RELEASE_ID] ?? '');
        $scopeKey = (string) ($row[SchemaSnapshot::schema_fields_SCOPE_KEY] ?? '');
        if ($backupSetId <= 0) {
            $backupSetId = $this->backupCatalog->createSet([
                'release_id' => $releaseId,
                'operation_id' => 'snapshot',
                'scope_key' => $scopeKey,
                'kind' => BackupSet::KIND_RELEASE,
                'status' => SchemaSnapshot::STATUS_READY,
            ]);
        }

        $restoredAt = date('Y-m-d H:i:s');
        $snapshotKey = (string) ($row[SchemaSnapshot::schema_fields_SNAPSHOT_KEY] ?? '');
        if ($snapshotKey === '') {
            $snapshotKey = pathinfo($path, PATHINFO_FILENAME);
        }
        $data = [
            SchemaSnapshot::schema_fields_SNAPSHOT_KEY => $snapshotKey,
            SchemaSnapshot::schema_fields_BACKUP_SET_ID => $backupSetId,
            SchemaSnapshot::schema_fields_RELEASE_ID => $releaseId,
            SchemaSnapshot::schema_fields_SCOPE_KEY => $scopeKey,
            SchemaSnapshot::schema_fields_ENGINE => (string) ($row[SchemaSnapshot::schema_fields_ENGINE] ?? ''),
            SchemaSnapshot::schema_fields_PATH => $path,
            SchemaSnapshot::schema_fields_CHECKSUM => $checksum,
            SchemaSnapshot::schema_fields_POSITION => $row[SchemaSnapshot::schema_fields_POSITION] ?? null,
            SchemaSnapshot::schema_fields_SIZE_BYTES => $size,
            SchemaSnapshot::schema_fields_STATUS => SchemaSnapshot::STATUS_RESTORED,
            SchemaSnapshot::schema_fields_CREATED_AT => $row[SchemaSnapshot::schema_fields_CREATED_AT] ?? $restoredAt,
            SchemaSnapshot::schema_fields_RESTORED_AT => $restoredAt,
        ];
        $table = $this->newSnapshotModel()->getTable();
        $pdo = $this->connectionFactory->getConnector()->getWrappedConnection()->getPdo();
        $cols = array_keys($data);
        $values = [$snapshotId, ...array_values($data)];
        $stmt = $this->prepareReconcileUpsert($pdo, $table, $cols);
        $stmt->execute($values);
        $stmt->closeCursor();

        if ($this->findSnapshotRow((string) $snapshotId) === null) {
            throw new RuntimeException('Restored snapshot metadata could not be reconciled: ' . $snapshotId);
        }

        $objects = $this->backupCatalog->objects($backupSetId, ['object_type' => 'snapshot']);
        if ($objects === []) {
            $this->backupCatalog->addObject($backupSetId, [
                'object_type' => 'snapshot',
                'table_name' => '',
                'data_json' => (string) json_encode(
                    ['path' => $path],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'data_checksum' => $checksum,
                'row_count' => 0,
            ]);
        }
    }

    private function createArtifact(
        string $engine,
        ConnectorInterface $connector,
        ConfigProviderInterface $config,
        string $path,
    ): void {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create snapshot directory: ' . $directory);
        }
        if (is_file($path)) {
            throw new RuntimeException('Snapshot artifact already exists: ' . $path);
        }

        match ($engine) {
            self::ENGINE_SQLITE => $this->createSqliteArtifact($connector, $config, $path),
            self::ENGINE_PGSQL => $this->createPgsqlArtifact($config, $path),
            self::ENGINE_MYSQL => $this->createMysqlArtifact($config, $path),
        };

        if (!is_file($path)) {
            throw new RuntimeException('Snapshot artifact was not created: ' . $path);
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0) {
            throw new RuntimeException('Snapshot artifact is empty: ' . $path);
        }
    }

    private function createSqliteArtifact(
        ConnectorInterface $connector,
        ConfigProviderInterface $config,
        string $path,
    ): void {
        $sourcePath = $this->sqliteSourcePath($config);
        if ($sourcePath === '' || $sourcePath === ':memory:' || !is_file($sourcePath)) {
            throw new RuntimeException('SQLite snapshot requires a file database path');
        }

        $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }

        try {
            $connection = $connector->getWrappedConnection();
            $connection->execute('VACUUM INTO ' . $connection->quote($tmpPath));
            if (!is_file($tmpPath) || filesize($tmpPath) <= 0) {
                throw new RuntimeException('SQLite VACUUM INTO produced no snapshot');
            }
            if (!rename($tmpPath, $path)) {
                throw new RuntimeException('Cannot move SQLite snapshot into place: ' . $path);
            }
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function createPgsqlArtifact(ConfigProviderInterface $config, string $path): void
    {
        $database = $config->getDatabase();
        if ($database === '') {
            throw new RuntimeException('pgsql snapshot requires a database name');
        }

        $parts = ['pg_dump', '-Fc', '--no-owner', '--no-acl'];
        $host = $config->getHostName();
        if ($host !== '') {
            $parts[] = '-h';
            $parts[] = escapeshellarg($host);
        }
        $port = $config->getHostPort();
        if ($port <= 0) {
            $port = 5432;
        }
        $parts[] = '-p';
        $parts[] = escapeshellarg((string) $port);
        $user = $config->getUsername();
        if ($user !== '') {
            $parts[] = '-U';
            $parts[] = escapeshellarg($user);
        }
        $parts[] = '-d';
        $parts[] = escapeshellarg($database);
        $parts[] = '-f';
        $parts[] = escapeshellarg($path);

        $this->runCommand(
            implode(' ', $parts),
            $this->commandEnvironment('PGPASSWORD', $config->getPassword()),
            'pg_dump'
        );
    }

    private function createMysqlArtifact(ConfigProviderInterface $config, string $path): void
    {
        $database = $config->getDatabase();
        if ($database === '') {
            throw new RuntimeException('mysql snapshot requires a database name');
        }

        $parts = ['mysqldump', '--single-transaction', '--routines', '--triggers'];
        if (self::mysqldumpSupportsColumnStatistics()) {
            $parts[] = '--column-statistics=0';
        }
        $host = $config->getHostName();
        if ($host !== '') {
            $parts[] = '-h';
            $parts[] = escapeshellarg($host);
        }
        $port = $config->getHostPort();
        if ($port <= 0) {
            $port = 3306;
        }
        $parts[] = '-P';
        $parts[] = escapeshellarg((string) $port);
        $user = $config->getUsername();
        if ($user !== '') {
            $parts[] = '-u';
            $parts[] = escapeshellarg($user);
        }
        $parts[] = escapeshellarg($database);
        $command = implode(' ', $parts) . ' > ' . escapeshellarg($path);

        $this->runCommand(
            $command,
            $this->commandEnvironment('MYSQL_PWD', $config->getPassword()),
            'mysqldump'
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function mysqldumpSupportsColumnStatistics(): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }
        $output = [];
        $exit = 0;
        @exec('mysqldump --help 2>&1', $output, $exit);
        $supported = str_contains(implode("\n", $output), 'column-statistics');

        return $supported;
    }

    private function restoreSqlite(string $snapshotPath, array $options): void
    {
        $connector = $this->connectionFactory->getConnector();
        $config = $connector->getConfigProvider();
        $targetPath = (string) ($options['path'] ?? $options['database_path'] ?? $this->sqliteSourcePath($config));
        if ($targetPath === '' || $targetPath === ':memory:') {
            throw new RuntimeException('SQLite restore requires a file database path');
        }

        $snapshotRealPath = realpath($snapshotPath);
        $targetRealPath = realpath($targetPath);
        if ($snapshotRealPath !== false && $targetRealPath !== false && $snapshotRealPath === $targetRealPath) {
            throw new RuntimeException('SQLite snapshot and restore target must differ');
        }

        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create SQLite restore directory: ' . $directory);
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        $connector->close();
        try {
            ConnectionPool::discardConnection($pdo, $config);
        } catch (\Throwable) {
            // The close above is sufficient; a pooled handle is best-effort discarded.
        }
        unset($pdo);

        foreach ([$targetPath . '-wal', $targetPath . '-shm', $targetPath . '-journal'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }

        if (!copy($snapshotPath, $targetPath)) {
            throw new RuntimeException('SQLite snapshot restore copy failed: ' . $targetPath);
        }
    }

    private function restorePgsql(string $snapshotPath): void
    {
        $config = $this->connectionFactory->getConnector()->getConfigProvider();
        $database = $config->getDatabase();
        if ($database === '') {
            throw new RuntimeException('pgsql restore requires a database name');
        }

        $parts = ['pg_restore', '--clean', '--if-exists'];
        $host = $config->getHostName();
        if ($host !== '') {
            $parts[] = '-h';
            $parts[] = escapeshellarg($host);
        }
        $port = $config->getHostPort();
        if ($port <= 0) {
            $port = 5432;
        }
        $parts[] = '-p';
        $parts[] = escapeshellarg((string) $port);
        $user = $config->getUsername();
        if ($user !== '') {
            $parts[] = '-U';
            $parts[] = escapeshellarg($user);
        }
        $parts[] = '-d';
        $parts[] = escapeshellarg($database);
        $parts[] = escapeshellarg($snapshotPath);

        $this->runCommand(
            implode(' ', $parts),
            $this->commandEnvironment('PGPASSWORD', $config->getPassword()),
            'pg_restore'
        );
    }

    private function restoreMysql(string $snapshotPath): void
    {
        $config = $this->connectionFactory->getConnector()->getConfigProvider();
        $database = $config->getDatabase();
        if ($database === '') {
            throw new RuntimeException('mysql restore requires a database name');
        }

        $parts = ['mysql'];
        $host = $config->getHostName();
        if ($host !== '') {
            $parts[] = '-h';
            $parts[] = escapeshellarg($host);
        }
        $port = $config->getHostPort();
        if ($port <= 0) {
            $port = 3306;
        }
        $parts[] = '-P';
        $parts[] = escapeshellarg((string) $port);
        $user = $config->getUsername();
        if ($user !== '') {
            $parts[] = '-u';
            $parts[] = escapeshellarg($user);
        }
        $parts[] = escapeshellarg($database);
        $command = implode(' ', $parts) . ' < ' . escapeshellarg($snapshotPath);

        $this->runCommand(
            $command,
            $this->commandEnvironment('MYSQL_PWD', $config->getPassword()),
            'mysql'
        );
    }

    private function normalizeEngine(string $dbType): string
    {
        return match (strtolower(trim($dbType))) {
            'sqlite', 'sqlite3' => self::ENGINE_SQLITE,
            'pgsql', 'postgres', 'postgresql' => self::ENGINE_PGSQL,
            'mysql', 'mariadb' => self::ENGINE_MYSQL,
            default => throw new RuntimeException('Unsupported snapshot database engine: ' . $dbType),
        };
    }

    private function snapshotPath(string $releaseId, string $snapshotKey, string $engine): string
    {
        $extension = match ($engine) {
            self::ENGINE_SQLITE => '.sqlite',
            self::ENGINE_PGSQL => '.dump',
            default => '.sql',
        };

        return $this->baseDir()
            . 'var' . DIRECTORY_SEPARATOR
            . 'backup' . DIRECTORY_SEPARATOR
            . 'release' . DIRECTORY_SEPARATOR
            . $this->safeName($releaseId) . DIRECTORY_SEPARATOR
            . $snapshotKey . $extension;
    }

    private function baseDir(): string
    {
        $base = defined('BP') ? (string) constant('BP') : dirname(__DIR__, 6) . DIRECTORY_SEPARATOR;

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR;
    }

    private function safeName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?? '';
        $safe = trim($safe, '._-');
        if ($safe === '') {
            $safe = 'release-' . substr(hash('sha256', $value), 0, 12);
        }

        return $safe;
    }

    private function safeKeyName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9-]+/', '-', $value) ?? '';
        $safe = trim($safe, '-');
        if ($safe === '') {
            $safe = substr(hash('sha256', $value), 0, 12);
        }

        return $safe;
    }

    private function sqliteSourcePath(ConfigProviderInterface $config): string
    {
        $path = '';
        if (method_exists($config, 'getData')) {
            $path = (string) ($config->getData('path') ?? '');
        }
        if ($path === '') {
            $path = $config->getDatabase();
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function commandEnvironment(string $passwordKey, string $password): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        if ($password !== '') {
            $environment[$passwordKey] = $password;
        }

        return $environment;
    }

    /**
     * @param array<string, string> $environment
     */
    private function runCommand(string $command, array $environment, string $commandType): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException($commandType . ' could not be started');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim((string) ($stderr !== '' ? $stderr : $stdout));
            if (strlen($detail) > 500) {
                $detail = substr($detail, 0, 500);
            }
            throw new RuntimeException(
                $commandType . ' failed with exit code ' . $exitCode
                . ($detail === '' ? '' : ': ' . $detail)
            );
        }
    }

    private function newSnapshotModel(): SchemaSnapshot
    {
        $model = clone $this->schemaSnapshot;
        $model->clearDataObject();
        $model->setConnection($this->connectionFactory);
        $model->reset();

        return $model;
    }
}
