<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\SharedStateRuntimeResolver;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\HealthCheckResult;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * Worker 服务提供者
 *
 * HTTP Worker 进程，处理实际的 HTTP 请求。
 *
 * 优先级：20（在 Session Server 之后启动）
 */
class WorkerProvider extends AbstractServiceProvider
{
    public const PROCESS_NAME_PREFIX = 'weline-wls-worker';

    public function getRole(): string
    {
        return 'worker';
    }

    public function getDisplayName(): string
    {
        return 'HTTP Worker';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        return true;
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        $count = $context->getWorkerCount();
        if ($count === 'auto') {
            return $this->getAutoCpuCount();
        }
        return (int) $count;
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getResurrectionPriority(): int
    {
        return 2;
    }

    public function getReloadStrategy(): string
    {
        return 'graceful';
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $scriptDir = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin';

        $script = $context->sslEnabled
            ? $scriptDir . DS . 'worker_ssl.php'
            : $scriptDir . DS . 'worker.php';

        $port = $this->getPort($instanceId, $context);
        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName, $instanceId);

        // 安全：Dispatcher 模式下 Worker 仅监听 127.0.0.1，不暴露内网端口
        // 仅主端口（-p 指定或默认 80/443）通过 Dispatcher/Redirect 对外，Worker 端口只供本机 Dispatcher 连接
        $mode = $context->mode;
        $host = ($mode === 'linux-direct')
            ? ($context->host ?: '127.0.0.1')
            : '127.0.0.1';

        $arguments = [
            $host,
            (string) $port,
            (string) $instanceId,
            $context->instanceName,
        ];

        if ($context->sslEnabled && $context->sslCert && $context->sslKey) {
            $arguments[] = '--ssl-cert=' . $context->sslCert;
            $arguments[] = '--ssl-key=' . $context->sslKey;
        }

        $arguments[] = '--control-port=' . $context->controlPort;
        $arguments[] = '--master-pid=' . $context->masterPid;

        if ($context->frontend) {
            $arguments[] = '--frontend';
        }

        if ($mode === 'linux-direct') {
            $arguments[] = '--reuseport';
        }

        $arguments = \array_merge($arguments, $this->buildSharedStateArguments($context));

        $loopDriver = (string) $context->getConfig('wls.loop.driver', 'auto');
        $loopDriver = \strtolower(\trim($loopDriver));
        if ($loopDriver === '') {
            $loopDriver = 'auto';
        }
        $arguments[] = '--wls-loop-driver=' . $loopDriver;

        // Dispatcher 模式（TCP 透传）下启用延迟 SSL
        // Worker 先 tcp:// 接入，accept 后根据首包判断协议并手动启用 SSL
        // 解决 ssl:// 非阻塞模式下 stream_socket_accept 无法完成 TLS 握手的问题。
        if ($context->sslEnabled && $mode !== 'linux-direct') {
            $arguments[] = '--defer-ssl';
        }

        return new ServiceCommand(
            script: $script,
            arguments: $arguments,
            processName: $processName,
        );
    }

    public function getPort(int $instanceId, ServiceContext $context): ?int
    {
        $basePort = $context->getWorkerBasePort();
        $mode = $context->mode;

        if ($mode === 'linux-direct') {
            return $context->mainPort;
        }

        // 零停机滚动重启：临时 Worker (ID > 100) 使用动态端口避免冲突
        // 例如：Worker 1 使用 9501，临时 Worker 101 使用 9601
        if ($instanceId > 100) {
            return $basePort + $instanceId;
        }

        return $basePort + $instanceId;
    }

    public function healthCheck(ServiceInstance $instance): HealthCheckResult
    {
        $result = parent::healthCheck($instance);
        if (!$result->isHealthy()) {
            return $result;
        }

        if ($instance->state !== ServiceInstance::STATE_READY) {
            return HealthCheckResult::degraded('Worker not ready, state: ' . $instance->state);
        }

        return HealthCheckResult::healthy();
    }

    public function handleMessage(array $message, ServiceInstance $instance, ServiceOrchestrator $orchestrator): bool
    {
        $type = $message['type'] ?? '';

        switch ($type) {
            case 'status_report':
                $instance->setMeta('last_status_report', $message);
                $orchestrator->getRegistry()->updateInstance($instance);
                return true;
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function buildSharedStateArguments(ServiceContext $context): array
    {
        $session = $this->resolveSessionRuntime($context);
        $memory = $this->resolveMemoryRuntime($context);

        return [
            '--session-host=' . $session['host'],
            '--session-port=' . $session['port'],
            '--session-token-file-name=' . $session['token_file_name'],
            '--memory-host=' . $memory['host'],
            '--memory-port=' . $memory['port'],
            '--memory-token-file-name=' . $memory['token_file_name'],
        ];
    }

    /**
     * @return array{host: string, port: int, token_file_name: string}
     */
    private function resolveSessionRuntime(ServiceContext $context): array
    {
        $runtime = (new SharedStateRuntimeResolver())->resolve($context->envConfig, $context->envConfig, $context->instanceName);
        $session = \is_array($runtime['session'] ?? null) ? $runtime['session'] : [];
        $host = \trim((string) ($session['host'] ?? '127.0.0.1'));
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $defaultPort = 19970 + MasterProcess::getProjectPortOffset();
        $port = (int) ($session['port'] ?? $defaultPort);
        $tokenFileName = \trim((string) ($session['token_file_name'] ?? 'session_server.token'));

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : $defaultPort,
            'token_file_name' => $tokenFileName !== '' ? $tokenFileName : 'session_server.token',
        ];
    }

    /**
     * @return array{host: string, port: int, token_file_name: string}
     */
    private function resolveMemoryRuntime(ServiceContext $context): array
    {
        $runtime = (new SharedStateRuntimeResolver())->resolve($context->envConfig, $context->envConfig, $context->instanceName);
        $memory = \is_array($runtime['memory'] ?? null) ? $runtime['memory'] : [];
        $host = \trim((string) ($memory['host'] ?? '127.0.0.1'));
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
        $port = (int) ($memory['port'] ?? $defaultPort);
        $tokenFileName = \trim((string) ($memory['token_file_name'] ?? 'memory_server.token'));

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : $defaultPort,
            'token_file_name' => $tokenFileName !== '' ? $tokenFileName : 'memory_server.token',
        ];
    }
}
