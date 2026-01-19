<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Tax',
    __DIR__,
    '1.0.0',
    'WeShop税务模块',
    [
        'Weline_Framework',
        'Weline_I18n',
    ]
);
