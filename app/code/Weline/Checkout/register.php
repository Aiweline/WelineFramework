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
    'Weline_Checkout',
    __DIR__,
    '1.0.0',
    '结账模块，提供完整的订单创建、订单管理和支付处理功能，支持国际化并为支付模块提供hook接口',
    [
        'Weline_Framework',
        'Weline_Backend',
        'Weline_Customer',
        'Weline_I18n'
    ]
);

