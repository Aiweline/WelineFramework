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
    'Weline_Geo',
    __DIR__,
    '1.0.0',
    'Geo定位模块，提供浏览器定位和IP定位功能',
    ['Weline_Framework', 'Weline_Theme']
);

