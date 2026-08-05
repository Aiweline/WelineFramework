<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_B2B',
    __DIR__,
    '2.3.0',
    'Durable B2B pricing, immutable Order snapshots and checkpointed clone cutover',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
