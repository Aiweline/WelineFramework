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
    'Weline_Seo',
    __DIR__,
    '1.1.0',  // 新增 SeoWebsiteStats 统计模型、平台适配器 getStats() 接口、StatsSync 定时任务
    'SEO 集成与智能优化模块',
    [
        'Weline_Ai',
        'Weline_Backend',
        'Weline_Websites',
        'WeShop_Store',
    ]
);



