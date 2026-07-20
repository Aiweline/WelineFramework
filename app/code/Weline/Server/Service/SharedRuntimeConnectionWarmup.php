<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

final class SharedRuntimeConnectionWarmup
{
    /** @var array<string, float> */
    private static array $lastWarmupAt = [];

    /** @var array<string, float> */
    private static array $nextRetryAt = [];

    public static function primeWorkerPools(int $workerId = 0, string $instanceName = 'default', array $runtime = []): array
    {
        return [
            'enabled' => self::enabled(),
            'connect_now' => false,
            'deferred' => true,
            'worker_id' => $workerId,
        ];
    }

    public static function warmWorkerPools(int $workerId = 0, string $instanceName = 'default', array $runtime = []): array
    {
        return self::warm($workerId, $instanceName, $runtime, true, 'full');
    }

    public static function warmReadyMemory(int $workerId = 0, string $instanceName = 'default', array $runtime = []): array
    {
        return self::warm($workerId, $instanceName, $runtime, true, 'ready_memory');
    }

    private static function warm(
        int $workerId,
        string $instanceName,
        array $runtime,
        bool $connectNow,
        string $mode
    ): array
    {
        if (!self::enabled()) {
            return [
                'enabled' => false,
                'connect_now' => $connectNow,
            ];
        }

        $scope = self::scope($instanceName, $workerId, $connectNow, $mode);
        $readyMemoryOnly = $mode === 'ready_memory';
        $now = \microtime(true);
        if ($connectNow && ($now < (self::$nextRetryAt[$scope] ?? 0.0))) {
            return [
                'enabled' => true,
                'connect_now' => true,
                'skipped' => 'retry_cooldown',
                'retry_after_ms' => (int) \max(0, ((self::$nextRetryAt[$scope] ?? $now) - $now) * 1000),
            ];
        }
        if ($connectNow && ($now - (self::$lastWarmupAt[$scope] ?? 0.0)) < 1.0) {
            return [
                'enabled' => true,
                'connect_now' => true,
                'skipped' => 'recent',
            ];
        }

        self::$lastWarmupAt[$scope] = $now;
        $policyOptions = self::policyMemoryOptions();
        $session = $readyMemoryOnly ? null : self::resolveEndpoint('session', $runtime);
        $memory = self::resolveEndpoint('memory', $runtime);

        $sessionMinIdle = $connectNow && !$readyMemoryOnly
            ? self::intConfig('wls.shared_state.prewarm_session_min_idle', 1, 0, 16)
            : 0;
        $memoryMinIdle = $readyMemoryOnly
            ? 1
            : ($connectNow ? self::intConfig('wls.shared_state.prewarm_memory_min_idle', 2, 0, 32) : 0);

        $result = [
            'enabled' => true,
            'connect_now' => $connectNow,
            'worker_id' => $workerId,
            'session' => null,
            'memory' => null,
            'errors' => [],
        ];

        if (!$readyMemoryOnly && \is_array($session)) {
            try {
                $result['session'] = self::warmPool(
                    $session,
                    'Session',
                    ControlMessage::ROLE_SESSION_SERVER,
                    $sessionMinIdle,
                    $policyOptions
                );
            } catch (\Throwable $e) {
                $result['errors']['session'] = $e->getMessage();
            }
        }

        try {
            $result['memory'] = self::warmPool(
                $memory,
                'Memory',
                ControlMessage::ROLE_MEMORY_SERVER,
                $memoryMinIdle,
                $policyOptions
            );
        } catch (\Throwable $e) {
            $result['errors']['memory'] = $e->getMessage();
        }

        if ($connectNow && $result['errors'] !== []) {
            self::$nextRetryAt[$scope] = $now + self::floatConfig('wls.shared_state.prewarm_retry_seconds', 3.0, 0.25, 30.0);
        } elseif ($connectNow) {
            unset(self::$nextRetryAt[$scope]);
        }

        return $result;
    }

    private static function warmPool(
        array $endpoint,
        string $serviceType,
        string $serviceRole,
        int $minIdle,
        array $policyOptions
    ): array {
        $poolSize = self::intConfig('wls.shared_state.prewarm_pool_size', 8, 1, 64);
        $poolSize = \max($poolSize, $minIdle, 1);

        $options = [
            'token_file_name' => $endpoint['token_file_name'],
            'service_type' => $serviceType,
            'service_role' => $serviceRole,
            'min_idle' => $minIdle,
            'pool_min_idle' => $minIdle,
            'max_size' => $poolSize,
            'pool_size' => $poolSize,
            'connect_timeout' => self::floatConfig(
                'wls.shared_state.prewarm_connect_timeout',
                (float) ($policyOptions['connect_timeout'] ?? 0.08),
                0.001,
                2.0
            ),
            'timeout' => self::floatConfig(
                'wls.shared_state.prewarm_timeout',
                (float) ($policyOptions['timeout'] ?? 0.12),
                0.001,
                2.0
            ),
            'acquire_timeout' => self::floatConfig(
                'wls.shared_state.prewarm_acquire_timeout',
                (float) ($policyOptions['acquire_timeout'] ?? 0.08),
                0.001,
                2.0
            ),
            'idle_timeout' => self::floatConfig('wls.shared_state.prewarm_idle_timeout', 86400.0, 1.0, 604800.0),
            'pool_health_ping_idle' => false,
            'log_connect_fail' => false,
            'log_pool_lifecycle' => false,
        ];

        $pool = ConnectionPoolManager::getInstance((string) $endpoint['host'], (int) $endpoint['port'], $options);
        $verified = 0;
        $failed = 0;

        if ($minIdle > 0 && self::boolConfig('wls.shared_state.prewarm_ping_connections', true)) {
            [$verified, $failed] = self::verifyWarmConnections(
                $pool,
                $minIdle,
                (float) $options['acquire_timeout']
            );
        }

        $metrics = $pool->getPoolMetrics();

        return [
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'min_idle' => $minIdle,
            'pool_size' => $poolSize,
            'verified' => $verified,
            'failed' => $failed,
            'idle' => $metrics['idle'] ?? 0,
            'busy' => $metrics['busy'] ?? 0,
            'total' => $metrics['total'] ?? 0,
        ];
    }

    private static function verifyWarmConnections(ConnectionPoolManager $pool, int $target, float $acquireTimeout): array
    {
        $leased = [];
        $verified = 0;
        $failed = 0;

        for ($i = 0; $i < $target; $i++) {
            $connection = $pool->acquire($acquireTimeout);
            if (!$connection instanceof PooledConnectionInterface) {
                $failed++;
                break;
            }

            if (!$connection->ping()) {
                $failed++;
                $pool->invalidate($connection);
                SchedulerSystem::yield();
                continue;
            }

            $leased[] = $connection;
            $verified++;
            SchedulerSystem::yield();
        }

        foreach ($leased as $connection) {
            try {
                $pool->release($connection);
            } catch (\Throwable) {
                $pool->invalidate($connection);
            }
            SchedulerSystem::yield();
        }

        return [$verified, $failed];
    }

    private static function enabled(): bool
    {
        return self::boolConfig('wls.shared_state.prewarm_enabled', true);
    }

    private static function policyMemoryOptions(): array
    {
        try {
            return ObjectManager::getInstance(RuntimeCachePolicy::class)->memoryOptions([]);
        } catch (\Throwable $e) {
            WlsLogger::warning_('[SharedStateWarmup] runtime cache policy unavailable: ' . $e->getMessage());
            return [
                'connect_timeout' => 0.08,
                'timeout' => 0.12,
                'acquire_timeout' => 0.08,
            ];
        }
    }

    private static function resolveEndpoint(string $kind, array $runtime): array
    {
        $runtimeKey = $kind === 'memory' ? 'memory' : 'session';
        if (\is_array($runtime[$runtimeKey] ?? null)) {
            return self::normalizeEndpoint($runtime[$runtimeKey], $kind);
        }

        $wls = self::wlsConfig();
        $serviceKey = $kind === 'memory' ? 'memory_service' : 'session_service';
        $service = \is_array($wls[$serviceKey] ?? null) ? $wls[$serviceKey] : [];

        return self::normalizeEndpoint($service, $kind);
    }

    private static function normalizeEndpoint(array $source, string $kind): array
    {
        $host = \trim((string) ($source['host'] ?? '127.0.0.1'));
        if ($host === '') {
            $host = '127.0.0.1';
        }

        $defaultPort = ($kind === 'memory' ? 19971 : 19970) + MasterProcess::getProjectPortOffset();
        $port = (int) ($source['port'] ?? $defaultPort);
        if ($port <= 0) {
            $port = $defaultPort;
        }

        $defaultToken = SharedStateRuntimeScope::defaultTokenFileNameForRole($kind, $port);
        $tokenFileName = \trim((string) ($source['token_file_name'] ?? $defaultToken));
        if ($tokenFileName === '') {
            $tokenFileName = $defaultToken;
        }

        return [
            'host' => $host,
            'port' => $port,
            'token_file_name' => $tokenFileName,
        ];
    }

    private static function scope(string $instanceName, int $workerId, bool $connectNow, string $mode): string
    {
        return $instanceName . ':' . $workerId . ':' . ($connectNow ? 'warm' : 'prime') . ':' . $mode;
    }

    private static function boolConfig(string $path, bool $default): bool
    {
        $value = self::configValue($path, $default);
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private static function intConfig(string $path, int $default, int $min, int $max): int
    {
        $value = self::configValue($path, $default);
        if (!\is_numeric($value)) {
            $value = $default;
        }

        return \max($min, \min($max, (int) $value));
    }

    private static function floatConfig(string $path, float $default, float $min, float $max): float
    {
        $value = self::configValue($path, $default);
        if (!\is_numeric($value)) {
            $value = $default;
        }

        return \max($min, \min($max, (float) $value));
    }

    private static function configValue(string $path, mixed $default): mixed
    {
        $config = Env::getInstance()->getConfig();
        if (!\is_array($config)) {
            return $default;
        }

        $value = $config;
        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function wlsConfig(): array
    {
        $config = Env::getInstance()->getConfig('wls');
        return \is_array($config) ? $config : [];
    }
}
