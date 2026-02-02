<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

// 注册模块
Register::register(
    Register::MODULE,
    'Weline_Theme',
    __DIR__,
    '1.0.5',
    '<a href="https://bbs.aiweline.com">官网</a>提供主题功能的模块。',
    ['Weline_Meta']
);

// 注册默认主题 - 确保系统始终有一个可用的基础主题
Register::register(
    Register::THEME,
    'Weline_Theme',
    [
        'name' => 'Default 默认主题',
        'path' => __DIR__ . '/view/theme',
    ],
    '1.0.5',
    'Weline Framework 默认主题，提供基础的前后台界面样式和布局。'
);
