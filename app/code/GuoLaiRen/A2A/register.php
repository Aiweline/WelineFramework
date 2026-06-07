<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'GuoLaiRen_A2A',
    __DIR__,
    '0.1.0',
    __('Agent-to-Agent 托管交易平台原型'),
    [
        'Weline_Framework',
        'Weline_Frontend',
        'Weline_Backend',
        'Weline_Acl',
    ]
);
