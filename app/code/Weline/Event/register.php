<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Event',
    __DIR__,
    '1.0.0',
    '事件管理模块，用于管理和查看系统中所有事件的详细信息、观察者关系等功能。',
    [
        'Weline_Framework'
    ]
);

