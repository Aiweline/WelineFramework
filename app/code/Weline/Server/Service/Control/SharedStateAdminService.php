<?php

declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Server\Service\SharedStateProtocolProbe;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\SessionStateFacade;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;

class SharedStateAdminService
{
    private const ROLE_SESSION = 'session';
    private const ROLE_MEMORY = 'memory';
    private const SESSION_NAMESPACE = 'sess';
    private const SESSION_NAMESPACE_STATE_ID = '__kv__:sess';

    private ?SessionStateFacade $sessionFacade = null;
    private ?MemoryStateFacade $memoryFacade = null;
    private SharedStateServiceManager $sharedStateServiceManager;

    public function __construct(
        ?SessionStateFacade $sessionFacade = null,
        ?MemoryStateFacade $memoryFacade = null,
        ?SharedStateServiceManager $sharedStateServiceManager = null
    ) {
        $this->sessionFacade = $sessionFacade;
        $this->memoryFacade = $memoryFacade;
        $this->sharedStateServiceManager = $sharedStateServiceManager ?? new SharedStateServiceManager();
    }

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
        $stateFilter = $filter;
        $stateFilter['__domain'] = self::ROLE_SESSION;

        $rawItems = $this->sessionFacade()->list([
            'filter' => $stateFilter,
            'limit' => $limit,
        ]);

        $rows = [];
        foreach ($rawItems as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $sessionId = (string) ($item['session_id'] ?? '');
            $data = \is_array($item['data'] ?? null) ? $item['data'] : [];

            if ($sessionId === '__ns__:sess:__kv__:sess') {
                foreach ($data as $realSessionId => $sessionPayload) {
                    if (!\is_string($realSessionId) || $realSessionId === '' || !\is_array($sessionPayload)) {
                        continue;
                    }
                    if (!$this->matchesPayloadFilter($sessionPayload, $payloadFilter)) {
                        continue;
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

            if (!$this->matchesPayloadFilter($data, $payloadFilter)) {
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

    public function getSessionDetail(string $sessionId): array
    {
        if ($sessionId === '') {
            return [];
        }

        $data = $this->sessionFacade()->read($sessionId);
        if ($data === []) {
            return [];
        }

        $rows = [];
        foreach ($data as $key => $value) {
            $rows[] = [
                'key' => (string) $key,
                'type' => \gettype($value),
                'preview' => $this->previewValue($value),
                'preview_detail' => $this->buildPreviewDetail($value),
            ];
        }

        return $rows;
    }

    public function destroySession(string $sessionId): bool
    {
        return $sessionId !== '' && $this->sessionFacade()->destroy($sessionId);
    }

    public function gcSession(int $maxLifetime): bool
    {
        $facade = $this->sessionFacade();
        if (!$facade->ping()) {
            return false;
        }

        $facade->gc(\max(1, $maxLifetime));

        return true;
    }

    public function persistSession(): bool
    {
        return $this->sessionFacade()->persist();
    }

    public function listMemoryNamespaces(int $limit = 200): array
    {
        $limit = $this->normalizeLimit($limit, 500);
        $items = $this->memoryFacade()->list([
            'filter' => [],
            'limit' => $limit,
        ]);

        $result = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $sid = (string) ($item['session_id'] ?? '');
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

        $payload = $this->memoryFacade()->getAll($namespace);
        $rows = [];
        $index = 0;
        foreach ($payload as $key => $value) {
            if ($index >= $limit) {
                break;
            }
            $rows[] = [
                'key' => (string) $key,
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
        return $namespace !== '' && $this->memoryFacade()->clearNamespace($namespace);
    }

    public function deleteMemoryKey(string $namespace, string $key): bool
    {
        return $namespace !== '' && $key !== '' && $this->memoryFacade()->delete($namespace, $key);
    }

    public function persistMemory(): bool
    {
        return $this->memoryFacade()->persist();
    }

    public function gcMemory(int $maxLifetime): bool
    {
        $facade = $this->memoryFacade();
        if (!$facade->ping()) {
            return false;
        }

        $facade->gc(\max(1, $maxLifetime));

        return true;
    }

    private function getOverview(string $role): array
    {
        $runtime = $this->sharedStateServiceManager->peekRuntime($role);
        $host = (string) ($runtime['host'] ?? '127.0.0.1');
        // 使用项目偏移量计算动态端口，避免硬编码
        $port = (int) ($runtime['port'] ?? 0);
        if ($port <= 0) {
            $basePort = $role === self::ROLE_MEMORY ? 19971 : 19970;
            $port = $basePort + \Weline\Server\Service\MasterProcess::getProjectPortOffset();
        }
        $defaultTokenFileName = (string) ($runtime['token_file_name'] ?? ($role === self::ROLE_MEMORY ? 'memory_server.token' : 'session_server.token'));
        $probeTokenFileName = null;
        $ping = false;
        $stats = [];
        $probeError = '';

        if ($port > 0) {
            $probeTokenFileName = $this->resolveProbeTokenFileName($host, $port, $defaultTokenFileName);
            if ($probeTokenFileName !== null) {
                $client = $this->buildStateClient($host, $port, $probeTokenFileName);
                if ($client !== null) {
                    try {
                        $response = $client->request(SessionProtocol::CMD_STATS);
                        $ping = \is_array($response) && SessionProtocol::isSuccess($response);
                        $data = $ping ? SessionProtocol::getData($response) : [];
                        $stats = \is_array($data) ? $data : [];
                        if (!$ping) {
                            $probeError = 'stats_request_failed';
                        }
                    } catch (\Throwable $throwable) {
                        $probeError = $throwable->getMessage();
                    } finally {
                        $client->disconnect();
                    }
                } else {
                    $probeError = 'client_build_failed';
                }
            } else {
                $probeError = 'unreachable';
            }
        } else {
            $probeError = 'port_missing';
        }

        return [
            'connected' => $ping,
            'host' => $host,
            'port' => $port,
            'token_file_name' => $probeTokenFileName ?? $defaultTokenFileName,
            'stats' => $stats,
            'probe' => [
                'ping_ok' => $ping,
                'stats_ok' => $stats !== [],
                'error' => $probeError,
            ],
            'registered' => (bool) ($runtime['registered'] ?? false),
            'consumer_count' => (int) ($runtime['consumer_count'] ?? 0),
            'shutdown_due_at' => $runtime['shutdown_due_at'] ?? null,
        ];
    }

    private function sessionFacade(): SessionStateFacade
    {
        if ($this->sessionFacade === null) {
            $this->sessionFacade = new SessionStateFacade();
        }

        return $this->sessionFacade;
    }

    private function memoryFacade(): MemoryStateFacade
    {
        if ($this->memoryFacade === null) {
            $this->memoryFacade = new MemoryStateFacade();
        }

        return $this->memoryFacade;
    }

    private function normalizeLimit(int $limit, int $max): int
    {
        return \max(1, \min($limit, $max));
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function sanitizePayloadFilter(array $filter): array
    {
        $payloadFilter = [];
        foreach ($filter as $key => $value) {
            if (\str_starts_with((string) $key, '__')) {
                continue;
            }
            $payloadFilter[(string) $key] = $value;
        }

        return $payloadFilter;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $filter
     */
    private function matchesPayloadFilter(array $payload, array $filter): bool
    {
        if ($filter === []) {
            return true;
        }

        foreach ($filter as $key => $value) {
            if (($payload[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
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
            $name = (string) $key;
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
            $text = (string) $value;
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
                $result[(string) $k] = $this->normalizePreviewValue($v, $depth + 1);
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

    protected function resolveProbeTokenFileName(string $host, int $port, string $defaultTokenFileName): ?string
    {
        return SharedStateProtocolProbe::findWorkingTokenBasename($host, $port, $defaultTokenFileName);
    }

    protected function buildStateClient(string $host, int $port, string $tokenFileName): ?SharedStateClient
    {
        try {
            return new SharedStateClient($host, $port, [
                'token_file_name' => $tokenFileName,
                'acquire_timeout' => 0.3,
                'connect_timeout' => 0.5,
                'timeout' => 1.0,
                'log_connect_fail' => false,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
