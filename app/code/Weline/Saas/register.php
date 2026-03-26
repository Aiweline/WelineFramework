<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Saas',
    __DIR__,
    '1.0.0',
    'Legacy SaaS backend route compatibility module.',
    [
        'Weline_Websites',
    ]
);
