<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Database\Service\Release\SchemaDiffExecutor;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Setup\Model\ModuleBackup;
use Weline\Framework\Setup\Model\ModuleTable;

/**
 * Framework 数据库引导阶段（order=0）
 * 创建 Framework bootstrap 表（NamespaceVersion / ModuleTable / ModuleBackup）与 RSSE 表，
 * 方言由适配器层实现，本阶段禁止使用 raw SQL 方言。
 */
class FrameworkDbBootstrapStage extends AbstractStage
{
    private const BOOTSTRAP_MODELS = [
        NamespaceVersion::class,
        ModuleTable::class,
        ModuleBackup::class,
    ];

    /**
     * Optional module-owned bootstrap tables. They must exist before schema_diff
     * so a deferred index job can be written during the same setup run.
     * The RSSE release ledger (weline_schema_*) is excluded from the release
     * manifest, so on a fresh database nothing but this stage creates it.
     * Class names are strings to keep Framework free of a hard module dependency.
     */
    private const OPTIONAL_BOOTSTRAP_MODELS = [
        'Weline\\Database\\Model\\SchemaIndexJob',
        'Weline\\Database\\Model\\SchemaIndexState',
        'Weline\\Database\\Model\\Release\\SchemaRelease',
        'Weline\\Database\\Model\\Release\\SchemaCheckpoint',
        'Weline\\Database\\Model\\Release\\SchemaScopeRecord',
        'Weline\\Database\\Model\\Release\\SchemaOperationEvent',
        'Weline\\Database\\Model\\Release\\SchemaSnapshot',
        'Weline\\Database\\Model\\Release\\SchemaChange',
        'Weline\\Database\\Model\\Release\\SchemaPhysicalState',
        'Weline\\Database\\Model\\Release\\TableOwner',
        'Weline\\Database\\Model\\Release\\BackupSet',
        'Weline\\Database\\Model\\Release\\BackupObject',
        'Weline\\Database\\Model\\Release\\DataTransformProgress',
    ];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaParser $schemaParser,
        private readonly SchemaDiffExecutor $schemaDiffExecutor,
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

        foreach (array_merge(self::BOOTSTRAP_MODELS, self::OPTIONAL_BOOTSTRAP_MODELS) as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }
            /** @var \Weline\Framework\Database\AbstractModel $model */
            $model = ObjectManager::getInstance($modelClass);
            $tableName = $model->getTable();
            if (!$connector->tableExist($tableName)) {
                $this->createTableFromModel($connector, $modelClass);
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
        $this->schemaDiffExecutor->createBootstrapTable($connector, $schema);
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

}
