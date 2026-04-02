<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 诊断 Create::create() Bug ===\n\n";

// 获取当前schema
$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
echo "当前 schema: {$currentSchema}\n\n";

// 测试表名
$testTable = 'diagnose_create_test_' . time();
$fullTableName = "{$currentSchema}.{$testTable}";

echo "1. 测试框架 API 创建表\n";
echo "   表名: {$fullTableName}\n";

try {
    // 使用 Connector 获取 Create 实例
    $tableCreate = $connector->reset()->createTable()->createTable($testTable, '诊断测试表')
        ->addColumn('id', 'int', 11, 'auto_increment primary key', 'ID')
        ->addColumn('name', 'varchar', 255, 'not null', '名称')
        ->addColumn('description', 'text', null, '', '描述');

    $result = $tableCreate->create();
    echo "   create() 返回值: " . ($result ? 'true' : 'false') . "\n";

    // 获取实际的表名（带前缀）
    $reflection = new ReflectionClass($tableCreate);
    $tableProperty = $reflection->getProperty('table');
    $tableProperty->setAccessible(true);
    $formattedTableName = $tableProperty->getValue($tableCreate);

    // 解析表名
    $actualTableName = $testTable;
    if (str_contains($formattedTableName, '.')) {
        $parts = explode('.', $formattedTableName);
        $actualTableName = trim($parts[1] ?? $testTable, '"');
    }

    echo "   实际表名: {$actualTableName}\n";

    // 检查表是否真的存在
    $checkSql = "SELECT EXISTS(
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = '{$currentSchema}'
        AND table_name = '{$actualTableName}'
    )";
    $exists = $pdo->query($checkSql)->fetchColumn();
    echo "   表是否存在: " . ($exists === 't' || $exists === true ? 'YES' : 'NO') . "\n";

    if ($exists === 't' || $exists === true) {
        echo "   ✓ 框架 API 工作正常\n";
        // 清理
        $pdo->exec("DROP TABLE IF EXISTS \"{$actualTableName}\" CASCADE");
    } else {
        echo "   ✗ 框架 API 有 BUG：返回 true 但表未创建\n\n";

        // 尝试直接用 PDO 创建
        echo "2. 测试直接 PDO 创建表\n";
        $directSql = "CREATE TABLE {$testTable} (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT
        )";

        try {
            $pdo->exec($directSql);
            echo "   PDO 创建成功\n";

            // 再次检查
            $exists2 = $pdo->query($checkSql)->fetchColumn();
            echo "   表是否存在: " . ($exists2 === 't' || $exists2 === true ? 'YES' : 'NO') . "\n";

            if ($exists2 === 't' || $exists2 === true) {
                echo "   ✓ PDO 直接创建工作正常\n";
                // 清理
                $pdo->exec("DROP TABLE IF EXISTS {$testTable} CASCADE");
            }
        } catch (\Exception $e) {
            echo "   PDO 创建失败: " . $e->getMessage() . "\n";
        }

        // 检查权限
        echo "\n3. 检查数据库权限\n";
        try {
            $currentUser = $pdo->query("SELECT current_user")->fetchColumn();
            echo "   当前用户: {$currentUser}\n";

            $hasPermission = $pdo->query("SELECT has_schema_privilege(current_user, '{$currentSchema}', 'CREATE')")->fetchColumn();
            echo "   CREATE 权限: " . ($hasPermission === 't' || $hasPermission === true ? 'YES' : 'NO') . "\n";

            if ($hasPermission !== 't' && $hasPermission !== true) {
                echo "\n   ✗ 权限不足！需要执行：\n";
                echo "   GRANT USAGE, CREATE ON SCHEMA {$currentSchema} TO {$currentUser};\n";
            }
        } catch (\Exception $e) {
            echo "   权限检查失败: " . $e->getMessage() . "\n";
        }

        // 检查事务状态
        echo "\n4. 检查事务状态\n";
        try {
            $inTransaction = $pdo->inTransaction();
            echo "   是否在事务中: " . ($inTransaction ? 'YES' : 'NO') . "\n";

            if ($inTransaction) {
                echo "   ✗ 可能的问题：在事务中但未提交\n";
            }
        } catch (\Exception $e) {
            echo "   事务检查失败: " . $e->getMessage() . "\n";
        }
    }

} catch (\Exception $e) {
    echo "   创建失败: " . $e->getMessage() . "\n";
}

echo "\n完成！\n";
