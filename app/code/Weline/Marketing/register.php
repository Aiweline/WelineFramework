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
    'Weline_Marketing',
    __DIR__,
    '1.0.0',
    '市场营销模块，提供全面的优惠规则引擎系统，支持多种优惠类型和多维度条件判断，符合国际标准',
    ['Weline_Framework', 'Weline_Backend']
);

