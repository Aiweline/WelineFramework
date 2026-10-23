<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\PrimaryKeyPromotionDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * T44b 静态验收：SchemaDdlExecutor 只通过 ConnectorInterface 公共 API 执行 DDL。
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
        echo 'T44B DDL FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T44B DDL PASS' . PHP_EOL;
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

$databasePath = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 't44b_ddl_' . \bin2hex(\random_bytes(6)) . '.sqlite';
\register_shutdown_function(static function () use ($databasePath): void {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
        if (\is_file($file)) {
            @\unlink($file);
        }
    }
});

// ---- 1. 结构与签名 ----
$reflection = new ReflectionClass(SchemaDdlExecutor::class);
$check($reflection->isFinal(), 'SchemaDdlExecutor 必须是 final');
$constructor = $reflection->getConstructor();
$check($constructor !== null, 'SchemaDdlExecutor 缺少构造函数');
if ($constructor !== null) {
    $check(
        $constructor->getNumberOfParameters() === 1
            && (string)$constructor->getParameters()[0]->getType() === ConnectionFactory::class,
        'SchemaDdlExecutor 构造依赖应为 ConnectionFactory'
    );
}
foreach (['build', 'apply', 'tableSchemaPayload'] as $method) {
    $check($reflection->hasMethod($method), 'SchemaDdlExecutor 缺少方法：' . $method);
    if ($reflection->hasMethod($method)) {
        $check($reflection->getMethod($method)->isPublic(), $method . ' 必须 public');
    }
}

// ---- 2. SQLite 连接 ----
$config = new ConfigProvider([
    'type' => 'sqlite',
    'database' => $databasePath,
    'prefix' => 'w_',
]);
$connectionFactory = ConnectionFactory::getInstance($config);
$connector = $connectionFactory->getConnector();
$executor = new SchemaDdlExecutor($connectionFactory);
$check(
    \strtolower($connector->getConfigProvider()->getDbType()) === 'sqlite',
    '验收连接必须是 sqlite'
);

$id = new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID');
$name = new ColumnDefinition('name', 'varchar', 64, true, false, false, null, 'Name');
$tableSchema = new TableSchema('unit_ddl', 'T44b unit', [$id, $name]);

// ---- 3. tableSchemaPayload 契约 ----
$payload = $executor->tableSchemaPayload($tableSchema);
$check(
    \array_keys($payload) === ['comment', 'columns', 'indexes', 'foreignKeys'],
    'tableSchemaPayload 顶层字段应为 comment/columns/indexes/foreignKeys'
);
$check(
    \array_keys($payload['columns'][0]) === [
        'name', 'type', 'length', 'nullable', 'primaryKey',
        'autoIncrement', 'default', 'comment', 'unique',
    ],
    'tableSchemaPayload 列字段应为 9 键'
);
$check($payload['columns'][1]['unique'] === false, '非主键列 unique 必须为 false');

$uniquePrimary = new ColumnDefinition('id', 'int', 11, false, true, true, null, 'ID', true);
$uniquePayload = $executor->tableSchemaPayload(
    new TableSchema('unit_unique', '', [$uniquePrimary])
);
$check($uniquePayload['columns'][0]['unique'] === true, '主键列 unique 应保留声明值');

$relationPayload = $executor->tableSchemaPayload(new TableSchema(
    tableName: 'unit_rel',
    columns: [$id],
    indexes: [new IndexDefinition('idx_rel_name', ['name'])],
    foreignKeys: [new ForeignKeyDefinition('fk_rel_name', ['name'], 'other_table', ['id'])],
));
$check(
    \array_keys($relationPayload['indexes'][0]) === ['name', 'columns', 'type', 'method', 'comment'],
    'tableSchemaPayload 索引字段应为 5 键'
);
$check(
    \array_keys($relationPayload['foreignKeys'][0]) === [
        'name', 'columns', 'referencesTable', 'referencesColumns',
        'onDeleteCascade', 'onUpdateCascade',
    ],
    'tableSchemaPayload 外键字段应为 6 键'
);

// ---- 4. CREATE_TABLE forward / rollback ----
$createOp = new SchemaDiffOp(SchemaDiffOp::KIND_CREATE_TABLE, 'unit_ddl', $tableSchema);
$check($executor->build($createOp) === '', 'CREATE_TABLE forward build 应为空（走 createTableFromSchema）');
$check(
    \str_starts_with($executor->build($createOp, true), 'DROP TABLE IF EXISTS '),
    'CREATE_TABLE rollback 应为 DROP TABLE IF EXISTS'
);

$executor->apply($createOp);
$check($connector->tableExist('unit_ddl'), 'CREATE_TABLE apply 后表应存在');
$columnNames = static fn(): array => \array_map(
    static fn(array $column): string => (string)($column['name'] ?? ''),
    $connector->getTableColumns('unit_ddl'),
);
$check($columnNames() === ['id', 'name'], 'CREATE_TABLE 后列应为 id/name');

// ---- 5. ADD_COLUMN forward / rollback ----
$age = new ColumnDefinition('age', 'int', 11, true, false, false, null, 'Age');
$addAgeOp = new SchemaDiffOp(SchemaDiffOp::KIND_ADD_COLUMN, 'unit_ddl', $age);
$check(
    \str_contains($executor->build($addAgeOp), 'ADD COLUMN'),
    'ADD_COLUMN build 应包含 ADD COLUMN'
);
$executor->apply($addAgeOp);
$check(\in_array('age', $columnNames(), true), 'ADD_COLUMN apply 后列应存在');
$executor->apply($addAgeOp, true);
$check(!\in_array('age', $columnNames(), true), 'ADD_COLUMN rollback 后列应消失');

// ---- 6. DROP_COLUMN forward + rollback ----
$legacy = new ColumnDefinition('legacy', 'varchar', 32, true, false, false, null, 'Legacy');
$executor->apply(new SchemaDiffOp(SchemaDiffOp::KIND_ADD_COLUMN, 'unit_ddl', $legacy));
$dropLegacyOp = new SchemaDiffOp(SchemaDiffOp::KIND_DROP_COLUMN, 'unit_ddl', $legacy);
$executor->apply($dropLegacyOp);
$check(!\in_array('legacy', $columnNames(), true), 'DROP_COLUMN apply 后列应消失');
$executor->apply($dropLegacyOp, true);
$check(\in_array('legacy', $columnNames(), true), 'DROP_COLUMN rollback 后列应恢复');

// ---- 7. MODIFY_TABLE_COMMENT：适配器空 DDL 是明确 no-op ----
$commentOp = new SchemaDiffOp(
    SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
    'unit_ddl',
    'new comment',
    null,
    'old comment',
);
$check($executor->build($commentOp) === '', 'SQLite 表注释 build 应为空');
$commentApplied = true;
try {
    $executor->apply($commentOp);
} catch (Throwable) {
    $commentApplied = false;
}
$check($commentApplied, '适配器空注释 DDL 应作为明确 no-op 跳过');

// ---- 8. PROMOTE_PRIMARY_KEY：SQLite 必须 fail closed ----
$promoteOp = new SchemaDiffOp(
    SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY,
    'unit_ddl',
    new PrimaryKeyPromotionDefinition(['id']),
);
$expectRuntimeException(
    static fn() => $executor->build($promoteOp),
    'SQLite 无法提升 PRIMARY KEY 时 build 应抛 RuntimeException'
);

// ---- 9. 错误 payload 必须抛 RuntimeException ----
$expectRuntimeException(
    static fn() => $executor->build(new SchemaDiffOp(
        SchemaDiffOp::KIND_ADD_COLUMN,
        'unit_ddl',
        'not-a-column',
    )),
    'ADD_COLUMN 错误 payload 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $executor->build(new SchemaDiffOp(
        SchemaDiffOp::KIND_ADD_INDEX,
        'unit_ddl',
        $age,
    )),
    'ADD_INDEX 错误 payload 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $executor->build(new SchemaDiffOp(
        SchemaDiffOp::KIND_ADD_FOREIGN_KEY,
        'unit_ddl',
        new IndexDefinition('idx_bad', ['id']),
    )),
    'ADD_FOREIGN_KEY 错误 payload 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $executor->build(new SchemaDiffOp(
        SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
        'unit_ddl',
        123,
    )),
    'MODIFY_TABLE_COMMENT 错误 payload 应抛 RuntimeException'
);
$expectRuntimeException(
    static fn() => $executor->apply(new SchemaDiffOp(
        SchemaDiffOp::KIND_CREATE_TABLE,
        'unit_bad_create',
        'not-a-table-schema',
    )),
    'CREATE_TABLE 错误 payload 的 apply 应抛 RuntimeException'
);
$check(!$connector->tableExist('unit_bad_create'), '错误 CREATE_TABLE 不得建表');

// ---- 10. DROP_TABLE rollback ----
$executor->apply($createOp, true);
$check(!$connector->tableExist('unit_ddl'), 'CREATE_TABLE rollback 后表应被删除');

// ---- 11. 不接旧域 ----
$executorSource = (string)\file_get_contents(
    BP . 'app/code/Weline/Database/Service/Release/SchemaDdlExecutor.php'
);
$check($executorSource !== '', '无法读取 SchemaDdlExecutor 源码');
$legacySymbols = [
    'SchemaMigration' . 'Executor',
    'SchemaDiff' . 'Stage',
    'Migration' . 'Service',
    'Migration' . 'Backup',
    'Field' . 'Backup',
    'Model' . 'Setup',
];
foreach ([$executorSource, (string)\file_get_contents(__FILE__)] as $source) {
    foreach ($legacySymbols as $symbol) {
        $check(!\str_contains($source, $symbol), '发现旧域符号：' . $symbol);
    }
}

echo 'OK SchemaDdlExecutor (create/add/drop/rollback)' . PHP_EOL;
echo 'OK tableSchemaPayload contract' . PHP_EOL;
echo 'OK invalid payload rejected' . PHP_EOL;

$finish();
