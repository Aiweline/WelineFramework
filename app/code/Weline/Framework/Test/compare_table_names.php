<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 对比不同表名的创建结果 ===\n\n";

$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
echo "当前 schema: {$currentSchema}\n\n";

$testCases = [
    'simple_test',
    'test_field_backup_table',
    'diagnose_create_test_' . time(),
];

foreach ($testCases as $testTable) {
    echo "测试表名: {$testTable}\n";

    // 获取格式化后的表名
    $tableCreate = $connector->reset()->createTable()->createTable($testTable, '测试')
        ->addColumn('id', 'int', 11, 'auto_increment primary key', 'ID');

    $reflection = new ReflectionClass($tableCreate);
    $tableProperty = $reflection->getProperty('table');
    $tableProperty->setAccessible(true);
    $formattedTableName = $tableProperty->getValue($tableCreate);

    echo "  格式化后: {$formattedTableName}\n";

    // 检查表是否已存在
    $actualTableName = '';
    if (str_contains($formattedTableName, '.')) {
        $parts = explode('.', $formattedTableName);
        $actualTableName = trim($parts[1] ?? '', '"');
    }

    $checkSql = "SELECT EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = '{$currentSchema}'
        AND table_name = '{$actualTableName}'
    )";
    $existsBefore = $pdo->query($checkSql)->fetchColumn();
    echo "  创建前存在: " . ($existsBefore === 't' || $existsBefore === true ? 'YES' : 'NO') . "\n";

    if ($existsBefore === 't' || $existsBefore === true) {
        echo "  ⚠ 表已存在，create() 会直接返回 true\n\n";
        continue;
    }

    // 创建表
    try {
        $result = $tableCreate->create();
        echo "  create() 返回: " . ($result ? 'true' : 'false') . "\n";
    } catch (\Exception $e) {
        echo "  create() 异常: " . $e->getMessage() . "\n";
    }

    // 检查表是否被创建
    $existsAfter = $pdo->query($checkSql)->fetchColumn();
    echo "  创建后存在: " . ($existsAfter === 't' || $existsAfter === true ? 'YES' : 'NO') . "\n";

    if ($existsAfter === 't' || $existsAfter === true) {
        echo "  ✓ 成功\n";
        // 清理
        @$pdo->exec("DROP TABLE IF EXISTS \"{$actualTableName}\" CASCADE");
    } else {
        echo "  ✗ 失败\n";
    }

    echo "\n";
}

echo "完成！\n";
