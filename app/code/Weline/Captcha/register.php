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
    '1.0.0',
    '人机验证模块 - 提供Google reCAPTCHA v2/v3验证和备用图形验证码功能',
    ['Weline_Framework']
);
