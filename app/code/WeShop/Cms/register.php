<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Cms',
    __DIR__,
    '1.0.0',
    '<a href="https://bbs.aiweline.com">WeShop CMS模块 - 内容页面管理</a>',
    [
        'Weline_Framework',
        'Weline_Backend',
        'Weline_Frontend',
        'WeShop_Frontend',
    ]
);
