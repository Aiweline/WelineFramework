<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_GoogleAuth',
    __DIR__,
    '1.0.0',
    'WeShop Google login and binding module',
    [
        'Weline_Framework',
        'Weline_Admin',
        'Weline_Backend',
        'WeShop_Auth',
        'WeShop_Customer',
        'WeShop_Social',
    ]
);
