<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Social',
    __DIR__,
    '1.0.0',
    'WeShop社交功能模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
    ]
);
