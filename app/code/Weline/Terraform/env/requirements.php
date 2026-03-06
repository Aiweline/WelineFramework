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
 * 约定：Terraform CLI 统一作为「推荐依赖」，缺失不会导致 env:check 失败，
 * 安装脚本可正常完成；需要批量 CDN 绑定时再安装 Terraform CLI 即可。
 */

$terraformItem = [
    'name' => 'Terraform CLI',
    'description' => 'Terraform CLI 用于批量创建 CDN 域名与 DNS 记录。',
    'script_linux' => 'script/terraform_linux.sh',
    'script_darwin' => 'script/terraform_linux.sh',
    'script_windows' => 'script/terraform_windows.ps1',
];

$items = [];
$recommendedItems = [$terraformItem];

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
