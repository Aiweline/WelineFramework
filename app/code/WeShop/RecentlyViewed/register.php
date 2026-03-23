<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_RecentlyViewed',
    __DIR__,
    '1.0.0',
    'WeShop浏览历史模块',
    [
        'Weline_Framework',
        'WeShop_Customer',
        'WeShop_Product',
    ]
);
