<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    \Weline\Theme\Register\TypeInterface::type,
    'Codex_DemoTheme',
    [
        'name' => 'codex-demo-theme',
        'path' => __DIR__,
    ],
    '0.1.0',
    'Theme layout discovery demo theme.'
);
