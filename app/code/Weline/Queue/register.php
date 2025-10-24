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
    'Weline_Queue',
    __DIR__,
    '1.1.1',
    '消息队列：目前使用数据库做消息队列。',
    [
        'Weline_Eav'
    ]
);
