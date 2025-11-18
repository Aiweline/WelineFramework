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
    'Weline_Extends',
    __DIR__,
    '1.0.0',
    '扩展管理模块，用于管理和查看系统中所有模块的扩展关系、循环依赖检测等功能。',
    [
        'Weline_Framework'
    ]
);

