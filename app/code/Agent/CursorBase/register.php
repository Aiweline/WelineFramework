<?php

declare(strict_types=1);

/**
 * Agent CursorBase Module
 * Cursor 核心操作基础模块 - 提供 Cursor CLI 操作、信号弹注入、智能体调度等核心功能
 * 供其他智能体模块复用
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Agent_CursorBase',
    __DIR__,
    '1.0.0',
    'Cursor 核心操作基础模块 - CLI 操作、信号弹、调度器、任务池'
);
