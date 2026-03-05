<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;

/**
 * Schema Diff 阶段（order=2）：解析 #[Col]、与库表 diff、执行 DDL，并派发 table_ddl_before/after。
 * 解析失败即硬失败。
 */
class SchemaDiffStage extends AbstractStage
{
    /** 不参与 SchemaDiff 的表（由 bootstrap 创建，表名不含前缀） */
    private const EXCLUDE_TABLES = [
        'weline_database_migrations',
        'weline_database_backups',
        'weline_module_table',
        'weline_module_backup',
    ];

    /** 不参与 SchemaDiff 的 Model 类（bootstrap/系统表，由 FrameworkDbBootstrapStage 创建） */
    private const EXCLUDE_MODEL_CLASSES = [
        \Weline\Framework\Setup\Model\Migration::class,
        \Weline\Framework\Setup\Model\MigrationBackup::class,
        \Weline\Framework\Setup\Model\ModuleTable::class,
        \Weline\Framework\Setup\Model\ModuleBackup::class,
        \Weline\Database\Model\Migration::class,
        \Weline\Database\Model\MigrationBackup::class,
    ];

    /** @var list<SchemaDiffOp> */
    private array $diffOps = [];

    public function __construct(
        private readonly Handle $moduleHandle,
        private readonly ModuleFileReader $moduleReader,
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaParser $schemaParser,
        private readonly DbSchemaReader $dbSchemaReader,
        private readonly SchemaDiffEngine $diffEngine,
        private readonly SchemaMigrationExecutor $executor,
        private readonly Printing $printing,
    ) {
    }

    public function getName(): string
    {
        return 'schema_diff';
    }

    public function prepare(array $context = []): void
    {
        if ($this->prepared) {
            return;
        }

        $connector = $this->connectionFactory->getConnector();
        $modules = $this->moduleHandle->getModules();
        $this->diffOps = [];
        $processedTables = [];

        foreach ($modules as $moduleData) {
            $module = new Module($moduleData);
            try {
                $modelClasses = $this->moduleReader->readClass($module, 'Model');
            } catch (\Throwable $e) {
                $this->addError(__('模块 %{1} 读取 Model 列表失败：%{2}', [$module->getName(), $e->getMessage()]));
                throw new Exception($this->errors[0] ?? 'SchemaDiff prepare failed', 0, $e);
            }

            foreach ($modelClasses as $modelClass) {
                if (!is_string($modelClass) || $modelClass === '') {
                    continue;
                }
                if (trait_exists($modelClass) || interface_exists($modelClass)) {
                    continue;
                }
                if (!class_exists($modelClass)) {
                    continue;
                }
                try {
                    $ref = new \ReflectionClass($modelClass);
                    if ($ref->isAbstract() || $ref->isTrait() || $ref->isInterface()) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }

                if (in_array($modelClass, self::EXCLUDE_MODEL_CLASSES, true)) {
                    continue;
                }
                $declared = $this->schemaParser->parse($modelClass);
                if ($declared === null) {
                    continue;
                }
                if (in_array($declared->tableName, self::EXCLUDE_TABLES, true)) {
                    continue;
                }
                if (isset($processedTables[$declared->tableName])) {
                    continue;
                }
                $processedTables[$declared->tableName] = true;

                $actual = $this->dbSchemaReader->readTable($connector, $declared->tableName);
                $ops = $this->diffEngine->diff($declared, $actual);
                foreach ($ops as $op) {
                    $this->diffOps[] = $op;
                }
            }
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

        if ($this->diffOps === []) {
            $this->committed = true;
            return;
        }

        $connector = $this->connectionFactory->getConnector();
        try {
            $this->executor->execute($connector, $this->diffOps);
        } catch (\Throwable $e) {
            $this->addError(__('Schema 执行失败：%{1}', [$e->getMessage()]));
            throw new Exception(__('Schema 执行失败：%{1}', [$e->getMessage()]), 0, $e);
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
        $this->diffOps = [];
    }

    /** @return list<SchemaDiffOp> */
    public function getDiffOps(): array
    {
        return $this->diffOps;
    }
}
