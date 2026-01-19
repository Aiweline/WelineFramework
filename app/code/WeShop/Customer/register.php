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
    'WeShop_Customer',
    __DIR__,
    '1.0.0',
    '<a href="https://bbs.aiweline.com">WeShop客户模块</a>',
    ['Weline_Framework', 'Weline_Backend', 'Weline_Frontend', 'Weline_I18n', 'Weline_Currency']
);
