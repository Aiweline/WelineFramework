<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

/**
 * 子进程身份标识
 *
 * processKind 区分进程归属：
 *  - 'framework' : WLS 内置进程（Worker、Dispatcher、Session Server 等）
 *  - 'module'    : 由第三方模块注册并管理的自定义子进程
 *
 * 模块进程须设置 moduleCode 以便监控系统区分来源（格式：{VendorModule}，如 'Weline_Payment'）。
 */
final class ChildProcessIdentity
{
    public const KIND_FRAMEWORK = 'framework';
    public const KIND_MODULE    = 'module';

    public function __construct(
        public readonly string $role,
        public readonly int    $pid,
        public readonly int    $port       = 0,
        public readonly int    $workerId   = 0,
        public readonly int    $epoch      = 0,
        public readonly string $launchId   = '',
        /** 进程归属类型：'framework' 或 'module' */
        public readonly string $processKind = self::KIND_FRAMEWORK,
        /** 模块代码（仅 module 类进程有效，格式如 'Weline_Payment'） */
        public readonly string $moduleCode  = '',
    ) {
    }

    public function isFrameworkProcess(): bool
    {
        return $this->processKind === self::KIND_FRAMEWORK;
    }

    public function isModuleProcess(): bool
    {
        return $this->processKind === self::KIND_MODULE;
    }
}
