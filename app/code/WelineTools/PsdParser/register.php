<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WelineTools_PsdParser',
    __DIR__,
    '1.0.0',
    __('PSD 在线解析与图层文字提取工具'),
    [
        'Weline_Framework',
    ]
);
