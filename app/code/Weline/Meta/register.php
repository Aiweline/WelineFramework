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
    'Weline_Meta',
    __DIR__,
    '1.0.0',
    '元数据管理模块，统一管理系统中各种文件的元数据，支持多语言翻译',
    ['Weline_Framework', 'Weline_Backend', 'Weline_I18n']
);

