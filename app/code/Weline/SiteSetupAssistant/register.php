<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_SiteSetupAssistant',
    __DIR__,
    '0.3.4',
    '建站助手：默认全站；胶囊提示各站未完成并可切站。',
    [
        'Weline_Backend',
        'Weline_Dashboard',
        'Weline_SystemConfig',
        'Weline_Websites',
    ]
);
