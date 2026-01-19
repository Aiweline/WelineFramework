<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WeShop_Notification',
    __DIR__,
    '1.0.0',
    'WeShop通知中心模块',
    [
        'Weline_Framework',
        'Weline_Smtp',
        'Weline_Queue',
    ]
);
