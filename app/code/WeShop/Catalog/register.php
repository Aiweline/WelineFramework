<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Catalog',
    __DIR__,
    '1.0.0',
    'WeShop目录分类模块',
    [
        'Weline_Framework',
        'WeShop_Product',
    ]
);
