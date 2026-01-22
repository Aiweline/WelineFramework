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
    'Weline_Bt_Center',
    __DIR__,
    '1.0.0',
    '宝塔面板管理中心',
    [
        'Weline_Backend',
        'Weline_Framework',
    ]
);
