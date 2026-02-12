<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Framework 环境需求声明
 * 
 * 这是 Weline Framework 的核心环境需求，包含框架运行所必需的扩展和函数。
 */
return [
    // PHP 版本约束
    'php' => '^8.1',

    // 必需的扩展
    'extensions' => [
        'PDO',
        'json',
        'iconv',
        'fileinfo',
        'dom',
        'libxml',
        'simplexml',
        'intl',  // I18n 多语言排序等需要；未安装时 Symfony Polyfill 仅支持 en，zh_Hans_CN 会报错
    ],

    // 必需的函数（须未被 disable_functions）
    'functions' => [
        'exec',
        'putenv',
        'proc_open',
    ],

    // 复杂依赖项
    'items' => [
        [
            'name' => '函数解禁',
            'description' => '框架需要 exec、putenv、proc_open 函数。请编辑 php.ini，从 disable_functions 中移除这些函数。',
            'script_linux' => 'script/unblock_functions.php',
            'script_windows' => 'script/unblock_functions.php',
        ],
    ],
];
