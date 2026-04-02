<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 测试表创建 ===\n\n";

$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
echo "当前 schema: {$currentSchema}\n\n";

$testTable = 'test_field_backup_table';

echo "1. 使用框架 API 创建表\n";
try {
    $connector->reset()->createTable()->createTable($testTable, '测试表')
        ->addColumn('id', 'int', 11, 'auto_increment primary key', 'ID')
        ->addColumn('name', 'varchar', 255, 'not null', '名称')
        ->addColumn('description', 'text', null, '', '描述')
        ->create();
    echo "   ✓ create() 成功\n";
} catch (\Exception $e) {
    echo "   ✗ create() 失败: " . $e->getMessage() . "\n";
}

echo "\n2. 检查表是否存在\n";
$checkSql = "SELECT table_name FROM information_schema.tables
    WHERE table_schema = '{$currentSchema}'
    AND table_name LIKE '%{$testTable}%'";
$tables = $pdo->query($checkSql)->fetchAll(PDO::FETCH_COLUMN);

if (empty($tables)) {
    echo "   ✗ 未找到表\n";
} else {
    echo "   ✓ 找到表：\n";
    foreach ($tables as $t) {
        echo "     - {$currentSchema}.{$t}\n";
    }
}

echo "\n3. 尝试插入数据\n";
try {
    $query = $connector->getQuery();
    $query->clearQuery()->table($testTable)->insert([
        'id' => 1,
        'name' => 'Test',
        'description' => 'Desc'
    ])->fetch();
    echo "   ✓ 插入成功\n";
} catch (\Exception $e) {
    echo "   ✗ 插入失败: " . $e->getMessage() . "\n";
}

// 清理
@$pdo->exec("DROP TABLE IF EXISTS m_{$testTable} CASCADE");

echo "\n完成！\n";
