<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Session\Storage\SharedSessionStateInterface;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\SessionStateFacadeInterface;
use Weline\Server\Session\Client\SessionClient;
use Weline\Server\Shared\Service\SharedMemoryService;

class SessionStateFacade implements SessionStateFacadeInterface, SharedSessionStateInterface
{
    private SharedStateServiceManager $manager;
    private SessionClient $sessionClient;
    private SessionMemoryService $sessionMemoryService;

    /**
     * @var array<string, mixed>
     */
    private array $runtime;

    private string $consumerCode;
    private bool $released = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config = [],
        ?SharedStateServiceManager $manager = null,
        ?SessionClient $sessionClient = null,
        ?SessionMemoryService $sessionMemoryService = null
    ) {
        $this->manager = $manager ?? new SharedStateServiceManager();
        $this->consumerCode = $this->resolveConsumerCode($config);

        // WLS 模式下默认启用直连，避免 Worker 启动时阻塞的 ensure/probe
        if (!isset($config['prefer_direct_connect']) && \defined('WLS_MODE') && WLS_MODE) {
            $config['prefer_direct_connect'] = true;
        }
        if (!isset($config['fail_fast_on_unhealthy']) && \defined('WLS_MODE') && WLS_MODE) {
            $config['fail_fast_on_unhealthy'] = true;
        }

        if ($this->attemptDirectBootstrap($config, $manager, $sessionClient, $sessionMemoryService)) {
            return;
        }

        $this->runtime = $this->manager->ensure(
            ControlMessage::ROLE_SESSION_SERVER,
            $config,
            [],
            $this->resolveManagerRequesterInstanceName(),
            SharedStateServiceManager::resolveEnsureFrontendFlag($config)
        );
        $this->runtime['consumer_code'] = $this->consumerCode;

        try {
            $serviceOptions = $this->buildServiceOptions($config, $this->runtime);
            $host = (string) ($this->runtime['host'] ?? '127.0.0.1');
            // 使用项目偏移量计算动态端口，避免硬编码
            $port = (int) ($this->runtime['port'] ?? 0);
            if ($port <= 0) {
                $port = 19970 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
            }

            if ($sessionMemoryService !== null) {
                $this->sessionMemoryService = $sessionMemoryService;
            } else {
                $sharedMemoryService = $this->createSharedMemoryService($host, $port, $serviceOptions);
                $this->sessionMemoryService = $this->createSessionMemoryService($sharedMemoryService);
            }

            $client = $sessionClient ?? $this->createSessionClient($host, $port, $serviceOptions);
            // 延迟到首个真实请求时再走连接池获取，避免构造阶段重复探测。
            $this->sessionClient = $client;
        } catch (\Throwable $throwable) {
            $this->cleanupInitializationFailure();

            throw $throwable;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function read(string $sessionId): array
    {
        return $this->traceOperation('wls.session.read', ['operation' => 'read'], fn(): array => $this->sessionMemoryService->read($sessionId));
    }

    public function write(string $sessionId, array $data, int $ttl): bool
    {
        return $this->traceOperation('wls.session.write', ['operation' => 'write'], fn(): bool => $this->sessionMemoryService->write($sessionId, $data, $ttl));
    }

    public function destroy(string $sessionId): bool
    {
        return $this->traceOperation('wls.session.destroy', ['operation' => 'destroy'], fn(): bool => $this->sessionMemoryService->destroy($sessionId));
    }

    public function exists(string $sessionId): bool
    {
        return $this->traceOperation('wls.session.exists', ['operation' => 'exists'], fn(): bool => $this->sessionMemoryService->exists($sessionId));
    }

    public function touch(string $sessionId, int $ttl): bool
    {
        return $this->traceOperation('wls.session.touch', ['operation' => 'touch'], fn(): bool => $this->sessionMemoryService->touch($sessionId, $ttl));
    }

    public function list(array $options = []): array
    {
        $filter = \is_array($options['filter'] ?? null) ? $options['filter'] : [];
        $limit = (int) ($options['limit'] ?? 50);

        return $this->traceOperation('wls.session.list', ['operation' => 'list'], fn(): array => $this->sessionClient->list($filter, $limit));
    }

    public function gc(int $maxLifetime): int
    {
        return $this->traceOperation('wls.session.gc', ['operation' => 'gc'], fn(): int => $this->sessionClient->gc($maxLifetime));
    }

    public function persist(): bool
    {
        return $this->traceOperation('wls.session.persist', ['operation' => 'persist'], fn(): bool => $this->sessionClient->persist());
    }

    public function ping(): bool
    {
        return $this->traceOperation('wls.session.ping', ['operation' => 'ping'], fn(): bool => $this->sessionClient->ping());
    }

    public function getStats(): array
    {
        return $this->traceOperation('wls.session.stats', ['operation' => 'stats'], fn(): array => $this->sessionClient->getStats());
    }

    public function getRuntime(): array
    {
        return $this->runtime;
    }

    public function disconnect(): void
    {
        if ($this->released) {
            return;
        }

        if (isset($this->sessionClient)) {
            $this->sessionClient->disconnect();
        }

        $this->released = true;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private function buildServiceOptions(array $config, array $runtime = []): array
    {
        return [
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 0.5),
            'timeout' => (float) ($config['timeout'] ?? 1.0),
            'pool_size' => (int) ($config['pool_size'] ?? 8),
            'pool_min_idle' => (int) ($config['pool_min_idle'] ?? 0),
            'acquire_timeout' => (float) ($config['acquire_timeout'] ?? 0.1),
            'idle_timeout' => (float) ($config['idle_timeout'] ?? 86400.0),
            'pool_health_ping_idle' => (bool) ($config['pool_health_ping_idle'] ?? false),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $this->resolveConfiguredRuntime($config)['token_file_name']),
            'service_type' => 'Session',
            'service_role' => ControlMessage::ROLE_SESSION_SERVER,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function createSharedMemoryService(string $host, int $port, array $options): SharedMemoryService
    {
        return new SharedMemoryService($host, $port, $options);
    }

    protected function createSessionMemoryService(SharedMemoryService $sharedMemoryService): SessionMemoryService
    {
        return new SessionMemoryService($sharedMemoryService);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function createSessionClient(string $host, int $port, array $options): SessionClient
    {
        return new SessionClient($host, $port, $options);
    }

    protected function connectDirectClient(SessionClient $client): bool
    {
        return $this->traceOperation('wls.session.connect', ['operation' => 'connect'], fn(): bool => $client->connect());
    }

    private function traceOperation(string $name, array $meta, callable $operation): mixed
    {
        $start = \class_exists(RequestLifecycleTrace::class, false) && RequestLifecycleTrace::isEnabled()
            ? \microtime(true)
            : 0.0;

        try {
            return $operation();
        } finally {
            if ($start > 0.0) {
                RequestLifecycleTrace::recordSpan(
                    $name,
                    (\microtime(true) - $start) * 1000,
                    'wls',
                    null,
                    $meta + $this->traceRuntimeMeta()
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function traceRuntimeMeta(): array
    {
        $runtime = isset($this->runtime) ? $this->runtime : [];

        return [
            'service' => 'session',
            'host' => (string)($runtime['host'] ?? ''),
            'port' => (int)($runtime['port'] ?? 0),
            'direct_connect' => (bool)($runtime['direct_connect'] ?? false),
        ];
    }

    private function cleanupInitializationFailure(): void
    {
        try {
            if (isset($this->sessionClient)) {
                $this->sessionClient->disconnect();
            }
        } catch (\Throwable) {
        }

        $this->released = true;
    }

    private function resolveManagerRequesterInstanceName(): string
    {
        return \defined('WLS_MODE') && WLS_MODE ? 'system' : $this->consumerCode;
    }

    private function attemptDirectBootstrap(
        array $config,
        ?SharedStateServiceManager $manager,
        ?SessionClient $sessionClient,
        ?SessionMemoryService $sessionMemoryService
    ): bool {
        if (!(bool) ($config['prefer_direct_connect'] ?? false)) {
            return false;
        }

        $allowDirectBootstrap = $manager === null
            || ($sessionClient !== null && $sessionMemoryService !== null);
        if (!$allowDirectBootstrap) {
            return false;
        }

        $runtime = $this->resolveConfiguredRuntime($config);
        // 兼容直连快捷配置：允许用通用键覆盖 session 端点。
        if (\array_key_exists('port', $config)) {
            $runtime['port'] = (int) $config['port'];
        }
        if (\array_key_exists('token_file_name', $config)) {
            $runtime['token_file_name'] = (string) $config['token_file_name'];
        }
        $serviceOptions = $this->buildServiceOptions($config, $runtime);
        $host = (string) $runtime['host'];
        $port = (int) $runtime['port'];

        try {
            if ($sessionMemoryService !== null) {
                $this->sessionMemoryService = $sessionMemoryService;
            } else {
                $sharedMemoryService = $this->createSharedMemoryService($host, $port, $serviceOptions);
                $this->sessionMemoryService = $this->createSessionMemoryService($sharedMemoryService);
            }

            $client = $sessionClient ?? $this->createSessionClient($host, $port, $serviceOptions);
            if (!$this->connectDirectClient($client)) {
                throw new \RuntimeException('Shared session facade is not healthy');
            }

            $this->runtime = [
                'host' => $host,
                'port' => $port,
                'token_file_name' => (string) $runtime['token_file_name'],
                'reuse_existing' => true,
                'direct_connect' => true,
            ];
            $this->sessionClient = $client;

            return true;
        } catch (\Throwable $throwable) {
            $this->cleanupDirectBootstrapState();

            if (!(bool) ($config['fail_fast_on_unhealthy'] ?? false)) {
                return false;
            }

            if ($throwable instanceof \RuntimeException) {
                throw $throwable;
            }

            throw new \RuntimeException('Shared session facade is not healthy', 0, $throwable);
        }
    }

    private function cleanupDirectBootstrapState(): void
    {
        try {
            if (isset($this->sessionClient)) {
                $this->sessionClient->disconnect();
            }
        } catch (\Throwable) {
        }

        if (isset($this->sessionClient)) {
            unset($this->sessionClient);
        }
        if (isset($this->sessionMemoryService)) {
            unset($this->sessionMemoryService);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveConsumerCode(array $config): string
    {
        $consumerCode = \trim((string) ($config['consumer_code'] ?? ''));
        if ($consumerCode !== '') {
            return $consumerCode;
        }

        foreach ([
            \getenv('WLS_INSTANCE'),
            \getenv('WLS_INSTANCE_NAME'),
            $_ENV['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
        ] as $candidate) {
            if (\is_string($candidate) && \trim($candidate) !== '') {
                return \trim($candidate);
            }
        }

        $pid = \getmypid();

        return 'cli:' . ($pid !== false ? (string) $pid : 'unknown');
    }

    /**
     * @param array<string, mixed> $config
     * @return array{host:string, port:int, token_file_name:string}
     */
    private function resolveConfiguredRuntime(array $config): array
    {
        // 直接从 env.php 读取配置，避免调用 resolve() 触发阻塞的 probe
        $envConfig = \Weline\Framework\App\Env::getInstance()->getConfig();
        if (!\is_array($envConfig)) {
            $envConfig = [];
        }

        $wls = $envConfig['wls'] ?? [];
        $sessionConfig = \is_array($wls['session'] ?? null) ? $wls['session'] : [];
        $wlsServer = \is_array($sessionConfig['wls_server'] ?? null) ? $sessionConfig['wls_server'] : [];

        $host = \trim((string) ($wlsServer['host'] ?? $sessionConfig['host'] ?? '127.0.0.1'));
        if ($host === '') {
            $host = '127.0.0.1';
        }

        $defaultPort = 19970 + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
        $port = (int) ($wlsServer['port'] ?? $sessionConfig['port'] ?? $defaultPort);
        $tokenFileName = \trim((string) ($wlsServer['token_file_name'] ?? $sessionConfig['token_file_name'] ?? 'session_server.token'));

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : $defaultPort,
            'token_file_name' => $tokenFileName !== '' ? $tokenFileName : 'session_server.token',
        ];
    }
}
