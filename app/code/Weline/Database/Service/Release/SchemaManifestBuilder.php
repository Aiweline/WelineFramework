<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use ReflectionClass;
use RuntimeException;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaDiffExcludedModelInterface;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\SchemaProviderInterface;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderInterface;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderRegistry;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;

/** 从模块声明构建发布级 schema manifest（只读声明侧，不读实际库）。 */
final class SchemaManifestBuilder
{
    private const EXCLUDED_TABLE_PREFIXES = [
        'weline_database_',
        'weline_framework_field_',
        'weline_module_',
        'weline_schema_',
    ];

    public function __construct(
        private readonly Handle $handle,
        private readonly ModuleFileReader $moduleFileReader,
        private readonly SchemaParser $schemaParser,
        private readonly SchemaFingerprint $schemaFingerprint,
        private readonly ?ShardSchemaFamilyProviderRegistry $shardProviderRegistry = null,
        private readonly ?DbSchemaReader $schemaReader = null,
        private readonly ?ConnectionFactory $connectionFactory = null,
    ) {
    }

    /**
     * Build the manifest from the actual database schema (baseline/cutover).
     *
     * @param list<string> $moduleFilter
     * @return array<string, mixed>
     */
    public function buildActual(array $moduleFilter = []): array
    {
        $schemaReader = $this->schemaReader ?? \Weline\Framework\Manager\ObjectManager::getInstance(DbSchemaReader::class);
        $connectionFactory = $this->connectionFactory ?? \Weline\Framework\Manager\ObjectManager::getInstance(ConnectionFactory::class);
        $declared = $this->build($moduleFilter);
        $connector = $connectionFactory->getConnector();
        $modules = [];
        foreach ($declared['modules'] as $moduleName => $module) {
            $tables = [];
            $schemas = [];
            foreach ($module['tables'] as $tableName => $_fingerprint) {
                $actual = $schemaReader->readTable($connector, (string)$tableName);
                if ($actual === null) {
                    continue;
                }
                $tables[$tableName] = $this->schemaFingerprint->fingerprint($actual);
                $schemas[$tableName] = $this->schemaToArray($actual);
            }
            if ($tables === []) {
                continue;
            }
            $modules[$moduleName] = [
                'version' => (string)($module['version'] ?? '0.0.0'),
                'tables' => $tables,
                'schemas' => $schemas,
                'checksum' => $this->hash($tables),
            ];
        }
        \ksort($modules);

        return [
            'modules' => $modules,
            'checksum' => $this->hash($modules),
            'generated_at' => \date('c'),
            'actual' => true,
        ];
    }

    /**
     * @param list<string> $moduleFilter 为空表示全部模块
     * @return array{
     *     modules: array<string, array{
     *         version: string,
     *         tables: array<string, string>,
     *         schemas: array<string, array<string, mixed>>,
     *         checksum: string
     *     }>,
     *     checksum: string,
     *     generated_at: string
     * }
     */
    public function build(array $moduleFilter = []): array
    {
        $modules = [];
        $tableFingerprints = [];
        $tableOwners = [];

        $moduleDataList = $this->handle->getModules();
        usort($moduleDataList, static function ($left, $right): int {
            $leftName = is_array($left) ? (string)($left['name'] ?? '') : '';
            $rightName = is_array($right) ? (string)($right['name'] ?? '') : '';
            return strcmp($leftName, $rightName);
        });
        foreach ($moduleDataList as $moduleData) {
            $module = new Module($moduleData);
            $moduleName = $module->getName();
            if ($moduleName === '' || !$this->isModuleSelected($moduleName, $moduleFilter)) {
                continue;
            }
            $version = $module->getVersion() !== '' ? $module->getVersion() : '0.0.0';
            foreach ($this->moduleFileReader->readClass($module, 'Model') as $modelClass) {
                if (!\is_string($modelClass) || !\class_exists($modelClass)) {
                    continue;
                }
                $reflection = new ReflectionClass($modelClass);
                if ($reflection->isAbstract()
                    || $reflection->isInterface()
                    || $reflection->isTrait()
                    || $reflection->implementsInterface(SchemaDiffExcludedModelInterface::class)
                ) {
                    continue;
                }
                $schema = $this->schemaParser->parse($modelClass);
                if ($schema === null) {
                    continue;
                }
                $this->addSchema($modules, $tableFingerprints, $tableOwners, $moduleName, $version, $schema);
            }
        }

        if ($this->shardProviderRegistry !== null) {
            $this->collectProviderSchemas($modules, $tableFingerprints, $tableOwners);
        }

        foreach ($modules as &$module) {
            \ksort($module['tables']);
            \ksort($module['schemas']);
            $module['checksum'] = $this->hash($module['tables']);
        }
        unset($module);

        \ksort($modules);

        return [
            'modules' => $modules,
            'checksum' => $this->hash($modules),
            'generated_at' => \date('c'),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @param array<string, string> $tableFingerprints
     * @param array<string, string> $tableOwners
     */
    private function collectProviderSchemas(
        array &$modules,
        array &$tableFingerprints,
        array &$tableOwners,
    ): void {
        foreach ($this->shardProviderRegistry?->getAllSchemaProviders() ?? [] as $provider) {
            if (!$provider instanceof SchemaProviderInterface) {
                continue;
            }
            if ($provider instanceof ShardSchemaFamilyProviderInterface) {
                $family = $provider->getFamilyCode();
                if ($family === '') {
                    continue;
                }
                $moduleName = 'shard:' . $family;
                $version = $provider->getSchemaVersion() !== '' ? $provider->getSchemaVersion() : '0.0.0';
            } else {
                $moduleName = $this->providerModuleName($provider);
                $version = '0.0.0';
            }
            foreach ($provider->getTableSchemas() as $schema) {
                if (!$schema instanceof TableSchema) {
                    continue;
                }
                $this->addSchema($modules, $tableFingerprints, $tableOwners, $moduleName, $version, $schema);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @param array<string, string> $tableFingerprints
     * @param array<string, string> $tableOwners
     */
    private function addSchema(
        array &$modules,
        array &$tableFingerprints,
        array &$tableOwners,
        string $moduleName,
        string $version,
        TableSchema $schema,
    ): void {
        $normalized = $this->schemaFingerprint->normalizeSchema($schema);
        $tableName = $normalized->tableName;
        if ($tableName === '' || $this->isExcludedTable($tableName)) {
            return;
        }

        $fingerprint = $this->schemaFingerprint->fingerprint($normalized);
        $existing = $tableFingerprints[$tableName] ?? null;
        $existingOwner = $tableOwners[$tableName] ?? null;
        if ($existing !== null && $existing === $fingerprint && $existingOwner !== $moduleName) {
            // Equivalent cross-module projections are allowed; the first
            // deterministic owner owns the physical table and checkpoint.
            return;
        }
        if ($existing !== null && $existing !== $fingerprint) {
            // Same module: existing SchemaDiff ownership keeps the first declaration.
            if ($existingOwner === $moduleName) {
                return;
            }
            throw new RuntimeException(\sprintf(
                '表 %s 的声明式 Schema 不一致：%s 与 %s',
                $tableName,
                $existingOwner ?? 'unknown',
                $moduleName,
            ));
        }
        $tableFingerprints[$tableName] = $fingerprint;
        $tableOwners[$tableName] ??= $moduleName;

        if (!isset($modules[$moduleName])) {
            $modules[$moduleName] = [
                'version' => $version,
                'tables' => [],
                'schemas' => [],
            ];
        }
        $modules[$moduleName]['tables'][$tableName] = $fingerprint;
        $modules[$moduleName]['schemas'][$tableName] = $this->schemaToArray($normalized);
    }

    /** @param list<string> $moduleFilter */
    private function isModuleSelected(string $moduleName, array $moduleFilter): bool
    {
        return $moduleFilter === [] || \in_array($moduleName, $moduleFilter, true);
    }

    /** 排除 RSSE/框架自管表；物理表名可能带部署前缀，故按包含判断。 */
    private function isExcludedTable(string $tableName): bool
    {
        foreach (self::EXCLUDED_TABLE_PREFIXES as $prefix) {
            if (\str_contains($tableName, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** Provider 类名 Weline\Eav\... 归属模块 Weline_Eav。 */
    private function providerModuleName(SchemaProviderInterface $provider): string
    {
        $parts = \explode('\\', $provider::class);
        if (\count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }
        return $parts[0];
    }

    /** @return array<string, mixed> */
    private function schemaToArray(TableSchema $schema): array
    {
        $columns = [];
        foreach ($schema->columns as $column) {
            if (!$column instanceof ColumnDefinition) {
                continue;
            }
            $columns[] = [
                'name' => $column->name,
                'type' => $column->type,
                'length' => $column->length,
                'nullable' => $column->nullable,
                'primaryKey' => $column->primaryKey,
                'autoIncrement' => $column->autoIncrement,
                'default' => $column->default,
                'comment' => $column->comment,
                'unique' => $column->primaryKey ? false : $column->unique,
            ];
        }

        $indexes = [];
        foreach ($schema->indexes as $index) {
            if (!$index instanceof IndexDefinition) {
                continue;
            }
            $indexes[] = [
                'name' => $index->name,
                'columns' => $index->columns,
                'type' => $index->type,
                'method' => $index->method,
                'comment' => $index->comment,
            ];
        }

        $foreignKeys = [];
        foreach ($schema->foreignKeys as $foreignKey) {
            if (!$foreignKey instanceof ForeignKeyDefinition) {
                continue;
            }
            $foreignKeys[] = [
                'name' => $foreignKey->name,
                'columns' => $foreignKey->columns,
                'referencesTable' => $foreignKey->referencesTable,
                'referencesColumns' => $foreignKey->referencesColumns,
                'onDeleteCascade' => $foreignKey->onDeleteCascade,
                'onUpdateCascade' => $foreignKey->onUpdateCascade,
            ];
        }

        return [
            'comment' => $schema->comment,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
        ];
    }

    /** @param array<string, mixed> $data */
    private function hash(array $data): string
    {
        return \hash('sha256', (string)\json_encode(
            self::normalize($data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (\is_object($value)) {
            $value = \get_object_vars($value);
            unset($value['modelClass']);
        }
        if (\is_array($value)) {
            if (!\array_is_list($value)) {
                \ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }
        }
        return $value;
    }
}
