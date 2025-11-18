<?php

/*
 * GuoLaiRen SMTP Module
 * SMTP邮件发送模块 - 用于通过SMTP协议发送邮件
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'GuoLaiRen_Smtp',
    __DIR__,
    '1.0.0',
    'SMTP邮件发送模块 - 支持Namecheap等邮件服务商',
    [
        'Weline_Framework'
    ]
);

