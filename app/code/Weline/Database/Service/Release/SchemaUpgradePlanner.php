<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use Throwable;
use Weline\Database\Model\Release\SchemaChange;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Database\Schema\PgsqlSchemaIndexNormalizer;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * 只读规划器：比较 release manifest 的声明式 Schema 与实际库表，产出
 * expand / transform / contract 变更节点。只规划，不执行任何 DDL，
 * 也不写 release/checkpoint；persist 仅登记 planned 变更节点。
 */
final class SchemaUpgradePlanner
{
    /** @var array<string, string> */
    private const PHASE_BY_KIND = [
        SchemaDiffOp::KIND_CREATE_TABLE => SchemaChange::PHASE_EXPAND,
        SchemaDiffOp::KIND_ADD_COLUMN => SchemaChange::PHASE_EXPAND,
        SchemaDiffOp::KIND_ADD_INDEX => SchemaChange::PHASE_EXPAND,
        SchemaDiffOp::KIND_ADD_FOREIGN_KEY => SchemaChange::PHASE_EXPAND,
        SchemaDiffOp::KIND_DROP_COLUMN => SchemaChange::PHASE_CONTRACT,
        SchemaDiffOp::KIND_DROP_INDEX => SchemaChange::PHASE_CONTRACT,
        SchemaDiffOp::KIND_DROP_FOREIGN_KEY => SchemaChange::PHASE_CONTRACT,
        SchemaDiffOp::KIND_MODIFY_COLUMN => SchemaChange::PHASE_CONTRACT,
        SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY => SchemaChange::PHASE_CONTRACT,
        SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT => SchemaChange::PHASE_CONTRACT,
    ];

    public function __construct(
        private readonly SchemaDiffEngine $diffEngine,
        private readonly SchemaChange $schemaChange,
        private readonly ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * 读取实际库表后规划；不执行 DDL。
     *
     * @param array<string, mixed> $manifest T43a SchemaManifestBuilder 输出
     * @param array{
     *     persist?: bool,
     *     operation_id?: string,
     *     scope_key?: string,
     *     db_type?: string
     * } $options
     * @return array{
     *     release_id: string,
     *     expand: list<array<string, mixed>>,
     *     transform: list<array<string, mixed>>,
     *     contract: list<array<string, mixed>>,
     *     blockers: list<array<string, mixed>>,
     *     table_count: int
     * }
     */
    public function plan(string $releaseId, array $manifest, array $options = []): array
    {
        $releaseId = \trim($releaseId);
        if ($releaseId === '') {
            throw new InvalidArgumentException('release id 不能为空');
        }

        $connector = $this->connectionFactory->getConnector();
        $databaseType = \strtolower((string)($options['db_type'] ?? $connector->getConfigProvider()->getDbType()));

        $declaredSchemas = [];
        $modules = [];
        $blockers = [];
        $manifestModules = \is_array($manifest['modules'] ?? null) ? $manifest['modules'] : [];
        foreach ($manifestModules as $moduleName => $module) {
            $moduleName = (string)$moduleName;
            if (!\is_array($module) || !\is_array($module['schemas'] ?? null)) {
                $blockers[] = [
                    'module' => $moduleName,
                    'table' => '',
                    'reason' => 'invalid_manifest_module',
                    'message' => 'manifest module 缺少 schemas 数组',
                ];
                continue;
            }
            foreach ($module['schemas'] as $tableName => $schema) {
                $tableName = (string)$tableName;
                try {
                    $declaredSchemas[$tableName] = $this->hydrateSchema($tableName, $schema);
                } catch (InvalidArgumentException $exception) {
                    $blockers[] = [
                        'module' => $moduleName,
                        'table' => $tableName,
                        'reason' => 'invalid_declared_schema',
                        'message' => $exception->getMessage(),
                    ];
                    continue;
                }
                $modules[$tableName] = $moduleName;
            }
        }

        $reader = new DbSchemaReader();
        $actualSchemas = [];
        foreach (\array_keys($declaredSchemas) as $tableName) {
            try {
                $actualSchemas[$tableName] = $reader->readTable($connector, $tableName);
            } catch (Throwable $exception) {
                $blockers[] = [
                    'module' => $modules[$tableName] ?? '',
                    'table' => $tableName,
                    'reason' => 'actual_read_failed',
                    'message' => $exception->getMessage(),
                ];
                // 读库失败不等同于表不存在：不得据此规划 create_table。
                unset($declaredSchemas[$tableName], $modules[$tableName]);
            }
        }

        $plan = $this->planTables($declaredSchemas, $actualSchemas, $releaseId, [
            'db_type' => $databaseType,
            'modules' => $modules,
            'index_identity' => static fn(string $tableName, string $indexName): string
                => IndexDefinitionContract::physicalIdentity($connector, $tableName, $indexName),
        ] + $options);
        $plan['blockers'] = \array_merge($blockers, $plan['blockers']);

        if (!empty($options['persist'])) {
            $this->persistChanges(
                $releaseId,
                \array_merge($plan['expand'], $plan['transform'], $plan['contract']),
                $options,
            );
        }

        return $plan;
    }

    /**
     * 纯规划入口：调用方提供声明与实际 Schema，不读库、不写库。
     *
     * @param array<string, TableSchema|array<string, mixed>> $declaredSchemas
     * @param array<string, TableSchema|array<string, mixed>|null> $actualSchemas
     * @param array{
     *     module?: string,
     *     modules?: array<string, string>,
     *     db_type?: string,
     *     index_identity?: callable(string, string): string
     * } $options
     * @return array{
     *     release_id: string,
     *     expand: list<array<string, mixed>>,
     *     transform: list<array<string, mixed>>,
     *     contract: list<array<string, mixed>>,
     *     blockers: list<array<string, mixed>>,
     *     table_count: int
     * }
     */
    public function planTables(
        array $declaredSchemas,
        array $actualSchemas,
        string $releaseId,
        array $options = [],
    ): array {
        $plan = [
            'release_id' => $releaseId,
            'expand' => [],
            'transform' => [],
            'contract' => [],
            'blockers' => [],
            'table_count' => 0,
        ];
        $databaseType = isset($options['db_type'])
            ? \strtolower((string)$options['db_type'])
            : null;
        $moduleMap = \is_array($options['modules'] ?? null) ? $options['modules'] : [];
        $defaultModule = isset($options['module']) ? (string)$options['module'] : '';
        $indexIdentity = \is_callable($options['index_identity'] ?? null)
            ? $options['index_identity']
            : null;
        $connector = $this->connectionFactory->getConnector();

        foreach ($declaredSchemas as $tableName => $declared) {
            $tableName = (string)$tableName;
            $module = (string)($moduleMap[$tableName] ?? $defaultModule);
            if (!$declared instanceof TableSchema) {
                $plan['blockers'][] = [
                    'module' => $module,
                    'table' => $tableName,
                    'reason' => 'invalid_declared_schema',
                    'message' => 'declared schema 必须是 TableSchema',
                ];
                continue;
            }
            $actual = $actualSchemas[$tableName] ?? null;
            if ($actual !== null && !$actual instanceof TableSchema) {
                $plan['blockers'][] = [
                    'module' => $module,
                    'table' => $tableName,
                    'reason' => 'invalid_actual_schema',
                    'message' => 'actual schema 必须是 TableSchema 或 null',
                ];
                continue;
            }

            $plan['table_count']++;
            if ($actual !== null
                && $databaseType !== null
                && in_array($databaseType, ['pgsql', 'postgres', 'postgresql'], true)
            ) {
                $actual = (new PgsqlSchemaIndexNormalizer())->normalize($connector, $declared, $actual);
            }
            $identity = $indexIdentity === null
                ? static fn(string $indexName): string => IndexDefinitionContract::physicalIdentity(
                    $connector,
                    $tableName,
                    $indexName,
                )
                : static fn(string $indexName): string
                    => (string)$indexIdentity($tableName, $indexName);

            try {
                $ops = $this->diffEngine->diff($declared, $actual, $databaseType, $identity);
            } catch (Throwable $exception) {
                $plan['blockers'][] = [
                    'module' => $module,
                    'table' => $tableName,
                    'reason' => 'diff_failed',
                    'message' => $exception->getMessage(),
                ];
                continue;
            }

            $addedColumns = [];
            foreach ($ops as $op) {
                if ($op instanceof SchemaDiffOp
                    && $op->kind === SchemaDiffOp::KIND_ADD_COLUMN
                    && $op->payload instanceof ColumnDefinition
                ) {
                    $addedColumns[strtolower($op->payload->name)] = true;
                }
            }

            foreach ($ops as $op) {
                if (!$op instanceof SchemaDiffOp) {
                    continue;
                }
                $phase = self::PHASE_BY_KIND[$op->kind] ?? null;
                // A unique index over a column added in this release cannot be
                // created in expand: existing rows all receive the column
                // default (often ''), so the unique build would fail before the
                // data transform can backfill it. Defer it to contract.
                if ($op->kind === SchemaDiffOp::KIND_ADD_INDEX
                    && $op->payload instanceof IndexDefinition
                    && strtoupper((string)$op->payload->type) === 'UNIQUE'
                ) {
                    foreach ($op->payload->columns as $indexedColumn) {
                        if (isset($addedColumns[strtolower((string)$indexedColumn)])) {
                            $phase = SchemaChange::PHASE_CONTRACT;
                            break;
                        }
                    }
                }
                if ($phase === null) {
                    $plan['blockers'][] = [
                        'module' => $module,
                        'table' => $tableName,
                        'reason' => 'unsupported_change',
                        'message' => $op->kind,
                    ];
                    continue;
                }
                $payload = $this->normalizePayload($op->payload);
                $rollbackPayload = $this->normalizePayload($op->rollbackPayload);
                $checksum = $this->checksum($payload, $rollbackPayload);
                $plan[$phase][] = [
                    'module' => $module,
                    'table' => $tableName,
                    'node_id' => $module . ':' . $tableName . ':' . $op->kind . ':' . \substr($checksum, 0, 12),
                    'change_type' => $op->kind,
                    'phase' => $phase,
                    'payload' => $payload,
                    'rollback_payload' => $rollbackPayload,
                    'checksum' => $checksum,
                ];
            }
        }

        return $plan;
    }

    /**
     * @param array<string, mixed>|TableSchema $schema
     */
    private function hydrateSchema(string $tableName, mixed $schema): TableSchema
    {
        if ($schema instanceof TableSchema) {
            return $schema;
        }
        if (!\is_array($schema)) {
            throw new InvalidArgumentException('schema 必须是数组或 TableSchema');
        }
        if ($tableName === '') {
            throw new InvalidArgumentException('表名不能为空');
        }

        $columns = [];
        foreach ((array)($schema['columns'] ?? []) as $column) {
            if (!\is_array($column) || (string)($column['name'] ?? '') === '') {
                throw new InvalidArgumentException('列声明缺少 name');
            }
            $columns[] = new ColumnDefinition(
                name: (string)$column['name'],
                type: (string)($column['type'] ?? ''),
                length: $column['length'] ?? null,
                nullable: (bool)($column['nullable'] ?? true),
                primaryKey: (bool)($column['primaryKey'] ?? false),
                autoIncrement: (bool)($column['autoIncrement'] ?? false),
                default: $column['default'] ?? null,
                comment: (string)($column['comment'] ?? ''),
                unique: (bool)($column['unique'] ?? false),
            );
        }

        $indexes = [];
        foreach ((array)($schema['indexes'] ?? []) as $index) {
            if (!\is_array($index) || (string)($index['name'] ?? '') === '') {
                throw new InvalidArgumentException('索引声明缺少 name');
            }
            $indexes[] = new IndexDefinition(
                name: (string)$index['name'],
                columns: \array_values(\array_map('strval', (array)($index['columns'] ?? []))),
                type: (string)($index['type'] ?? TableInterface::index_type_DEFAULT),
                comment: (string)($index['comment'] ?? ''),
                method: (string)($index['method'] ?? TableInterface::index_method_BTREE),
            );
        }

        $foreignKeys = [];
        foreach ((array)($schema['foreignKeys'] ?? []) as $foreignKey) {
            if (!\is_array($foreignKey) || (string)($foreignKey['name'] ?? '') === '') {
                throw new InvalidArgumentException('外键声明缺少 name');
            }
            $foreignKeys[] = new ForeignKeyDefinition(
                name: (string)$foreignKey['name'],
                columns: \array_values(\array_map('strval', (array)($foreignKey['columns'] ?? []))),
                referencesTable: (string)($foreignKey['referencesTable'] ?? ''),
                referencesColumns: \array_values(\array_map(
                    'strval',
                    (array)($foreignKey['referencesColumns'] ?? []),
                )),
                onDeleteCascade: (bool)($foreignKey['onDeleteCascade'] ?? false),
                onUpdateCascade: (bool)($foreignKey['onUpdateCascade'] ?? false),
            );
        }

        return new TableSchema(
            tableName: $tableName,
            comment: (string)($schema['comment'] ?? ''),
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            modelClass: null,
        );
    }

    private function normalizePayload(mixed $value): mixed
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
                $value[$key] = $this->normalizePayload($item);
            }
        }

        return $value;
    }

    private function checksum(mixed $payload, mixed $rollbackPayload): string
    {
        return \hash('sha256', (string)\json_encode(
            ['payload' => $payload, 'rollback_payload' => $rollbackPayload],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @param array<string, mixed> $options
     */
    private function persistChanges(string $releaseId, array $changes, array $options): void
    {
        $operationId = (string)($options['operation_id'] ?? $releaseId);
        $scopeKey = (string)($options['scope_key'] ?? '');
        $createdAt = \date('Y-m-d H:i:s');

        foreach ($changes as $change) {
            $model = $this->newChangeModel();
            $existing = $model
                ->where(SchemaChange::schema_fields_RELEASE_ID, $releaseId)
                ->where(SchemaChange::schema_fields_NODE_ID, (string)$change['node_id'])
                ->find()
                ->fetch();
            $existingId = (int)$existing->getId();
            $exists = $existingId > 0;
            if ($exists
                && (string)$existing->getData(SchemaChange::schema_fields_STATUS) !== SchemaChange::STATUS_PLANNED
            ) {
                continue;
            }

            $data = [
                SchemaChange::schema_fields_RELEASE_ID => $releaseId,
                SchemaChange::schema_fields_OPERATION_ID => $operationId,
                SchemaChange::schema_fields_SCOPE_KEY => $scopeKey,
                SchemaChange::schema_fields_NODE_ID => (string)$change['node_id'],
                SchemaChange::schema_fields_MODULE_NAME => (string)$change['module'],
                SchemaChange::schema_fields_TABLE_NAME => (string)$change['table'],
                SchemaChange::schema_fields_CHANGE_TYPE => (string)$change['change_type'],
                SchemaChange::schema_fields_PHASE => (string)$change['phase'],
                SchemaChange::schema_fields_PAYLOAD_JSON => $this->encode($change['payload']),
                SchemaChange::schema_fields_ROLLBACK_PAYLOAD_JSON => $change['rollback_payload'] === null
                    ? null
                    : $this->encode($change['rollback_payload']),
                SchemaChange::schema_fields_CHECKSUM => (string)$change['checksum'],
                SchemaChange::schema_fields_STATUS => SchemaChange::STATUS_PLANNED,
            ];

            if ($exists) {
                $model->clear()->reset()
                    ->where(SchemaChange::schema_fields_ID, $existingId)
                    ->update($data)
                    ->fetch();
                continue;
            }

            $data[SchemaChange::schema_fields_CREATED_AT] = $createdAt;
            $model->clear()->reset()->insert($data)->fetch();
        }
    }

    private function newChangeModel(): SchemaChange
    {
        $model = clone $this->schemaChange;
        $model->clearDataObject();
        $model->setConnection($this->connectionFactory);
        $model->reset();

        return $model;
    }

    private function encode(mixed $value): string
    {
        return (string)\json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
