<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_WidgetDemo',
    __DIR__,
    '1.0.0',
    'Demo widget module used to verify first database registration driven default injection.',
    [
        'Weline_Dashboard',
        'Weline_Theme',
        'Weline_Widget',
    ]
);
