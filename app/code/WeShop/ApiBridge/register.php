<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_ApiBridge',
    __DIR__,
    '1.0.0',
    'WeShop API contract bridge module',
    [
        'Weline_Framework',
        'Weline_Api',
        'WeShop_Auth',
    ]
);
