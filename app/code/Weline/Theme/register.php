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
    '2.2.837',
    '<a href="https://bbs.aiweline.com">官网</a>提供主题功能的模块。',
    ['Weline_Backend', 'Weline_Framework', 'Weline_I18n', 'Weline_Meta', 'Weline_SystemConfig', 'Weline_Widget']
);

// Theme 模块即全局默认主题（磁盘权威）；Register::THEME 仅写入目录便于继承/列表，
// 运行缺省不依赖库表 id（绝非固定 id=1），无目录行仍可用模块 view/theme。
Register::register(
    Register::THEME,
    'Weline_Theme',
    [
        'name' => 'Default 默认主题',
        'path' => __DIR__ . '/view/theme',
    ],
    '2.2.836',
    'Weline Framework 默认主题，提供基础的前后台界面样式和布局；系统全局默认，禁止当作可卸载业务主题。'
);
