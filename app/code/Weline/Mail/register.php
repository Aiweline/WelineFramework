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
    'Weline_Mail',
    __DIR__,
    '0.1.0',
    '企业邮箱管理模块：管理自建邮件服务环境、域名、账号、DNS 检测和服务状态。',
    [
        'Weline_Admin',
        'Weline_SystemConfig',
        'Weline_Queue',
        'Weline_Cron',
        'Weline_Customer',
    ]
);
