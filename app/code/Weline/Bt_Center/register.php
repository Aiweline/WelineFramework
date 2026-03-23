<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Bt_Center',
    __DIR__,
    '1.1.1',
    'BT 面板管理中心',
    [
        'Weline_Backend',
        'Weline_Cron',
        'Weline_Framework',
    ]
);
