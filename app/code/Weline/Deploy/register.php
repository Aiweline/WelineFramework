<?php

declare(strict_types=1);

/**
 * Weline Deploy Module Registration
 * 
 * @package Weline_Deploy
 * @author WelineFramework Team
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Deploy',
    __DIR__,
    '1.1.0',
    'WelineFramework 部署模块，提供基于 Git 的代码更新、发布系统与版本探测功能。'
);

