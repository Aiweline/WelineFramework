<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Search',
    __DIR__,
    '1.0.0',
    'WeShop搜索模块',
    [
        'Weline_Framework',
        'Weline_Backend',
        'WeShop_Product',
        'WeShop_Catalog',
    ]
);
