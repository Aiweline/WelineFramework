<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Search',
    __DIR__,
    '1.4.0',
    'Universal search hub with scoped analytics and provider SPI',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
        'Weline_Queue',
    ]
);
