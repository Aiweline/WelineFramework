<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 诊断数据清理问题 ===\n\n";

// 获取当前 schema
$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
echo "当前 schema: {$currentSchema}\n\n";

// 检查备份表是否存在
$backupTables = [
    'weline_framework_field_backup',
    'weline_framework_field_definition_backup',
    'weline_framework_field_backup_conflict'
];

foreach ($backupTables as $table) {
    $actualTableName = 'm_' . $table;
    $checkSql = "SELECT EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = '{$currentSchema}'
        AND table_name = '{$actualTableName}'
    )";
    $exists = $pdo->query($checkSql)->fetchColumn();
    echo "表 {$actualTableName}: " . ($exists === 't' || $exists === true ? 'EXISTS' : 'NOT EXISTS') . "\n";

    if ($exists === 't' || $exists === true) {
        // 查询表中的数据
        $countSql = "SELECT COUNT(*) FROM \"{$currentSchema}\".\"{$actualTableName}\"";
        $count = $pdo->query($countSql)->fetchColumn();
        echo "  总记录数: {$count}\n";

        // 查询测试相关的数据
        $testCountSql = "SELECT COUNT(*) FROM \"{$currentSchema}\".\"{$actualTableName}\" WHERE table_name LIKE '%test_field_backup_table%'";
        $testCount = $pdo->query($testCountSql)->fetchColumn();
        echo "  测试相关记录数: {$testCount}\n";

        if ($testCount > 0) {
            // 显示测试相关的数据
            $dataSql = "SELECT * FROM \"{$currentSchema}\".\"{$actualTableName}\" WHERE table_name LIKE '%test_field_backup_table%' LIMIT 5";
            $data = $pdo->query($dataSql)->fetchAll(\PDO::FETCH_ASSOC);
            echo "  示例数据:\n";
            foreach ($data as $row) {
                $fieldName = isset($row['field_name']) ? $row['field_name'] : 'N/A';
                echo "    - table_name: {$row['table_name']}, field_name: {$fieldName}\n";
            }
        }
    }
    echo "\n";
}

// 测试 Query API 删除
echo "=== 测试 Query API 删除 ===\n\n";

try {
    $query = $connector->getQuery();

    // 尝试删除测试数据
    echo "尝试删除 weline_framework_field_backup 中的测试数据...\n";
    $result = $query->clearQuery()
        ->table('weline_framework_field_backup')
        ->where('table_name', '%test_field_backup_table%', 'like')
        ->delete()
        ->fetch();
    echo "删除结果: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";

    // 检查是否真的删除了
    $actualTableName = 'm_weline_framework_field_backup';
    $testCountSql = "SELECT COUNT(*) FROM \"{$currentSchema}\".\"{$actualTableName}\" WHERE table_name LIKE '%test_field_backup_table%'";
    $testCount = $pdo->query($testCountSql)->fetchColumn();
    echo "删除后测试记录数: {$testCount}\n";

} catch (\Exception $e) {
    echo "删除失败: " . $e->getMessage() . "\n";
}

echo "\n完成！\n";
