<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

/**
 * AI助手工具模块注册文件
 * 
 * 功能：
 * - 统一的AI模型管理和助手工具平台
 * - 支持多种AI模型和场景适配器
 * - 提供API接口和PHP服务两种调用模式
 * - 支持多语言和版本管理
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Ai',
    __DIR__,
    '1.0.0',
    '统一的AI模型管理和助手工具平台',
    [
        'Weline_Framework',
        'Weline_I18n',
        'Weline_Admin',
        'Weline_Frontend'
    ]
);
