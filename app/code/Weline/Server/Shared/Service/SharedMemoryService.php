<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Service;

use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Contract\AtomicMemoryServiceInterface;
use Weline\Server\Shared\Contract\MemoryServiceInterface;

/**
 * Unified shared memory service built on top of state server protocol.
 */
class SharedMemoryService implements MemoryServiceInterface, AtomicMemoryServiceInterface
{
    private SharedStateClient $client;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 0,
        array $options = []
    ) {
        if ($port <= 0) {
            $endpoint = RoutingPolicyRegistry::getMemoryEndpoint();
            $host = $endpoint['host'];
            $port = $endpoint['port'];
        }
        $options['service_type'] = (string)($options['service_type'] ?? 'Memory');
        if (!isset($options['token_file_name']) || (string)$options['token_file_name'] === '') {
            $options['token_file_name'] = 'memory_server.token';
        }
        if (!isset($options['pool_min_idle']) && !isset($options['min_idle'])) {
            $options['pool_min_idle'] = 0;
        }
        if (!isset($options['pool_size']) && !isset($options['max_size'])) {
            // 默认提升共享服务连接池容量，适配高并发 Worker 复用场景。
            $options['pool_size'] = 32;
        }
        if (!isset($options['idle_timeout'])) {
            $options['idle_timeout'] = 86400.0;
        }
        if (!isset($options['pool_health_ping_idle'])) {
            $options['pool_health_ping_idle'] = false;
        }
        $this->client = new SharedStateClient($host, $port, $options);
    }

    public function get(string $ns, string $key): mixed
    {
        $resp = $this->client->request(SessionProtocol::CMD_GET, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
        ]);
        if (!\is_array($resp) || !SessionProtocol::isSuccess($resp)) {
            return null;
        }
        return SessionProtocol::getData($resp);
    }

    public function set(string $ns, string $key, mixed $value, int $ttl = 0): bool
    {
        $resp = $this->client->request(SessionProtocol::CMD_SET, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
            'val' => $value,
            'ttl' => $this->ttl($ttl),
        ]);
        return \is_array($resp) && SessionProtocol::isSuccess($resp);
    }

    public function delete(string $ns, string $key): bool
    {
        $resp = $this->client->request(SessionProtocol::CMD_DELETE, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
        ]);
        return \is_array($resp) && SessionProtocol::isSuccess($resp);
    }

    public function exists(string $ns, string $key): bool
    {
        $resp = $this->client->request(SessionProtocol::CMD_EXISTS, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
        ]);
        return \is_array($resp) && SessionProtocol::isSuccess($resp) && SessionProtocol::getData($resp) === true;
    }

    public function touch(string $ns, string $key, int $ttl): bool
    {
        $payload = [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'ttl' => $this->ttl($ttl),
        ];
        if ($key !== '') {
            $payload['key'] = $key;
        }
        $resp = $this->client->request(SessionProtocol::CMD_TOUCH, $payload);
        return \is_array($resp) && SessionProtocol::isSuccess($resp);
    }

    public function mget(string $ns, array $keys): array
    {
        $normalizedKeys = \array_map(static fn($key): string => (string)$key, $keys);
        $resp = $this->client->request(SessionProtocol::CMD_MGET, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'keys' => $normalizedKeys,
        ]);
        if (!\is_array($resp) || !SessionProtocol::isSuccess($resp)) {
            return [];
        }

        $data = SessionProtocol::getData($resp);
        return \is_array($data) ? $data : [];
    }

    public function mset(string $ns, array $kv, int $ttl = 0): bool
    {
        $resp = $this->client->request(SessionProtocol::CMD_MSET, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'data' => $kv,
            'ttl' => $this->ttl($ttl),
        ]);
        return \is_array($resp) && SessionProtocol::isSuccess($resp);
    }

    public function clearNamespace(string $ns): bool
    {
        $resp = $this->client->request(SessionProtocol::CMD_DESTROY, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
        ]);
        if (!\is_array($resp)) {
            return false;
        }
        if (SessionProtocol::isSuccess($resp)) {
            return true;
        }

        // Namespace clearing is idempotent. Multiple Workers clear the same
        // shared L2 pools, so the authoritative "not found" response means the
        // desired postcondition is already true. Authentication, transport and
        // every other protocol error remain hard failures.
        $errorCode = \strtolower(\trim((string)($resp['code'] ?? $resp['error_code'] ?? '')));

        return $errorCode === 'not_found'
            || SessionProtocol::getError($resp) === 'Session not found';
    }

    public function incr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        $resp = $this->client->request(SessionProtocol::CMD_INCREMENT, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
            'delta' => $delta,
            'ttl' => $this->ttl($ttl),
        ]);
        if (!\is_array($resp) || !SessionProtocol::isSuccess($resp)) {
            return null;
        }
        $data = SessionProtocol::getData($resp);
        return \is_int($data) ? $data : (int)$data;
    }

    public function decr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        $resp = $this->client->request(SessionProtocol::CMD_DECREMENT, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
            'delta' => $delta,
            'ttl' => $this->ttl($ttl),
        ]);
        if (!\is_array($resp) || !SessionProtocol::isSuccess($resp)) {
            return null;
        }
        $data = SessionProtocol::getData($resp);
        return \is_int($data) ? $data : (int)$data;
    }

    public function append(string $ns, string $key, mixed $value, int $ttl = 0): bool
    {
        $resp = $this->client->request(SessionProtocol::CMD_APPEND, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
            'val' => $value,
            'ttl' => $this->ttl($ttl),
        ]);
        return \is_array($resp) && SessionProtocol::isSuccess($resp);
    }

    public function cas(string $ns, string $key, mixed $expected, mixed $newValue, int $ttl = 0): bool
    {
        $resp = $this->client->request(SessionProtocol::CMD_COMPARE_SET, [
            'ns' => $ns,
            'sid' => $this->sid($ns),
            'key' => $key,
            'expected' => $expected,
            'val' => $newValue,
            'ttl' => $this->ttl($ttl),
        ]);
        return \is_array($resp) && SessionProtocol::isSuccess($resp);
    }

    public function ping(): bool
    {
        return $this->client->ping();
    }

    private function sid(string $ns): string
    {
        return '__kv__:' . $ns;
    }

    private function ttl(int $ttl): int
    {
        return $ttl > 0 ? $ttl : 3600;
    }

    public function __destruct()
    {
        if (isset($this->client)) {
            $this->client->disconnect();
        }
    }
}
