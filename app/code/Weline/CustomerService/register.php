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
    'Weline_CustomerService',
    __DIR__,
    '1.1.0',
    '客服服务模块，提供多语言实时聊天、客户语言配置、客服语言配置、邮件绑定客户等功能',
    ['Weline_Framework', 'Weline_Backend', 'Weline_Customer', 'Weline_Theme', 'Weline_Smtp']
);

