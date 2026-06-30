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
    'Weline_Payment',
    __DIR__,
    '1.0.2',
    '支付管理模块，提供统一的支付接口标准，支持第三方支付供应商通过模块扩展机制接入',
    ['Weline_Framework', 'Weline_Backend', 'Weline_Frontend', 'Weline_I18n', 'Weline_Hook', 'Weline_Theme', 'Weline_Eav', 'Weline_SystemConfig']
);

