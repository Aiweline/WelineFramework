<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Affiliate',
    __DIR__,
    '1.0.0',
    'Weline 万能分销模块',
    ['Weline_Framework', 'Weline_Customer', 'Weline_Backend', 'Weline_I18n']
);
