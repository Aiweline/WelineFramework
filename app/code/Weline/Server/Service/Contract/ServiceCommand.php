<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\LongRunningPhpRuntime;

/**
 * 服务启动命令
 */
class ServiceCommand
{
    public function __construct(
        public readonly string $script,
        public readonly array $arguments = [],
        public readonly array $environment = [],
        public readonly ?string $workingDir = null,
        public readonly ?string $processName = null,
        /**
         * 进程归属类型：'framework' | 'module'。
         * 模块自定义进程须设置为 'module' 并提供 moduleCode。
         */
        public readonly string $processKind = ControlMessage::PROCESS_KIND_FRAMEWORK,
        /**
         * 模块代码（仅 module 类进程有效，格式如 'Weline_Payment'）。
         * 子进程在 register 时会携带此信息，便于 Master 区分进程来源。
         */
        public readonly string $moduleCode = '',
    ) {}

    /**
     * 构建完整命令字符串
     */
    public function build(): string
    {
        $php = PHP_BINARY;
        $script = $this->getAbsoluteScript();
        $phpArguments = \implode(' ', \array_map('escapeshellarg', LongRunningPhpRuntime::startupCliArguments()));
        $args = \implode(' ', \array_map('escapeshellarg', $this->arguments));

        $cmd = "\"{$php}\"";
        if ($phpArguments !== '') {
            $cmd .= ' ' . $phpArguments;
        }
        $cmd .= " \"{$script}\"";
        if ($args !== '') {
            $cmd .= ' ' . $args;
        }

        return $cmd;
    }

    /**
     * 获取脚本绝对路径
     */
    public function getAbsoluteScript(): string
    {
        if (\str_starts_with($this->script, '/') || \str_starts_with($this->script, '\\') || \preg_match('/^[A-Z]:/i', $this->script)) {
            return $this->script;
        }
        return BP . $this->script;
    }

    /**
     * 获取环境变量数组
     */
    public function getEnvironment(): array
    {
        return \array_merge($_ENV, $this->environment);
    }

    /**
     * 获取工作目录
     */
    public function getWorkingDir(): string
    {
        return $this->workingDir ?? BP;
    }

    /**
     * 获取进程名
     */
    public function getProcessName(): ?string
    {
        return $this->processName;
    }

    public function isModuleProcess(): bool
    {
        return $this->processKind === ControlMessage::PROCESS_KIND_MODULE;
    }
}
