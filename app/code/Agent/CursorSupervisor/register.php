<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Agent_CursorSupervisor',
    __DIR__,
    '1.0.1',
    'Cursor AI 智能监督助手：监控文件变化、语法检查、AI 自动修复',
    [
        'Agent_CursorBase',
    ]
);
