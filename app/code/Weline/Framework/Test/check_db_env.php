<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;

echo "=== 数据库测试环境检查 ===\n\n";

try {
    $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
    $connector = $connectionFactory->getConnector();

    echo "✓ 数据库连接成功\n";
    echo "数据库类型: " . get_class($connector) . "\n";

    // 检查表前缀
    if (method_exists($connector, 'getTablePrefix')) {
        $prefix = $connector->getTablePrefix();
        echo "表前缀: " . ($prefix ?: '(无)') . "\n";
    }

    // 测试创建表
    echo "\n--- 测试创建表 ---\n";
    $testTable = 'test_check_table';

    $create = $connector->createTable();
    $create->createTable($testTable, 'Test table');
    $create->addColumn('id', 'INTEGER', null, 'PRIMARY KEY AUTO_INCREMENT', 'ID');
    $create->addColumn('name', 'VARCHAR', 255, 'NOT NULL', 'Name');
    $create->addAdditional('');
    $create->create();

    echo "✓ 表创建成功\n";

    // 检查表是否存在
    $hasTable = $connector->hasTable($testTable);
    echo "表存在检查: " . ($hasTable ? '是' : '否') . "\n";

    // 检查字段
    if ($hasTable) {
        $hasName = $connector->hasField($testTable, 'name');
        echo "字段 'name' 存在: " . ($hasName ? '是' : '否') . "\n";

        // 获取表结构
        $columns = $connector->getTableColumns($testTable);
        echo "表字段列表:\n";
        foreach ($columns as $col) {
            echo "  - " . ($col['name'] ?? 'unknown') . " (" . ($col['type'] ?? 'unknown') . ")\n";
        }
    }

    // 清理
    $connector->dropTableIfExists($testTable);
    echo "\n✓ 测试表已清理\n";

} catch (\Exception $e) {
    echo "✗ 错误: " . $e->getMessage() . "\n";
    echo "堆栈: " . $e->getTraceAsString() . "\n";
}

echo "\n=== 检查完成 ===\n";
