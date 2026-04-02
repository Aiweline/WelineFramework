<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 检查备份表数据 ===\n\n";

try {
    // 查询备份表
    $stmt = $pdo->query("SELECT COUNT(*) FROM weline_framework_field_backup WHERE table_name LIKE '%test_field_backup_table%'");
    $count = $stmt->fetchColumn();
    echo "weline_framework_field_backup 中的测试数据: {$count} 条\n";

    if ($count > 0) {
        $stmt = $pdo->query("SELECT backup_id, table_name, field_name, module FROM weline_framework_field_backup WHERE table_name LIKE '%test_field_backup_table%' LIMIT 10");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\n前10条记录:\n";
        foreach ($rows as $row) {
            echo "  - ID:{$row['backup_id']}, Table:{$row['table_name']}, Field:{$row['field_name']}, Module:{$row['module']}\n";
        }
    }

    // 清理
    echo "\n清理测试数据...\n";
    $pdo->exec("DELETE FROM weline_framework_field_backup WHERE table_name LIKE '%test_field_backup_table%'");
    $pdo->exec("DELETE FROM weline_framework_field_definition_backup WHERE table_name LIKE '%test_field_backup_table%'");
    $pdo->exec("DELETE FROM weline_framework_field_backup_conflict WHERE table_name LIKE '%test_field_backup_table%'");

    $stmt = $pdo->query("SELECT COUNT(*) FROM weline_framework_field_backup WHERE table_name LIKE '%test_field_backup_table%'");
    $count = $stmt->fetchColumn();
    echo "清理后剩余: {$count} 条\n";

} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
