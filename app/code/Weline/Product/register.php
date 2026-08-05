<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Product',
    __DIR__,
    '1.0.15',
    'Product catalog website shards and commerce kernel contracts',
    [
        'Weline_Framework',
        'Weline_Websites',
    ]
);
