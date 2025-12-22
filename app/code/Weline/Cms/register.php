<?php

/*
 * Weline Cms Module
 * CMS内容管理系统模块 - 用于可视化构建和管理页面内容
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Cms',
    __DIR__,
    '1.0.7',
    'CMS内容管理系统模块 - 提供可视化页面构建和管理功能',
    [
        'Weline_Framework',
        'Weline_Admin',
        'Weline_Backend'
    ]
);

