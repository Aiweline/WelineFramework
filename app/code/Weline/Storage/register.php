<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Storage',
    __DIR__,
    '1.0.0',
    __('统一存储抽象层，支持本地存储、AWS S3、阿里云 OSS 等多种存储后端。')
);
