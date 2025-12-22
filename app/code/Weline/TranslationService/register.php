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
    'Weline_TranslationService',
    __DIR__,
    '1.0.0',
    '翻译服务模块，为其他模块提供全球主流翻译渠道对接服务',
    ['Weline_Framework', 'Weline_Backend', 'Weline_I18n']
);

