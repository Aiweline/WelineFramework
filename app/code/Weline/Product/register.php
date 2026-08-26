<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Product',
    __DIR__,
    '1.1.0',
    'Product catalog website shards and commerce kernel contracts',
    [
        'Weline_Framework',
        'Weline_Catalog',
        'Weline_Websites',
        'Weline_Search',
    ]
);
