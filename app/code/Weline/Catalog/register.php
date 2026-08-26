<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Catalog',
    __DIR__,
    '1.0.0',
    'Universal catalog hub for multi-space category trees',
    [
        'Weline_Framework',
        'Weline_Backend',
        'Weline_Eav',
        'Weline_I18n',
        'Weline_Websites',
        'Weline_Acl',
    ],
);
