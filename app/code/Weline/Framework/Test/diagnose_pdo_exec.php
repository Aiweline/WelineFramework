<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 诊断 PDO::exec() 返回值 ===\n\n";

$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
$testTable = 'diagnose_pdo_exec_' . time();

echo "当前 schema: {$currentSchema}\n";
echo "测试表名: {$testTable}\n\n";

// 测试1：正常的 CREATE TABLE
echo "1. 测试正常的 CREATE TABLE\n";
$sql1 = "CREATE TABLE {$testTable} (id SERIAL PRIMARY KEY, name VARCHAR(255))";
echo "   SQL: {$sql1}\n";
$result1 = @$pdo->exec($sql1);
echo "   返回值: " . var_export($result1, true) . "\n";
echo "   类型: " . gettype($result1) . "\n";

if ($result1 === false) {
    $errorInfo = $pdo->errorInfo();
    echo "   错误信息: " . print_r($errorInfo, true) . "\n";
} else {
    echo "   ✓ 执行成功\n";

    // 检查表是否存在
    $checkSql = "SELECT EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = '{$currentSchema}'
        AND table_name = '{$testTable}'
    )";
    $exists = $pdo->query($checkSql)->fetchColumn();
    echo "   表是否存在: " . ($exists === 't' || $exists === true ? 'YES' : 'NO') . "\n";
}

// 清理
@$pdo->exec("DROP TABLE IF EXISTS {$testTable} CASCADE");

echo "\n2. 测试错误的 SQL（使用 @ 抑制错误）\n";
$sql2 = "CREATE TABLE {$testTable} (id INVALID_TYPE)";
echo "   SQL: {$sql2}\n";
$result2 = @$pdo->exec($sql2);
echo "   返回值: " . var_export($result2, true) . "\n";
echo "   类型: " . gettype($result2) . "\n";

if ($result2 === false) {
    $errorInfo = $pdo->errorInfo();
    echo "   ✗ 执行失败（但被 @ 抑制了）\n";
    echo "   错误信息: " . print_r($errorInfo, true) . "\n";
}

echo "\n3. 测试框架生成的 SQL\n";
$tableCreate = $connector->reset()->createTable()->createTable($testTable, '测试表')
    ->addColumn('id', 'int', 11, 'auto_increment primary key', 'ID')
    ->addColumn('name', 'varchar', 255, 'not null', '名称');

// 获取生成的 SQL（通过反射）
$reflection = new ReflectionClass($tableCreate);
$tableProperty = $reflection->getProperty('table');
$tableProperty->setAccessible(true);
$tableName = $tableProperty->getValue($tableCreate);

echo "   表名（框架格式化后）: {$tableName}\n";

// 尝试手动构建 SQL 看看
$manualSql = "CREATE TABLE {$tableName}(
    \"id\" SERIAL PRIMARY KEY,
    \"name\" VARCHAR(255) not null,
    \"create_time\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    \"update_time\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);";

echo "   手动构建的 SQL:\n";
echo "   {$manualSql}\n\n";

echo "   测试手动 SQL:\n";
$result3 = @$pdo->exec($manualSql);
echo "   返回值: " . var_export($result3, true) . "\n";

if ($result3 === false) {
    $errorInfo = $pdo->errorInfo();
    echo "   ✗ 执行失败\n";
    echo "   错误信息: " . print_r($errorInfo, true) . "\n";
} else {
    echo "   ✓ 执行成功\n";

    // 检查表是否存在
    $checkSql = "SELECT EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = '{$currentSchema}'
        AND table_name = '{$testTable}'
    )";
    $exists = $pdo->query($checkSql)->fetchColumn();
    echo "   表是否存在: " . ($exists === 't' || $exists === true ? 'YES' : 'NO') . "\n";
}

// 清理
@$pdo->exec("DROP TABLE IF EXISTS {$testTable} CASCADE");

echo "\n完成！\n";
