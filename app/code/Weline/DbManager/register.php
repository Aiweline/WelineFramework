<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_DbManager',
    __DIR__,
    '1.0.0',
    'WLS Panel database profile manager.',
    [
        'Weline_Backend',
        'Weline_Server',
    ]
);
