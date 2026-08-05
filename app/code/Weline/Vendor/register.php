<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Vendor',
    __DIR__,
    '1.2.0',
    'Vendor identity, ACL, split snapshot, payout and refund reversal',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
        'Weline_Acl',
    ]
);
