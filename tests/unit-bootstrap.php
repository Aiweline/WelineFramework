<?php

declare(strict_types=1);

/**
 * 单元测试引导：加载框架统一自动加载入口。
 *
 * 仓库自身没有 phpunit.xml bootstrap，直接跑 phpunit 时 BP/DS/PUB/VAR_DIR
 * 等常量未定义，会导致大量与本意无关的 "Undefined constant" 错误，
 * 掩盖真正的断言失败。本文件只补齐常量与自动加载，不改变任何业务行为。
 *
 * 用法：
 *   vendor/bin/phpunit --bootstrap tests/unit-bootstrap.php <测试路径>
 */

$root = \dirname(__DIR__);
$autoload = $root . '/app/autoload.php';
if (!\is_file($autoload)) {
    \fwrite(\STDERR, "unit-bootstrap: app/autoload.php not found at {$autoload}\n");
    exit(1);
}

require_once $autoload;

// 部分测试直接引用全局 VAR_DIR（完整 bootstrap 时由 Env 定义）。测试进程不走
// 完整 bootstrap，这里按同一约定补齐，避免 "Undefined constant VAR_DIR" 被误报
// 成业务缺陷。
if (\defined('VAR_DIR') === false) {
    \define('VAR_DIR', BP . 'var' . DS);
}

// Env 在部分测试里会读取 var 目录；缺失时按框架约定补上，避免因目录不存在而抛错。
if (\class_exists(\Weline\Framework\App\Env::class)) {
    try {
        \Weline\Framework\App\Env::getInstance();
    } catch (\Throwable) {
        // Env 初始化失败不应阻断纯逻辑测试；需要 Env 的测试会自行报错。
    }
}
