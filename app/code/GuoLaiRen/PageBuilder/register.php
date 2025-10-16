<?php

/*
 * GuoLaiRen PageBuilder Module
 * 页面构建器模块 - 用于可视化构建和管理页面内容
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'GuoLaiRen_PageBuilder',
    __DIR__,
    '1.0.0',
    '页面构建器模块 - 提供可视化页面构建和管理功能',
    [
        'Weline_Framework',
        'Weline_Admin',
        'Weline_Backend'
    ]
);

