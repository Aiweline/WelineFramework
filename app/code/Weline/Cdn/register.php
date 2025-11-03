<?php

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
    '<a href="https://bbs.aiweline.com">CDN模块: 多适配器CDN管理、缓存清理、预热</a>',
    [
        'Weline_Framework',
        'Weline_Websites',
        'Weline_Cron'
    ]
);

