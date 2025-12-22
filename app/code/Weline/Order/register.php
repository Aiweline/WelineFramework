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
    'Weline_Order',
    __DIR__,
    '1.0.0',
    '订单管理模块 - 提供完整的订单生命周期管理功能，符合国际电商标准',
    [
        'Weline_Framework',
        'Weline_Backend',
        'Weline_Customer'
    ]
);

