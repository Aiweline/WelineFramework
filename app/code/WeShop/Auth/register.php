<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Auth',
    __DIR__,
    '1.0.0',
    'WeShop unified auth module',
    [
        'Weline_Framework',
        'Weline_Api',
        'Weline_Admin',
        'Weline_Backend',
        'Weline_Customer',
        'Weline_TwoFactorAuth',
        'WeShop_Customer',
    ]
);
