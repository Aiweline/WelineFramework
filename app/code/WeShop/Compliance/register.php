<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Compliance',
    __DIR__,
    '1.0.0',
    'WeShop合规隐私模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
    ]
);
