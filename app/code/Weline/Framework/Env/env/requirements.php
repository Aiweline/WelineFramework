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
        'intl',   // I18n 多语言排序等需要；未安装时 Symfony Polyfill 仅支持 en，zh_Hans_CN 会报错
        'mbstring',
        'sockets', // WLS / 队列等常驻进程与网络 I/O
        'openssl',
        'curl',
        'exif',   // 媒体/上传元数据；composer 平台要求常声明
        'xsl',    // XML 转换 / 部分依赖
        'zip',
        'bcmath',
        'pdo_pgsql', // PostgreSQL 支持；使用 pgsql 时须安装（apt-get install php-pgsql / yum install php-pgsql）
        'pdo_mysql', // MySQL 支持；使用 mysql 时须安装
    ],

    // 必需的函数（须未被 disable_functions）——与 ConfigurePhpIni / unblock_functions.php 同步
    'functions' => [
        'exec',
        'putenv',
        'proc_open',
        'proc_close',
        'proc_get_status',
        'shell_exec',
        'system',
        'popen',
        'chown',
        'chmod',
        'chgrp',
    ],

    // 复杂依赖项
    'items' => [
        [
            'name' => '函数解禁',
            'description' => '框架需要 exec/putenv/proc_open/chown 等函数。安装阶段会自动从 disable_functions 移除；失败时请手动编辑 php.ini。',
            'script_linux' => 'script/unblock_functions.php',
            'script_windows' => 'script/unblock_functions.php',
        ],
    ],
];
