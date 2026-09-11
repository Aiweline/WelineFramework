<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 前台事件拾取短时 token + 本会话累计缓冲。
 */
final class EventPickerTokenService
{
    private const CACHE_IDENTITY = 'visitor';
    private const TOKEN_TTL = 1800;
    private const BUFFER_TTL = 1800;
    private const BUFFER_MAX = 80;

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
        $key = $this->bufferKey($websiteId);
        $cache = w_cache(self::CACHE_IDENTITY);
        $list = $cache->getCustom($key);
        if (!\is_array($list)) {
            $list = [];
        }
        \array_unshift($list, [
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
        ]);
        $list = \array_slice($list, 0, self::BUFFER_MAX);
        $cache->setCustom($key, $list, self::BUFFER_TTL);

        if (!empty($event['has_value']) || $this->annotation()->detectHasValueFromPayload($event)) {
            try {
                $scope = (string)($event['storage_scope'] ?? '');
                $this->annotation()->markHasValue($websiteId, $name, $scope);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccumulate(int $websiteId, int $limit = 40): array
    {
        $raw = w_cache(self::CACHE_IDENTITY)->getCustom($this->bufferKey($websiteId));
        if (!\is_array($raw)) {
            return [];
        }

        return \array_slice(\array_values($raw), 0, \max(1, $limit));
    }

    private function tokenKey(string $token): string
    {
        return 'pixel_picker_token:' . $token;
    }

    private function bufferKey(int $websiteId): string
    {
        return 'pixel_picker_acc:' . \max(0, $websiteId);
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
