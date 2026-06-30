<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_PhpManager',
    __DIR__,
    '1.0.0',
    'WLS Panel PHP profile manager.',
    [
        'Weline_Backend',
        'Weline_Server',
    ]
);
