<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 诊断 Schema 解析问题 ===\n\n";

$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
echo "当前 schema: {$currentSchema}\n\n";

$testTable = 'diagnose_schema_' . time();

echo "1. 测试框架的表名格式化\n";
$tableCreate = $connector->reset()->createTable()->createTable($testTable, '测试表');

// 通过反射获取内部的 table 属性
$reflection = new ReflectionClass($tableCreate);
$tableProperty = $reflection->getProperty('table');
$tableProperty->setAccessible(true);
$formattedTableName = $tableProperty->getValue($tableCreate);

echo "   原始表名: {$testTable}\n";
echo "   格式化后: {$formattedTableName}\n\n";

// 解析表名（模拟 Create.php:453-459 的逻辑）
echo "2. 模拟 Create.php 的表名解析逻辑\n";
$schemaName = 'public';  // 硬编码为 public（这是bug）
$tableName = '';

if (str_contains($formattedTableName, '.')) {
    $parts = explode('.', $formattedTableName);
    $schemaName = trim($parts[0], '"');
    $tableName = trim($parts[1] ?? '', '"');
    echo "   检测到 '.'，解析为:\n";
    echo "     schema: {$schemaName}\n";
    echo "     table: {$tableName}\n";
} else {
    $tableName = trim($formattedTableName, '"');
    echo "   未检测到 '.'，使用默认:\n";
    echo "     schema: {$schemaName} (硬编码)\n";
    echo "     table: {$tableName}\n";
}

$checkTableName = "{$schemaName}.{$tableName}";
echo "   检查表名: {$checkTableName}\n\n";

// 检查表是否存在
echo "3. 检查表是否存在\n";
$exists = $connector->tableExist($checkTableName);
echo "   tableExist('{$checkTableName}'): " . ($exists ? 'true' : 'false') . "\n\n";

// 正确的检查方式
echo "4. 正确的检查方式（使用 current_schema）\n";
$correctCheckSql = "SELECT EXISTS(
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = current_schema()
    AND table_name = '{$tableName}'
)";
$correctExists = $pdo->query($correctCheckSql)->fetchColumn();
echo "   使用 current_schema(): " . ($correctExists === 't' || $correctExists === true ? 'true' : 'false') . "\n\n";

echo "5. 问题分析\n";
if ($schemaName === 'public' && $currentSchema !== 'public') {
    echo "   ✗ 发现 Bug！\n";
    echo "   当前 schema 是 '{$currentSchema}'，但代码硬编码为 'public'\n";
    echo "   导致 tableExist() 检查错误的 schema\n";
    echo "   如果表不存在，会尝试创建，但创建到了错误的 schema\n\n";

    echo "   修复方案：\n";
    echo "   将 Create.php:451 改为：\n";
    echo "   \$schemaName = \$pdo->query('SELECT current_schema()')->fetchColumn() ?: 'public';\n";
}

echo "\n完成！\n";
