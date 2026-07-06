<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

/**
 * 服务启动上下文
 *
 * 包含启动服务所需的全局信息。
 * 
 * 运行态字段（runtime fields）优先级高于 envConfig：
 * - dispatcherEnabled: 本次启动是否启用 Dispatcher（由 CLI 参数或智能决策）
 * - workerCount: 本次启动的 Worker 数量
 * - workerBasePort: Worker 基础端口
 * - workerPort: 首个 Worker 端口
 */
class ServiceContext
{
    public function __construct(
        public readonly string $instanceName,
        public readonly int $epoch,
        public readonly int $controlPort,
        public readonly int $masterPid,
        public readonly string $host,
        public readonly int $mainPort,
        public readonly bool $sslEnabled,
        public readonly string $sslCert,
        public readonly string $sslKey,
        public readonly string $mode,
        public readonly bool $daemon,
        public readonly bool $debug,
        /** Windows 子进程可见窗口等（原 frontend 语义中的「窗口」部分） */
        public readonly bool $windowMode,
        public readonly array $envConfig,
        public readonly int $httpRedirectPort = 0,
        // 运行态字段：由 Start.php 计算后传入，优先级高于 envConfig
        public readonly ?bool $dispatcherEnabled = null,
        public readonly int|string|null $workerCount = null,
        public readonly ?int $workerBasePort = null,
        public readonly ?int $workerPort = null,
        /** 浏览器/对外展示的访问主机名（可与实际 bind 的 host 不同，例如 bind 127.0.0.1 而展示 *.weline.test） */
        public readonly ?string $publicHost = null,
        public readonly string $controlToken = '',
        public readonly string $masterLeaseFile = '',
        public readonly string $masterToken = '',
    ) {}

    /**
     * 生成新代际上下文
     */
    public function withEpoch(int $epoch): self
    {
        return new self(
            instanceName: $this->instanceName,
            epoch: $epoch,
            controlPort: $this->controlPort,
            masterPid: $this->masterPid,
            host: $this->host,
            mainPort: $this->mainPort,
            sslEnabled: $this->sslEnabled,
            sslCert: $this->sslCert,
            sslKey: $this->sslKey,
            mode: $this->mode,
            daemon: $this->daemon,
            debug: $this->debug,
            controlToken: $this->controlToken,
            windowMode: $this->windowMode,
            envConfig: $this->envConfig,
            httpRedirectPort: $this->httpRedirectPort,
            dispatcherEnabled: $this->dispatcherEnabled,
            workerCount: $this->workerCount,
            workerBasePort: $this->workerBasePort,
            workerPort: $this->workerPort,
            publicHost: $this->publicHost,
            masterLeaseFile: $this->masterLeaseFile,
            masterToken: $this->masterToken,
        );
    }

    public function withControlPort(int $controlPort): self
    {
        return new self(
            instanceName: $this->instanceName,
            epoch: $this->epoch,
            controlPort: $controlPort,
            masterPid: $this->masterPid,
            host: $this->host,
            mainPort: $this->mainPort,
            sslEnabled: $this->sslEnabled,
            sslCert: $this->sslCert,
            sslKey: $this->sslKey,
            mode: $this->mode,
            daemon: $this->daemon,
            debug: $this->debug,
            controlToken: $this->controlToken,
            windowMode: $this->windowMode,
            envConfig: $this->envConfig,
            httpRedirectPort: $this->httpRedirectPort,
            dispatcherEnabled: $this->dispatcherEnabled,
            workerCount: $this->workerCount,
            workerBasePort: $this->workerBasePort,
            workerPort: $this->workerPort,
            publicHost: $this->publicHost,
            masterLeaseFile: $this->masterLeaseFile,
            masterToken: $this->masterToken,
        );
    }

    /**
     * 获取指定配置路径的值
     */
    public function getConfig(string $path, mixed $default = null): mixed
    {
        $keys = \explode('.', $path);
        $value = $this->envConfig;
        foreach ($keys as $key) {
            if (!\is_array($value) || !isset($value[$key])) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * 判断是否启用 Dispatcher
     * 
     * 优先级：运行态字段 > envConfig
     */
    public function isDispatcherEnabled(): bool
    {
        // 运行态字段优先
        if ($this->dispatcherEnabled !== null) {
            return $this->dispatcherEnabled;
        }
        // 回退到 envConfig（仅显式禁用时为 false）
        $wls = $this->envConfig['wls'] ?? [];
        $envValue = \is_array($wls) ? ($wls['dispatcher_enabled'] ?? null) : null;
        return $envValue !== false;
    }

    /**
     * 获取 Worker 基础端口
     *
     * 优先级：运行态字段 > envConfig
     */
    public function getWorkerBasePort(): int
    {
        // 运行态字段优先
        if ($this->workerBasePort !== null) {
            return $this->workerBasePort;
        }
        // 默认端口 10000 + 项目偏移量，确保多项目不冲突
        $defaultPort = 10000 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
        return (int) (($this->envConfig['wls'] ?? [])['worker_base_port'] ?? $defaultPort);
    }

    /**
     * 获取 Worker 数量
     * 
     * 优先级：运行态字段 > envConfig
     */
    public function getWorkerCount(): int|string
    {
        // 运行态字段优先
        if ($this->workerCount !== null) {
            return $this->workerCount;
        }
        return ($this->envConfig['wls'] ?? [])['worker_count'] ?? 'auto';
    }

    public function getWorkerMemoryLimit(): string
    {
        return self::normalizeMemoryLimit($this->getConfig('wls.worker_memory_limit', '256M'));
    }

    public function getDispatcherMemoryLimit(): string
    {
        return self::normalizeMemoryLimit(
            $this->getConfig('wls.dispatcher_memory_limit', $this->getWorkerMemoryLimit()),
            $this->getWorkerMemoryLimit()
        );
    }

    public static function normalizeMemoryLimit(mixed $value, string $default = '256M'): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) (int) $value;
        }

        $value = \strtoupper(\trim((string) $value));
        $default = \strtoupper(\trim($default)) ?: '256M';

        if ($value === '') {
            return $default;
        }
        if ($value === '-1') {
            return '-1';
        }
        if (\preg_match('/^[1-9]\d*$/', $value)) {
            return $value . 'M';
        }
        if (\preg_match('/^[1-9]\d*(?:K|M|G)$/', $value)) {
            return $value;
        }

        return $default;
    }

    /**
     * 获取首个 Worker 端口
     * 
     * 优先级：运行态字段 > 基于模式计算
     */
    public function getWorkerPort(): int
    {
        // 运行态字段优先
        if ($this->workerPort !== null) {
            return $this->workerPort;
        }
        // 直连模式：Worker 直接监听主端口
        if ($this->mode === 'linux-direct') {
            return $this->mainPort;
        }
        // Dispatcher 模式或独立端口模式：基于 workerBasePort 计算
        return $this->getWorkerBasePort() + $this->mainPort;
    }
}
