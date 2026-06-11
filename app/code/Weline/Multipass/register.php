<?php

/*
 * Weline Multipass Module
 * Multipass 多通道登录模块 - 类似 Shopify Multipass 的第三方站点登录功能
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Multipass',
    __DIR__,
    '1.0.0',
    'Multipass 多通道登录模块 - 支持第三方站点直接跳转登录',
    [
        'Weline_Framework',
        'Weline_Backend',
        'Weline_Frontend',
        'Weline_Customer'
    ]
);

