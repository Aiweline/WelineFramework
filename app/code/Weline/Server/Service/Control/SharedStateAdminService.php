<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Framework\App\Env;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Storage\WlsSharedStorage;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;

class SharedStateAdminService
{
    private const ROLE_SESSION = 'session';
    private const ROLE_MEMORY = 'memory';
    private const SESSION_NAMESPACE = 'sess';
    private const SESSION_NAMESPACE_STATE_ID = '__kv__:sess';

    public function getSessionOverview(): array
    {
        return $this->getOverview(self::ROLE_SESSION);
    }

    public function getMemoryOverview(): array
    {
        return $this->getOverview(self::ROLE_MEMORY);
    }

    public function listSessions(array $filter = [], int $limit = 50): array
    {
        $limit = $this->normalizeLimit($limit, 200);
        $payloadFilter = $this->sanitizePayloadFilter($filter);
        $storage = SessionFactory::getInstance()->createStorage();
        $stateFilter = $filter;
        if ($storage instanceof WlsSharedStorage) {
            // WLS unified state server carries multiple namespaces; session list must be scoped.
            $stateFilter['__domain'] = self::ROLE_SESSION;
        }
        $rawItems = $storage->list([
            'filter' => $stateFilter,
            'limit' => $limit,
        ]);

        $rows = [];
        foreach ($rawItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $sessionId = (string)($item['session_id'] ?? '');
            $data = \is_array($item['data'] ?? null) ? $item['data'] : [];

            // 兼容聚合存储：__ns__:sess:__kv__:sess => [sid => payload]
            if ($sessionId === '__ns__:sess:__kv__:sess') {
                foreach ($data as $realSessionId => $sessionPayload) {
                    if (!\is_string($realSessionId) || $realSessionId === '' || !\is_array($sessionPayload)) {
                        continue;
                    }
                    if (!empty($payloadFilter)) {
                        $match = true;
                        foreach ($payloadFilter as $key => $value) {
                            if (($sessionPayload[$key] ?? null) !== $value) {
                                $match = false;
                                break;
                            }
                        }
                        if (!$match) {
                            continue;
                        }
                    }
                    $rows[] = [
                        'session_id' => $realSessionId,
                        'data_count' => \count($sessionPayload),
                        'keys' => \array_slice(\array_keys($sessionPayload), 0, 8),
                        'preview' => $this->buildPreview($sessionPayload),
                    ];
                    if (\count($rows) >= $limit) {
                        break 2;
                    }
                }
                continue;
            }

            $rows[] = [
                'session_id' => $sessionId,
                'data_count' => \count($data),
                'keys' => \array_slice(\array_keys($data), 0, 8),
                'preview' => $this->buildPreview($data),
            ];
            if (\count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    public function destroySession(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }
        $client = $this->buildClient(self::ROLE_SESSION);
        // Prefer deleting from sess namespace to avoid accidentally deleting cache namespace records.
        $response = $client->request(SessionProtocol::CMD_DELETE, [
            'ns' => self::SESSION_NAMESPACE,
            'sid' => self::SESSION_NAMESPACE_STATE_ID,
            'key' => $sessionId,
        ]);
        if ($this->isOk($response)) {
            return true;
        }
        // Backward compatibility: older deployments may still store sessions by raw sid.
        $fallback = $client->request(SessionProtocol::CMD_DESTROY, ['sid' => $sessionId]);
        return $this->isOk($fallback);
    }

    public function gcSession(int $maxLifetime): bool
    {
        $client = $this->buildClient(self::ROLE_SESSION);
        $response = $client->request(SessionProtocol::CMD_GC, [
            'max_lifetime' => \max(1, $maxLifetime),
            'domain' => self::ROLE_SESSION,
        ]);
        return $this->isOk($response);
    }

    public function persistSession(): bool
    {
        $client = $this->buildClient(self::ROLE_SESSION);
        $response = $client->request(SessionProtocol::CMD_PERSIST);
        return $this->isOk($response);
    }

    public function listMemoryNamespaces(int $limit = 200): array
    {
        $limit = $this->normalizeLimit($limit, 500);
        $client = $this->buildClient(self::ROLE_MEMORY);
        $response = $client->request(SessionProtocol::CMD_LIST, [
            'filter' => [],
            'limit' => $limit,
        ]);
        if (!$this->isOk($response)) {
            return [];
        }
        $items = (array)SessionProtocol::getData((array)$response);
        $result = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $sid = (string)($item['session_id'] ?? '');
            $namespace = $this->extractNamespace($sid);
            if ($namespace === '') {
                continue;
            }
            $data = \is_array($item['data'] ?? null) ? $item['data'] : [];
            $result[$namespace] = [
                'namespace' => $namespace,
                'keys' => \count($data),
                'sample_keys' => \array_slice(\array_keys($data), 0, 6),
            ];
        }
        \ksort($result);
        return \array_values($result);
    }

    public function getMemoryNamespaceDetail(string $namespace, int $limit = 100): array
    {
        if ($namespace === '') {
            return [];
        }
        $client = $this->buildClient(self::ROLE_MEMORY);
        $response = $client->request(SessionProtocol::CMD_GET_ALL, [
            'ns' => $namespace,
            'sid' => '__kv__:' . $namespace,
        ]);
        if (!$this->isOk($response)) {
            return [];
        }
        $payload = SessionProtocol::getData((array)$response);
        if (!\is_array($payload)) {
            return [];
        }
        $rows = [];
        $index = 0;
        foreach ($payload as $key => $value) {
            if ($index >= $limit) {
                break;
            }
            $rows[] = [
                'key' => (string)$key,
                'type' => \gettype($value),
                'preview' => $this->previewValue($value),
                'preview_detail' => $this->buildPreviewDetail($value),
            ];
            $index++;
        }
        return $rows;
    }

    public function clearMemoryNamespace(string $namespace): bool
    {
        if ($namespace === '') {
            return false;
        }
        $client = $this->buildClient(self::ROLE_MEMORY);
        $response = $client->request(SessionProtocol::CMD_DESTROY, [
            'ns' => $namespace,
            'sid' => '__kv__:' . $namespace,
        ]);
        return $this->isOk($response);
    }

    public function deleteMemoryKey(string $namespace, string $key): bool
    {
        if ($namespace === '' || $key === '') {
            return false;
        }
        $client = $this->buildClient(self::ROLE_MEMORY);
        $response = $client->request(SessionProtocol::CMD_DELETE, [
            'ns' => $namespace,
            'sid' => '__kv__:' . $namespace,
            'key' => $key,
        ]);
        return $this->isOk($response);
    }

    public function persistMemory(): bool
    {
        $client = $this->buildClient(self::ROLE_MEMORY);
        $response = $client->request(SessionProtocol::CMD_PERSIST);
        return $this->isOk($response);
    }

    public function gcMemory(int $maxLifetime): bool
    {
        $client = $this->buildClient(self::ROLE_MEMORY);
        $response = $client->request(SessionProtocol::CMD_GC, ['max_lifetime' => \max(1, $maxLifetime)]);
        return $this->isOk($response);
    }

    private function getOverview(string $role): array
    {
        $config = $this->getEndpointConfig($role);
        $client = $this->buildClient($role);
        $ping = $client->ping();
        if (!$ping) {
            // 常驻进程场景下，连接池可能处于刚重连状态，补一次轻量重试降低误报。
            \usleep(20000);
            $ping = $client->ping();
        }
        $statsResp = $client->request(SessionProtocol::CMD_STATS);
        $statsOk = $this->isOk($statsResp);
        $stats = $statsOk ? (array)SessionProtocol::getData((array)$statsResp) : [];
        $connected = $ping || $statsOk;
        $error = (!$connected && \is_array($statsResp)) ? (string)(SessionProtocol::getError($statsResp) ?? '') : '';
        return [
            'connected' => $connected,
            'host' => $config['host'],
            'port' => $config['port'],
            'token_file_name' => $config['token_file_name'],
            'stats' => $stats,
            'probe' => [
                'ping_ok' => $ping,
                'stats_ok' => $statsOk,
                'error' => $error,
            ],
        ];
    }

    private function buildClient(string $role): SharedStateClient
    {
        $config = $this->getEndpointConfig($role);
        return new SharedStateClient(
            (string)$config['host'],
            (int)$config['port'],
            [
                'token_file_name' => (string)$config['token_file_name'],
                'acquire_timeout' => 0.3,
            ]
        );
    }

    private function getEndpointConfig(string $role): array
    {
        if ($role === self::ROLE_MEMORY) {
            $host = (string)(Env::get('server.memory_service.host') ?? '127.0.0.1');
            $host = \trim($host);
            if ($host === '') {
                $host = '127.0.0.1';
            }
            $port = (int)(Env::get('server.memory_service.port') ?? 19971);
            return [
                'host' => $host,
                'port' => $port > 0 ? $port : 19971,
                'token_file_name' => 'memory_server.token',
            ];
        }
        $host = (string)(Env::get('session.server_host') ?? '127.0.0.1');
        $host = \trim($host);
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $port = (int)(Env::get('session.server_port') ?? 19970);
        return [
            'host' => $host,
            'port' => $port > 0 ? $port : 19970,
            'token_file_name' => 'session_server.token',
        ];
    }

    private function isOk(?array $response): bool
    {
        return \is_array($response) && SessionProtocol::isSuccess($response);
    }

    private function normalizeLimit(int $limit, int $max): int
    {
        return \max(1, \min($limit, $max));
    }

    /**
     * 过滤掉仅用于 SharedState 协议层的内部键，避免误伤真实 Session payload 筛选。
     *
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function sanitizePayloadFilter(array $filter): array
    {
        $payloadFilter = [];
        foreach ($filter as $key => $value) {
            if (\str_starts_with((string)$key, '__')) {
                continue;
            }
            $payloadFilter[(string)$key] = $value;
        }
        return $payloadFilter;
    }

    private function extractNamespace(string $sessionId): string
    {
        if (!\str_starts_with($sessionId, '__ns__:')) {
            return '';
        }
        $body = \substr($sessionId, 7);
        $pos = \strpos($body, ':__kv__:');
        if ($pos === false) {
            return $body;
        }
        return \substr($body, 0, $pos);
    }

    private function buildPreview(array $data): array
    {
        $preview = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if ($count >= 5) {
                break;
            }
            $name = (string)$key;
            $preview[$name] = $this->isSensitiveKey($name) ? '***' : $this->previewValue($value);
            $count++;
        }
        return $preview;
    }

    private function isSensitiveKey(string $key): bool
    {
        $name = \strtolower($key);
        return \str_contains($name, 'password')
            || \str_contains($name, 'token')
            || \str_contains($name, 'secret')
            || \str_contains($name, 'cookie');
    }

    private function previewValue(mixed $value): string
    {
        if (\is_scalar($value) || $value === null) {
            $text = (string)$value;
            if (\strlen($text) > 120) {
                return \substr($text, 0, 120) . '...';
            }
            return $text;
        }
        if (\is_array($value)) {
            return 'array(' . \count($value) . ')';
        }
        if (\is_object($value)) {
            return 'object(' . $value::class . ')';
        }
        return \gettype($value);
    }

    private function buildPreviewDetail(mixed $value): array
    {
        if ($value === null) {
            return [
                'kind' => 'null',
                'display' => 'null',
                'size' => 0,
                'json' => null,
            ];
        }
        if (\is_scalar($value)) {
            return [
                'kind' => 'scalar',
                'display' => $this->previewValue($value),
                'size' => 1,
                'json' => null,
            ];
        }

        if (\is_array($value)) {
            return [
                'kind' => 'array',
                'display' => 'array(' . \count($value) . ')',
                'size' => \count($value),
                'json' => $this->toPreviewJson($value),
            ];
        }

        if (\is_object($value)) {
            $props = \get_object_vars($value);
            return [
                'kind' => 'object',
                'display' => 'object(' . $value::class . ')',
                'size' => \count($props),
                'json' => $this->toPreviewJson([
                    '__class' => $value::class,
                    '__props' => $props,
                ]),
            ];
        }

        return [
            'kind' => \gettype($value),
            'display' => \gettype($value),
            'size' => 0,
            'json' => null,
        ];
    }

    private function toPreviewJson(array $value): string
    {
        $normalized = $this->normalizePreviewValue($value, 0);
        $json = \json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!\is_string($json) || $json === '') {
            return '';
        }
        if (\strlen($json) > 1200) {
            return \substr($json, 0, 1200) . '...';
        }
        return $json;
    }

    private function normalizePreviewValue(mixed $value, int $depth): mixed
    {
        if ($depth >= 2) {
            if (\is_array($value)) {
                return '[array:' . \count($value) . ']';
            }
            if (\is_object($value)) {
                return '[object:' . $value::class . ']';
            }
            return \is_scalar($value) || $value === null ? $value : \gettype($value);
        }

        if (\is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($count >= 12) {
                    $result['__truncated'] = true;
                    break;
                }
                $result[(string)$k] = $this->normalizePreviewValue($v, $depth + 1);
                $count++;
            }
            return $result;
        }

        if (\is_object($value)) {
            return [
                '__class' => $value::class,
                '__props' => $this->normalizePreviewValue(\get_object_vars($value), $depth + 1),
            ];
        }

        if (\is_scalar($value) || $value === null) {
            if (\is_string($value) && \strlen($value) > 240) {
                return \substr($value, 0, 240) . '...';
            }
            return $value;
        }

        return \gettype($value);
    }
}
