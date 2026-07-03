<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Dashboard',
    __DIR__,
    '1.0.0',
    '后台 Dashboard 报表视图模块，复用 Theme 后台 dashboard 布局与 Widget 部件体系。',
    [
        'Weline_Admin',
        'Weline_Backend',
        'Weline_Theme',
        'Weline_Widget',
        'Weline_Websites',
    ]
);
