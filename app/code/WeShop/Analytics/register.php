<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Analytics',
    __DIR__,
    '1.0.0',
    'WeShop统计模块',
    [
        'Weline_Framework',
        'Weline_Visitor',
    ]
);
