<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Cache\Contract\SharedBufferStateFactoryInterface;
use Weline\Framework\Cache\Contract\SharedBufferStateInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * 前台事件拾取短时 token + 本会话累计缓冲。
 *
 * 沙盒缓冲落在共享缓存服务（SharedBufferState），禁止依赖 WLS Worker 进程 L1。
 */
final class EventPickerTokenService
{
    private const CACHE_IDENTITY = 'visitor';
    private const SHARED_NAMESPACE = 'visitor.pixel.picker_acc';
    private const TOKEN_TTL = 1800;
    private const BUFFER_TTL = 1800;
    private const BUFFER_MAX = 120;
    private const MEMORY_FAILURE_COOLDOWN_SECONDS = 2.0;

    private ?SharedBufferStateInterface $sharedBuffer = null;
    private float $sharedBufferRetryAfter = 0.0;

    /** @var array<int, array{list: list<array<string, mixed>>, seq: int}> */
    private static array $processFallback = [];

    public function __construct(
        private readonly ?EventDictionaryService $dictionary = null,
        private readonly ?EventAnnotationService $annotation = null,
    ) {
    }

    /**
     * @return array{token: string, expires_at: int, website_id: int, vendor_code: string, storage_scope: string}
     */
    public function issue(int $websiteId, string $vendorCode, int $adminUserId = 0, string $storageScope = ''): array
    {
        $token = \bin2hex(\random_bytes(16));
        $expiresAt = \time() + self::TOKEN_TTL;
        $payload = [
            'website_id' => \max(0, $websiteId),
            'vendor_code' => \trim($vendorCode),
            'admin_user_id' => \max(0, $adminUserId),
            'storage_scope' => \trim($storageScope),
            'expires_at' => $expiresAt,
            'issued_at' => \time(),
        ];
        $this->writeTokenPayload($token, $payload);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'website_id' => $payload['website_id'],
            'vendor_code' => $payload['vendor_code'],
            'storage_scope' => $payload['storage_scope'],
        ];
    }

    /**
     * @return array{website_id: int, vendor_code: string, admin_user_id: int, expires_at: int, storage_scope: string}|null
     */
    public function validate(string $token): ?array
    {
        $token = \trim($token);
        if ($token === '' || !\preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $raw = $this->readTokenPayload($token);
        if (!\is_array($raw)) {
            return null;
        }
        $expiresAt = (int)($raw['expires_at'] ?? 0);
        if ($expiresAt < \time()) {
            $this->deleteTokenPayload($token);

            return null;
        }

        return [
            'website_id' => (int)($raw['website_id'] ?? 0),
            'vendor_code' => (string)($raw['vendor_code'] ?? ''),
            'admin_user_id' => (int)($raw['admin_user_id'] ?? 0),
            'expires_at' => $expiresAt,
            'storage_scope' => (string)($raw['storage_scope'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    public function pushAccumulate(int $websiteId, array $event): void
    {
        $name = $this->dictionary()->normalizeEventName((string)($event['weline_event'] ?? $event['event'] ?? ''));
        if ($name === '') {
            return;
        }
        $websiteId = \max(0, $websiteId);
        $params = $event['params'] ?? null;
        if (!\is_array($params)) {
            $params = [];
        } else {
            // 限制体积，避免会话缓冲膨胀
            $params = \array_slice($params, 0, 40, true);
        }
        $row = [
            'id' => (string)($event['id'] ?? ('acc-' . \microtime(true) . '-' . \bin2hex(\random_bytes(3)))),
            'seq' => 0,
            'weline_event' => $name,
            'third_party_event' => (string)($event['third_party_event'] ?? ''),
            'source' => (string)($event['source'] ?? 'picker'),
            'summary' => (string)($event['summary'] ?? ''),
            'at' => (string)($event['at'] ?? \date('c')),
            'path' => (string)($event['path'] ?? ''),
            'kind' => (string)($event['kind'] ?? 'single'),
            'chain' => \is_array($event['chain'] ?? null) ? $event['chain'] : null,
            'chain_steps' => (int)($event['chain_steps'] ?? 0),
            'has_value' => !empty($event['has_value']),
            'value' => $event['value'] ?? null,
            'params' => $params,
            'hit_kind' => (string)($event['hit_kind'] ?? ''),
            'custom' => !empty($event['custom']),
            'event_hit' => \array_key_exists('event_hit', $event)
                ? !empty($event['event_hit'])
                : ((string)($event['hit_kind'] ?? '') === 'system' || (string)($event['hit_kind'] ?? '') === 'custom' || !empty($event['custom'])),
        ];

        $this->withBufferMutation($websiteId, function (array $list, int $seq) use ($row): array {
            $row['seq'] = $seq + 1;
            \array_unshift($list, $row);
            $list = \array_slice($list, 0, self::BUFFER_MAX);

            return ['list' => $list, 'seq' => $row['seq']];
        });

        try {
            if (!empty($event['has_value']) || $this->annotation()->detectHasValueFromPayload($event)) {
                $scope = (string)($event['storage_scope'] ?? '');
                $this->annotation()->markHasValue($websiteId, $name, $scope);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccumulate(int $websiteId, int $limit = 40): array
    {
        $pack = $this->readBufferPack(\max(0, $websiteId));
        $raw = $pack['list'];

        return \array_slice(\array_values($raw), 0, \max(1, $limit));
    }

    /**
     * 打洞读取：仅返回 seq > afterSeq 的新事件（时间倒序缓冲内筛选）。
     *
     * @return array{events: list<array<string, mixed>>, cursor: int}
     */
    public function listAccumulateSince(int $websiteId, int $afterSeq = 0, int $limit = 80): array
    {
        $all = $this->listAccumulate($websiteId, self::BUFFER_MAX);
        $cursor = $afterSeq;
        $out = [];
        foreach ($all as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $seq = (int)($row['seq'] ?? 0);
            if ($seq > $cursor) {
                $cursor = $seq;
            }
            if ($seq > $afterSeq) {
                $out[] = $row;
            }
        }
        // 缓冲是新→旧，since 结果改为旧→新便于前端追加观感，最终仍由 UI 按 at/seq 排序
        $out = \array_reverse($out);
        if (\count($out) > $limit) {
            $out = \array_slice($out, -$limit);
        }

        return ['events' => $out, 'cursor' => \max($cursor, $afterSeq)];
    }

    public function clearAccumulate(int $websiteId): bool
    {
        $websiteId = \max(0, $websiteId);
        try {
            $this->withBufferMutation($websiteId, static function (): array {
                return ['list' => [], 'seq' => 0];
            });
            $this->bumpBufferGeneration($websiteId);
            $this->purgeLegacyWorkerCache($websiteId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 缓冲世代：清理后递增，供 live poll 的 changed 判定。 */
    public function bufferGeneration(int $websiteId): int
    {
        $websiteId = \max(0, $websiteId);
        $memory = $this->sharedBuffer();
        if ($memory) {
            try {
                return \max(0, (int)($memory->get(self::SHARED_NAMESPACE, $this->generationKey($websiteId)) ?? 0));
            } catch (\Throwable) {
            }
        }

        return 0;
    }

    /**
     * @param callable(array, int): array{list: list<array<string, mixed>>, seq: int} $mutator
     */
    private function withBufferMutation(int $websiteId, callable $mutator): void
    {
        $websiteId = \max(0, $websiteId);
        $memory = $this->sharedBuffer();
        if ($memory) {
            $key = $this->bufferKey($websiteId);
            for ($i = 0; $i < 8; $i++) {
                $expected = $memory->get(self::SHARED_NAMESPACE, $key);
                $pack = $this->normalizePack($expected);
                $next = $mutator($pack['list'], $pack['seq']);
                $payload = [
                    'list' => \array_values(\is_array($next['list'] ?? null) ? $next['list'] : []),
                    'seq' => (int)($next['seq'] ?? 0),
                    'updated_at' => \time(),
                ];
                if ($memory->cas(self::SHARED_NAMESPACE, $key, $expected, $payload, self::BUFFER_TTL)) {
                    unset(self::$processFallback[$websiteId]);
                    $this->purgeLegacyWorkerCache($websiteId);

                    return;
                }
            }
            // CAS 连续冲突时仍强制写入共享服务，避免丢清理/事件
            $pack = $this->normalizePack($memory->get(self::SHARED_NAMESPACE, $key));
            $next = $mutator($pack['list'], $pack['seq']);
            $memory->set(self::SHARED_NAMESPACE, $key, [
                'list' => \array_values(\is_array($next['list'] ?? null) ? $next['list'] : []),
                'seq' => (int)($next['seq'] ?? 0),
                'updated_at' => \time(),
            ], self::BUFFER_TTL);
            unset(self::$processFallback[$websiteId]);
            $this->purgeLegacyWorkerCache($websiteId);

            return;
        }

        $pack = $this->readProcessFallback($websiteId);
        $next = $mutator($pack['list'], $pack['seq']);
        self::$processFallback[$websiteId] = [
            'list' => \array_values(\is_array($next['list'] ?? null) ? $next['list'] : []),
            'seq' => (int)($next['seq'] ?? 0),
        ];
    }

    /**
     * @return array{list: list<array<string, mixed>>, seq: int}
     */
    private function readBufferPack(int $websiteId): array
    {
        $websiteId = \max(0, $websiteId);
        $memory = $this->sharedBuffer();
        if ($memory) {
            try {
                $raw = $memory->get(self::SHARED_NAMESPACE, $this->bufferKey($websiteId));
                if ($raw !== null) {
                    return $this->normalizePack($raw);
                }
            } catch (\Throwable) {
            }
        }

        return $this->readProcessFallback($websiteId);
    }

    /**
     * @return array{list: list<array<string, mixed>>, seq: int}
     */
    private function readProcessFallback(int $websiteId): array
    {
        $row = self::$processFallback[$websiteId] ?? null;
        if (\is_array($row)) {
            return [
                'list' => \is_array($row['list'] ?? null) ? \array_values($row['list']) : [],
                'seq' => (int)($row['seq'] ?? 0),
            ];
        }

        return ['list' => [], 'seq' => 0];
    }

    /**
     * @return array{list: list<array<string, mixed>>, seq: int}
     */
    private function normalizePack(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return ['list' => [], 'seq' => 0];
        }

        return [
            'list' => \is_array($raw['list'] ?? null) ? \array_values($raw['list']) : [],
            'seq' => (int)($raw['seq'] ?? 0),
        ];
    }

    private function bumpBufferGeneration(int $websiteId): void
    {
        $memory = $this->sharedBuffer();
        if (!$memory) {
            return;
        }
        try {
            $memory->incr(self::SHARED_NAMESPACE, $this->generationKey($websiteId), 1, self::BUFFER_TTL);
        } catch (\Throwable) {
        }
    }

    private function purgeLegacyWorkerCache(int $websiteId): void
    {
        try {
            $cache = w_cache(self::CACHE_IDENTITY);
            // 旧路径曾把缓冲写进 w_cache(visitor)（含 Worker L1），清理时顺带删掉
            $cache->deleteCustom('pixel_picker_acc:' . $websiteId);
            $cache->deleteCustom('pixel_picker_acc_seq:' . $websiteId);
            $cache->deleteCustom($this->bufferKey($websiteId));
            $cache->deleteCustom($this->seqKey($websiteId));
        } catch (\Throwable) {
        }
        $root = \defined('BP') ? (string)BP : \dirname(__DIR__, 5);
        $legacy = $root . '/var/pixel_picker_acc/' . $websiteId . '.json';
        if (\is_file($legacy)) {
            @\unlink($legacy);
        }
    }

    private function sharedBuffer(): ?SharedBufferStateInterface
    {
        if ($this->sharedBuffer) {
            return $this->sharedBuffer;
        }
        if ($this->sharedBufferRetryAfter > \microtime(true)) {
            return null;
        }
        try {
            $factory = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(SharedBufferStateFactoryInterface::class);
            if (!$factory instanceof SharedBufferStateFactoryInterface) {
                $this->sharedBufferRetryAfter = \microtime(true) + self::MEMORY_FAILURE_COOLDOWN_SECONDS;

                return null;
            }
            $memory = $factory->create([
                'consumer_code' => self::SHARED_NAMESPACE,
                'prefer_direct_connect' => true,
                'fail_fast_on_unhealthy' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]);
            if (!$memory instanceof SharedBufferStateInterface || !$memory->ping()) {
                $this->sharedBufferRetryAfter = \microtime(true) + self::MEMORY_FAILURE_COOLDOWN_SECONDS;

                return null;
            }
            $this->sharedBuffer = $memory;
            $this->sharedBufferRetryAfter = 0.0;

            return $memory;
        } catch (\Throwable) {
            $this->sharedBufferRetryAfter = \microtime(true) + self::MEMORY_FAILURE_COOLDOWN_SECONDS;

            return null;
        }
    }

    private function generationKey(int $websiteId): string
    {
        return 'gen:' . \max(0, $websiteId);
    }

    private function seqKey(int $websiteId): string
    {
        return 'pixel_picker_acc_seq:' . \max(0, $websiteId);
    }

    private function tokenKey(string $token): string
    {
        return 'pixel_picker_token:' . $token;
    }

    private function bufferKey(int $websiteId): string
    {
        return 'pack:' . \max(0, $websiteId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeTokenPayload(string $token, array $payload): void
    {
        try {
            w_cache(self::CACHE_IDENTITY)->setCustom($this->tokenKey($token), $payload, self::TOKEN_TTL);
        } catch (\Throwable) {
        }
        // 文件回落：CLI / 多 Worker 进程间 cache 不共享时仍可校验
        $file = $this->tokenFile($token);
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        @\file_put_contents(
            $file,
            \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readTokenPayload(string $token): ?array
    {
        try {
            $raw = w_cache(self::CACHE_IDENTITY)->getCustom($this->tokenKey($token));
            if (\is_array($raw)) {
                return $raw;
            }
        } catch (\Throwable) {
        }
        $file = $this->tokenFile($token);
        if (!\is_file($file)) {
            return null;
        }
        $decoded = \json_decode((string)@\file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function deleteTokenPayload(string $token): void
    {
        try {
            w_cache(self::CACHE_IDENTITY)->deleteCustom($this->tokenKey($token));
        } catch (\Throwable) {
        }
        $file = $this->tokenFile($token);
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    private function tokenFile(string $token): string
    {
        $root = \defined('BP') ? (string)BP : \dirname(__DIR__, 5);

        return $root . '/var/pixel_picker_tokens/' . $token . '.json';
    }

    private function dictionary(): EventDictionaryService
    {
        return $this->dictionary ?? ObjectManager::getInstance(EventDictionaryService::class);
    }

    private function annotation(): EventAnnotationService
    {
        return $this->annotation ?? ObjectManager::getInstance(EventAnnotationService::class);
    }
}
