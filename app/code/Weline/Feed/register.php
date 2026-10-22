<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Feed',
    __DIR__,
    '0.1.0',
    '购物目录 Feed 内核：定义、连接态、生成物与公开拉取',
    [
        'Weline_Framework',
        'Weline_Product',
    ],
);
