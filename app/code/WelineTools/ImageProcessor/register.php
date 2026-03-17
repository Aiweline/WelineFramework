<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WelineTools_ImageProcessor',
    __DIR__,
    '1.0.0',
    __('图片处理工具集'),
    [
        'Weline_Framework',
    ]
);
