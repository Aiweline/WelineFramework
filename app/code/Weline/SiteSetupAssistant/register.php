<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_SiteSetupAssistant',
    __DIR__,
    '0.1.0-prototype',
    '建站助手：按站点范围展示上线/迁站贴士与任务进度（原型阶段）。',
    [
        'Weline_Backend',
        'Weline_Dashboard',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
