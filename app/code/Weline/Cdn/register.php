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
    'CDN模块 - 提供CDN缓存管理和规则配置功能',
    [
        'Weline_Framework'
    ]
);

