<?php

declare(strict_types=1);

define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

use Weline\Database\Service\Release\SchemaDdlExecutor;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;

/**
 * T44d2b 严格验收：SQLite DROP COLUMN 前清理引用被删列的显式索引。
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
        echo 'T44D2B DROP INDEX FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T44D2B DROP INDEX PASS' . PHP_EOL;
    exit(0);
};

$databasePath = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 't44d2b_drop_index_' . \bin2hex(\random_bytes(6)) . '.sqlite';
\register_shutdown_function(static function () use ($databasePath): void {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
        if (\is_file($file)) {
            @\unlink($file);
        }
    }
});

$config = new ConfigProvider([
    'type' => 'sqlite',
    'database' => $databasePath,
    'prefix' => '',
]);
$connectionFactory = ConnectionFactory::getInstance($config);
$connector = $connectionFactory->getConnector();
$executor = new SchemaDdlExecutor($connectionFactory);
$check(
    \strtolower($connector->getConfigProvider()->getDbType()) === 'sqlite',
    '验收连接必须是 sqlite'
);

$columns = static function (string $table) use ($connector): array {
    return \array_map(
        static fn(array $column): string => (string)($column['name'] ?? ''),
        $connector->getTableColumns($table),
    );
};
$names = static function (string $table) use ($connector): array {
    $rows = $connector->query(
        'SELECT name FROM ' . $connector->formatTableName($table) . ' ORDER BY id'
    )->fetchArray();

    return \array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $rows);
};
$indexNames = static function (string $table) use ($connector): array {
    return \array_map(
        static fn(array $index): string => (string)($index['name'] ?? ''),
        $connector->getTableIndexes($table),
    );
};
$findIndex = static function (string $table, string $logicalName) use ($connector): string {
    foreach ($connector->getTableIndexes($table) as $index) {
        $name = (string)($index['name'] ?? '');
        if (\str_contains($name, $logicalName)) {
            return $name;
        }
    }

    return '';
};
$hasIndex = static function (string $table, string $logicalName) use ($findIndex): bool {
    return $findIndex($table, $logicalName) !== '';
};
$createTable = static function (string $table, array $indexes) use ($connector): void {
    $connector->createTableFromSchema($table, [
        'comment' => 'T44d2b ' . $table,
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'length' => 11, 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true, 'default' => null, 'comment' => 'ID', 'unique' => false],
            ['name' => 'name', 'type' => 'varchar', 'length' => 64, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false, 'default' => null, 'comment' => 'Name', 'unique' => false],
            ['name' => 'legacy', 'type' => 'varchar', 'length' => 64, 'nullable' => true, 'primaryKey' => false, 'autoIncrement' => false, 'default' => null, 'comment' => 'Legacy', 'unique' => false],
        ],
        'indexes' => $indexes,
        'foreignKeys' => [],
    ]);
};
$insertRows = static function (string $table, array $rows) use ($connector): void {
    $tableSql = $connector->formatTableName($table);
    foreach ($rows as $row) {
        $connector->query(
            'INSERT INTO ' . $tableSql . " (name, legacy) VALUES ('" . $row[0] . "', '" . $row[1] . "')"
        )->fetch();
    }
};
$legacyIndex = static fn(): array => [
    'name' => 'idx_exec_legacy',
    'columns' => ['legacy'],
    'type' => 'DEFAULT',
    'method' => 'BTREE',
    'comment' => '',
];
$dropLegacyOp = static fn(string $table): SchemaDiffOp => new SchemaDiffOp(
    SchemaDiffOp::KIND_DROP_COLUMN,
    $table,
    new ColumnDefinition('legacy', 'varchar', 64, true, false, false, null, 'Legacy'),
);

// ---- 1. 显式索引存在时 DROP COLUMN 必须先清理索引再重建 ----
$tableA = 'exec_idx_drop';
$createTable($tableA, [$legacyIndex()]);
$insertRows($tableA, [['Alpha', 'L1'], ['Beta', 'L2']]);
$check($hasIndex($tableA, 'idx_exec_legacy'), 'A: 初始显式索引 idx_exec_legacy 应存在');

$executor->apply($dropLegacyOp($tableA));
$check($columns($tableA) === ['id', 'name'], 'A: DROP COLUMN 后应只剩 id/name');
$check(!$hasIndex($tableA, 'idx_exec_legacy'), 'A: 引用 legacy 的显式索引应被删除');
$check($names($tableA) === ['Alpha', 'Beta'], 'A: DROP COLUMN 后数据应保留');
$check($indexNames($tableA) === [], 'A: 表上不应残留索引');

// ---- 2. DROP INDEX 正常路径 ----
$tableB = 'exec_idx_only';
$createTable($tableB, [$legacyIndex()]);
$insertRows($tableB, [['Alpha', 'L1'], ['Beta', 'L2']]);
$actualIndexName = $findIndex($tableB, 'idx_exec_legacy');
$check($actualIndexName !== '', 'B: 初始显式索引应存在');
$executor->apply(new SchemaDiffOp(
    SchemaDiffOp::KIND_DROP_INDEX,
    $tableB,
    new IndexDefinition($actualIndexName, ['legacy']),
));
$check(!$hasIndex($tableB, 'idx_exec_legacy'), 'B: DROP INDEX 后索引应消失');
$check($columns($tableB) === ['id', 'name', 'legacy'], 'B: DROP INDEX 不得删除列');
$check($names($tableB) === ['Alpha', 'Beta'], 'B: DROP INDEX 后数据应不变');

// ---- 3. 不引用被删列的索引必须保留 ----
$tableC = 'exec_idx_keep';
$createTable($tableC, [[
    'name' => 'idx_exec_name',
    'columns' => ['name'],
    'type' => 'DEFAULT',
    'method' => 'BTREE',
    'comment' => '',
]]);
$insertRows($tableC, [['Gamma', 'L3']]);
$check($hasIndex($tableC, 'idx_exec_name'), 'C: 初始 name 索引应存在');
$executor->apply($dropLegacyOp($tableC));
$check($columns($tableC) === ['id', 'name'], 'C: DROP COLUMN 后应只剩 id/name');
$check($hasIndex($tableC, 'idx_exec_name'), 'C: 不引用 legacy 的索引必须保留');
$check($names($tableC) === ['Gamma'], 'C: DROP COLUMN 后数据应保留');

// ---- 4. 索引已删除时 DROP COLUMN 不应报错 ----
$tableD = 'exec_idx_gone';
$createTable($tableD, [$legacyIndex()]);
$insertRows($tableD, [['Delta', 'L4']]);
$goneIndexName = $findIndex($tableD, 'idx_exec_legacy');
$check($goneIndexName !== '', 'D: 初始显式索引应存在');
$connector->query(
    $connector->buildDropIndexSql($connector->formatTableName($tableD), $goneIndexName)
)->fetch();
$check(!$hasIndex($tableD, 'idx_exec_legacy'), 'D: 预删除索引应成功');
$executor->apply($dropLegacyOp($tableD));
$check($columns($tableD) === ['id', 'name'], 'D: 索引已删除时 DROP COLUMN 仍应成功');
$check($names($tableD) === ['Delta'], 'D: DROP COLUMN 后数据应保留');

echo 'OK SQLite DROP COLUMN pre-clean explicit indexes' . PHP_EOL;
echo 'OK DROP INDEX path and unrelated index retention' . PHP_EOL;
echo 'OK drop column with missing dependent index' . PHP_EOL;

$finish();
