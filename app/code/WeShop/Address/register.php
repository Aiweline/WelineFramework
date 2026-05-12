<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Address',
    __DIR__,
    '1.0.0',
    'WeShop地址模块',
    [
        'Weline_Framework',
        'Weline_I18n',
        'Weline_Location',
    ]
);
