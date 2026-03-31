<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\SessionStateFacadeInterface;
use Weline\Server\Session\Client\SessionClient;
use Weline\Server\Shared\Service\SharedMemoryService;

class SessionStateFacade implements SessionStateFacadeInterface
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

        if ($this->attemptDirectBootstrap($config, $manager, $sessionClient, $sessionMemoryService)) {
            return;
        }

        $this->runtime = $this->manager->ensure(ControlMessage::ROLE_SESSION_SERVER, $config, [], $this->consumerCode);
        $this->runtime['consumer_code'] = $this->consumerCode;

        try {
            $serviceOptions = $this->buildServiceOptions($config, $this->runtime);
            $host = (string) ($this->runtime['host'] ?? '127.0.0.1');
            $port = (int) ($this->runtime['port'] ?? 19970);

            if ($sessionMemoryService !== null) {
                $this->sessionMemoryService = $sessionMemoryService;
            } else {
                $sharedMemoryService = $this->createSharedMemoryService($host, $port, $serviceOptions);
                $this->sessionMemoryService = $this->createSessionMemoryService($sharedMemoryService);
            }

            $client = $sessionClient ?? $this->createSessionClient($host, $port, $serviceOptions);
            $client->connect();
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
        return $this->sessionMemoryService->read($sessionId);
    }

    public function write(string $sessionId, array $data, int $ttl): bool
    {
        return $this->sessionMemoryService->write($sessionId, $data, $ttl);
    }

    public function destroy(string $sessionId): bool
    {
        return $this->sessionMemoryService->destroy($sessionId);
    }

    public function exists(string $sessionId): bool
    {
        return $this->sessionMemoryService->exists($sessionId);
    }

    public function touch(string $sessionId, int $ttl): bool
    {
        return $this->sessionMemoryService->touch($sessionId, $ttl);
    }

    public function list(array $options = []): array
    {
        $filter = \is_array($options['filter'] ?? null) ? $options['filter'] : [];
        $limit = (int) ($options['limit'] ?? 50);

        return $this->sessionClient->list($filter, $limit);
    }

    public function gc(int $maxLifetime): int
    {
        return $this->sessionClient->gc($maxLifetime);
    }

    public function persist(): bool
    {
        return $this->sessionClient->persist();
    }

    public function ping(): bool
    {
        return $this->sessionClient->ping();
    }

    public function getStats(): array
    {
        return $this->sessionClient->getStats();
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
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 1.0),
            'timeout' => (float) ($config['timeout'] ?? 2.0),
            'pool_size' => (int) ($config['pool_size'] ?? 8),
            'pool_min_idle' => (int) ($config['pool_min_idle'] ?? 1),
            'acquire_timeout' => (float) ($config['acquire_timeout'] ?? 0.2),
            'token_file_name' => (string) ($runtime['token_file_name'] ?? $this->resolveConfiguredRuntime($config)['token_file_name']),
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
        return $client->connect();
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
        $runtime = (new SharedStateRuntimeResolver())->resolve($config);

        return $runtime['session'];
    }
}
