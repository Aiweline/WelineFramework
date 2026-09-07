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
    'Weline_Captcha',
    __DIR__,
    '1.0.1',
    '统一人机验证模块 - 腾讯云 / Google reCAPTCHA Enterprise / 本地图码，支持按国家路由',
    ['Weline_Framework', 'Weline_SystemConfig']
);
