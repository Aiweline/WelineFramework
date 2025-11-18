<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    \Weline\Theme\Register\TypeInterface::type,
    'Weline_test',
    [
        'name' => 'test',
        'parent' => 'default',
        'path' => __DIR__,
    ],
    '1.0.1',
    'demo主题'
);
