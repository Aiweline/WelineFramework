<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Logistics',
    __DIR__,
    '1.0.0',
    'WeShop物流跟踪模块',
    [
        'Weline_Framework',
        'WeShop_Shipping',
    ]
);
