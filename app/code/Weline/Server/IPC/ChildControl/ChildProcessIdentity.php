<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Framework\System\IPC\ProcessKind;

/**
 * 子进程身份标识（WLS 特化版）
 *
 * processKind 和 moduleCode 已迁移至框架层 ProcessKind。
 * 此处保留 KIND_* 常量作向后兼容别名。
 *
 * @see \Weline\Framework\System\IPC\ProcessIdentity  框架通用版
 */
final class ChildProcessIdentity
{
    /** @deprecated 使用 ProcessKind::FRAMEWORK */
    public const KIND_FRAMEWORK = ProcessKind::FRAMEWORK;
    /** @deprecated 使用 ProcessKind::MODULE */
    public const KIND_MODULE    = ProcessKind::MODULE;

    public function __construct(
        public readonly string $role,
        public readonly int    $pid,
        public readonly int    $port       = 0,
        public readonly int    $workerId   = 0,
        public readonly int    $epoch      = 0,
        public readonly string $launchId   = '',
        /** 进程归属类型：ProcessKind::FRAMEWORK | ProcessKind::MODULE */
        public readonly string $processKind = ProcessKind::FRAMEWORK,
        /** 模块代码（仅 module 类进程有效，格式如 'Weline_Payment'） */
        public readonly string $moduleCode  = '',
    ) {
    }

    public function isFrameworkProcess(): bool
    {
        return $this->processKind === ProcessKind::FRAMEWORK;
    }

    public function isModuleProcess(): bool
    {
        return $this->processKind === ProcessKind::MODULE;
    }
}

