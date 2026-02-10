<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Saas',
    __DIR__,
    '1.0.0',
    __('SaaS 一站式配置：域名购买、DNS 绑定、CDN 绑定、SSL 证书申请'),
    [
        'Weline_Framework',
        'Weline_Websites',
        'Weline_Cdn',
        'Weline_Server',
    ]
);
