<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Cms',
    __DIR__,
    '1.0.0',
    'WeShop内容管理模块',
    [
        'Weline_Framework',
    ]
);
