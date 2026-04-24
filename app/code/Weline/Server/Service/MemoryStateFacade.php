<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\MemoryStateFacadeInterface;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Service\SharedMemoryService;

class MemoryStateFacade implements MemoryStateFacadeInterface
{
    private SharedStateServiceManager $manager;
    private SharedMemoryService $sharedMemoryService;
    private CacheMemoryService $cacheMemoryService;
    private SharedStateClient $stateClient;

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
        ?SharedMemoryService $sharedMemoryService = null,
        ?CacheMemoryService $cacheMemoryService = null,
        ?SharedStateClient $stateClient = null
    ) {
        $this->manager = $manager ?? new SharedStateServiceManager();
        $this->consumerCode = $this->resolveConsumerCode($config);

        // WLS 模式下默认启用直连，避免 Worker 启动时阻塞的 ensure/probe
        if (!isset($config['prefer_direct_connect']) && \defined('WLS_MODE') && WLS_MODE) {
            $config['prefer_direct_connect'] = true;
        }

        if ($this->attemptDirectBootstrap($config, $manager, $sharedMemoryService, $cacheMemoryService, $stateClient)) {
            return;
        }
        $this->runtime = $this->manager->ensure(
            ControlMessage::ROLE_MEMORY_SERVER,
            $config,
            [],
            $this->consumerCode,
            SharedStateServiceManager::resolveEnsureFrontendFlag($config)
        );
        $this->runtime['consumer_code'] = $this->consumerCode;
        try {
            $serviceOptions = $this->buildServiceOptions($config);
            $host = (string) ($this->runtime['host'] ?? '127.0.0.1');
            // 默认端口 19971 + 项目偏移量，确保多项目不冲突
            $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
            $port = (int) ($this->runtime['port'] ?? $defaultPort);

            $this->sharedMemoryService = $sharedMemoryService ?? $this->createSharedMemoryService($host, $port, $serviceOptions);
            $this->cacheMemoryService = $cacheMemoryService ?? $this->createCacheMemoryService($this->sharedMemoryService);
            $this->stateClient = $stateClient ?? $this->createStateClient($host, $port, $serviceOptions);
        } catch (\Throwable $throwable) {
            $this->cleanupInitializationFailure();

            throw $throwable;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function get(string $ns, string $key): mixed
    {
        return $this->sharedMemoryService->get($ns, $key);
    }

    public function set(string $ns, string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->sharedMemoryService->set($ns, $key, $value, $ttl);
    }

    public function delete(string $ns, string $key): bool
    {
        return $this->sharedMemoryService->delete($ns, $key);
    }

    public function exists(string $ns, string $key): bool
    {
        return $this->sharedMemoryService->exists($ns, $key);
    }

    public function touch(string $ns, string $key, int $ttl): bool
    {
        return $this->sharedMemoryService->touch($ns, $key, $ttl);
    }

    public function mget(string $ns, array $keys): array
    {
        return $this->sharedMemoryService->mget($ns, $keys);
    }

    public function mset(string $ns, array $kv, int $ttl = 0): bool
    {
        return $this->sharedMemoryService->mset($ns, $kv, $ttl);
    }

    public function clearNamespace(string $ns): bool
    {
        return $this->sharedMemoryService->clearNamespace($ns);
    }

    public function incr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        return $this->sharedMemoryService->incr($ns, $key, $delta, $ttl);
    }

    public function decr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        return $this->sharedMemoryService->decr($ns, $key, $delta, $ttl);
    }

    public function append(string $ns, string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->sharedMemoryService->append($ns, $key, $value, $ttl);
    }

    public function cas(string $ns, string $key, mixed $expected, mixed $newValue, int $ttl = 0): bool
    {
        return $this->sharedMemoryService->cas($ns, $key, $expected, $newValue, $ttl);
    }

    public function list(array $options = []): array
    {
        $filter = \is_array($options['filter'] ?? null) ? $options['filter'] : [];
        $limit = (int) ($options['limit'] ?? 50);
        $response = $this->stateClient->request(SessionProtocol::CMD_LIST, [
            'filter' => $filter,
            'limit' => $limit,
        ]);

        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return [];
        }

        $data = SessionProtocol::getData($response);

        return \is_array($data) ? $data : [];
    }

    public function getAll(string $ns): array
    {
        $response = $this->stateClient->request(SessionProtocol::CMD_GET_ALL, [
            'ns' => $ns,
            'sid' => '__kv__:' . $ns,
        ]);

        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return [];
        }

        $data = SessionProtocol::getData($response);

        return \is_array($data) ? $data : [];
    }

    public function gc(int $maxLifetime): int
    {
        $response = $this->stateClient->request(SessionProtocol::CMD_GC, [
            'max_lifetime' => \max(1, $maxLifetime),
        ]);
        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return 0;
        }

        $data = SessionProtocol::getData($response);

        return (int) (($data['cleaned'] ?? 0));
    }

    public function persist(): bool
    {
        $response = $this->stateClient->request(SessionProtocol::CMD_PERSIST);

        return \is_array($response) && SessionProtocol::isSuccess($response);
    }

    public function ping(): bool
    {
        return $this->stateClient->ping();
    }

    public function getStats(): array
    {
        $response = $this->stateClient->request(SessionProtocol::CMD_STATS);
        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return [];
        }

        $data = SessionProtocol::getData($response);

        return \is_array($data) ? $data : [];
    }

    public function getCache(string $poolIdentity, string $key): mixed
    {
        return $this->cacheMemoryService->get($poolIdentity, $key);
    }

    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->cacheMemoryService->set($poolIdentity, $key, $value, $ttl);
    }

    public function deleteCache(string $poolIdentity, string $key): bool
    {
        return $this->cacheMemoryService->delete($poolIdentity, $key);
    }

    public function hasCache(string $poolIdentity, string $key): bool
    {
        return $this->cacheMemoryService->exists($poolIdentity, $key);
    }

    public function clearCache(string $poolIdentity): bool
    {
        return $this->cacheMemoryService->clear($poolIdentity);
    }

    public function compareAndSetCache(string $poolIdentity, string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        return $this->cacheMemoryService->cas($poolIdentity, $key, $expected, $value, $ttl);
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

        if (isset($this->stateClient)) {
            $this->stateClient->disconnect();
        }
        $this->released = true;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildServiceOptions(array $config): array
    {
        return [
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 1.0),
            'timeout' => (float) ($config['timeout'] ?? 2.0),
            'pool_size' => (int) ($config['pool_size'] ?? 8),
            'pool_min_idle' => (int) ($config['pool_min_idle'] ?? 0),
            'acquire_timeout' => (float) ($config['acquire_timeout'] ?? 0.2),
            'idle_timeout' => (float) ($config['idle_timeout'] ?? 86400.0),
            'pool_health_ping_idle' => (bool) ($config['pool_health_ping_idle'] ?? false),
            'token_file_name' => (string) (
                $config['token_file_name']
                ?? $this->runtime['token_file_name']
                ?? $this->resolveConfiguredRuntime($config)['token_file_name']
            ),
            'service_type' => 'Memory',
            'service_role' => ControlMessage::ROLE_MEMORY_SERVER,
            'consumer_code' => $this->consumerCode,
            'instance_name' => $this->consumerCode,
            'owner_type' => 'instance',
            // Master/CLI 门面默认静默逐条 CONN-*，避免与 Memory 侧车/token 就绪竞态时刷屏；排障可设 log_pool_lifecycle=true
            'log_pool_lifecycle' => (bool) ($config['log_pool_lifecycle'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function createSharedMemoryService(string $host, int $port, array $options): SharedMemoryService
    {
        return new SharedMemoryService($host, $port, $options);
    }

    protected function createCacheMemoryService(SharedMemoryService $sharedMemoryService): CacheMemoryService
    {
        return new CacheMemoryService($sharedMemoryService);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function createStateClient(string $host, int $port, array $options): SharedStateClient
    {
        return new SharedStateClient($host, $port, $options);
    }

    private function cleanupInitializationFailure(): void
    {
        try {
            if (isset($this->stateClient)) {
                $this->stateClient->disconnect();
            }
        } catch (\Throwable) {
        }

        $this->released = true;
    }

    private function attemptDirectBootstrap(
        array $config,
        ?SharedStateServiceManager $manager,
        ?SharedMemoryService $sharedMemoryService,
        ?CacheMemoryService $cacheMemoryService,
        ?SharedStateClient $stateClient
    ): bool {
        if (!(bool)($config['prefer_direct_connect'] ?? true)) {
            return false;
        }

        $allowDirectBootstrap = $manager === null
            || ($sharedMemoryService !== null && $cacheMemoryService !== null && $stateClient !== null);
        if (!$allowDirectBootstrap) {
            return false;
        }

        $runtime = $this->resolveConfiguredRuntime($config);
        if (\array_key_exists('port', $config)) {
            $runtime['port'] = (int)$config['port'];
        }
        if (\array_key_exists('token_file_name', $config)) {
            $runtime['token_file_name'] = (string)$config['token_file_name'];
        }
        $serviceOptions = $this->buildServiceOptions(\array_merge($config, $runtime));
        $host = (string)$runtime['host'];
        $port = (int)$runtime['port'];

        try {
            $this->sharedMemoryService = $sharedMemoryService ?? $this->createSharedMemoryService($host, $port, $serviceOptions);
            $this->cacheMemoryService = $cacheMemoryService ?? $this->createCacheMemoryService($this->sharedMemoryService);
            $this->stateClient = $stateClient ?? $this->createStateClient($host, $port, $serviceOptions);

            $this->runtime = [
                'host' => $host,
                'port' => $port,
                'token_file_name' => (string)$runtime['token_file_name'],
                'reuse_existing' => true,
                'direct_connect' => true,
            ];

            return true;
        } catch (\Throwable $throwable) {
            $this->cleanupDirectBootstrapState();
            if (!(bool)($config['fail_fast_on_unhealthy'] ?? false)) {
                return false;
            }
            throw new \RuntimeException('Shared memory facade is not healthy', 0, $throwable);
        }
    }

    private function cleanupDirectBootstrapState(): void
    {
        if (isset($this->stateClient)) {
            unset($this->stateClient);
        }
        if (isset($this->cacheMemoryService)) {
            unset($this->cacheMemoryService);
        }
        if (isset($this->sharedMemoryService)) {
            unset($this->sharedMemoryService);
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
            \w_env('wls.instance', ''),
            \w_env('wls.instance_name', ''),
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
        $memoryService = \is_array($wls['memory_service'] ?? null) ? $wls['memory_service'] : [];

        $host = \trim((string) ($memoryService['host'] ?? '127.0.0.1'));
        if ($host === '') {
            $host = '127.0.0.1';
        }

        $defaultPort = 19971 + MasterProcess::getProjectPortOffset();
        $port = (int) ($memoryService['port'] ?? $defaultPort);
        $tokenFileName = \trim((string) ($memoryService['token_file_name'] ?? 'memory_server.token'));

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : $defaultPort,
            'token_file_name' => $tokenFileName !== '' ? $tokenFileName : 'memory_server.token',
        ];
    }
}
