<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

use Weline\Database\Model\Release\SchemaChange;
use Weline\Database\Service\Release\SchemaChangeHydrator;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\PrimaryKeyPromotionDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * T44d1 静态验收：SchemaChangeHydrator 把 T44a payload 数组还原为 SchemaDiffOp。
 */

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$finish = static function () use (&$failures): never {
    if ($failures !== []) {
        \fwrite(STDERR, \implode(PHP_EOL, $failures) . PHP_EOL);
        echo 'T44D1 HYDRATE FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T44D1 HYDRATE PASS' . PHP_EOL;
    exit(0);
};
$expectRuntimeException = static function (callable $callback, string $message) use ($check): void {
    $thrown = false;
    try {
        $callback();
    } catch (RuntimeException) {
        $thrown = true;
    }
    $check($thrown, $message);
};

// ---- 1. 结构与签名 ----
$reflection = new ReflectionClass(SchemaChangeHydrator::class);
$check($reflection->isFinal(), 'SchemaChangeHydrator 必须是 final');
foreach (['hydrate', 'hydrateChange'] as $method) {
    $check($reflection->hasMethod($method), 'SchemaChangeHydrator 缺少方法：' . $method);
    if ($reflection->hasMethod($method)) {
        $check($reflection->getMethod($method)->isPublic(), $method . ' 必须 public');
    }
}
$hydrateMethod = $reflection->getMethod('hydrate');
$check(
    $hydrateMethod->getNumberOfParameters() === 1
        && (string)$hydrateMethod->getParameters()[0]->getType() === 'array'
        && (string)$hydrateMethod->getReturnType() === SchemaDiffOp::class,
    'hydrate(array $change): SchemaDiffOp 签名错误'
);
$hydrateChangeMethod = $reflection->getMethod('hydrateChange');
$check(
    $hydrateChangeMethod->getNumberOfParameters() === 1
        && (string)$hydrateChangeMethod->getParameters()[0]->getType() === SchemaChange::class
        && (string)$hydrateChangeMethod->getReturnType() === SchemaDiffOp::class,
    'hydrateChange(SchemaChange $change): SchemaDiffOp 签名错误'
);

$hydrator = new SchemaChangeHydrator();

// ---- 2. T44a 归一化 payload（对象转数组后按键排序） ----
$idPayload = [
    'autoIncrement' => true,
    'comment' => 'ID',
    'default' => null,
    'length' => 11,
    'name' => 'id',
    'nullable' => false,
    'primaryKey' => true,
    'type' => 'int',
    'unique' => false,
];
$namePayload = [
    'autoIncrement' => false,
    'comment' => 'Name',
    'default' => null,
    'length' => 64,
    'name' => 'name',
    'nullable' => true,
    'primaryKey' => false,
    'type' => 'varchar',
    'unique' => false,
];
$indexPayload = [
    'columns' => ['name'],
    'comment' => '',
    'method' => 'BTREE',
    'name' => 'idx_unit_name',
    'type' => 'DEFAULT',
];
$foreignKeyPayload = [
    'columns' => ['name'],
    'name' => 'fk_unit_name',
    'onDeleteCascade' => false,
    'onUpdateCascade' => true,
    'referencesColumns' => ['id'],
    'referencesTable' => 'other_table',
];

// ---- 3. create_table ----
$createOp = $hydrator->hydrate([
    'change_type' => SchemaDiffOp::KIND_CREATE_TABLE,
    'table_name' => 'unit_hydrate',
    'model_class' => 'Weline\\Test\\Model\\UnitHydrate',
    'payload' => [
        'comment' => 'T44d1 unit',
        'columns' => [$idPayload, $namePayload],
        'foreignKeys' => [$foreignKeyPayload],
        'indexes' => [$indexPayload],
        'tableName' => 'unit_hydrate',
    ],
]);
$check($createOp instanceof SchemaDiffOp, 'create_table 应返回 SchemaDiffOp');
$check($createOp->kind === SchemaDiffOp::KIND_CREATE_TABLE, 'create_table kind 错误');
$check($createOp->tableName === 'unit_hydrate', 'create_table tableName 错误');
$check($createOp->payload instanceof TableSchema, 'create_table payload 应为 TableSchema');
$check($createOp->rollbackPayload === null, 'create_table 默认 rollbackPayload 应为 null');
if ($createOp->payload instanceof TableSchema) {
    $tableSchema = $createOp->payload;
    $check($tableSchema->tableName === 'unit_hydrate', 'TableSchema tableName 错误');
    $check($tableSchema->comment === 'T44d1 unit', 'TableSchema comment 错误');
    $check($tableSchema->modelClass === 'Weline\\Test\\Model\\UnitHydrate', 'TableSchema modelClass 错误');
    $check(\count($tableSchema->columns) === 2, 'TableSchema columns 数量错误');
    $check($tableSchema->columns[0] instanceof ColumnDefinition, 'TableSchema 列应为 ColumnDefinition');
    $check($tableSchema->columns[0]->name === 'id' && $tableSchema->columns[0]->primaryKey, 'TableSchema 主键列错误');
    $check($tableSchema->columns[1]->name === 'name' && $tableSchema->columns[1]->length === 64, 'TableSchema 普通列错误');
    $check(
        \count($tableSchema->indexes) === 1
            && $tableSchema->indexes[0] instanceof IndexDefinition
            && $tableSchema->indexes[0]->name === 'idx_unit_name',
        'TableSchema 索引错误'
    );
    $check(
        \count($tableSchema->foreignKeys) === 1
            && $tableSchema->foreignKeys[0] instanceof ForeignKeyDefinition
            && $tableSchema->foreignKeys[0]->referencesTable === 'other_table',
        'TableSchema 外键错误'
    );
}

$fallbackTableOp = $hydrator->hydrate([
    'change_type' => SchemaDiffOp::KIND_CREATE_TABLE,
    'table_name' => 'fallback_table',
    'payload' => ['comment' => '', 'columns' => [], 'indexes' => [], 'foreignKeys' => []],
]);
$check(
    $fallbackTableOp->payload instanceof TableSchema
        && $fallbackTableOp->payload->tableName === 'fallback_table'
        && $fallbackTableOp->tableName === 'fallback_table',
    'create_table 应从 table_name 回填 TableSchema 表名'
);

// ---- 4. column 三类 ----
foreach ([SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_DROP_COLUMN] as $changeType) {
    $op = $hydrator->hydrate([
        'change_type' => $changeType,
        'table_name' => 'unit_hydrate',
        'payload' => $namePayload,
    ]);
    $check($op->kind === $changeType, $changeType . ' kind 错误');
    $check($op->payload instanceof ColumnDefinition, $changeType . ' payload 应为 ColumnDefinition');
    if ($op->payload instanceof ColumnDefinition) {
        $check($op->payload->name === 'name', $changeType . ' 列名错误');
        $check($op->payload->type === 'varchar' && $op->payload->length === 64, $changeType . ' 列类型错误');
        $check($op->payload->nullable === true && $op->payload->primaryKey === false, $changeType . ' 列属性错误');
    }
    $check($op->rollbackPayload === null, $changeType . ' 默认 rollbackPayload 应为 null');
}

$oldNamePayload = $namePayload;
$oldNamePayload['length'] = 32;
$oldNamePayload['comment'] = 'Old Name';
$newNamePayload = $namePayload;
$newNamePayload['length'] = 128;
$modifyOp = $hydrator->hydrate([
    'change_type' => SchemaDiffOp::KIND_MODIFY_COLUMN,
    'table_name' => 'unit_hydrate',
    'payload' => $newNamePayload,
    'rollback_payload' => $oldNamePayload,
]);
$check(
    $modifyOp->payload instanceof ColumnDefinition && $modifyOp->payload->length === 128,
    'modify_column 正向 payload 错误'
);
$check(
    $modifyOp->rollbackPayload instanceof ColumnDefinition
        && $modifyOp->rollbackPayload->length === 32
        && $modifyOp->rollbackPayload->comment === 'Old Name',
    'modify_column rollbackPayload 错误'
);

// ---- 5. index 两类 ----
foreach ([SchemaDiffOp::KIND_ADD_INDEX, SchemaDiffOp::KIND_DROP_INDEX] as $changeType) {
    $op = $hydrator->hydrate([
        'change_type' => $changeType,
        'table_name' => 'unit_hydrate',
        'payload' => $indexPayload,
    ]);
    $check($op->payload instanceof IndexDefinition, $changeType . ' payload 应为 IndexDefinition');
    if ($op->payload instanceof IndexDefinition) {
        $check($op->payload->name === 'idx_unit_name', $changeType . ' 索引名错误');
        $check($op->payload->columns === ['name'], $changeType . ' 索引列错误');
        $check($op->payload->type === 'DEFAULT' && $op->payload->method === 'BTREE', $changeType . ' 索引类型错误');
    }
    $check($op->rollbackPayload === null, $changeType . ' 默认 rollbackPayload 应为 null');
}

// ---- 6. foreign key 两类 ----
foreach ([SchemaDiffOp::KIND_ADD_FOREIGN_KEY, SchemaDiffOp::KIND_DROP_FOREIGN_KEY] as $changeType) {
    $op = $hydrator->hydrate([
        'change_type' => $changeType,
        'table_name' => 'unit_hydrate',
        'payload' => $foreignKeyPayload,
    ]);
    $check($op->payload instanceof ForeignKeyDefinition, $changeType . ' payload 应为 ForeignKeyDefinition');
    if ($op->payload instanceof ForeignKeyDefinition) {
        $check($op->payload->name === 'fk_unit_name', $changeType . ' 外键名错误');
        $check($op->payload->columns === ['name'], $changeType . ' 外键列错误');
        $check($op->payload->referencesTable === 'other_table', $changeType . ' 外键引用表错误');
        $check($op->payload->referencesColumns === ['id'], $changeType . ' 外键引用列错误');
        $check($op->payload->onDeleteCascade === false && $op->payload->onUpdateCascade === true, $changeType . ' 外键级联错误');
    }
    $check($op->rollbackPayload === null, $changeType . ' 默认 rollbackPayload 应为 null');
}

// ---- 7. modify_table_comment ----
$commentOp = $hydrator->hydrate([
    'change_type' => SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
    'table_name' => 'unit_hydrate',
    'payload' => 'new comment',
    'rollback_payload' => 'old comment',
]);
$check($commentOp->payload === 'new comment', 'modify_table_comment 正向 payload 错误');
$check($commentOp->rollbackPayload === 'old comment', 'modify_table_comment rollbackPayload 错误');

$wrappedCommentOp = $hydrator->hydrate([
    'change_type' => SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
    'table_name' => 'unit_hydrate',
    'payload' => ['value' => 'wrapped comment'],
]);
$check($wrappedCommentOp->payload === 'wrapped comment', 'modify_table_comment 应支持 [value => string]');

// ---- 8. promote_primary_key ----
$promoteOp = $hydrator->hydrate([
    'change_type' => SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY,
    'table_name' => 'unit_hydrate',
    'payload' => [
        'columns' => ['id'],
        'replaceUniqueIndexNames' => ['uniq_unit_id'],
    ],
    'rollback_payload' => ['legacy_id'],
]);
$check($promoteOp->payload instanceof PrimaryKeyPromotionDefinition, 'promote_primary_key payload 类型错误');
if ($promoteOp->payload instanceof PrimaryKeyPromotionDefinition) {
    $check($promoteOp->payload->columns === ['id'], 'promote_primary_key columns 错误');
    $check(
        $promoteOp->payload->replaceUniqueIndexNames === ['uniq_unit_id'],
        'promote_primary_key replaceUniqueIndexNames 错误'
    );
}
$check($promoteOp->rollbackPayload === ['legacy_id'], 'promote_primary_key rollback 应保留旧主键列');

// ---- 9. hydrateChange ----
$changeModel = new SchemaChange();
$changeModel->setData([
    SchemaChange::schema_fields_CHANGE_TYPE => SchemaDiffOp::KIND_MODIFY_COLUMN,
    SchemaChange::schema_fields_PAYLOAD_JSON => \json_encode($newNamePayload, JSON_UNESCAPED_UNICODE),
    SchemaChange::schema_fields_ROLLBACK_PAYLOAD_JSON => \json_encode($oldNamePayload, JSON_UNESCAPED_UNICODE),
    SchemaChange::schema_fields_TABLE_NAME => 'unit_hydrate',
]);
$modelOp = $hydrator->hydrateChange($changeModel);
$check($modelOp->kind === SchemaDiffOp::KIND_MODIFY_COLUMN, 'hydrateChange kind 错误');
$check($modelOp->tableName === 'unit_hydrate', 'hydrateChange tableName 错误');
$check(
    $modelOp->payload instanceof ColumnDefinition && $modelOp->payload->length === 128,
    'hydrateChange payload 错误'
);
$check(
    $modelOp->rollbackPayload instanceof ColumnDefinition && $modelOp->rollbackPayload->length === 32,
    'hydrateChange rollbackPayload 错误'
);

$commentModel = new SchemaChange();
$commentModel->setData([
    SchemaChange::schema_fields_CHANGE_TYPE => SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
    SchemaChange::schema_fields_PAYLOAD_JSON => \json_encode('new comment'),
    SchemaChange::schema_fields_ROLLBACK_PAYLOAD_JSON => null,
    SchemaChange::schema_fields_TABLE_NAME => 'unit_hydrate',
]);
$modelCommentOp = $hydrator->hydrateChange($commentModel);
$check($modelCommentOp->payload === 'new comment', 'hydrateChange 注释 payload 错误');
$check($modelCommentOp->rollbackPayload === null, 'hydrateChange 注释 rollbackPayload 应为 null');

// ---- 10. 非法输入 ----
$expectRuntimeException(
    static fn() => $hydrator->hydrate(['change_type' => 'bogus_change', 'payload' => []]),
    '未知 change_type 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $hydrator->hydrate(['payload' => []]),
    '缺少 change_type 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $hydrator->hydrate(['change_type' => SchemaDiffOp::KIND_ADD_COLUMN]),
    '缺少 payload 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $hydrator->hydrate([
        'change_type' => SchemaDiffOp::KIND_ADD_COLUMN,
        'payload' => ['name' => ''],
    ]),
    '列 payload 缺 name 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $hydrator->hydrate([
        'change_type' => SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
        'payload' => 123,
    ]),
    '注释 payload 类型错误应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $hydrator->hydrate([
        'change_type' => SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY,
        'payload' => ['columns' => []],
    ]),
    'promote_primary_key 空 columns 应抛 RuntimeException'
);

$badJsonModel = new SchemaChange();
$badJsonModel->setData([
    SchemaChange::schema_fields_CHANGE_TYPE => SchemaDiffOp::KIND_ADD_COLUMN,
    SchemaChange::schema_fields_PAYLOAD_JSON => '{not-json',
    SchemaChange::schema_fields_TABLE_NAME => 'unit_hydrate',
]);
$expectRuntimeException(
    static fn() => $hydrator->hydrateChange($badJsonModel),
    '非法 payload_json 应抛 RuntimeException'
);

$emptyPayloadModel = new SchemaChange();
$emptyPayloadModel->setData([
    SchemaChange::schema_fields_CHANGE_TYPE => SchemaDiffOp::KIND_ADD_COLUMN,
    SchemaChange::schema_fields_PAYLOAD_JSON => '',
    SchemaChange::schema_fields_TABLE_NAME => 'unit_hydrate',
]);
$expectRuntimeException(
    static fn() => $hydrator->hydrateChange($emptyPayloadModel),
    '空 payload_json 应抛 RuntimeException'
);

// ---- 11. 不接旧域 ----
$hydratorSource = (string)\file_get_contents(
    BP . 'app/code/Weline/Database/Service/Release/SchemaChangeHydrator.php'
);
$check($hydratorSource !== '', '无法读取 SchemaChangeHydrator 源码');
$legacySymbols = [
    'SchemaMigration' . 'Executor',
    'SchemaDiff' . 'Stage',
    'Migration' . 'Service',
    'Migration' . 'Backup',
    'Field' . 'Backup',
    'Model' . 'Setup',
];
foreach ([$hydratorSource, (string)\file_get_contents(__FILE__)] as $source) {
    foreach ($legacySymbols as $symbol) {
        $check(!\str_contains($source, $symbol), '发现旧域符号：' . $symbol);
    }
}

echo 'OK SchemaChangeHydrator (create/column/index/fk/comment/promote)' . PHP_EOL;
echo 'OK hydrateChange model payload' . PHP_EOL;
echo 'OK invalid change rejected' . PHP_EOL;

$finish();
