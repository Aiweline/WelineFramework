<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_WlsDemoPlugin',
    __DIR__,
    '1.0.0',
    'WLS Panel marketplace demo plugin used to verify typed-tag discovery and installation.',
    [
        'Weline_Backend',
    ]
);
