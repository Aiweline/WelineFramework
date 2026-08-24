<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_GenerativeEngineOptimization',
    __DIR__,
    '1.0.0',
    '生成式搜索引擎优化模块，专门向AI生成式搜索引擎（Google SGE、Perplexity、Bing Chat、OpenAI、Claude等）提供Feed，支持多平台、密钥管理、一键推送和自动更新推送功能',
    ['Weline_Framework', 'Weline_Backend', 'Weline_I18n']
);
