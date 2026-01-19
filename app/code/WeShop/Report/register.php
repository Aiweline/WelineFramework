<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Report',
    __DIR__,
    '1.0.0',
    'WeShop数据报表模块',
    [
        'Weline_Framework',
        'WeShop_Order',
    ]
);
