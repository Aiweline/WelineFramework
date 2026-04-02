<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Setup\Db\Service\FieldBackupService;
use Weline\Framework\Output\Cli\Printing;

$dbManager = ObjectManager::getInstance(DbManager::class);
$printing = ObjectManager::getInstance(Printing::class);
$service = new FieldBackupService($dbManager, $printing);

echo "=== 测试ensureBackupTableExists ===\n\n";

// 使用反射调用私有方法
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('ensureBackupTableExists');
$method->setAccessible(true);

try {
    echo "调用ensureBackupTableExists()...\n";
    $method->invoke($service);
    echo "调用完成\n\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n\n";
}

// 检查表是否创建
$pdo = $dbManager->getConnector()->getWrappedConnection()->getPdo();
$stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE '%field_backup%'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tables)) {
    echo "❌ 备份表未创建\n";
} else {
    echo "✓ 找到备份表:\n";
    foreach ($tables as $t) {
        echo "  - {$t}\n";
    }
}
