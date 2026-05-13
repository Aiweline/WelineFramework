<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_FakeData',
    __DIR__,
    '1.0.0',
    'Development-only fake data import module. Providers are registered through extends and executed only by CLI commands.',
    [
        'Weline_Framework',
        'Weline_Extends',
    ]
);

