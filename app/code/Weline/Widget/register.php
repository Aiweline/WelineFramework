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
    'Weline_Widget',
    __DIR__,
    '1.0.0',
    '可视化编辑器模块，支持通过拖放方式组织页面，使用 w:widget 标签存储和渲染部件。',
    [
        'Weline_Framework',
        'Weline_Extends',
        'Weline_Meta',
        'Weline_Taglib',
        'Weline_Backend'
    ]
);

