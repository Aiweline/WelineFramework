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
    'Weline_Websites',
    __DIR__,
    '1.6.3',
    '<a href="https://bbs.aiweline.com">系统组件模块: 多网站（含域名商管理、域名购买、DNS管理、证书管理集成）</a>',
    [
        'Weline_Acl',
        'Weline_Admin',
        'Weline_Framework',
        'Weline_Currency',
    ]
);
