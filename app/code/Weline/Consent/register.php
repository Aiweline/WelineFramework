<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Consent',
    __DIR__,
    '1.1.0',
    'Website-scoped consent records and banner (TASK-P1D-004-CONSENT)',
    [
        'Weline_Framework',
        'Weline_Frontend',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
