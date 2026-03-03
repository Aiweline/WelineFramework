<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

/**
 * 服务启动上下文
 *
 * 包含启动服务所需的全局信息
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
        public readonly bool $frontend,
        public readonly array $envConfig,
        public readonly int $httpRedirectPort = 0,
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
            frontend: $this->frontend,
            envConfig: $this->envConfig,
            httpRedirectPort: $this->httpRedirectPort,
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
     * 获取 Worker 基础端口
     */
    public function getWorkerBasePort(): int
    {
        return (int) ($this->envConfig['server']['worker_base_port'] ?? 10443);
    }

    /**
     * 获取 Worker 数量
     */
    public function getWorkerCount(): int|string
    {
        return $this->envConfig['server']['worker_count'] ?? 'auto';
    }
}
