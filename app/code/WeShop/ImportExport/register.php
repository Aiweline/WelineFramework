<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_ImportExport',
    __DIR__,
    '1.0.0',
    'WeShop导入导出模块',
    [
        'Weline_Framework',
    ]
);
