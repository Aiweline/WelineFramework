<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Database\Schema\PgsqlSchemaIndexNormalizer;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\SchemaDiffExcludedModelInterface;
use Weline\Framework\Database\Schema\SchemaCheckpointIdentity;
use Weline\Framework\Database\Schema\SchemaProviderInterface;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderInterface;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderRegistry;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
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

    /** 不参与 SchemaDiff 的 Model 类（bootstrap/系统表，由 FrameworkDbBootstrapStage 创建；或表名动态依赖运行时数据的模型） */
    private const EXCLUDE_MODEL_CLASSES = [
        \Weline\Framework\Setup\Model\Migration::class,
        \Weline\Framework\Setup\Model\MigrationBackup::class,
        \Weline\Framework\Setup\Model\ModuleTable::class,
        \Weline\Framework\Setup\Model\ModuleBackup::class,
    ];

    /** @var list<SchemaDiffOp> */
    private array $diffOps = [];

    /** @var array<string, string> */
    private array $moduleVersions = [];

    /** @var array<string, array{before: string, after: string}> */
    private array $tableFingerprints = [];

    /** @var array<string, array<string, string>> */
    private array $moduleSchemaFingerprints = [];

    /** @var array<string, array<string, string>> */
    private array $moduleSchemaLegacyFingerprints = [];

    /** @var array<string, array<string, string>> */
    private array $moduleSchemaHistoricalFingerprints = [];

    /** @var array<string, list<array<string, string>>> */
    private array $moduleSchemaFingerprintCandidates = [];

    /** @var array<string, array<string, string>> */
    private array $moduleCheckpointSources = [];

    /** @var list<string> */
    private array $checkpointRuntimeQualifiers = [];

    private string $operationId = '';

    private bool $forceSchemaRebind = false;

    public function __construct(
        private readonly Handle $moduleHandle,
        private readonly ModuleFileReader $moduleReader,
        private readonly ConnectionFactory $connectionFactory,
        private readonly SchemaParser $schemaParser,
        private readonly DbSchemaReader $dbSchemaReader,
        private readonly SchemaDiffEngine $diffEngine,
        private readonly SchemaMigrationExecutor $executor,
        private readonly Printing $printing,
        private readonly ShardSchemaFamilyProviderRegistry $schemaProviderRegistry,
        private readonly PgsqlSchemaIndexNormalizer $pgsqlIndexNormalizer = new PgsqlSchemaIndexNormalizer(),
    ) {
    }

    public function getName(): string
    {
        return 'schema_diff';
    }

    public function prepare(array $context = []): void
    {
        $operationId = trim((string)($context['operation_id'] ?? ''));
        if (strlen($operationId) > 64) {
            throw new Exception(__('operation_id 长度不能超过 64 字符'));
        }
        if ($this->prepared) {
            if ($this->operationId !== $operationId) {
                throw new Exception(__('SchemaDiffStage 不能重新绑定到其他 operation_id'));
            }
            return;
        }
        $this->operationId = $operationId;
        $this->forceSchemaRebind = !empty($context['force_schema_rebind']);
        $connector = $this->connectionFactory->getConnector();
        $modules = $this->moduleHandle->getModules();
        $this->diffOps = [];
        $this->moduleVersions = [];
        $this->tableFingerprints = [];
        $this->moduleSchemaFingerprints = [];
        $this->moduleSchemaLegacyFingerprints = [];
        $this->moduleSchemaHistoricalFingerprints = [];
        $this->moduleSchemaFingerprintCandidates = [];
        $this->moduleCheckpointSources = [];
        $this->checkpointRuntimeQualifiers = SchemaCheckpointIdentity::runtimeQualifiers($connector);
        $processedTables = [];

        // ── Pass 1: 收集所有需要 diff 的表及其声明 schema ──
        /** @var array<string, \Weline\Framework\Database\Schema\TableSchema> $declaredSchemas */
        $declaredSchemas = [];

        foreach ($modules as $moduleData) {
            $module = new Module($moduleData);
            $this->moduleVersions[$module->getName()] = $module->getVersion();
            $this->moduleSchemaFingerprints[$module->getName()] ??= [];
            $this->moduleSchemaLegacyFingerprints[$module->getName()] ??= [];
            $this->moduleSchemaHistoricalFingerprints[$module->getName()] ??= [];
            $this->moduleCheckpointSources[$module->getName()] ??= [];
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
                if (is_subclass_of($modelClass, SchemaDiffExcludedModelInterface::class)) {
                    continue;
                }
                $declared = $this->schemaParser->parse($modelClass);
                if ($declared === null) {
                    continue;
                }
                if (in_array($declared->tableName, self::EXCLUDE_TABLES, true)) {
                    continue;
                }
                $processedTableKey = $this->normalizeProcessedTableKey($declared->tableName);
                if (isset($processedTables[$processedTableKey])) {
                    continue;
                }
                $processedTables[$processedTableKey] = true;
                $declaredSchemas[$declared->tableName] = $declared;
                $checkpointTableName = SchemaCheckpointIdentity::tableName(
                    $declared->tableName,
                    $this->checkpointRuntimeQualifiers,
                );
                $checkpointSource = SchemaCheckpointIdentity::qualifiedTableName($declared->tableName);
                $this->registerModuleCheckpointSource(
                    $module->getName(),
                    $checkpointTableName,
                    $checkpointSource,
                );
                $this->moduleSchemaFingerprints[$module->getName()][$checkpointTableName]
                    = $this->schemaFingerprint($declared, true);
                $historicalTableName = SchemaCheckpointIdentity::legacyTableName(
                    $declared->tableName,
                    $this->checkpointRuntimeQualifiers,
                );
                $this->moduleSchemaHistoricalFingerprints[$module->getName()][$historicalTableName]
                    = $this->schemaFingerprint(
                        SchemaCheckpointIdentity::legacySchema($declared, $this->checkpointRuntimeQualifiers),
                        false,
                    );
                $this->moduleSchemaLegacyFingerprints[$module->getName()][$declared->tableName]
                    = $this->schemaFingerprint($declared, false);
            }
        }

        // Pass 1b: extends SchemaProvider（含 Shard family 全量展开）合入同一 declaredSchemas
        $this->mergeSchemaProviders($declaredSchemas, $processedTables);

        foreach ($this->moduleSchemaFingerprints as $moduleName => $fingerprints) {
            $candidates = [$fingerprints];
            $historical = $this->moduleSchemaHistoricalFingerprints[$moduleName] ?? [];
            if (!in_array($historical, $candidates, true)) {
                $candidates[] = $historical;
            }
            $legacy = $this->moduleSchemaLegacyFingerprints[$moduleName] ?? [];
            if (!in_array($legacy, $candidates, true)) {
                $candidates[] = $legacy;
            }
            $this->moduleSchemaFingerprintCandidates[$moduleName] = $candidates;
        }

        // ── Pass 2: 批量读取已存在的表结构（N→1 tableExist 查询）──
        $tableNames = array_keys($declaredSchemas);
        $actualSchemas = $this->dbSchemaReader->readTablesBatch($connector, $tableNames);
        $databaseType = strtolower($connector->getConfigProvider()->getDbType());

        // ── Pass 3: 执行 diff ──
        foreach ($declaredSchemas as $tableName => $declared) {
            $actual = $actualSchemas[$tableName] ?? null;
            IndexDefinitionContract::assertAdapterLimits($connector, $declared->indexes);
            if ($databaseType === 'pgsql' && $actual instanceof TableSchema) {
                $actual = $this->pgsqlIndexNormalizer->normalize($connector, $declared, $actual);
            }
            $this->tableFingerprints[$tableName] = [
                'before' => $this->schemaFingerprint($actual, true),
                'after' => $this->schemaFingerprint($declared, true),
            ];
            $ops = $this->diffEngine->diff(
                $declared,
                $actual,
                $databaseType,
                static fn(string $indexName): string => IndexDefinitionContract::physicalIdentity(
                    $connector,
                    $declared->tableName,
                    $indexName,
                ),
            );
            foreach ($ops as $op) {
                $this->diffOps[] = $op;
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

        $connector = $this->connectionFactory->getConnector();
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $beforeEvent = [
                'module_versions' => $this->moduleVersions,
                'operation_id' => $this->operationId,
                'diff_op_count' => count($this->diffOps),
            ];
            $eventsManager->dispatch('Weline_Framework_Setup::before_schema_diff_commit', $beforeEvent);

            $this->executor->execute($connector, $this->diffOps, [
                'module_versions' => $this->moduleVersions,
                'table_fingerprints' => $this->tableFingerprints,
                'module_schema_fingerprints' => $this->moduleSchemaFingerprints,
                'module_schema_fingerprint_candidates' => $this->moduleSchemaFingerprintCandidates,
                'checkpoint_runtime_qualifiers' => $this->checkpointRuntimeQualifiers,
                'operation_id' => $this->operationId,
                'force_schema_rebind' => $this->forceSchemaRebind,
            ]);
        } catch (\Throwable $e) {
            $this->addError(__('Schema 执行失败：%{1}', [$e->getMessage()]));
            throw new Exception(__('Schema 执行失败：%{1}', [$e->getMessage()]), 0, $e);
        }

        $this->committed = true;
        $this->clearErrors();
    }

    /**
     * Declared Model fingerprints keyed by module (for setup:schema:check).
     *
     * @return array<string, array<string, string>>
     */
    public function getModuleSchemaFingerprints(): array
    {
        return $this->moduleSchemaFingerprints;
    }

    /**
     * Current, historical and legacy checkpoint identities keyed by module.
     *
     * Read-only gates must use the same compatibility candidates as the DDL
     * executor, otherwise quote-style-only checkpoint history becomes drift.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function getModuleSchemaFingerprintCandidates(): array
    {
        return $this->moduleSchemaFingerprintCandidates;
    }

    /**
     * @return array<string, string>
     */
    public function getModuleVersions(): array
    {
        return $this->moduleVersions;
    }

    public function rollback(): void
    {
        if (!$this->prepared) {
            return;
        }
        $this->prepared = false;
        $this->committed = false;
        $this->diffOps = [];
        $this->moduleVersions = [];
        $this->tableFingerprints = [];
        $this->moduleSchemaFingerprints = [];
        $this->moduleSchemaLegacyFingerprints = [];
        $this->moduleSchemaHistoricalFingerprints = [];
        $this->moduleSchemaFingerprintCandidates = [];
        $this->moduleCheckpointSources = [];
        $this->checkpointRuntimeQualifiers = [];
        $this->operationId = '';
    }

    /** @return list<SchemaDiffOp> */
    public function getDiffOps(): array
    {
        return $this->diffOps;
    }

    private function normalizeProcessedTableKey(string $tableName): string
    {
        return SchemaCheckpointIdentity::qualifiedTableName($tableName);
    }

    private function registerModuleCheckpointSource(
        string $moduleName,
        string $checkpointTableName,
        string $checkpointSource,
    ): void {
        $existingSource = $this->moduleCheckpointSources[$moduleName][$checkpointTableName] ?? null;
        if ($existingSource !== null && $existingSource !== $checkpointSource) {
            throw new Exception(__(
                '模块 %{1} 的表 %{2} 与 %{3} 产生相同 Schema checkpoint 身份 %{4}',
                [$moduleName, $existingSource, $checkpointSource, $checkpointTableName]
            ));
        }
        $this->moduleCheckpointSources[$moduleName][$checkpointTableName] = $checkpointSource;
    }

    private function schemaFingerprint(?object $schema, bool $canonical): string
    {
        if ($schema === null) {
            return hash('sha256', 'absent');
        }
        if ($canonical && $schema instanceof \Weline\Framework\Database\Schema\TableSchema) {
            $schema = SchemaCheckpointIdentity::schema($schema, $this->checkpointRuntimeQualifiers);
        }

        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_object($value)) {
                $value = get_object_vars($value);
                unset($value['modelClass']);
            }
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value);
                }
                foreach ($value as $key => $item) {
                    $value[$key] = $normalize($item);
                }
            }
            return $value;
        };

        return hash('sha256', (string)json_encode(
            $normalize($schema),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * @param array<string, TableSchema> $declaredSchemas
     * @param array<string, true> $processedTables
     */
    private function mergeSchemaProviders(array &$declaredSchemas, array &$processedTables): void
    {
        foreach ($this->schemaProviderRegistry->getAllSchemaProviders() as $provider) {
            if (!$provider instanceof SchemaProviderInterface) {
                continue;
            }
            $moduleName = $this->resolveProviderModuleName($provider);
            if ($provider instanceof ShardSchemaFamilyProviderInterface) {
                $this->moduleVersions[$moduleName] = $provider->getSchemaVersion();
            } else {
                $this->moduleVersions[$moduleName] ??= '0.0.0';
            }
            $this->moduleSchemaFingerprints[$moduleName] ??= [];
            $this->moduleSchemaLegacyFingerprints[$moduleName] ??= [];
            $this->moduleSchemaHistoricalFingerprints[$moduleName] ??= [];
            $this->moduleCheckpointSources[$moduleName] ??= [];

            try {
                $schemas = $provider->getTableSchemas();
                $checkpointSchemas = $provider instanceof ShardSchemaFamilyProviderInterface
                    ? $provider->getSchemaCheckpointTableSchemas()
                    : $schemas;
            } catch (\Throwable $e) {
                $this->addError(__(
                    'SchemaProvider %{1} 读取 Schema 声明失败：%{2}',
                    [$provider::class, $e->getMessage()],
                ));
                throw new Exception($this->errors[0] ?? 'SchemaDiff prepare failed', 0, $e);
            }

            foreach ($schemas as $declared) {
                if (!$declared instanceof TableSchema) {
                    throw new Exception(__(
                        'SchemaProvider %{1} 必须返回 TableSchema 列表',
                        [$provider::class],
                    ));
                }
                if (in_array($declared->tableName, self::EXCLUDE_TABLES, true)) {
                    continue;
                }
                $processedTableKey = $this->normalizeProcessedTableKey($declared->tableName);
                if (isset($processedTables[$processedTableKey])) {
                    throw new Exception(__(
                        'SchemaProvider %{1} 重复声明表 %{2}',
                        [$provider::class, $declared->tableName],
                    ));
                }
                $processedTables[$processedTableKey] = true;
                $declaredSchemas[$declared->tableName] = $declared;
            }

            foreach ($checkpointSchemas as $checkpointSchema) {
                $this->registerProviderCheckpointSchema(
                    $provider::class,
                    $moduleName,
                    $checkpointSchema,
                );
            }
        }
    }

    private function registerProviderCheckpointSchema(
        string $providerClass,
        string $moduleName,
        mixed $declared,
    ): void {
        if (!$declared instanceof TableSchema) {
            throw new Exception(__(
                'SchemaProvider %{1} 的 checkpoint 模板必须返回 TableSchema 列表',
                [$providerClass],
            ));
        }
        if (in_array($declared->tableName, self::EXCLUDE_TABLES, true)) {
            return;
        }

        $checkpointTableName = SchemaCheckpointIdentity::tableName(
            $declared->tableName,
            $this->checkpointRuntimeQualifiers,
        );
        $checkpointSource = SchemaCheckpointIdentity::qualifiedTableName($declared->tableName);
        $this->registerModuleCheckpointSource(
            $moduleName,
            $checkpointTableName,
            $checkpointSource,
        );
        $this->moduleSchemaFingerprints[$moduleName][$checkpointTableName]
            = $this->schemaFingerprint($declared, true);
        $historicalTableName = SchemaCheckpointIdentity::legacyTableName(
            $declared->tableName,
            $this->checkpointRuntimeQualifiers,
        );
        $this->moduleSchemaHistoricalFingerprints[$moduleName][$historicalTableName]
            = $this->schemaFingerprint(
                SchemaCheckpointIdentity::legacySchema($declared, $this->checkpointRuntimeQualifiers),
                false,
            );
        $this->moduleSchemaLegacyFingerprints[$moduleName][$declared->tableName]
            = $this->schemaFingerprint($declared, false);
    }

    private function resolveProviderModuleName(SchemaProviderInterface $provider): string
    {
        if ($provider instanceof ShardSchemaFamilyProviderInterface) {
            $family = $provider->getFamilyCode();
            if ($family !== '') {
                return 'shard:' . $family;
            }
        }
        $parts = explode('\\', $provider::class);
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }
        return $provider::class;
    }

}
