<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Points',
    __DIR__,
    '1.1.0',
    '万能积分与会员成长/VIP等级规则模块：赚分、成长值、累计积分、等级权益、结账抵扣、退款扣回',
    [
        'Weline_Framework',
        'Weline_Customer',
        'Weline_CustomerAsset',
        'Weline_SystemConfig',
    ]
);
