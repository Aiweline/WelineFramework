<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_DeveloperWorkspace',
    __DIR__,
    '1.3.0',
    '系统开发空间',
    [
        'Weline_Ai',
        'Weline_SystemConfig',
        'Weline_Cron',
        'Weline_I18n',
    ]
);
