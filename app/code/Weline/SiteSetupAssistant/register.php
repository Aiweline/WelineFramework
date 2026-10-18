<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_SiteSetupAssistant',
    __DIR__,
    '0.3.14',
    '建站助手：面板弹出层效果（遮罩模糊 + 分层阴影 + 入场动画）。',
    [
        'Weline_Backend',
        'Weline_Dashboard',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
