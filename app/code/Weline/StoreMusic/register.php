<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_StoreMusic',
    __DIR__,
    '1.0.0',
    '店面进店音乐',
    [
        'Weline_Framework',
        'Weline_Backend',
        'Weline_Frontend',
        'Weline_Theme',
        'Weline_SystemConfig',
    ]
);
