<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Filters',
    __DIR__,
    '1.0.0',
    '店面筛选模块：分类/列表侧栏部件注入，含价格与 EAV 属性筛选',
    [
        'Weline_Framework',
        'Weline_Eav',
        'Weline_Product',
        'Weline_Theme',
        'Weline_Widget',
    ],
);
