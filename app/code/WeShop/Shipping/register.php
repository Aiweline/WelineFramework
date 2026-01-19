<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Shipping',
    __DIR__,
    '1.0.0',
    'WeShop配送模块',
    [
        'Weline_Framework',
    ]
);
