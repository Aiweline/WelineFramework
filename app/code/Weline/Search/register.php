<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Search',
    __DIR__,
    '1.3.0',
    'Website-sharded Search projection, staged full build and scoped incremental Queue',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
        'Weline_Queue',
        'Weline_Product',
    ]
);
