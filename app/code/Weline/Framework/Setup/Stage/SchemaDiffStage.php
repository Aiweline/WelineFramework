<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableSnapshotInterface;
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
use Weline\Framework\Php\FiberTaskBatch;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;

/**
 * Schema Diff 阶段（order=2）：解析 #[Col]、与库表 diff、执行 DDL，并派发 table_ddl_before/after。
 * 解析失败即硬失败。
 */
class SchemaDiffStage extends AbstractStage
{
    private bool $sourceFingerprintSkipped = false;

    /** @var array<string, string> prepare 产出、commit 成功后写入源指纹仓 */
    private array $pendingSourceFingerprints = [];

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

    /** @var array<string, string> */
    private array $physicalTableFingerprints = [];

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
        $this->physicalTableFingerprints = [];
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
        $modelDeclarations = [];

        $sourceFingerprint = new SetupSourceFingerprint();
        $forceRebind = $this->forceSchemaRebind;
        $moduleFingerprints = [];
        $allFresh = !$forceRebind && $modules !== [];
        foreach ($modules as $moduleData) {
            $module = new Module(\is_array($moduleData) ? $moduleData : []);
            $name = $module->getName();
            $basePath = \rtrim((string)$module->getBasePath(), DIRECTORY_SEPARATOR);
            $modelDir = $basePath !== '' ? $basePath . DIRECTORY_SEPARATOR . 'Model' : '';
            $schemaProviderDir = $basePath !== ''
                ? $basePath . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Schema'
                : '';
            $fpParts = [];
            if ($modelDir !== '' && \is_dir($modelDir)) {
                $fpParts[] = $sourceFingerprint->fingerprintTree($modelDir);
            }
            if ($schemaProviderDir !== '' && \is_dir($schemaProviderDir)) {
                $fpParts[] = $sourceFingerprint->fingerprintTree($schemaProviderDir);
            }
            $fp = $fpParts === []
                ? \hash('sha256', 'schema-empty:' . $name)
                : \hash('sha256', \implode('|', $fpParts));
            $moduleFingerprints['schema:' . $name] = $fp;
            if (!$sourceFingerprint->matches('schema:' . $name, $fp)) {
                $allFresh = false;
            }
            $this->moduleVersions[$name] = $module->getVersion();
            $this->moduleSchemaFingerprints[$name] ??= [];
            $this->moduleSchemaLegacyFingerprints[$name] ??= [];
            $this->moduleSchemaHistoricalFingerprints[$name] ??= [];
            $this->moduleCheckpointSources[$name] ??= [];
        }

        // 源指纹全员命中但库内无 schema checkpoint：产物空，禁止跳过 prepare
        if ($allFresh && !$this->schemaCheckpointsPresent()) {
            $allFresh = false;
            if (\PHP_SAPI === 'cli') {
                try {
                    ObjectManager::getInstance(Printing::class)->note(__(
                        '   - Schema checkpoint 产物为空，忽略 Model 源指纹，强制 prepare 扫描'
                    ));
                } catch (\Throwable) {
                }
            }
        }

        if ($allFresh) {
            $this->diffOps = [];
            $this->prepared = true;
            $this->sourceFingerprintSkipped = true;
            $this->clearErrors();
            if (\PHP_SAPI === 'cli') {
                try {
                    $printing = ObjectManager::getInstance(Printing::class);
                    $printing->note(__('   - SchemaDiff：全部模块 Model 源指纹未变，跳过 prepare 扫描'));
                } catch (\Throwable) {
                }
            }

            return;
        }

        // 禁止部分模块跳扫空 declarations（会误 DROP 未扫模块表）。
        // 全员命中已在上方 allFresh 短路；此处必须全量解析 Model。
        $schemaFpPending = [];
        $schemaCollectBatch = new FiberTaskBatch(null, true, 'WELINE_SETUP_FIBER_CONCURRENCY');
        $schemaCollectBatch->mapModules(
            $modules,
            function (string $moduleName, mixed $moduleData) use ($moduleFingerprints): array {
                $module = new Module(\is_array($moduleData) ? $moduleData : []);
                $name = $module->getName();
                $fpKey = 'schema:' . $name;
                $fp = (string)($moduleFingerprints[$fpKey] ?? '');
                $bag = [
                    'version' => $module->getVersion(),
                    'declarations' => [],
                    'source_fp' => $fp,
                    'fp_key' => $fpKey,
                ];

                try {
                    $modelClasses = $this->moduleReader->readClass($module, 'Model');
                } catch (\Throwable $e) {
                    throw new Exception(__('模块 %{1} 读取 Model 列表失败：%{2}', [$module->getName(), $e->getMessage()]), 0, $e);
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
                    $bag['declarations'][] = [
                        'table_key' => $processedTableKey,
                        'module' => $module->getName(),
                        'schema' => $declared,
                    ];
                }

                return $bag;
            },
            function (string $phase, array $ctx) use (&$modelDeclarations, &$schemaFpPending): void {
                if ($phase !== 'task' || !($ctx['ok'] ?? false)) {
                    return;
                }
                $moduleName = (string)($ctx['key'] ?? '');
                $bag = $ctx['result'] ?? null;
                if ($moduleName === '' || !\is_array($bag)) {
                    return;
                }
                $this->moduleVersions[$moduleName] = (string)($bag['version'] ?? '1.0.0');
                $this->moduleSchemaFingerprints[$moduleName] ??= [];
                $this->moduleSchemaLegacyFingerprints[$moduleName] ??= [];
                $this->moduleSchemaHistoricalFingerprints[$moduleName] ??= [];
                $this->moduleCheckpointSources[$moduleName] ??= [];
                $fpKey = (string)($bag['fp_key'] ?? ('schema:' . $moduleName));
                $fp = (string)($bag['source_fp'] ?? '');
                if ($fp !== '') {
                    $schemaFpPending[$fpKey] = $fp;
                }
                foreach (($bag['declarations'] ?? []) as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $tableKey = (string)($row['table_key'] ?? '');
                    if ($tableKey === '') {
                        continue;
                    }
                    $modelDeclarations[$tableKey][] = [
                        'module' => (string)($row['module'] ?? $moduleName),
                        'schema' => $row['schema'],
                    ];
                }
            },
            [
                'env' => 'WELINE_SETUP_FIBER_CONCURRENCY',
                'fail_fast' => true,
                'label' => 'schema-diff-collect',
                'keep_results' => false,
            ]
        );
        unset($schemaCollectBatch);

        $this->collectModelTableSchemas($modelDeclarations, $declaredSchemas, $processedTables);

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
            if ($actual instanceof TableSchema
                && $connector instanceof PhysicalTableIdentityProviderInterface
                && $connector instanceof PhysicalTableSnapshotInterface) {
                $identity = $connector->resolvePhysicalTableIdentity($tableName);
                $this->physicalTableFingerprints[$tableName]
                    = $connector->physicalTableCatalogFingerprint($identity);
            }
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

        // 源指纹仓仅在 commit 成功后落盘；prepare 失败/checkpoint 冲突不得写入，避免下次错误跳过。
        $this->pendingSourceFingerprints = [];
        foreach ($moduleFingerprints as $fpKey => $fpVal) {
            $this->pendingSourceFingerprints[(string)$fpKey] = (string)$fpVal;
        }
        foreach ($schemaFpPending as $fpKey => $fpVal) {
            $this->pendingSourceFingerprints[(string)$fpKey] = (string)$fpVal;
        }

        $this->prepared = true;
        $this->clearErrors();
    }

    public function wasSourceFingerprintSkipped(): bool
    {
        return $this->sourceFingerprintSkipped;
    }

    /**
     * 库内是否已有 schema checkpoint 产物。源指纹命中但 checkpoint 空时不得跳过 prepare。
     */
    private function schemaCheckpointsPresent(): bool
    {
        try {
            /** @var Migration $migration */
            $migration = ObjectManager::getInstance(Migration::class);
            $total = (int)$migration
                ->clear()
                ->where(Migration::schema_fields_MIGRATION_TYPE, Migration::SCHEMA_CHECKPOINT_TYPE)
                ->where(Migration::schema_fields_FILE, Migration::SCHEMA_CHECKPOINT_FILE)
                ->total();

            return $total > 0;
        } catch (\Throwable) {
            return false;
        }
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

        // 源指纹全员命中：无 DDL、无声明指纹重绑，禁止走 executor（避免空指纹触发 checkpoint 冲突假阳性）
        if ($this->sourceFingerprintSkipped) {
            $this->committed = true;
            $this->clearErrors();

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
                'physical_table_fingerprints' => $this->physicalTableFingerprints,
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

        if ($this->pendingSourceFingerprints !== []) {
            $fpService = new \Weline\Framework\Setup\Service\SetupSourceFingerprint();
            $fpService->mergeUpdates($this->pendingSourceFingerprints);
            $this->pendingSourceFingerprints = [];
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
        $this->physicalTableFingerprints = [];
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
     * Physical-table deduplication must not transfer checkpoint ownership when
     * the module discovery order changes. Existing equivalent owners retain
     * their maps; the physical table is still diffed only once.
     *
     * @param array<string, list<array{module: string, schema: TableSchema}>> $declarations
     * @param array<string, TableSchema> $declaredSchemas
     * @param array<string, bool> $processedTables
     */
    private function collectModelTableSchemas(
        array $declarations,
        array &$declaredSchemas,
        array &$processedTables,
    ): void {
        $checkpointTables = [];
        foreach ($declarations as $tableKey => $candidates) {
            // 同模块的同表投影保留原扫描首项；跨模块归属比较只使用各模块的代表声明。
            $moduleCandidates = [];
            foreach ($candidates as $candidate) {
                $moduleCandidates[$candidate['module']] ??= $candidate;
            }
            $candidates = array_values($moduleCandidates);
            usort($candidates, static fn(array $left, array $right): int =>
                strcmp($left['module'], $right['module'])
                ?: strcmp($left['schema']->modelClass ?? '', $right['schema']->modelClass ?? '')
            );
            $first = $candidates[0];
            $owners = [];
            if (count($candidates) > 1) {
                $fingerprint = $this->schemaFingerprint($first['schema'], true);
                foreach ($candidates as $candidate) {
                    if ($this->schemaFingerprint($candidate['schema'], true) !== $fingerprint) {
                        throw new Exception(__(
                            '表 %{1} 存在不一致的 Model Schema 声明：%{2} 与 %{3}',
                            [
                                $first['schema']->tableName,
                                $first['module'] . ':' . $first['schema']->modelClass,
                                $candidate['module'] . ':' . $candidate['schema']->modelClass,
                            ],
                        ));
                    }
                }
                foreach ($candidates as $candidate) {
                    $moduleName = $candidate['module'];
                    if (!array_key_exists($moduleName, $checkpointTables)) {
                        $checkpoint = $this->executor->getSchemaCheckpointForOwnership(
                            $moduleName,
                            $this->moduleVersions[$moduleName],
                        );
                        $checkpointTables[$moduleName] = [];
                        foreach ($checkpoint['tables'] ?? [] as $storedTable => $storedFingerprint) {
                            $identity = SchemaCheckpointIdentity::tableName(
                                $storedTable,
                                $this->checkpointRuntimeQualifiers,
                            );
                            $checkpointTables[$moduleName][$identity] = true;
                        }
                    }
                    $identity = SchemaCheckpointIdentity::tableName(
                        $candidate['schema']->tableName,
                        $this->checkpointRuntimeQualifiers,
                    );
                    if (isset($checkpointTables[$moduleName][$identity])) {
                        $owners[$moduleName] ??= $candidate;
                    }
                }
            }
            // A first installation has no historical owner. Use the same
            // deterministic representative regardless of discovery order.
            $owners = $owners ?: [$first['module'] => $first];
            $representative = reset($owners)['schema'];
            $processedTables[$tableKey] = true;
            $declaredSchemas[$representative->tableName] = $representative;
            foreach ($owners as $owner) {
                $this->registerModelCheckpointSchema($owner['module'], $owner['schema']);
            }
        }
    }

    private function registerModelCheckpointSchema(string $moduleName, TableSchema $declared): void
    {
        $checkpointTableName = SchemaCheckpointIdentity::tableName(
            $declared->tableName,
            $this->checkpointRuntimeQualifiers,
        );
        $this->registerModuleCheckpointSource(
            $moduleName,
            $checkpointTableName,
            SchemaCheckpointIdentity::qualifiedTableName($declared->tableName),
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
