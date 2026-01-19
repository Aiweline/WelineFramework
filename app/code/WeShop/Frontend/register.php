<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Frontend',
    __DIR__,
    '1.0.0',
    'WeShop前端模块',
    [
        'Weline_Framework',
        'Weline_Frontend',
        'Weline_Currency',
        'Weline_I18n',
    ]
);
