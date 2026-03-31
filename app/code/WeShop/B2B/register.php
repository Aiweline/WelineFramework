<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_B2B',
    __DIR__,
    '1.0.0',
    'WeShop B2B企业模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
        'WeShop_Order',
        'WeShop_Payment',
    ]
);
