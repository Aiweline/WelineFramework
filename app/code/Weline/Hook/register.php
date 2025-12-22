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
    'Weline_Hook',
    __DIR__,
    '1.0.0',
    '<a href="https://bbs.aiweline.com">官网</a>提供 Hook 钩子功能的模块，用于管理视图模板的扩展点。',
    ['Weline_Framework']
);

