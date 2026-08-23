<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_StorageOss',
    __DIR__,
    '1.0.0',
    __('阿里云 OSS 的统一存储驱动与 URL 适配器。'),
    ['Weline_Framework', 'Weline_Storage'],
);
