<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$pdo = $dbManager->getConnector()->getWrappedConnection()->getPdo();

echo "=== 查找测试表 ===\n\n";

$stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE '%test_field_backup%'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tables)) {
    echo "没有找到测试表\n";
} else {
    echo "找到的测试表:\n";
    foreach ($tables as $t) {
        echo "  - {$t}\n";

        // 查询记录数
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$t}");
        $count = $stmt->fetchColumn();
        echo "    记录数: {$count}\n";
    }
}
