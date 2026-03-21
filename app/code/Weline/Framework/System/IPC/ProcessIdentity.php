<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

/**
 * 子进程身份标识
 *
 * 所有使用框架 IPC 通道的子进程均通过此类声明自身身份。
 * - role：进程角色标识（如 'worker', 'dispatcher', 'my_queue'）
 * - processKind：归属类型，使用 ProcessKind::FRAMEWORK / ProcessKind::MODULE
 * - moduleCode：模块代码（仅模块类进程需要，如 'Weline_Payment'）
 */
final class ProcessIdentity
{
    public function __construct(
        public readonly string $role,
        public readonly int    $pid,
        public readonly int    $port       = 0,
        public readonly int    $workerId   = 0,
        public readonly int    $epoch      = 0,
        public readonly string $launchId   = '',
        public readonly string $processKind = ProcessKind::FRAMEWORK,
        public readonly string $moduleCode  = '',
    ) {}

    public function isFrameworkProcess(): bool
    {
        return $this->processKind === ProcessKind::FRAMEWORK;
    }

    public function isModuleProcess(): bool
    {
        return $this->processKind === ProcessKind::MODULE;
    }

    public function getDisplayTag(): string
    {
        $tag = $this->role;
        if ($this->workerId > 0) {
            $tag .= '#' . $this->workerId;
        }
        if ($this->port > 0) {
            $tag .= ':' . $this->port;
        }
        return $tag;
    }
}
