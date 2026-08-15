<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Model\MigrationBackup;
use Weline\Framework\Setup\Model\ModuleBackup;
use Weline\Framework\Setup\Model\ModuleTable;

/**
 * Framework 数据库引导阶段（order=0）
 * 创建 Migration、MigrationBackup 等 bootstrap 表，使用 connector->createTable() / buildAlterAddColumnSql 等 API，
 * 方言由适配器层实现，本阶段禁止使用 raw SQL 方言。
 */
class FrameworkDbBootstrapStage extends AbstractStage
{
    private const BOOTSTRAP_MODELS = [
        NamespaceVersion::class,
        Migration::class,
        MigrationBackup::class,
        ModuleTable::class,
        ModuleBackup::class,
    ];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaParser $schemaParser,
        private readonly SchemaMigrationExecutor $schemaMigrationExecutor,
    ) {
    }

    public function getName(): string
    {
        return 'framework_db_bootstrap';
    }

    public function prepare(array $context = []): void
    {
        if ($this->prepared) {
            return;
        }
        $this->prepared = true;
        $this->clearErrors();
    }

    public function validate(): bool
    {
        return parent::validate();
    }

    public function commit(): void
    {
        if (!$this->prepared) {
            throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$this->getName()]));
        }
        if ($this->committed) {
            return;
        }

        $connector = $this->connectionFactory->getConnector();

        foreach (self::BOOTSTRAP_MODELS as $modelClass) {
            /** @var \Weline\Framework\Database\AbstractModel $model */
            $model = ObjectManager::getInstance($modelClass);
            $tableName = $model->getTable();
            if (!$connector->tableExist($tableName)) {
                $this->createTableFromModel($connector, $modelClass);
            } elseif ($modelClass === Migration::class) {
                $this->ensureMigrationsSchemaDdlColumns($connector, $tableName);
            } elseif ($modelClass === MigrationBackup::class) {
                $this->ensureBackupsTableStructure($connector, $tableName);
            } elseif ($modelClass === ModuleTable::class) {
                $this->ensureModuleTablePolicyColumns($connector, $tableName);
            }
        }

        $this->committed = true;
        $this->clearErrors();
    }

    public function rollback(): void
    {
        if (!$this->prepared) {
            return;
        }
        $this->prepared = false;
        $this->committed = false;
    }

    private function createTableFromModel(ConnectorInterface $connector, string $modelClass): void
    {
        $schema = $this->schemaParser->parse($modelClass);
        if ($schema === null) {
            return;
        }
        $this->schemaMigrationExecutor->createBootstrapTable($connector, $schema);
    }

    /**
     * 确保 migrations 表包含 schema diff 相关列；缺列则通过 connector->buildAlterAddColumnSql 添加。
     */
    private function ensureMigrationsSchemaDdlColumns(ConnectorInterface $connector, string $tableName): void
    {
        $schema = $this->schemaParser->parse(Migration::class);
        if ($schema === null) {
            return;
        }
        $needCols = [
            'forward_ddl',
            'rollback_ddl',
            'schema_table_name',
            'connection_name',
            'batch_id',
            'sequence_no',
            'migration_type',
            'operation_kind',
            'model_class',
            'schema_before_checksum',
            'schema_after_checksum',
            'operation_id',
            'operation_payload',
        ];
        foreach ($schema->columns as $col) {
            if (!in_array($col->name, $needCols, true)) {
                continue;
            }
            if ($connector->hasField($tableName, $col->name)) {
                continue;
            }
            $colArr = [
                'name' => $col->name,
                'type' => $col->type,
                'length' => $col->length,
                'nullable' => $col->nullable,
                'primaryKey' => $col->primaryKey,
                'autoIncrement' => $col->autoIncrement,
                'default' => $col->default,
                'comment' => $col->comment,
                'unique' => $col->unique,
            ];
            $sql = $connector->buildAlterAddColumnSql($connector->formatTableName($tableName), $colArr);
            if ($sql !== '') {
                $connector->query($sql)->fetch();
            }
        }
    }

    /**
     * 若 weline_database_backups 缺少 backup_id 或 migration_id，则重建表。
     * 使用 getTableColumns 校验实际列，避免 hasField 与表名格式不一致导致的误判。
     * 探测异常必须硬失败，禁止当成空列集后 DROP（避免瞬时错误删光迁移备份）。
     */
    private function ensureBackupsTableStructure(ConnectorInterface $connector, string $backupsTable): void
    {
        $requiredCols = ['backup_id', 'migration_id'];
        try {
            $cols = $connector->getTableColumns($backupsTable);
        } catch (\Throwable $e) {
            throw new Exception(__(
                '无法探测备份表 %{1} 结构，已中止升级以避免误删备份数据：%{2}',
                [$backupsTable, $e->getMessage()]
            ), 0, $e);
        }
        $colNames = array_column($cols, 'name');
        $colSet = array_flip(array_map('strtolower', $colNames));
        $missing = array_filter($requiredCols, fn (string $c) => !isset($colSet[strtolower($c)]));
        if ($missing !== []) {
            $rowCount = $this->countBackupTableRows($connector, $backupsTable);
            if ($rowCount > 0) {
                throw new Exception(__(
                    '备份表 %{1} 缺少列 %{2} 且已有 %{3} 行数据，拒绝自动 DROP 重建；请先转存备份后再升级',
                    [$backupsTable, implode(', ', $missing), (string)$rowCount]
                ));
            }
            $connector->dropTableIfExists($backupsTable);
            $this->createTableFromModel($connector, MigrationBackup::class);
            return;
        }

        $schema = $this->schemaParser->parse(MigrationBackup::class);
        if ($schema === null) {
            return;
        }
        $retentionColumns = [
            'column_name',
            'backup_scope',
            'operation_id',
            'source_backup_id',
            'retention_state',
            'retain_until',
            'restored_at',
        ];
        foreach ($schema->columns as $column) {
            if (!in_array($column->name, $retentionColumns, true)
                || $connector->hasField($backupsTable, $column->name)) {
                continue;
            }
            $sql = $connector->buildAlterAddColumnSql($connector->formatTableName($backupsTable), [
                'name' => $column->name,
                'type' => $column->type,
                'length' => $column->length,
                'nullable' => $column->nullable,
                'primaryKey' => $column->primaryKey,
                'autoIncrement' => $column->autoIncrement,
                'default' => $column->default,
                'comment' => $column->comment,
                'unique' => $column->unique,
            ]);
            if ($sql !== '') {
                $connector->query($sql)->fetch();
            }
        }
    }

    /**
     * weline_module_table 不参与 SchemaDiff；旧库可能缺 table_policy 默认值，导致登记 INSERT 失败。
     */
    private function ensureModuleTablePolicyColumns(ConnectorInterface $connector, string $tableName): void
    {
        $schema = $this->schemaParser->parse(ModuleTable::class);
        if ($schema === null) {
            return;
        }
        $needCols = [
            ModuleTable::schema_fields_TABLE_POLICY,
            ModuleTable::schema_fields_OWNER_MODULE_NAME,
            ModuleTable::schema_fields_SUCCESSOR_MODULE_NAME,
            ModuleTable::schema_fields_DEPRECATED_AT,
        ];
        $byName = [];
        foreach ($schema->columns as $col) {
            $byName[$col->name] = $col;
            if (!in_array($col->name, $needCols, true)) {
                continue;
            }
            if ($connector->hasField($tableName, $col->name)) {
                continue;
            }
            $sql = $connector->buildAlterAddColumnSql($connector->formatTableName($tableName), [
                'name' => $col->name,
                'type' => $col->type,
                'length' => $col->length,
                'nullable' => $col->nullable,
                'primaryKey' => $col->primaryKey,
                'autoIncrement' => $col->autoIncrement,
                'default' => $col->default,
                'comment' => $col->comment,
                'unique' => $col->unique,
            ]);
            if ($sql !== '') {
                $connector->query($sql)->fetch();
            }
        }

        $policyCol = $byName[ModuleTable::schema_fields_TABLE_POLICY] ?? null;
        if ($policyCol === null || !$connector->hasField($tableName, $policyCol->name)) {
            return;
        }
        $existing = null;
        foreach ($connector->getTableColumns($tableName) as $row) {
            $name = (string)($row['name'] ?? $row['Field'] ?? '');
            if (strcasecmp($name, $policyCol->name) === 0) {
                $existing = $row;
                break;
            }
        }
        $observedDefault = trim((string)($existing['default'] ?? $existing['Default'] ?? ''));
        $expectedDefault = (string)($policyCol->default ?? ModuleTable::POLICY_OWNED);
        if ($observedDefault !== '' && (
            $observedDefault === $expectedDefault
            || str_contains($observedDefault, "'" . $expectedDefault . "'")
        )) {
            return;
        }

        // 禁止走 buildAlterModifyColumnSql 多语句包：PDO 对无结果集 fetch 会崩。
        $quotedTable = $connector->quoteTable($tableName);
        $quotedCol = $connector->quoteIdentifier($policyCol->name);
        $literal = "'" . str_replace("'", "''", $expectedDefault) . "'";
        $connector->query(
            "UPDATE {$quotedTable} SET {$quotedCol} = {$literal} WHERE {$quotedCol} IS NULL OR {$quotedCol} = ''"
        )->fetch();
        $connector->query(
            "ALTER TABLE {$quotedTable} ALTER COLUMN {$quotedCol} SET DEFAULT {$literal}"
        )->fetch();
        $connector->query(
            "ALTER TABLE {$quotedTable} ALTER COLUMN {$quotedCol} SET NOT NULL"
        )->fetch();
    }

    private function countBackupTableRows(ConnectorInterface $connector, string $backupsTable): int
    {
        try {
            if (!$connector->tableExist($backupsTable)) {
                return 0;
            }
            $quoted = $connector->quoteTable($backupsTable);
            $result = $connector->query("SELECT COUNT(*) AS c FROM {$quoted}")->fetch();
            if (is_array($result)) {
                if (isset($result['c'])) {
                    return (int)$result['c'];
                }
                if (isset($result[0]['c'])) {
                    return (int)$result[0]['c'];
                }
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '无法统计备份表 %{1} 行数，已中止自动重建：%{2}',
                [$backupsTable, $e->getMessage()]
            ), 0, $e);
        }

        return 0;
    }
}
