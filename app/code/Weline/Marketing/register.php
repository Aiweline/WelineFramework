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
    'Weline_Marketing',
    __DIR__,
    '1.1.0',
    '万能优惠规则模块：规则引擎、优惠券、DiscountQuote 与 Checkout 算价契约',
    ['Weline_Framework', 'Weline_Backend']
);
