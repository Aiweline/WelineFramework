<?php
require __DIR__ . '/../../../../../app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 迁移表从 public schema 到 weline schema ===\n\n";

// 获取 public schema 中的所有表
$sql = "SELECT table_name FROM information_schema.tables
        WHERE table_schema = 'public'
        AND table_name LIKE 'm_%'
        ORDER BY table_name";
$tables = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

echo "找到 " . count($tables) . " 个表需要迁移\n\n";

$successCount = 0;
$failCount = 0;
$skipCount = 0;

foreach ($tables as $table) {
    echo "迁移表: {$table}... ";

    try {
        // 检查 weline schema 中是否已存在
        $checkSql = "SELECT EXISTS(
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'weline' AND table_name = '{$table}'
        )";
        $exists = $pdo->query($checkSql)->fetchColumn();

        if ($exists === 't' || $exists === true) {
            echo "已存在，跳过\n";
            $skipCount++;
            continue;
        }

        // 迁移表（修改 schema）
        $migrateSql = "ALTER TABLE \"public\".\"{$table}\" SET SCHEMA weline";
        $pdo->exec($migrateSql);

        echo "✓ 成功\n";
        $successCount++;

    } catch (Exception $e) {
        echo "✗ 失败: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

echo "\n=== 迁移完成 ===\n";
echo "成功: {$successCount}\n";
echo "跳过: {$skipCount}\n";
echo "失败: {$failCount}\n";
echo "总计: " . count($tables) . "\n";
