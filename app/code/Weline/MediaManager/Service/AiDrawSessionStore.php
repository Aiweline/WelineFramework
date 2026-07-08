<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Framework\App\Env;

/**
 * AI 作图临时结果与会话存储。
 */
class AiDrawSessionStore
{
    private const TTL_SECONDS = 7200;
    private const MAX_TURNS = 10;

    public function getBaseDir(): string
    {
        $dir = \rtrim(Env::VAR_DIR, '/\\') . \DIRECTORY_SEPARATOR . 'tmp' . \DIRECTORY_SEPARATOR . 'media-ai-draw' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public function createSessionId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    public function createGenerationId(): string
    {
        return \bin2hex(\random_bytes(12));
    }

    /**
     * @param array<string,mixed> $meta
     * @return string preview_token
     */
    public function storeGeneration(string $sessionId, int $adminId, string $generationId, string $bytes, array $meta): string
    {
        $this->assertSessionOwner($sessionId, $adminId);
        $dir = $this->sessionDir($sessionId);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        $previewToken = \bin2hex(\random_bytes(16));
        $meta['generation_id'] = $generationId;
        $meta['admin_id'] = $adminId;
        $meta['preview_token'] = $previewToken;
        $meta['created_at'] = \time();
        @\file_put_contents($dir . $generationId . '.bin', $bytes);
        @\file_put_contents($dir . $generationId . '.json', \json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        $this->touchSessionMeta($sessionId, $adminId);

        return $previewToken;
    }

    /**
     * @return array{bytes:string,meta:array<string,mixed>}|null
     */
    public function loadGeneration(string $sessionId, int $adminId, string $generationId): ?array
    {
        $this->assertSessionOwner($sessionId, $adminId);
        $dir = $this->sessionDir($sessionId);
        $metaPath = $dir . $generationId . '.json';
        $binPath = $dir . $generationId . '.bin';
        if (!\is_file($metaPath) || !\is_file($binPath)) {
            return null;
        }
        $meta = \json_decode((string)@\file_get_contents($metaPath), true);
        if (!\is_array($meta)) {
            return null;
        }
        if ((int)($meta['admin_id'] ?? 0) !== $adminId) {
            return null;
        }
        $bytes = @\file_get_contents($binPath);
        if ($bytes === false) {
            return null;
        }

        return ['bytes' => $bytes, 'meta' => $meta];
    }

    /**
     * 凭 preview_token 读取生成结果（供 img 预览，不依赖后台登录 Cookie）。
     *
     * @return array{bytes:string,meta:array<string,mixed>}|null
     */
    public function loadGenerationByPreviewToken(string $sessionId, string $generationId, string $previewToken): ?array
    {
        $previewToken = \trim($previewToken);
        if ($previewToken === '') {
            return null;
        }
        $dir = $this->sessionDir($sessionId);
        $metaPath = $dir . $generationId . '.json';
        $binPath = $dir . $generationId . '.bin';
        if (!\is_file($metaPath) || !\is_file($binPath)) {
            return null;
        }
        $meta = \json_decode((string)@\file_get_contents($metaPath), true);
        if (!\is_array($meta)) {
            return null;
        }
        $storedToken = (string)($meta['preview_token'] ?? '');
        if ($storedToken === '' || !\hash_equals($storedToken, $previewToken)) {
            return null;
        }
        $bytes = @\file_get_contents($binPath);
        if ($bytes === false) {
            return null;
        }

        return ['bytes' => $bytes, 'meta' => $meta];
    }

    /**
     * @param list<string> $generationIds
     * @return list<array{bytes:string,meta:array<string,mixed>}>
     */
    public function loadGenerations(string $sessionId, int $adminId, array $generationIds): array
    {
        $items = [];
        foreach ($generationIds as $generationId) {
            $item = $this->loadGeneration($sessionId, $adminId, (string)$generationId);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function appendTurn(string $sessionId, int $adminId, string $generationId, string $prompt): void
    {
        $meta = $this->readSessionMeta($sessionId, $adminId);
        $turns = \is_array($meta['turns'] ?? null) ? $meta['turns'] : [];
        $turns[] = [
            'generation_id' => $generationId,
            'prompt' => $prompt,
            'at' => \time(),
        ];
        if (\count($turns) > self::MAX_TURNS) {
            $turns = \array_slice($turns, -self::MAX_TURNS);
        }
        $meta['turns'] = $turns;
        $meta['last_generation_id'] = $generationId;
        $this->writeSessionMeta($sessionId, $adminId, $meta);
    }

    /**
     * @return array<string,mixed>
     */
    public function readSessionMeta(string $sessionId, int $adminId): array
    {
        $this->assertSessionOwner($sessionId, $adminId);
        $path = $this->sessionDir($sessionId) . 'session.json';
        if (!\is_file($path)) {
            return ['admin_id' => $adminId, 'turns' => []];
        }
        $decoded = \json_decode((string)@\file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : ['admin_id' => $adminId, 'turns' => []];
    }

    public function ensureSession(string $sessionId, int $adminId): void
    {
        $dir = $this->sessionDir($sessionId);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        $path = $dir . 'session.json';
        if (!\is_file($path)) {
            $this->writeSessionMeta($sessionId, $adminId, ['admin_id' => $adminId, 'turns' => []]);
        } else {
            $meta = $this->readSessionMeta($sessionId, $adminId);
            if ((int)($meta['admin_id'] ?? 0) !== $adminId) {
                throw new \RuntimeException(__('会话无权访问'));
            }
        }
    }

    public function purgeExpired(): void
    {
        $base = $this->getBaseDir();
        $now = \time();
        foreach (@\scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $base . $entry;
            if (!\is_dir($path)) {
                continue;
            }
            $mtime = @\filemtime($path) ?: $now;
            if ($now - $mtime > self::TTL_SECONDS) {
                $this->deleteDir($path);
            }
        }
    }

    private function sessionDir(string $sessionId): string
    {
        $safe = \preg_replace('/[^a-f0-9]/', '', \strtolower($sessionId)) ?? '';
        if ($safe === '') {
            throw new \RuntimeException(__('会话 ID 无效'));
        }

        return $this->getBaseDir() . $safe . \DIRECTORY_SEPARATOR;
    }

    private function touchSessionMeta(string $sessionId, int $adminId): void
    {
        $meta = $this->readSessionMeta($sessionId, $adminId);
        $meta['updated_at'] = \time();
        $this->writeSessionMeta($sessionId, $adminId, $meta);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function writeSessionMeta(string $sessionId, int $adminId, array $meta): void
    {
        $meta['admin_id'] = $adminId;
        $dir = $this->sessionDir($sessionId);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        @\file_put_contents(
            $dir . 'session.json',
            \json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    private function assertSessionOwner(string $sessionId, int $adminId): void
    {
        if ($sessionId === '') {
            throw new \RuntimeException(__('缺少会话 ID'));
        }
        $dir = $this->sessionDir($sessionId);
        $path = $dir . 'session.json';
        if (!\is_file($path)) {
            $this->ensureSession($sessionId, $adminId);

            return;
        }
        $meta = \json_decode((string)@\file_get_contents($path), true);
        if (!\is_array($meta) || (int)($meta['admin_id'] ?? 0) !== $adminId) {
            throw new \RuntimeException(__('会话无权访问'));
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (@\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
