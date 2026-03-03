<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Framework\App\Env;
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
        $processName = self::PROCESS_NAME_PREFIX . '-' . $context->instanceName . '-' . $instanceId;

        $host = $context->host ?: '127.0.0.1';

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

        $mode = $context->mode;
        if ($mode === 'linux-direct') {
            $arguments[] = '--reuseport';
        }

        // Dispatcher 模式（TCP 透传）下启用延迟 SSL：
        // Worker 以 tcp:// 接入，accept 后根据首包判断协议并手动启用 SSL，
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
}
