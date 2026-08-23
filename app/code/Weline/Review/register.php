<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Review',
    __DIR__,
    '1.0.0',
    '通用评论模块，提供类型隔离、动态字段、匿名评论与图文视频评论能力',
    ['Weline_Framework']
);
