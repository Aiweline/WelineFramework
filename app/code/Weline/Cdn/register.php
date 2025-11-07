<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Cdn',
    __DIR__,
    '1.0.0',
    '多适配器CDN管理模块，提供缓存清理、规则管理、预热等功能。默认支持Cloudflare，同时允许其他模块通过适配器模式贡献其他CDN提供商。',
    [
        'Weline_Framework',
        'Weline_Websites',
        'Weline_Cron'
    ]
);

