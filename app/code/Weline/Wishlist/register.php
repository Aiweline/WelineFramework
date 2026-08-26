<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Wishlist',
    __DIR__,
    '1.0.0',
    '前台心愿单：游客 Cookie 与登录态可扩展，供产品卡片收藏按钮调用',
    ['Weline_Framework'],
);
