<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Subscription',
    __DIR__,
    '2.3.0',
    'Durable Subscription identity, scheduler and checkpointed migration cutover',
    [
        'Weline_Framework',
        'Weline_SystemConfig',
        'Weline_Websites',
        'Weline_Order',
        'Weline_Payment',
        'Weline_Queue',
    ]
);
