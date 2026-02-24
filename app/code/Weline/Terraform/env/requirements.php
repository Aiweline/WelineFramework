<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline Terraform 环境需求声明
 *
 * Terraform CLI 用于批量创建 CDN 域名与记录。
 *
 * 约定：
 * - Linux 服务器环境：Terraform CLI 作为「必需依赖」（items），env:check 未通过会中断安装。
 * - 非 Linux（如 macOS 开发机、Windows）：
 *   - Terraform CLI 作为「推荐依赖」（recommended_items），缺失不会导致 env:check 失败，
 *     但 env:install 仍会尝试自动安装，且相关功能在缺失时会自动降级。
 */

$terraformItem = [
    'name' => 'Terraform CLI',
    'description' => 'Terraform CLI 用于批量创建 CDN 域名与 DNS 记录。',
    'script_linux' => 'script/terraform_linux.sh',
    'script_darwin' => 'script/terraform_linux.sh',
    'script_windows' => 'script/terraform_windows.ps1',
];

$items = [];
$recommendedItems = [];

if (PHP_OS_FAMILY === 'Linux') {
    // 生产服务器场景：严格要求 Terraform CLI 存在
    $items[] = $terraformItem;
} else {
    // 开发机（macOS / Windows 等）：将 Terraform CLI 视为推荐项，避免安装脚本因其直接退出
    $recommendedItems[] = $terraformItem;
}

return [
    'php' => '^8.1',
    'functions' => [
        'exec',
        'shell_exec',
        'proc_open',
    ],
    'items' => $items,
    'recommended_items' => $recommendedItems,
];
