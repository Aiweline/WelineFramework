<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
define('DS', \DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Database\Service\Release\SchemaDiffExecutor;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutorInterface;
use Weline\Framework\Database\Schema\TableSchema;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

// 1. 新 executor 类型与接口
$reflection = new ReflectionClass(SchemaDiffExecutor::class);
$check($reflection->isFinal(), 'SchemaDiffExecutor 必须是 final');
$check(
    $reflection->implementsInterface(SchemaMigrationExecutorInterface::class),
    'SchemaDiffExecutor 必须实现 SchemaMigrationExecutorInterface'
);
$constructor = $reflection->getConstructor();
$check(
    $constructor !== null
        && $constructor->getNumberOfParameters() === 1
        && (string)$constructor->getParameters()[0]->getType() === SchemaDdlExecutor::class,
    'SchemaDiffExecutor 应注入 SchemaDdlExecutor'
);
foreach (['execute', 'createBootstrapTable'] as $method) {
    $check($reflection->hasMethod($method), 'SchemaDiffExecutor 缺少方法：' . $method);
}

// 2. 接口绑定
$frameworkModule = require BP . 'app/code/Weline/Framework/etc/module.php';
$binding = $frameworkModule['provides'][SchemaMigrationExecutorInterface::class] ?? null;
$check(
    $binding === SchemaDiffExecutor::class,
    'SchemaMigrationExecutorInterface 应绑定 SchemaDiffExecutor，实际：' . \var_export($binding, true)
);

// 3. bootstrap / index sink / ssl 不得再直接引用旧 executor
$consumers = [
    'app/code/Weline/Framework/Setup/Stage/FrameworkDbBootstrapStage.php',
    'app/code/Weline/Database/Service/Index/SchemaIndexChangeSink.php',
    'app/code/Weline/Server/Service/SslCertificateService.php',
];
foreach ($consumers as $relative) {
    $source = (string)\file_get_contents(BP . $relative);
    $check(!\str_contains($source, 'SchemaMigrationExecutor::class'), '仍直接引用旧 executor：' . $relative);
    $check(!\str_contains($source, 'new SchemaMigrationExecutor'), '仍 new 旧 executor：' . $relative);
    $check(\str_contains($source, 'SchemaDiffExecutor'), '未切换到 SchemaDiffExecutor：' . $relative);
}

// 4. SQLite 实测：建表 / 加列 / 加索引 / connector 覆盖隔离
$tmpA = \sys_get_temp_dir() . DS . 'weline_t47r_a_' . \getmypid() . '_' . \bin2hex(\random_bytes(4)) . '.sqlite';
$tmpB = \sys_get_temp_dir() . DS . 'weline_t47r_b_' . \getmypid() . '_' . \bin2hex(\random_bytes(4)) . '.sqlite';
$factoryA = null;
$factoryB = null;
try {
    $factoryA = ConnectionFactory::getInstance(new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmpA,
        'persistent' => false,
    ]));
    $factoryB = ConnectionFactory::getInstance(new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmpB,
        'persistent' => false,
    ]));
    $connectorA = $factoryA->getConnector();
    $connectorB = $factoryB->getConnector();

    $ddl = new SchemaDdlExecutor($factoryA);
    $executor = new SchemaDiffExecutor($ddl);

    $schema = new TableSchema(
        tableName: 'unit_t47r',
        comment: 't47r',
        columns: [
            new ColumnDefinition(name: 'id', type: 'int', length: 11, nullable: false, primaryKey: true, autoIncrement: true),
            new ColumnDefinition(name: 'name', type: 'varchar', length: 255, nullable: false),
        ],
        indexes: [new IndexDefinition(name: 'idx_name', columns: ['name'])],
    );
    $executor->createBootstrapTable($connectorA, $schema);
    $check($connectorA->tableExist('unit_t47r'), 'createBootstrapTable 未建表');
    $columns = \array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $connectorA->getTableColumns('unit_t47r'));
    $check(\in_array('id', $columns, true) && \in_array('name', $columns, true), '建表列不完整');
    $indexes = $connectorA->getTableIndexes('unit_t47r');
    $hasNameIndex = false;
    foreach ($indexes as $index) {
        if (\in_array('name', \array_map('strval', (array)($index['columns'] ?? [])), true)) {
            $hasNameIndex = true;
            break;
        }
    }
    $check($hasNameIndex, '建表索引未生效');

    $executor->execute($connectorA, [
        new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_COLUMN,
            'unit_t47r',
            new ColumnDefinition(name: 'note', type: 'varchar', length: 255, nullable: true),
        ),
    ]);
    $columns = \array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $connectorA->getTableColumns('unit_t47r'));
    $check(\in_array('note', $columns, true), 'ADD COLUMN 未生效');

    $executor->execute($connectorA, [
        new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_INDEX,
            'unit_t47r',
            new IndexDefinition(name: 'idx_note', columns: ['note']),
        ),
    ]);
    $hasNoteIndex = false;
    foreach ($connectorA->getTableIndexes('unit_t47r') as $index) {
        if (\in_array('note', \array_map('strval', (array)($index['columns'] ?? [])), true)) {
            $hasNoteIndex = true;
            break;
        }
    }
    $check($hasNoteIndex, 'ADD INDEX 未生效');

    // connector 覆盖只影响本次调用：executor 由 A 构造，却写到 B
    $overrideSchema = new TableSchema(
        tableName: 'unit_override',
        comment: '',
        columns: [new ColumnDefinition(name: 'id', type: 'int', length: 11, nullable: false, primaryKey: true, autoIncrement: true)],
    );
    $executor->execute($connectorB, [
        new SchemaDiffOp(SchemaDiffOp::KIND_CREATE_TABLE, 'unit_override', $overrideSchema),
    ]);
    $check($connectorB->tableExist('unit_override'), 'connector 覆盖未写到目标库');
    $check(!$connectorA->tableExist('unit_override'), 'connector 覆盖污染了 factory A');
    $check($connectorA->tableExist('unit_t47r'), 'factory A 原有表不应受影响');
} finally {
    foreach ([$factoryA, $factoryB] as $factory) {
        if ($factory instanceof ConnectionFactory) {
            try {
                $factory->close();
            } catch (Throwable) {
                // ignore close failure during cleanup
            }
        }
    }
    foreach ([$tmpA, $tmpA . '-wal', $tmpA . '-shm', $tmpA . '-journal', $tmpB, $tmpB . '-wal', $tmpB . '-shm', $tmpB . '-journal'] as $file) {
        if (\is_file($file)) {
            @\unlink($file);
        }
    }
}

if ($failures !== []) {
    \fwrite(STDERR, \implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'T47R SCHEMA-DIFF-EXECUTOR FAIL' . PHP_EOL;
    exit(1);
}

echo 'OK SchemaDiffExecutor implements SchemaMigrationExecutorInterface' . PHP_EOL;
echo 'OK interface bound to SchemaDiffExecutor' . PHP_EOL;
echo 'OK bootstrap/index/ssl no longer reference the legacy executor' . PHP_EOL;
echo 'OK sqlite create table / add column / add index through new executor' . PHP_EOL;
echo 'OK connector override isolated from factory singleton' . PHP_EOL;
echo 'T47R SCHEMA-DIFF-EXECUTOR PASS' . PHP_EOL;
exit(0);
