<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

$testTable = 'test_field_backup_table';

try {
    // 创建表
    $pdo->exec("DROP TABLE IF EXISTS {$testTable}");
    $sql = "CREATE TABLE {$testTable} (id SERIAL PRIMARY KEY, name VARCHAR(255), description TEXT)";
    $pdo->exec($sql);

    echo "=== 测试hasField修复 ===\n\n";

    // 获取当前schema
    $currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
    echo "当前schema: {$currentSchema}\n\n";

    // 测试不同的表名格式
    $testCases = [
        $testTable,
        "{$currentSchema}.{$testTable}",
        "weline.{$testTable}",
    ];

    foreach ($testCases as $tableName) {
        echo "测试表名: {$tableName}\n";
        $result = $connector->hasField($tableName, 'description');
        echo "  hasField('description'): " . ($result ? 'true' : 'false') . "\n\n";
    }

    // 清理
    $pdo->exec("DROP TABLE IF EXISTS {$testTable}");

} catch (\Exception $e) {
    echo "\n❌ 错误: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
