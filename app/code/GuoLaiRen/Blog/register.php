<?php

/*
 * GuoLaiRen Blog Module
 * 博客功能模块 - 基于 PageBuilder 页面作为博客文章
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'GuoLaiRen_Blog',
    __DIR__,
    '1.0.0',
    '博客功能模块 - 使用 PageBuilder 页面作为博客文章来源',
    [
        'Weline_Framework',
        'Weline_Frontend',
        'GuoLaiRen_PageBuilder',
    ]
);

