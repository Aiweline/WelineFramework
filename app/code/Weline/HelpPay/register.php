<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_HelpPay',
    __DIR__,
    '1.0.2',
    '帮我付 / 纯分享 / 快捷购买编排：Slot 注入、短链代付、出链双形态（链接+二维码）',
    [
        'Weline_Framework',
        'Weline_Frontend',
        'Weline_Theme',
        'Weline_Widget',
        'Weline_Payment',
        'Weline_Cart',
        'Weline_Checkout',
        'Weline_SystemConfig',
    ],
);
