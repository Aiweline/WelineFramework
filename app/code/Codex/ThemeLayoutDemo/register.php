<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Codex_ThemeLayoutDemo',
    __DIR__,
    '0.1.0',
    'Theme layout discovery demo module.',
    ['Weline_Theme']
);
