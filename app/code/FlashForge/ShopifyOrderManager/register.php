<?php

/*
 * Shopify订单管理模块
 * 用于从多个Shopify店铺抓取订单信息，包含订单状态监控和飞书通知功能
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'FlashForge_ShopifyOrderManager',
    __DIR__,
    '1.0.0',
    'Shopify订单管理系统 - 支持多店铺订单抓取、状态监控、飞书通知',
    [
        'Weline_Framework',
        'Weline_Admin',
        'Weline_Cron'
    ]
);
