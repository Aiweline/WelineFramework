<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_CustomerAsset',
    __DIR__,
    '1.0.0',
    'PostgreSQL-backed customer asset accounts, immutable ledger and reservations',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
