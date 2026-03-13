<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;
use Weline\Framework\Register\RegisterDataInterface;

Register::register(
    RegisterDataInterface::MODULE,
    'Weline_Smtp',
    __DIR__,
    '1.1.0',
    '邮件发送服务模组，支持多模块 SMTP 配置、w_query、w_smtp 全局函数.',
    [
        'Weline_Admin',
        'Weline_Backend',
        'Weline_SystemConfig',
    ]
);
