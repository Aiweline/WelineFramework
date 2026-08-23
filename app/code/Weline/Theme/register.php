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
    '2.1.1',
    '<a href="https://bbs.aiweline.com">官网</a>提供主题功能的模块。',
    ['Weline_Backend', 'Weline_Framework', 'Weline_I18n', 'Weline_Meta', 'Weline_SystemConfig', 'Weline_Widget']
);

// 注册默认主题 - 确保系统始终有一个可用的基础主题
Register::register(
    Register::THEME,
    'Weline_Theme',
    [
        'name' => 'Default 默认主题',
        'path' => __DIR__ . '/view/theme',
    ],
    '2.1.1',
    'Weline Framework 默认主题，提供基础的前后台界面样式和布局。'
);
