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
use Weline\Server\Service\Runtime\DirectSharedListener;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

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

        $protocolEdgeEnabled = $context->isProtocolEdgeEnabled();
        $script = $context->sslEnabled && !$protocolEdgeEnabled
            ? $this->resolveSslWorkerScript($scriptDir, $context)
            : $scriptDir . DS . 'worker.php';

        $port = $this->getPort($instanceId, $context);
        $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName, $instanceId);

        // 安全：Dispatcher 模式下 Worker 仅监听 127.0.0.1，不暴露内网端口
        // 仅主端口（-p 指定或默认 80/443）通过 Dispatcher/Redirect 对外，Worker 端口只供本机 Dispatcher 连接
        $direct = $context->isDirect();
        $directListenerMode = $this->resolveDirectListenerMode($context);
        $host = $direct && !$protocolEdgeEnabled
            ? ($context->host ?: '127.0.0.1')
            : '127.0.0.1';

        $arguments = [
            $host,
            (string) $port,
            (string) $instanceId,
            $context->instanceName,
        ];

        if ($context->sslEnabled && !$protocolEdgeEnabled && $context->sslCert && $context->sslKey) {
            $arguments[] = '--ssl-cert=' . $context->sslCert;
            $arguments[] = '--ssl-key=' . $context->sslKey;
        }

        if ($protocolEdgeEnabled) {
            $arguments[] = '--protocol-edge-token-file=' . ProtocolEdgeRuntime::ensureTokenFile($context->instanceName);
        }

        $arguments[] = '--control-port=' . $context->controlPort;
        $arguments[] = '--master-pid=' . $context->masterPid;
        $arguments[] = '--memory-limit=' . $context->getWorkerMemoryLimit();
        $arguments[] = '--worker-count=' . $this->getInstanceCount($context);
        $topology = $context->getEffectiveTopology()->value;
        $arguments[] = '--wls-dispatcher-enabled=' . ($context->isDispatcherEnabled() ? '1' : '0');
        $arguments[] = '--wls-runtime-topology=' . $topology;
        // READY 首页预热使用启动时固化的对外 origin，避免 Windows 替换 instance.json 时的短暂空窗。
        // 保持为离散 argv，不使用 environment，以便 Windows 继续走快速批量启动路径。
        $arguments[] = '--public-origin=' . $this->buildPublicOrigin($context);

        if ($context->windowMode) {
            $arguments[] = '--win';
        }

        if ($direct && !$protocolEdgeEnabled && $directListenerMode === 'shared_fd') {
            $arguments[] = '--listen-fd=' . DirectSharedListener::INHERITED_FD;
        } elseif ($direct && !$protocolEdgeEnabled && $directListenerMode === 'reuseport') {
            $arguments[] = '--reuseport';
        } elseif ($direct && !$protocolEdgeEnabled) {
            throw new \InvalidArgumentException(
                'Direct topology requires wls.runtime.listener_mode=reuseport or shared_fd.'
            );
        }

        $arguments = \array_merge($arguments, $this->buildSharedStateArguments($context));

        $loopDriver = (string) $context->getConfig('wls.loop.driver', 'auto');
        $loopDriver = \strtolower(\trim($loopDriver));
        if ($loopDriver === '') {
            $loopDriver = 'auto';
        }
        $arguments[] = '--wls-loop-driver=' . $loopDriver;

        // 延迟 SSL 统一用 tcp:// 接入，accept 后按首包做 HTTP->HTTPS 跳转或 SNI 证书选择。
        // 这同时覆盖 Dispatcher 透传和 direct SO_REUSEPORT 模式。
        if ($context->sslEnabled && !$protocolEdgeEnabled) {
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
        if ($context->isProtocolEdgeEnabled()) {
            return ProtocolEdgeRuntime::workerPort($context, $instanceId);
        }
        if ($context->isDirect()) {
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
        if ($engine === 'event_buffer' && $context->isDirect()) {
            throw new \InvalidArgumentException(
                'wls.ssl.engine=event_buffer does not support direct mode. '
                . 'Use wls.ssl.engine=stream for direct mode.'
            );
        }
        if ($engine === 'event_buffer' && $context->isDispatcherEnabled()) {
            throw new \InvalidArgumentException(
                'wls.ssl.engine=event_buffer cannot consume the authenticated PROXY v2 preface before TLS. '
                . 'Use wls.ssl.engine=stream; Dispatcher startup will not silently corrupt the TLS stream.'
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

    private function resolveDirectListenerMode(ServiceContext $context): string
    {
        if (!$context->isDirect()) {
            return 'single';
        }
        $mode = \strtolower(\trim((string)$context->getConfig('wls.runtime.listener_mode', '')));
        if ($mode === '') {
            // Read compatibility for an instance created before listener_mode
            // became part of RuntimeSelection. New starts always set it.
            $mode = PHP_OS_FAMILY === 'Darwin' ? 'shared_fd' : 'reuseport';
        }

        return $mode;
    }

    private function buildPublicOrigin(ServiceContext $context): string
    {
        $scheme = $context->sslEnabled ? 'https' : 'http';
        $rawHost = \trim((string)($context->publicHost ?: $context->host ?: '127.0.0.1'));
        $rawIpv6 = \trim($rawHost, '[]');
        if (!\str_contains($rawHost, '://')
            && \filter_var($rawIpv6, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)
        ) {
            $parts = ['host' => $rawIpv6];
        } else {
            $candidate = \str_contains($rawHost, '://') ? $rawHost : $scheme . '://' . $rawHost;
            try {
                $parts = \parse_url($candidate);
            } catch (\ValueError) {
                $parts = [];
            }
        }
        if (!\is_array($parts)) {
            $parts = [];
        }

        $host = \trim((string)($parts['host'] ?? ''));
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $authority = \filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)
            ? '[' . \trim($host, '[]') . ']'
            : $host;
        $port = isset($parts['port'])
            ? (int)$parts['port']
            : $context->mainPort;
        $defaultPort = $context->sslEnabled ? 443 : 80;
        if ($port > 0 && $port <= 65535 && $port !== $defaultPort) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority;
    }
}
