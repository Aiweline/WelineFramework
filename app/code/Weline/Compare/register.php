<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Compare',
    __DIR__,
    '1.0.0',
    '万能商品对比与快速查看：对比栏、对比页、Quick View 弹窗',
    ['Weline_Framework', 'Weline_Eav'],
);
