<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Setup\Db\Service\FieldBackupService;
use Weline\Framework\Output\Cli\Printing;

$dbManager = ObjectManager::getInstance(DbManager::class);
$printing = ObjectManager::getInstance(Printing::class);
$service = new FieldBackupService($dbManager, $printing);

$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

$testTable = 'test_field_backup_table';

try {
    // 创建表
    $pdo->exec("DROP TABLE IF EXISTS {$testTable}");
    $sql = "CREATE TABLE {$testTable} (id SERIAL PRIMARY KEY, name VARCHAR(255), description TEXT)";
    $pdo->exec($sql);

    // 插入数据
    $pdo->exec("INSERT INTO {$testTable} (name, description) VALUES ('Test 1', 'Desc 1')");
    $pdo->exec("INSERT INTO {$testTable} (name, description) VALUES ('Test 2', 'Desc 2')");

    echo "=== 测试FieldBackupService ===\n\n";

    // 获取当前schema
    $currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
    echo "当前schema: {$currentSchema}\n\n";

    // 测试不同的表名格式
    $testCases = [
        $testTable,
        "{$currentSchema}.{$testTable}",
    ];

    foreach ($testCases as $tableName) {
        echo "测试表名: {$tableName}\n";

        // 测试备份
        $result = $service->backupFieldData(
            $tableName,
            'description',
            'id',
            'Weline_Framework',
            '1.0.0'
        );

        echo "  backupFieldData结果: " . ($result ? 'true' : 'false') . "\n\n";
    }

    // 清理
    $pdo->exec("DROP TABLE IF EXISTS {$testTable}");

} catch (\Exception $e) {
    echo "\n❌ 错误: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
