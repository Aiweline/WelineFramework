<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$query = $connector->getQuery();

echo "=== 清理所有测试数据 ===\n\n";

// 清理备份表中的测试数据
$backupTables = [
    'weline_framework_field_backup',
    'weline_framework_field_definition_backup',
    'weline_framework_field_backup_conflict'
];

foreach ($backupTables as $table) {
    echo "清理表 {$table}...\n";
    try {
        $query->clearQuery()
            ->table($table)
            ->where('table_name', '%test_field_backup_table%', 'like')
            ->delete()
            ->fetch();
        echo "  ✓ 成功\n";
    } catch (\Exception $e) {
        echo "  ✗ 失败: " . $e->getMessage() . "\n";
    }
}

// 删除测试表
echo "\n删除测试表...\n";
try {
    $connector->dropTableIfExists('test_field_backup_table');
    echo "  ✓ 成功\n";
} catch (\Exception $e) {
    echo "  ✗ 失败: " . $e->getMessage() . "\n";
}

echo "\n完成！\n";
