<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Tax',
    __DIR__,
    '2.1.2',
    'Scope tax engine, frozen checkout snapshots and exact-scope versioned LKG',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
