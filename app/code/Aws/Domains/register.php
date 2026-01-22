<?php

declare(strict_types=1);

/*
 * AWS Domains 管理模块
 * 提供 AWS Route 53 Domains 域名管理功能
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Aws_Domains',
    __DIR__,
    '1.0.0',
    'AWS 域名管理模块 - 支持购买、续费、转入域名及状态查询',
    [
        'Weline_Backend',
    ]
);
