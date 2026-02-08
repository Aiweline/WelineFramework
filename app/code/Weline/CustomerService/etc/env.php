<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * 客服模块环境配置
 */
return [
    // 路由别名：使用 /customerservice/ 前缀
    'router' => 'customerservice',

    // 客服配置（这些通过后台管理界面设置，存储在 customer_service_config 表中）
    // 'ai_enabled' => '1',        // 是否启用 AI 自动回复（无客服在线时）
    // 'ai_model'   => 'gpt-4o',   // AI 回复使用的模型
];
