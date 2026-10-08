<?php
declare(strict_types=1);

return [
    'php' => '^8.1',
    // Stalwart 管理/读侧凭据密封硬依赖 sodium_crypto_secretbox；禁止无扩展却声称邮箱可开通。
    'extensions' => [
        'sodium',
    ],
    'functions' => [
        'exec',
        'proc_open',
        'fsockopen',
    ],
    'recommended_items' => [
        [
            'name' => 'Stalwart Mail Server',
            'install_id' => 'stalwart-mail-server',
            'description' => '企业邮箱模块底层邮件服务。Linux 使用原生二进制 + systemd；Windows 使用原生二进制 + NSSM 服务，不使用 Docker。',
            'platform' => 'all',
            'script_linux' => 'script/install_stalwart_linux.sh',
            'script_windows' => 'script/install_stalwart_windows.ps1',
        ],
    ],
];
