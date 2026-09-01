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
    '2.13.1',
    '订单管理模块 - 提供完整的订单生命周期管理与 TrackingProvider 跟踪壳',
    [
        'Weline_Acl',
        'Weline_Backend',
        'Weline_Customer',
        'Weline_Framework',
        'Weline_Inventory',
        'Weline_Payment',
        'Weline_Queue',
        'Weline_Websites',
    ]
);
