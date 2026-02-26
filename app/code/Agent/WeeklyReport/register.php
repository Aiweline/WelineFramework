<?php

declare(strict_types=1);

/**
 * Agent WeeklyReport Module
 * 周报管理器 - 交互式周报管理、中国节假日支持、Excel 导出
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Agent_WeeklyReport',
    __DIR__,
    '1.0.0',
    '周报管理器 - 交互式命令行周报管理、中国节假日支持、Excel 导出',
    [
        'Weline_Framework',
        'Weline_Backend',
    ]
);
