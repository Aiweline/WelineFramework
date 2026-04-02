<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$pdo = $dbManager->getConnector()->getWrappedConnection()->getPdo();

echo "=== 测试清理逻辑 ===\n\n";

// 插入测试数据
echo "1. 插入测试数据...\n";
$pdo->exec("INSERT INTO weline_framework_field_backup (module, table_name, field_name, primary_key, primary_value, field_value, version) VALUES ('Test', 'test_field_backup_table', 'test', 'id', '1', 'value', '1.0.0')");
$pdo->exec("INSERT INTO weline_framework_field_backup (module, table_name, field_name, primary_key, primary_value, field_value, version) VALUES ('Test', 'weline.test_field_backup_table', 'test', 'id', '2', 'value', '1.0.0')");

$stmt = $pdo->query("SELECT COUNT(*) FROM weline_framework_field_backup");
$count = $stmt->fetchColumn();
echo "   插入后: {$count} 条\n\n";

// 测试清理
echo "2. 执行清理...\n";
$pdo->exec("DELETE FROM weline_framework_field_backup WHERE table_name LIKE '%test_field_backup_table%'");

$stmt = $pdo->query("SELECT COUNT(*) FROM weline_framework_field_backup");
$count = $stmt->fetchColumn();
echo "   清理后: {$count} 条\n\n";

// 查看剩余数据
if ($count > 0) {
    $stmt = $pdo->query("SELECT table_name FROM weline_framework_field_backup");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "   剩余的table_name:\n";
    foreach ($tables as $t) {
        echo "     - {$t}\n";
    }
}
