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
    'WeShop_Checkout',
    __DIR__,
    '1.0.0',
    'WeShop结算模块',
    ['Weline_Framework', 'WeShop_Cart', 'WeShop_Shipping', 'WeShop_Payment', 'WeShop_Order', 'WeShop_B2B']
);
