<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_RdpWrapper',
    __DIR__,
    '1.0.0',
    'Windows RDP Wrapper 多用户远程桌面管理模块，支持多人同时远程连接。',
    ['Weline_Backend']
);
