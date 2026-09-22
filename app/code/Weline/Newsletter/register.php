<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Newsletter',
    __DIR__,
    '1.0.0',
    '邮件订阅：名单台账、订阅有奖、Smtp 默认模板；与周期订购/企业邮箱隔离',
    [
        'Weline_Framework',
        'Weline_Frontend',
        'Weline_Theme',
        'Weline_Widget',
        'Weline_Smtp',
        'Weline_Marketing',
        'Weline_Websites',
        'Weline_SystemConfig',
        'Weline_Acl',
        'Weline_Backend',
    ],
);
