<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

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
    ) {}

    /**
     * 构建完整命令字符串
     */
    public function build(): string
    {
        $php = PHP_BINARY;
        $script = $this->getAbsoluteScript();
        $args = \implode(' ', \array_map('escapeshellarg', $this->arguments));

        $cmd = "\"{$php}\" \"{$script}\"";
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
}
