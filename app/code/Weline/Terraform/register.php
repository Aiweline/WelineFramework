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
    'Weline_Terraform',
    __DIR__,
    '1.0.0',
    'Terraform 自动化基础设施模块，用于批量绑定 CDN 域名并记录执行结果。',
    [
        'Weline_Framework',
        'Weline_Cdn',
        'Weline_Websites',
        'Weline_Taglib',
    ]
);
