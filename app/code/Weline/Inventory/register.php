<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Inventory',
    __DIR__,
    '2.5.5',
    'Store logical inventory ledger, strategies, and reservation',
    [
        'Weline_Framework',
        'Weline_Websites',
    ]
);
