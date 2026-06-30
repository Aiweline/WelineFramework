<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\SharedStateRuntimeOptions;
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
            ? $this->resolveSslWorkerScript($scriptDir, $context)
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
        $arguments[] = '--memory-limit=' . $context->getWorkerMemoryLimit();
        $arguments[] = '--worker-count=' . $this->getInstanceCount($context);

        if ($context->windowMode) {
            $arguments[] = '--win';
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

        // 延迟 SSL 统一用 tcp:// 接入，accept 后按首包做 HTTP->HTTPS 跳转或 SNI 证书选择。
        // 这同时覆盖 Dispatcher 透传和 linux-direct SO_REUSEPORT 模式。
        if ($context->sslEnabled) {
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
        $runtime = SharedStateRuntimeOptions::fromCliArgs([], $context->instanceName, $context->envConfig);
        $session = $runtime->getSession();
        $memory = $runtime->getMemory();

        $sessionHost = \trim((string) ($session['host'] ?? '127.0.0.1'));
        if ($sessionHost === '') {
            $sessionHost = '127.0.0.1';
        }
        $sessionPort = (int) ($session['port'] ?? (19970 + MasterProcess::getProjectPortOffset()));
        if ($sessionPort <= 0) {
            $sessionPort = 19970 + MasterProcess::getProjectPortOffset();
        }
        $sessionTokenFileName = \trim((string) ($session['token_file_name'] ?? 'session_server.token'));
        if ($sessionTokenFileName === '') {
            $sessionTokenFileName = 'session_server.token';
        }

        $memoryHost = \trim((string) ($memory['host'] ?? '127.0.0.1'));
        if ($memoryHost === '') {
            $memoryHost = '127.0.0.1';
        }
        $memoryPort = (int) ($memory['port'] ?? (19971 + MasterProcess::getProjectPortOffset()));
        if ($memoryPort <= 0) {
            $memoryPort = 19971 + MasterProcess::getProjectPortOffset();
        }
        $memoryTokenFileName = \trim((string) ($memory['token_file_name'] ?? 'memory_server.token'));
        if ($memoryTokenFileName === '') {
            $memoryTokenFileName = 'memory_server.token';
        }

        return [
            '--session-host=' . $sessionHost,
            '--session-port=' . $sessionPort,
            '--session-token-file-name=' . $sessionTokenFileName,
            '--memory-host=' . $memoryHost,
            '--memory-port=' . $memoryPort,
            '--memory-token-file-name=' . $memoryTokenFileName,
        ];
    }

    private function resolveSslWorkerScript(string $scriptDir, ServiceContext $context): string
    {
        $engine = \strtolower(\trim((string)$context->getConfig('wls.ssl.engine', 'stream')));
        if ($engine === '') {
            $engine = 'stream';
        }
        if ($engine === 'event_buffer' && PHP_OS_FAMILY === 'Windows') {
            throw new \InvalidArgumentException(
                'wls.ssl.engine=event_buffer is not supported on native Windows: '
                . 'PHP event SSL bufferevent server exits during TLS accept. Use stream or external TLS termination.'
            );
        }

        return match ($engine) {
            'stream' => $scriptDir . DS . 'worker_ssl.php',
            'event_buffer' => $scriptDir . DS . 'worker_ssl_event.php',
            default => throw new \InvalidArgumentException(
                'Unsupported WLS SSL engine "' . $engine . '"; expected stream or event_buffer'
            ),
        };
    }
}
