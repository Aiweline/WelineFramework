<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Manager\ObjectManager;
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
        $needCols = ['forward_ddl', 'rollback_ddl', 'schema_table_name', 'connection_name'];
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
            $sql = $connector->buildAlterAddColumnSql($tableName, $colArr);
            if ($sql !== '') {
                $connector->query($sql)->fetch();
            }
        }
    }

    /**
     * 若 weline_database_backups 缺少 backup_id 或 migration_id，则重建表。
     * 使用 getTableColumns 校验实际列，避免 hasField 与表名格式不一致导致的误判。
     */
    private function ensureBackupsTableStructure(ConnectorInterface $connector, string $backupsTable): void
    {
        $requiredCols = ['backup_id', 'migration_id'];
        try {
            $cols = $connector->getTableColumns($backupsTable);
        } catch (\Throwable) {
            $cols = [];
        }
        $colNames = array_column($cols, 'name');
        $colSet = array_flip(array_map('strtolower', $colNames));
        $missing = array_filter($requiredCols, fn (string $c) => !isset($colSet[strtolower($c)]));
        if ($missing !== []) {
            $connector->dropTableIfExists($backupsTable);
            $this->createTableFromModel($connector, MigrationBackup::class);
        }
    }
}
