<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Ama所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_FileManager',
    __DIR__,
    '1.0.0',
    '文件管理器管理.提供file-manager标签，快速调用文件管理器。',
    [
        'Weline_Backend',
        'Weline_MediaManager'
    ]
);
