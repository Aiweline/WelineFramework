<?php

declare(strict_types=1);

/*
 * Weline Cron 环境需求声明
 *
 * Linux/macOS 下 cron:install 需要 crontab 命令
 * Windows 使用 Schtasks，无需 crontab
 */

return [
    'php' => '^8.1',
    'functions' => [
        'exec',
    ],
    'recommended_items' => [
        [
            'name' => 'Crontab CLI（Linux/macOS 定时任务）',
            'description' => 'Linux/macOS 下 cron:install 需要 crontab 命令，用于安装系统定时任务。Windows 使用 Schtasks，无需 crontab。',
            'platform' => 'unix',
            'script_linux' => 'script/install_crontab_linux.sh',
            'script_darwin' => 'script/install_crontab_linux.sh',
        ],
    ],
];
