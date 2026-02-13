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
 */
return [
    'php' => '^8.1',
    'functions' => [
        'exec',
        'shell_exec',
        'proc_open',
    ],
    'items' => [
        [
            'name' => 'Terraform CLI',
            'description' => 'Terraform CLI 用于批量创建 CDN 域名与 DNS 记录。',
            'script_linux' => 'script/terraform_linux.sh',
            'script_darwin' => 'script/terraform_linux.sh',
            'script_windows' => 'script/terraform_windows.ps1',
        ],
    ],
];
