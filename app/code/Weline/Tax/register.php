<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Tax',
    __DIR__,
    '2.1.6',
    'Scope tax engine, multi-source rate sync, frozen checkout snapshots and LKG',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
