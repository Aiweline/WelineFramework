<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Order',
    __DIR__,
    '1.0.0',
    'WeShop订单模块',
    [
        'Weline_Framework',
        'WeShop_Checkout',
    ]
);
