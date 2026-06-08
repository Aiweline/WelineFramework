<?php
declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_SampleModule',
    __DIR__,
    '1.0.18',
    __('Weline 官方示例功能模块')
);
