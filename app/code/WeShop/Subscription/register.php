<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Subscription',
    __DIR__,
    '1.0.0',
    'WeShop订阅模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
        'WeShop_Product',
        'WeShop_Order',
        'WeShop_Payment',
    ]
);
