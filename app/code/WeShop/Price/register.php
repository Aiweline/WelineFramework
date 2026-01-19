<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Price',
    __DIR__,
    '1.0.0',
    'WeShop价格管理模块',
    [
        'Weline_Framework',
        'Weline_I18n',
        'Weline_Currency',
        'WeShop_Product',
    ]
);
