<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 检查 PDO 错误模式 ===\n\n";

// 检查当前错误模式
$errorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
$errorModes = [
    PDO::ERRMODE_SILENT => 'ERRMODE_SILENT (0)',
    PDO::ERRMODE_WARNING => 'ERRMODE_WARNING (1)',
    PDO::ERRMODE_EXCEPTION => 'ERRMODE_EXCEPTION (2)',
];

echo "当前错误模式: " . ($errorModes[$errorMode] ?? "未知({$errorMode})") . "\n\n";

if ($errorMode === PDO::ERRMODE_EXCEPTION) {
    echo "✗ 问题找到了！\n";
    echo "   PDO 设置为 ERRMODE_EXCEPTION 模式\n";
    echo "   在这个模式下，@ 无法抑制异常\n";
    echo "   Create.php:510 的 @\$pdo->exec(\$sql) 无法抑制错误\n\n";

    echo "解决方案：\n";
    echo "1. 在 Create::create() 中使用 try-catch 包裹每个 exec()\n";
    echo "2. 检查 exec() 的返回值（false 表示失败）\n";
    echo "3. 临时切换错误模式为 ERRMODE_SILENT\n";
}

echo "\n测试验证：\n";

$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
$testTable = 'test_error_mode_' . time();

// 测试1：使用 try-catch
echo "1. 使用 try-catch 捕获异常\n";
try {
    $sql = "CREATE TABLE {$testTable} (id INVALID_TYPE)";
    $result = $pdo->exec($sql);
    echo "   返回值: " . var_export($result, true) . "\n";
} catch (\PDOException $e) {
    echo "   ✓ 捕获到异常: " . $e->getMessage() . "\n";
}

// 测试2：临时切换错误模式
echo "\n2. 临时切换为 ERRMODE_SILENT\n";
$oldMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

$sql = "CREATE TABLE {$testTable} (id INVALID_TYPE)";
$result = @$pdo->exec($sql);
echo "   返回值: " . var_export($result, true) . "\n";

if ($result === false) {
    $errorInfo = $pdo->errorInfo();
    echo "   ✓ 执行失败，但没有抛出异常\n";
    echo "   错误码: {$errorInfo[0]}\n";
    echo "   错误信息: {$errorInfo[2]}\n";
}

// 恢复错误模式
$pdo->setAttribute(PDO::ATTR_ERRMODE, $oldMode);

echo "\n完成！\n";
