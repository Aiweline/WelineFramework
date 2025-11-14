#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Sticker 模块测试运行脚本
 * 
 * 用于运行 Sticker 相关的所有单元测试
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use PHPUnit\Framework\TestSuite;
use PHPUnit\TextUI\Command;

echo "=== Weline_Sticker 模块测试套件 ===\n";
echo "运行 Sticker 相关功能的单元测试...\n\n";

// 创建测试套件
$suite = new TestSuite();

// 添加 Sticker 扩展配置测试
$suite->addTestFile(__DIR__ . '/Unit/StickerExtendsTest.php');

// 如果存在其他 Sticker 相关测试文件，可以在这里添加
$stickerTestFiles = [
    __DIR__ . '/Unit/Service/StickerRegistryTest.php',
    __DIR__ . '/Unit/Service/RuleParserTest.php',
    __DIR__ . '/Unit/Service/RuleScannerTest.php',
    __DIR__ . '/Unit/Service/CompilerTest.php',
    __DIR__ . '/Unit/StickerIntegrationTest.php'
];

foreach ($stickerTestFiles as $testFile) {
    if (file_exists($testFile)) {
        $suite->addTestFile($testFile);
        echo "✓ 添加测试文件: " . basename($testFile) . "\n";
    }
}

echo "\n总共添加了 " . $suite->count() . " 个测试方法\n\n";

// 创建测试命令
$command = new Command();
$command->run([$suite], false);

echo "\n=== 测试完成 ===\n";
