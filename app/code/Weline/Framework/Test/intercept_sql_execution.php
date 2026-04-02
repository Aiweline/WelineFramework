<?php
require __DIR__ . '/../../../../../app/bootstrap_phpunit.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\DbManager;

$dbManager = ObjectManager::getInstance(DbManager::class);
$connector = $dbManager->getConnector();
$pdo = $connector->getWrappedConnection()->getPdo();

echo "=== 拦截并记录 SQL 执行 ===\n\n";

$currentSchema = $pdo->query("SELECT current_schema()")->fetchColumn();
echo "当前 schema: {$currentSchema}\n\n";

$testTable = 'intercept_sql_' . time();

// 创建一个 PDO 代理来拦截 exec() 调用
class PDOProxy extends PDO {
    private $realPdo;
    public $executedSqls = [];

    public function __construct($realPdo) {
        $this->realPdo = $realPdo;
    }

    public function exec($sql): int|false {
        echo "  [PDO::exec] " . substr($sql, 0, 100) . (strlen($sql) > 100 ? '...' : '') . "\n";
        $this->executedSqls[] = $sql;

        try {
            $result = $this->realPdo->exec($sql);
            echo "    返回值: " . var_export($result, true) . "\n";
            return $result;
        } catch (\PDOException $e) {
            echo "    异常: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function query($sql, ...$args) {
        return $this->realPdo->query($sql, ...$args);
    }

    public function __call($method, $args) {
        return call_user_func_array([$this->realPdo, $method], $args);
    }
}

// 注意：我们不能直接替换 PDO，因为它是 final 类
// 让我们直接修改 Create.php 来添加日志

echo "由于 PDO 是 final 类，我们直接在 Create.php 中添加日志\n";
echo "让我们检查 Create.php 实际生成的 SQL\n\n";

$tableCreate = $connector->reset()->createTable()->createTable($testTable, '测试表')
    ->addColumn('id', 'int', 11, 'auto_increment primary key', 'ID')
    ->addColumn('name', 'varchar', 255, 'not null', '名称');

// 通过反射获取内部状态
$reflection = new ReflectionClass($tableCreate);

$tableProperty = $reflection->getProperty('table');
$tableProperty->setAccessible(true);
$tableName = $tableProperty->getValue($tableCreate);

$fieldsProperty = $reflection->getProperty('fields');
$fieldsProperty->setAccessible(true);
$fields = $fieldsProperty->getValue($tableCreate);

$indexesProperty = $reflection->getProperty('indexes');
$indexesProperty->setAccessible(true);
$indexes = $indexesProperty->getValue($tableCreate);

echo "内部状态：\n";
echo "  table: {$tableName}\n";
echo "  fields: " . count($fields) . " 个\n";
foreach ($fields as $fieldName => $fieldData) {
    if (is_array($fieldData)) {
        echo "    - {$fieldName}: {$fieldData['definition']}\n";
    } else {
        echo "    - {$fieldName}: {$fieldData}\n";
    }
}
echo "  indexes: " . count($indexes) . " 个\n";

echo "\n尝试创建表...\n";
try {
    $result = $tableCreate->create();
    echo "create() 返回: " . ($result ? 'true' : 'false') . "\n";
} catch (\Exception $e) {
    echo "create() 异常: " . $e->getMessage() . "\n";
}

// 检查表是否存在
$checkSql = "SELECT EXISTS(
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = '{$currentSchema}'
    AND table_name = '{$testTable}'
)";
$exists = $pdo->query($checkSql)->fetchColumn();
echo "\n表是否存在: " . ($exists === 't' || $exists === true ? 'YES' : 'NO') . "\n";

// 检查 public schema
$checkPublicSql = "SELECT EXISTS(
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = 'public'
    AND table_name LIKE '%{$testTable}%'
)";
$existsInPublic = $pdo->query($checkPublicSql)->fetchColumn();
echo "在 public schema 中: " . ($existsInPublic === 't' || $existsInPublic === true ? 'YES' : 'NO') . "\n";

if ($existsInPublic === 't' || $existsInPublic === true) {
    echo "\n✗ 表被创建到了 public schema！\n";
    $listSql = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE '%{$testTable}%'";
    $tables = $pdo->query($listSql)->fetchAll(PDO::FETCH_COLUMN);
    echo "找到的表：\n";
    foreach ($tables as $t) {
        echo "  - public.{$t}\n";
    }
}

echo "\n完成！\n";
