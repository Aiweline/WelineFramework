<?php
declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_App',
    __DIR__,
    '1.0.1',
    __('一键编译 App：按站点配置打包 Flutter 客户端，提供下载部件与多语言多货币版本 API')
);
