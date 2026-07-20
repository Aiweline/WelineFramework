<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 数据库迁移记录模型（Framework 内置）
 * 表由 FrameworkDbBootstrapStage 创建（SchemaDiff 排除），不参与 Model setup/upgrade/install。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: 'Database migrations')]
class Migration extends Model implements ModelInterface
{
    public const SCHEMA_CHECKPOINT_FILE = 'schema_checkpoint';
    public const SCHEMA_CHECKPOINT_TYPE = 'schema_checkpoint';
    public const SCHEMA_CHECKPOINT_OPERATION = 'checkpoint';

    public const schema_table = 'weline_database_migrations';
    public const schema_primary_key = 'migration_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: 'Migration ID')]
    public const schema_fields_ID = 'migration_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Module Name')]
    public const schema_fields_MODULE = 'module_name';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Version')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Migration File')]
    public const schema_fields_FILE = 'migration_file';
    #[Col(type: 'text', nullable: true, comment: 'Description')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 50, nullable: false, default: 'pending', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'timestamp', nullable: true, comment: 'Executed At')]
    public const schema_fields_EXECUTED_AT = 'executed_at';
    #[Col(type: 'timestamp', nullable: true, comment: 'Rollback At')]
    public const schema_fields_ROLLBACK_AT = 'rollback_at';
    #[Col(type: 'text', nullable: true, comment: 'Dependencies')]
    public const schema_fields_DEPENDENCIES = 'dependencies';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Checksum')]
    public const schema_fields_CHECKSUM = 'checksum';
    #[Col(type: 'timestamp', nullable: true, comment: 'Created At')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: true, comment: 'Updated At')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col(type: 'longtext', nullable: true, comment: 'Schema diff forward DDL')]
    public const schema_fields_FORWARD_DDL = 'forward_ddl';
    #[Col(type: 'longtext', nullable: true, comment: 'Schema diff rollback DDL')]
    public const schema_fields_ROLLBACK_DDL = 'rollback_ddl';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Schema diff table name')]
    public const schema_fields_SCHEMA_TABLE_NAME = 'schema_table_name';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Connection name for multi-db')]
    public const schema_fields_CONNECTION_NAME = 'connection_name';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Migration batch identifier')]
    public const schema_fields_BATCH_ID = 'batch_id';
    #[Col(type: 'integer', nullable: false, default: 0, comment: 'Execution order inside a batch')]
    public const schema_fields_SEQUENCE = 'sequence_no';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'script', comment: 'Migration type')]
    public const schema_fields_MIGRATION_TYPE = 'migration_type';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Schema operation kind')]
    public const schema_fields_OPERATION_KIND = 'operation_kind';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Owning model class')]
    public const schema_fields_MODEL_CLASS = 'model_class';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Schema fingerprint before migration')]
    public const schema_fields_SCHEMA_BEFORE_CHECKSUM = 'schema_before_checksum';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Schema fingerprint after migration')]
    public const schema_fields_SCHEMA_AFTER_CHECKSUM = 'schema_after_checksum';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Owning version operation')]
    public const schema_fields_OPERATION_ID = 'operation_id';
    #[Col(type: 'longtext', nullable: true, comment: 'Schema operation payload')]
    public const schema_fields_OPERATION_PAYLOAD = 'operation_payload';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MANUAL = 'manual';

    public function getModuleMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->order(self::schema_fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    public function getInstalledMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->order(self::schema_fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    public function isMigrationExists(string $moduleName, string $migrationFile): bool
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->total() > 0;
    }

    public function recordMigration(array $data): int
    {
        $this->clearData();
        $this->setData([
            self::schema_fields_MODULE => $data['module_name'],
            self::schema_fields_VERSION => $data['version'],
            self::schema_fields_FILE => $data['migration_file'],
            self::schema_fields_DESCRIPTION => $data['description'] ?? '',
            self::schema_fields_STATUS => $data['status'],
            self::schema_fields_DEPENDENCIES => json_encode($data['dependencies'] ?? []),
            self::schema_fields_CHECKSUM => $data['checksum'] ?? '',
            self::schema_fields_EXECUTED_AT => $data['executed_at'] ?? date('Y-m-d H:i:s'),
            self::schema_fields_BATCH_ID => $data['batch_id'] ?? '',
            self::schema_fields_SEQUENCE => (int)($data['sequence_no'] ?? 0),
            self::schema_fields_MIGRATION_TYPE => $data['migration_type'] ?? 'script',
            self::schema_fields_OPERATION_KIND => $data['operation_kind'] ?? '',
            self::schema_fields_MODEL_CLASS => $data['model_class'] ?? null,
            self::schema_fields_SCHEMA_BEFORE_CHECKSUM => $data['schema_before_checksum'] ?? '',
            self::schema_fields_SCHEMA_AFTER_CHECKSUM => $data['schema_after_checksum'] ?? '',
            self::schema_fields_OPERATION_ID => $data['operation_id'] ?? '',
            self::schema_fields_OPERATION_PAYLOAD => $data['operation_payload'] ?? null,
        ]);

        $saved = $this->save();
        return $saved ? (int) $this->getId() : 0;
    }

    public function updateStatus(string $status): bool
    {
        $migrationId = (int)$this->getId();
        $this->setData(self::schema_fields_STATUS, $status);

        if ($status === self::STATUS_ROLLED_BACK) {
            $this->setData(self::schema_fields_ROLLBACK_AT, date('Y-m-d H:i:s'));
        }

        $this->save();
        if ($migrationId <= 0) {
            return false;
        }

        // 不依赖驱动的 affected-row 返回值，以持久化状态回读为准。
        return (clone $this)->reset()
            ->where(self::schema_fields_ID, $migrationId)
            ->where(self::schema_fields_STATUS, $status)
            ->total() === 1;
    }

    /**
     * 记录 Schema Diff 执行的 DDL（用于回滚）。
     * 先以 status=running 插入并返回 migration_id，执行 DDL 后应调用 updateStatus(STATUS_INSTALLED)。
     */
    public function recordSchemaDdl(
        string $moduleName,
        string $tableName,
        string $connectionName,
        string $forwardDdl,
        string $rollbackDdl,
        ?string $modelClass = null,
        string $moduleVersion = '',
        string $batchId = '',
        int $sequence = 0,
        string $operationKind = '',
        string $schemaBeforeChecksum = '',
        string $schemaAfterChecksum = '',
        string $operationId = '',
        array $operationPayload = [],
    ): int {
        $this->clearData();
        $this->setData([
            self::schema_fields_MODULE => $moduleName,
            self::schema_fields_VERSION => $moduleVersion !== '' ? $moduleVersion : date('Y-m-d H:i:s'),
            self::schema_fields_FILE => 'schema_diff',
            self::schema_fields_DESCRIPTION => $modelClass ?? $tableName,
            self::schema_fields_STATUS => self::STATUS_RUNNING,
            self::schema_fields_FORWARD_DDL => $forwardDdl,
            self::schema_fields_ROLLBACK_DDL => $rollbackDdl,
            self::schema_fields_SCHEMA_TABLE_NAME => $tableName,
            self::schema_fields_CONNECTION_NAME => $connectionName,
            self::schema_fields_BATCH_ID => $batchId,
            self::schema_fields_SEQUENCE => $sequence,
            self::schema_fields_MIGRATION_TYPE => 'schema_diff',
            self::schema_fields_OPERATION_KIND => $operationKind,
            self::schema_fields_MODEL_CLASS => $modelClass,
            self::schema_fields_SCHEMA_BEFORE_CHECKSUM => $schemaBeforeChecksum,
            self::schema_fields_SCHEMA_AFTER_CHECKSUM => $schemaAfterChecksum,
            self::schema_fields_OPERATION_ID => $operationId,
            self::schema_fields_OPERATION_PAYLOAD => json_encode(
                $operationPayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            ),
            self::schema_fields_CHECKSUM => hash('sha256', $forwardDdl . "\0" . $rollbackDdl),
        ]);
        $saved = $this->save();
        return $saved ? (int) $this->getId() : 0;
    }

    /**
     * Ensure an immutable semantic checkpoint can be written before any DDL is
     * executed. A module version is not allowed to describe two schemas.
     *
     * @param array<string, string> $tableFingerprints
     * @return array{migration_id: int, module_name: string, version: string, checksum: string, tables: array<string, string>}|null
     */
    public function assertSchemaCheckpointCompatible(
        string $moduleName,
        string $moduleVersion,
        array $tableFingerprints,
    ): ?array {
        $expected = $this->buildSchemaCheckpointPayload($tableFingerprints);
        $existing = $this->getSchemaCheckpoint($moduleName, $moduleVersion);
        if ($existing !== null && !hash_equals($existing['checksum'], $expected['checksum'])) {
            throw new \RuntimeException(__(
                '模块 %{1} 版本 %{2} 已存在不同的 Schema checkpoint；请先提升模块版本',
                [$moduleName, $moduleVersion]
            ));
        }

        return $existing;
    }

    /**
     * Record a versioned module schema checkpoint after all DDL has completed.
     * Repeated setup runs reuse the same immutable record.
     *
     * @param array<string, string> $tableFingerprints
     */
    public function recordSchemaCheckpoint(
        string $moduleName,
        string $moduleVersion,
        array $tableFingerprints,
        string $operationId = '',
    ): int {
        $existing = $this->assertSchemaCheckpointCompatible($moduleName, $moduleVersion, $tableFingerprints);
        if ($existing !== null) {
            return $existing['migration_id'];
        }

        $payload = $this->buildSchemaCheckpointPayload($tableFingerprints);
        return $this->recordMigration([
            'module_name' => $moduleName,
            'version' => $moduleVersion,
            'migration_file' => self::SCHEMA_CHECKPOINT_FILE,
            'description' => sprintf('Schema checkpoint %s %s', $moduleName, $moduleVersion),
            'status' => self::STATUS_INSTALLED,
            'dependencies' => [],
            'checksum' => $payload['checksum'],
            'migration_type' => self::SCHEMA_CHECKPOINT_TYPE,
            'operation_kind' => self::SCHEMA_CHECKPOINT_OPERATION,
            'schema_after_checksum' => $payload['checksum'],
            'operation_id' => $operationId,
            'operation_payload' => $payload['json'],
            'executed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{migration_id: int, module_name: string, version: string, checksum: string, tables: array<string, string>}|null
     */
    public function getSchemaCheckpoint(string $moduleName, string $moduleVersion): ?array
    {
        if (!$this->isSemanticVersion($moduleVersion)) {
            throw new \InvalidArgumentException(__('无效的模块语义版本: %{1}', $moduleVersion));
        }

        $items = (clone $this)->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_VERSION, $moduleVersion)
            ->where(self::schema_fields_FILE, self::SCHEMA_CHECKPOINT_FILE)
            ->select()
            ->fetch()
            ->getItems();
        if ($items === []) {
            return null;
        }

        $checkpoint = null;
        foreach ($items as $item) {
            if ((string)$item->getData(self::schema_fields_STATUS) !== self::STATUS_INSTALLED
                || (string)$item->getData(self::schema_fields_MIGRATION_TYPE) !== self::SCHEMA_CHECKPOINT_TYPE
                || (string)$item->getData(self::schema_fields_OPERATION_KIND) !== self::SCHEMA_CHECKPOINT_OPERATION) {
                throw new \RuntimeException(__(
                    '模块 %{1} 版本 %{2} 的 Schema checkpoint 状态或类型无效',
                    [$moduleName, $moduleVersion]
                ));
            }

            $decoded = json_decode((string)$item->getData(self::schema_fields_OPERATION_PAYLOAD), true);
            if (!is_array($decoded) || !is_array($decoded['tables'] ?? null)) {
                throw new \RuntimeException(__(
                    '模块 %{1} 版本 %{2} 的 Schema checkpoint 数据损坏',
                    [$moduleName, $moduleVersion]
                ));
            }
            $payload = $this->buildSchemaCheckpointPayload($decoded['tables']);
            $storedChecksum = trim((string)$item->getData(self::schema_fields_CHECKSUM));
            $storedSchemaChecksum = trim((string)$item->getData(self::schema_fields_SCHEMA_AFTER_CHECKSUM));
            if ($storedChecksum === ''
                || !hash_equals($storedChecksum, $payload['checksum'])
                || !hash_equals($storedSchemaChecksum, $payload['checksum'])) {
                throw new \RuntimeException(__(
                    '模块 %{1} 版本 %{2} 的 Schema checkpoint 校验和不一致',
                    [$moduleName, $moduleVersion]
                ));
            }
            if ($checkpoint !== null && !hash_equals($checkpoint['checksum'], $payload['checksum'])) {
                throw new \RuntimeException(__(
                    '模块 %{1} 版本 %{2} 存在冲突的 Schema checkpoints',
                    [$moduleName, $moduleVersion]
                ));
            }
            $checkpoint ??= [
                'migration_id' => (int)$item->getId(),
                'module_name' => $moduleName,
                'version' => $moduleVersion,
                'checksum' => $payload['checksum'],
                'tables' => $payload['tables'],
            ];
        }

        return $checkpoint;
    }

    /**
     * @return array{migration_id: int, module_name: string, version: string, checksum: string, tables: array<string, string>}|null
     */
    public function getLatestSchemaCheckpointBefore(string $moduleName, string $moduleVersion): ?array
    {
        if (!$this->isSemanticVersion($moduleVersion)) {
            throw new \InvalidArgumentException(__('无效的模块语义版本: %{1}', $moduleVersion));
        }

        $items = (clone $this)->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, self::SCHEMA_CHECKPOINT_FILE)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->select(self::schema_fields_VERSION)
            ->fetchArray();
        $versions = [];
        foreach ($items as $item) {
            $version = trim((string)($item[self::schema_fields_VERSION] ?? ''));
            if ($this->isSemanticVersion($version) && version_compare($version, $moduleVersion, '<')) {
                $versions[$version] = true;
            }
        }
        $versions = array_keys($versions);
        usort($versions, static fn(string $left, string $right): int => version_compare($right, $left));

        return $versions === [] ? null : $this->getSchemaCheckpoint($moduleName, $versions[0]);
    }

    /**
     * @param array<string, mixed> $tableFingerprints
     * @return array{json: string, checksum: string, tables: array<string, string>}
     */
    private function buildSchemaCheckpointPayload(array $tableFingerprints): array
    {
        $normalized = [];
        foreach ($tableFingerprints as $tableName => $fingerprint) {
            $tableName = trim((string)$tableName);
            $fingerprint = strtolower(trim((string)$fingerprint));
            if ($tableName === '' || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
                throw new \InvalidArgumentException(__('Schema checkpoint 包含无效的表或指纹'));
            }
            $normalized[$tableName] = $fingerprint;
        }
        ksort($normalized);
        $json = (string)json_encode(
            ['format' => 1, 'tables' => $normalized],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return [
            'json' => $json,
            'checksum' => hash('sha256', $json),
            'tables' => $normalized,
        ];
    }

    private function isSemanticVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version) === 1;
    }

    public function findMigrationId(string $moduleName, string $migrationFile): int
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_FILE, $migrationFile)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $first = $items[0] ?? null;
        return $first && $first->getId() ? (int) $first->getId() : 0;
    }

    /** @return array{total: int, installed: int, failed: int, pending: int} */
    public function getMigrationStats(string $moduleName): array
    {
        $total = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->total();
        $installed = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_INSTALLED)
            ->total();
        $failed = $this->reset()
            ->where(self::schema_fields_MODULE, $moduleName)
            ->where(self::schema_fields_STATUS, self::STATUS_FAILED)
            ->total();
        return [
            'total' => $total,
            'installed' => $installed,
            'failed' => $failed,
            'pending' => $total - $installed - $failed
        ];
    }
}
