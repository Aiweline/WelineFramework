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
    'Weline_Api',
    __DIR__,
    '1.0.0',
    'Weline API模块，提供统一的API用户管理、认证授权、文档生成等功能',
    ['Weline_Framework', 'Weline_Backend', 'Weline_Acl', 'Weline_Admin']
);

