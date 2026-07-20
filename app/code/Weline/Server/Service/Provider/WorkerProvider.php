<?php
declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\HealthCheckResult;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\Runtime\DirectSharedListener;

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
        $direct = $context->isDirect();
        $listenerMode = $context->runtimeSelection->listenerMode;
        $host = $direct
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
            if ($direct && (bool)$context->getConfig('wls.http3.enabled', false)) {
                $nativeDigest = \strtolower(\trim((string)$context->getConfig('wls.http3.native_digest', '')));
                $nativeFingerprint = \strtolower(\trim((string)$context->getConfig(
                    'wls.http3.fingerprint',
                    '',
                )));
                if (\preg_match('/^[a-f0-9]{64}$/D', $nativeDigest) !== 1
                    || \preg_match('/^[a-f0-9]{32}$/D', $nativeFingerprint) !== 1
                ) {
                    throw new \RuntimeException('Direct HTTP/3 Worker requires a verified native fingerprint and digest.');
                }
                $ticketRingEpoch = (int)$context->getConfig('wls.http3.tls_ticket_ring_epoch', 0);
                $ticketRingDigest = \strtolower(\trim((string)$context->getConfig(
                    'wls.http3.tls_ticket_ring_digest',
                    '',
                )));
                if ($ticketRingEpoch <= 0
                    || \preg_match('/^[a-f0-9]{64}$/D', $ticketRingDigest) !== 1
                ) {
                    throw new \RuntimeException('Direct HTTP/3 Worker requires published TLS ticket-ring metadata.');
                }
                $http3Mode = match (\PHP_OS_FAMILY) {
                    'Darwin' => 'datagram-router',
                    'Linux' => 'reuseport-ebpf',
                    default => throw new \RuntimeException(
                        'Native HTTP/3 is unsupported on the selected platform data plane.'
                    ),
                };
                $arguments[] = '--wls-http3=1';
                $arguments[] = '--wls-http3-mode=' . $http3Mode;
                $arguments[] = '--wls-http3-native-fingerprint=' . $nativeFingerprint;
                $arguments[] = '--wls-http3-native-digest=' . $nativeDigest;
                $arguments[] = '--wls-http3-ticket-ring-epoch=' . $ticketRingEpoch;
                $arguments[] = '--wls-http3-ticket-ring-digest=' . $ticketRingDigest;
            }
        }

        $arguments[] = '--control-port=' . $context->controlPort;
        $arguments[] = '--master-pid=' . $context->masterPid;
        $arguments[] = '--memory-limit=' . $context->getWorkerMemoryLimit();
        $arguments[] = '--worker-count=' . $this->getInstanceCount($context);
        $arguments[] = '--wls-runtime-topology='
            . $context->runtimeSelection->effectiveTopology->value;
        $arguments[] = '--wls-listener-mode=' . $listenerMode;
        // READY 首页预热使用启动时固化的对外 origin，避免 Windows 替换 instance.json 时的短暂空窗。
        // 保持为离散 argv，不使用 environment，以便 Windows 继续走快速批量启动路径。
        $arguments[] = '--public-origin=' . WorkerRuntimeArgumentBuilder::publicOrigin($context);

        if ($direct && $listenerMode === 'shared_fd') {
            $arguments[] = '--listen-fd=' . DirectSharedListener::INHERITED_FD;
        } elseif ($direct && $listenerMode !== 'reuseport') {
            throw new \InvalidArgumentException(
                'Direct topology requires listener mode reuseport or shared_fd.'
            );
        }

        $arguments = \array_merge($arguments, WorkerRuntimeArgumentBuilder::sharedState($context));

        $arguments[] = '--wls-loop-driver=' . $context->runtimeSelection->eventLoopDriver;

        // 延迟 SSL 统一用 tcp:// 接入，accept 后按首包做 HTTP->HTTPS 跳转或 SNI 证书选择。
        // 这同时覆盖 Dispatcher 透传和 direct SO_REUSEPORT 模式。
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

    private function resolveSslWorkerScript(string $scriptDir, ServiceContext $context): string
    {
        $engine = $context->runtimeSelection->sslEngine;
        if ($engine === 'event_buffer' && PHP_OS_FAMILY === 'Windows') {
            throw new \InvalidArgumentException('wls.ssl.engine=event_buffer is disabled on native Windows because its live TLS accept path has no passing runtime self-test; use stream.');
        }
        if ($engine === 'event_buffer' && $context->runtimeSelection->isDirect()) {
            throw new \InvalidArgumentException('wls.ssl.engine=event_buffer does not support direct mode; use stream.');
        }
        if ($engine === 'event_buffer' && $context->runtimeSelection->isDispatcher()) {
            throw new \InvalidArgumentException('wls.ssl.engine=event_buffer cannot consume the authenticated PROXY v2 preface; use stream.');
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
