<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Filters',
    __DIR__,
    '1.0.0',
    '产品筛选模块 - 提供分类页面的筛选功能，包括价格、品牌、评分、库存、配送方式等筛选，以及基于EAV的动态属性筛选',
    [
        'Weline_Framework',
        'Weline_Eav',
        'WeShop_Product',
        'WeShop_Catalog',
    ]
);
