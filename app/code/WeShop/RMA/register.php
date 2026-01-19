<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_RMA',
    __DIR__,
    '1.0.0',
    'WeShop退换货模块',
    [
        'Weline_Framework',
        'WeShop_Order',
    ]
);
