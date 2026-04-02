<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();

echo "=== 追踪框架 Create::create() 执行过程 ===\n\n";

$testTable = 'trace_create_' . time();

echo "测试表名: {$testTable}\n\n";

// 启用详细错误报告
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "开始创建表...\n";

try {
    $tableCreate = $connector->reset()->createTable()->createTable($testTable, '追踪测试表')
        ->addColumn('id', 'int', 11, 'auto_increment primary key', 'ID')
        ->addColumn('name', 'varchar', 255, 'not null', '名称')
        ->addColumn('description', 'text', null, '', '描述');

    $result = $tableCreate->create();

    echo "create() 返回值: " . ($result ? 'true' : 'false') . "\n\n";

} catch (\Exception $e) {
    echo "捕获到异常:\n";
    echo "  类型: " . get_class($e) . "\n";
    echo "  消息: " . $e->getMessage() . "\n";
    echo "  文件: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\n堆栈跟踪:\n";
    echo $e->getTraceAsString() . "\n";
}

// 检查表是否存在
$pdo = $connector->getWrappedConnection()->getPdo();
$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();

$checkSql = "SELECT EXISTS(
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = '{$currentSchema}'
    AND table_name = '{$testTable}'
)";
$exists = $pdo->query($checkSql)->fetchColumn();

echo "\n表是否存在: " . ($exists === 't' || $exists === true ? 'YES' : 'NO') . "\n";

// 清理
@$pdo->exec("DROP TABLE IF EXISTS {$testTable} CASCADE");

echo "\n完成！\n";
