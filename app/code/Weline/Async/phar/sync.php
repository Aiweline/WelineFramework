#!/usr/bin/env php
<?php

/**
 * phar独立包入口文件
 * 支持独立运行，不依赖框架
 */

// 检查是否在phar中运行
if (class_exists('Phar')) {
    Phar::mapPhar('sync.phar');
}

// 解析命令行参数
$args = $argv;
array_shift($args); // 移除脚本名

if (empty($args) || in_array('-h', $args) || in_array('--help', $args)) {
    showHelp();
    exit(0);
}

$command = $args[0];
$params = array_slice($args, 1);

// 路由命令
switch ($command) {
    case 'start':
        handleStart($params);
        break;
    case 'stop':
        handleStop($params);
        break;
    case 'restart':
        handleRestart($params);
        break;
    case 'status':
        handleStatus();
        break;
    case 'daemon':
        handleDaemon($params);
        break;
    default:
        echo "未知命令: {$command}\n";
        echo "使用 -h 或 --help 查看帮助\n";
        exit(1);
}

function showHelp()
{
    echo <<<HELP
Weline Async 同步工具

用法:
    php sync.phar <command> [options]

命令:
    start [--host=id] [--mapping=id]    启动watcher
    stop [--host=id] [--mapping=id]     停止watcher
    restart [--host=id] [--mapping=id]  重启watcher
    status                              查看状态
    daemon [--interval=60]              守护进程模式
    -h, --help                          显示帮助信息

示例:
    php sync.phar start
    php sync.phar start --host=1
    php sync.phar start --mapping=1
    php sync.phar status
    php sync.phar daemon --interval=30

HELP;
}

function handleStart($params)
{
    echo "启动watcher...\n";
    // 这里需要实现启动逻辑
    // 由于phar独立运行，需要简化实现
    echo "注意: phar独立包需要配置数据库连接\n";
}

function handleStop($params)
{
    echo "停止watcher...\n";
    // 实现停止逻辑
}

function handleRestart($params)
{
    echo "重启watcher...\n";
    // 实现重启逻辑
}

function handleStatus()
{
    echo "查看状态...\n";
    // 实现状态查看逻辑
}

function handleDaemon($params)
{
    echo "启动守护进程...\n";
    // 实现守护进程逻辑
}

__HALT_COMPILER();
