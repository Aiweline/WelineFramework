#!/usr/bin/env php
<?php
/**
 * 快速验证数据库测试
 */

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     数据库升级回滚测试 - 快速验证                        ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$rootDir = dirname(__DIR__, 5);
$phpunitBin = $rootDir . '/vendor/bin/phpunit';

if (!file_exists($phpunitBin)) {
    echo "❌ PHPUnit未找到: {$phpunitBin}\n";
    exit(1);
}

echo "✓ PHPUnit已找到: {$phpunitBin}\n\n";

// 测试文件列表
$testFiles = [
    'Unit/Setup/Db/Service/FieldBackupServiceTest.php',
    'Unit/Database/Service/BackupServiceTest.php',
    'E2E/Setup/UpgradeFullFlowTest.php',
    'E2E/Setup/RollbackFullFlowTest.php',
];

echo "检查测试文件:\n";
$allExists = true;
foreach ($testFiles as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        echo "  ✓ {$file}\n";
    } else {
        echo "  ❌ {$file} (不存在)\n";
        $allExists = false;
    }
}

if (!$allExists) {
    echo "\n❌ 部分测试文件不存在\n";
    exit(1);
}

echo "\n";
echo "════════════════════════════════════════════════════════════\n";
echo "执行测试命令:\n";
echo "════════════════════════════════════════════════════════════\n\n";

// 执行单元测试
echo "1️⃣  单元测试:\n";
echo "   vendor/bin/phpunit app/code/Weline/Framework/Test/Unit --colors=always --testdox\n\n";

echo "2️⃣  E2E测试:\n";
echo "   vendor/bin/phpunit app/code/Weline/Framework/Test/E2E --colors=always --testdox\n\n";

echo "3️⃣  所有测试:\n";
echo "   vendor/bin/phpunit app/code/Weline/Framework/Test --colors=always --testdox\n\n";

echo "4️⃣  使用框架命令:\n";
echo "   php bin/w test:pest:run --path=app/code/Weline/Framework/Test\n\n";

echo "════════════════════════════════════════════════════════════\n";
echo "现在执行单元测试（示例）...\n";
echo "════════════════════════════════════════════════════════════\n\n";

// 执行一个简单的验证
$cmd = "cd {$rootDir} && php {$phpunitBin} app/code/Weline/Framework/Test/Unit --colors=always --testdox 2>&1";
echo "执行: {$cmd}\n\n";

passthru($cmd, $returnCode);

echo "\n";
if ($returnCode === 0) {
    echo "✅ 测试验证完成！\n";
} else {
    echo "⚠️  测试执行遇到问题，返回码: {$returnCode}\n";
    echo "\n提示: 如果是数据库连接问题，请确保:\n";
    echo "  1. 数据库服务已启动\n";
    echo "  2. env.php 配置正确\n";
    echo "  3. 测试数据库已创建\n";
}

echo "\n";
