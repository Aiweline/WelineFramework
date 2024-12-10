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
    'Weline_CacheManager',
    __DIR__,
    '1.1.0',
    '<a href="bbs.aiweline.com">缓存管理器</a>',
    [
        'Weline_Admin'
    ]
);
