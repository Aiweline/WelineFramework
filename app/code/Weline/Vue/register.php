<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Weline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Vue',
    __DIR__,
    '1.0.0',
    'Vue支持',
    [
        'Weline_Taglib'
    ]
);
