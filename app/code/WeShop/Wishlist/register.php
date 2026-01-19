<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Wishlist',
    __DIR__,
    '1.0.0',
    'WeShop心愿单模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
        'WeShop_Product',
    ]
);
