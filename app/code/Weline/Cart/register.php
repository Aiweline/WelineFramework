<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Cart',
    __DIR__,
    '1.0.0',
    '购物车根模块，提供购物车根路由与扩展契约，具体购物车实现由业务模块接入。',
    [
        'Weline_Framework',
    ]
);

