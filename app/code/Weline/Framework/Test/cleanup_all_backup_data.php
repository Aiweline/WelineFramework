<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$pdo = $dbManager->getConnector()->getWrappedConnection()->getPdo();

echo "=== 清理所有备份数据 ===\n\n";

$tables = [
    'weline_framework_field_backup',
    'weline_framework_field_definition_backup',
    'weline_framework_field_backup_conflict'
];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        echo "{$table}: {$count} 条记录\n";

        $pdo->exec("DELETE FROM {$table}");
        echo "  已清理\n";
    } catch (\Exception $e) {
        echo "  错误: " . $e->getMessage() . "\n";
    }
}

echo "\n完成！\n";
