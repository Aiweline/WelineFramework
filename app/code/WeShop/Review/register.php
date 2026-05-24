<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Review',
    __DIR__,
    '1.0.2',
    'WeShop评论模块',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'WeShop_Customer',
        'WeShop_Notification',
        'WeShop_Order',
        'WeShop_Product',
    ]
);
