<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use RuntimeException;
use Weline\Database\Model\Release\SchemaChange;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\PrimaryKeyPromotionDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * 把 SchemaChange 持久化的 payload 数组还原为 SchemaDiffOp。
 *
 * payload 结构由 SchemaUpgradePlanner 生成：对象先转数组、非 list 数组按键排序。
 */
final class SchemaChangeHydrator
{
    /**
     * @param array{
     *     change_type?: string,
     *     payload?: mixed,
     *     rollback_payload?: mixed,
     *     model_class?: string|null,
     *     table_name?: string
     * } $change
     */
    public function hydrate(array $change): SchemaDiffOp
    {
        $rawChangeType = $change['change_type'] ?? null;
        if (!\is_string($rawChangeType) || \trim($rawChangeType) === '') {
            throw new RuntimeException('schema change 缺少 change_type');
        }
        $changeType = \trim($rawChangeType);
        if (!\array_key_exists('payload', $change) || $change['payload'] === null) {
            throw new RuntimeException('schema change 缺少 payload; change_type=' . $changeType);
        }

        $modelClass = isset($change['model_class']) && \is_string($change['model_class'])
            ? $change['model_class']
            : null;
        $tableName = \trim((string)($change['table_name'] ?? ''));

        $payload = $this->hydratePayload(
            $changeType,
            $change['payload'],
            false,
            $modelClass,
            $tableName,
        );
        if ($payload instanceof TableSchema && $payload->tableName !== '') {
            $tableName = $payload->tableName;
        }

        $rollbackPayload = null;
        if (\array_key_exists('rollback_payload', $change) && $change['rollback_payload'] !== null) {
            $rollbackPayload = $this->hydratePayload(
                $changeType,
                $change['rollback_payload'],
                true,
                $modelClass,
                $tableName,
            );
        }

        return new SchemaDiffOp($changeType, $tableName, $payload, $modelClass, $rollbackPayload);
    }

    public function hydrateChange(SchemaChange $change): SchemaDiffOp
    {
        $payloadJson = $change->getData(SchemaChange::schema_fields_PAYLOAD_JSON);
        if (!\is_string($payloadJson) || \trim($payloadJson) === '') {
            throw new RuntimeException(
                'schema change 缺少 payload_json; change_id=' . (int)$change->getId()
            );
        }

        $rollbackJson = $change->getData(SchemaChange::schema_fields_ROLLBACK_PAYLOAD_JSON);
        $rollbackPayload = null;
        if (\is_string($rollbackJson) && \trim($rollbackJson) !== '') {
            $rollbackPayload = $this->decodeJson($rollbackJson, 'rollback_payload_json');
        }

        return $this->hydrate([
            'change_type' => (string)$change->getData(SchemaChange::schema_fields_CHANGE_TYPE),
            'payload' => $this->decodeJson($payloadJson, 'payload_json'),
            'rollback_payload' => $rollbackPayload,
            'table_name' => (string)$change->getData(SchemaChange::schema_fields_TABLE_NAME),
        ]);
    }

    private function hydratePayload(
        string $changeType,
        mixed $payload,
        bool $rollback,
        ?string $modelClass,
        string $fallbackTableName,
    ): mixed {
        switch ($changeType) {
            case SchemaDiffOp::KIND_CREATE_TABLE:
                return $this->hydrateTableSchema($payload, $modelClass, $fallbackTableName);

            case SchemaDiffOp::KIND_ADD_COLUMN:
            case SchemaDiffOp::KIND_DROP_COLUMN:
            case SchemaDiffOp::KIND_MODIFY_COLUMN:
                return $this->hydrateColumn($payload);

            case SchemaDiffOp::KIND_ADD_INDEX:
            case SchemaDiffOp::KIND_DROP_INDEX:
                return $this->hydrateIndex($payload);

            case SchemaDiffOp::KIND_ADD_FOREIGN_KEY:
            case SchemaDiffOp::KIND_DROP_FOREIGN_KEY:
                return $this->hydrateForeignKey($payload);

            case SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT:
                return $this->hydrateComment($payload);

            case SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY:
                return $this->hydratePrimaryKeyPromotion($payload, $rollback);
        }

        throw new RuntimeException('未知的 change_type: ' . $changeType);
    }

    private function hydrateTableSchema(mixed $payload, ?string $modelClass, string $fallbackTableName): TableSchema
    {
        if (!\is_array($payload)) {
            throw new RuntimeException('create_table payload 必须是数组');
        }

        $tableName = \trim((string)($payload['tableName'] ?? ''));
        if ($tableName === '') {
            $tableName = $fallbackTableName;
        }

        $columns = [];
        foreach ((array)($payload['columns'] ?? []) as $column) {
            $columns[] = $this->hydrateColumn($column);
        }

        $indexes = [];
        foreach ((array)($payload['indexes'] ?? []) as $index) {
            $indexes[] = $this->hydrateIndex($index);
        }

        $foreignKeys = [];
        foreach ((array)($payload['foreignKeys'] ?? []) as $foreignKey) {
            $foreignKeys[] = $this->hydrateForeignKey($foreignKey);
        }

        return new TableSchema(
            tableName: $tableName,
            comment: (string)($payload['comment'] ?? ''),
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            modelClass: $modelClass,
        );
    }

    private function hydrateColumn(mixed $payload): ColumnDefinition
    {
        if (!\is_array($payload)) {
            throw new RuntimeException('column payload 必须是数组');
        }
        $name = \trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('column payload 缺少 name');
        }

        return new ColumnDefinition(
            name: $name,
            type: (string)($payload['type'] ?? ''),
            length: $payload['length'] ?? null,
            nullable: (bool)($payload['nullable'] ?? true),
            primaryKey: (bool)($payload['primaryKey'] ?? false),
            autoIncrement: (bool)($payload['autoIncrement'] ?? false),
            default: $payload['default'] ?? null,
            comment: (string)($payload['comment'] ?? ''),
            unique: (bool)($payload['unique'] ?? false),
        );
    }

    private function hydrateIndex(mixed $payload): IndexDefinition
    {
        if (!\is_array($payload)) {
            throw new RuntimeException('index payload 必须是数组');
        }
        $name = \trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('index payload 缺少 name');
        }

        return new IndexDefinition(
            name: $name,
            columns: \array_values(\array_map('strval', (array)($payload['columns'] ?? []))),
            type: (string)($payload['type'] ?? TableInterface::index_type_DEFAULT),
            comment: (string)($payload['comment'] ?? ''),
            method: (string)($payload['method'] ?? TableInterface::index_method_BTREE),
        );
    }

    private function hydrateForeignKey(mixed $payload): ForeignKeyDefinition
    {
        if (!\is_array($payload)) {
            throw new RuntimeException('foreign key payload 必须是数组');
        }
        $name = \trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('foreign key payload 缺少 name');
        }

        return new ForeignKeyDefinition(
            name: $name,
            columns: \array_values(\array_map('strval', (array)($payload['columns'] ?? []))),
            referencesTable: (string)($payload['referencesTable'] ?? ''),
            referencesColumns: \array_values(\array_map(
                'strval',
                (array)($payload['referencesColumns'] ?? []),
            )),
            onDeleteCascade: (bool)($payload['onDeleteCascade'] ?? false),
            onUpdateCascade: (bool)($payload['onUpdateCascade'] ?? false),
        );
    }

    private function hydrateComment(mixed $payload): string
    {
        if (\is_string($payload)) {
            return $payload;
        }
        if (\is_array($payload) && \array_key_exists('value', $payload) && \is_string($payload['value'])) {
            return $payload['value'];
        }

        throw new RuntimeException('modify_table_comment payload 必须是 string 或 [value => string]');
    }

    private function hydratePrimaryKeyPromotion(mixed $payload, bool $rollback): mixed
    {
        if (!\is_array($payload)) {
            throw new RuntimeException('promote_primary_key payload 必须是数组');
        }
        if (\array_key_exists('columns', $payload)) {
            try {
                return new PrimaryKeyPromotionDefinition(
                    \array_values(\array_map('strval', (array)$payload['columns'])),
                    \array_values(\array_map(
                        'strval',
                        (array)($payload['replaceUniqueIndexNames'] ?? []),
                    )),
                );
            } catch (\InvalidArgumentException $exception) {
                throw new RuntimeException(
                    'promote_primary_key payload 非法: ' . $exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }
        if ($rollback) {
            // SchemaDiffOp::rollbackPayload 保存提升前的物理主键列（list<string>）。
            return \array_values(\array_map('strval', $payload));
        }

        throw new RuntimeException('promote_primary_key payload 缺少 columns');
    }

    private function decodeJson(string $json, string $field): mixed
    {
        try {
            return \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($field . ' 不是合法 JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
