<?php
declare(strict_types=1);

/**
 * Weline Server 配置值对象
 * 
 * 封装服务器启动所需的所有配置参数。
 * 使用值对象模式确保配置不可变且类型安全。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Strategy;

use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\WlsLogService;

/**
 * 服务器配置值对象
 * 
 * 不可变对象，所有属性通过构造函数设置
 */
final class ServerConfig
{
    /**
     * 实例名称
     */
    public readonly string $instanceName;
    
    /**
     * 监听主机地址
     */
    public readonly string $host;
    
    /**
     * 主监听端口（对外端口，如 443）
     */
    public readonly int $port;
    
    /**
     * Worker 数量
     */
    public readonly int $workerCount;
    
    /**
     * Worker 基础端口（用于 Dispatcher 模式，Worker 从此端口开始分配）
     */
    public readonly int $workerBasePort;

    public readonly string $workerMemoryLimit;

    public readonly string $dispatcherMemoryLimit;
    
    /**
     * SSL 证书路径
     */
    public readonly string $sslCert;
    
    /**
     * SSL 私钥路径
     */
    public readonly string $sslKey;
    
    /**
     * 是否启用 SSL
     */
    public readonly bool $sslEnabled;
    
    /**
     * 是否前台运行
     */
    public readonly bool $frontend;
    
    /**
     * HTTP 重定向端口（HTTP → HTTPS）
     */
    public readonly int $httpRedirectPort;
    
    /**
     * 是否启用 HTTP 重定向
     */
    public readonly bool $httpRedirectEnabled;
    
    /**
     * PHP 二进制路径
     */
    public readonly string $phpBinary;
    
    /**
     * 日志目录
     */
    public readonly string $logDir;
    
    /**
     * 服务器脚本目录
     */
    public readonly string $binDir;
    
    /**
     * 构造函数
     * 
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->instanceName = $config['instance_name'] ?? 'default';
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = (int) ($config['port'] ?? 443);
        $this->workerCount = (int) ($config['worker_count'] ?? $this->detectOptimalWorkerCount());
        // 默认端口 10000 + 项目偏移量，确保多项目不冲突
        $defaultWorkerBasePort = 10000 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
        $this->workerBasePort = (int) ($config['worker_base_port'] ?? $defaultWorkerBasePort);
        $this->workerMemoryLimit = ServiceContext::normalizeMemoryLimit($config['worker_memory_limit'] ?? '256M');
        $this->dispatcherMemoryLimit = ServiceContext::normalizeMemoryLimit(
            $config['dispatcher_memory_limit'] ?? $this->workerMemoryLimit,
            $this->workerMemoryLimit
        );
        $this->sslCert = $config['ssl_cert'] ?? '';
        $this->sslKey = $config['ssl_key'] ?? '';
        $this->sslEnabled = !empty($this->sslCert) && !empty($this->sslKey);
        $this->frontend = (bool) ($config['frontend'] ?? false);
        $portVal = (int) ($config['port'] ?? 443);
        if ($this->sslEnabled) {
            $this->httpRedirectPort = ($portVal === 443) ? 80 : 0;
        } else {
            $this->httpRedirectPort = 0;
        }
        $this->httpRedirectEnabled = $this->sslEnabled && $this->httpRedirectPort > 0;
        $this->phpBinary = $config['php_binary'] ?? PHP_BINARY;
        $this->logDir = $config['log_dir'] ?? $this->getDefaultLogDir();
        $this->binDir = $config['bin_dir'] ?? $this->getDefaultBinDir();
    }
    
    /**
     * 检测最优 Worker 数量
     * 
     * @return int Worker 数量
     */
    private function detectOptimalWorkerCount(): int
    {
        // 默认使用 CPU 核心数，最少 2 个
        $cpuCount = 1;
        
        if (\function_exists('swoole_cpu_num')) {
            $cpuCount = \swoole_cpu_num();
        } elseif (\is_file('/proc/cpuinfo')) {
            $content = \file_get_contents('/proc/cpuinfo');
            $cpuCount = \substr_count($content, 'processor');
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $cpuCount = (int) \getenv('NUMBER_OF_PROCESSORS') ?: 1;
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $output = [];
            @\exec('sysctl -n hw.ncpu', $output);
            $cpuCount = (int) ($output[0] ?? 1);
        }
        
        return \max(2, $cpuCount);
    }
    
    /**
     * 获取默认日志目录
     * 
     * @return string
     */
    private function getDefaultLogDir(): string
    {
        return WlsLogService::getLogDir($this->instanceName);
    }
    
    /**
     * 获取默认脚本目录
     * 
     * @return string
     */
    private function getDefaultBinDir(): string
    {
        return \dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
    }

    /**
     * 获取 Worker 端口列表
     * 
     * @return int[]
     */
    public function getWorkerPorts(): array
    {
        $ports = [];
        for ($i = 0; $i < $this->workerCount; $i++) {
            $ports[] = $this->workerBasePort + $i;
        }
        return $ports;
    }
    
    /**
     * 获取 Worker 脚本路径
     * 
     * @return string
     */
    public function getWorkerScript(): string
    {
        $script = $this->sslEnabled ? 'worker_ssl.php' : 'worker.php';
        return $this->binDir . $script;
    }
    
    /**
     * 获取 Dispatcher 脚本路径（透传模式）
     * 
     * @return string
     */
    public function getDispatcherScript(): string
    {
        return $this->binDir . 'dispatcher.php';
    }
    
    /**
     * 获取 HTTP 重定向 Worker 脚本路径
     * 
     * @return string
     */
    public function getHttpRedirectScript(): string
    {
        return $this->binDir . 'http_redirect_worker.php';
    }
    
    /**
     * 转换为数组
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'instance_name' => $this->instanceName,
            'host' => $this->host,
            'port' => $this->port,
            'worker_count' => $this->workerCount,
            'worker_base_port' => $this->workerBasePort,
            'worker_memory_limit' => $this->workerMemoryLimit,
            'dispatcher_memory_limit' => $this->dispatcherMemoryLimit,
            'ssl_cert' => $this->sslCert,
            'ssl_key' => $this->sslKey,
            'ssl_enabled' => $this->sslEnabled,
            'frontend' => $this->frontend,
            'http_redirect_port' => $this->httpRedirectPort,
            'http_redirect_enabled' => $this->httpRedirectEnabled,
            'php_binary' => $this->phpBinary,
            'log_dir' => $this->logDir,
            'bin_dir' => $this->binDir,
        ];
    }
    
    /**
     * 创建配置的副本并修改部分属性
     * 
     * @param array $overrides 要覆盖的属性
     * @return self
     */
    public function with(array $overrides): self
    {
        return new self(\array_merge($this->toArray(), $overrides));
    }
}
