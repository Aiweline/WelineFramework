<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Review',
    __DIR__,
    '1.0.0',
    'WeShop评论模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
        'WeShop_Product',
    ]
);
