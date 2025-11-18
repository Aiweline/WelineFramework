<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Weline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_AliDdnsServer',
    __DIR__,
    '1.0.0',
    '阿里云Ddns云解析服务，支持多域名，支持ipv4和ipv6。',
    ['Weline_Admin']
);
