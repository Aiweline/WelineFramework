<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Promotion',
    __DIR__,
    '1.0.0',
    '万能促销运营台：活动投放决策、前台活动页与 campaign run 持久化',
    ['Weline_Backend', 'Weline_Theme'],
);
