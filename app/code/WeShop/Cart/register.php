<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Cart',
    __DIR__,
    '1.0.0',
    'WeShop购物车模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
        'WeShop_Product',
    ]
);
