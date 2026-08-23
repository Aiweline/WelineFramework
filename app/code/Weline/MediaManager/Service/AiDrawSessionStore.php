<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestStreamInterface;
use Weline\Storage\Api\Runtime\StorageRuntimeDiagnosticsReporterInterface;

/** Bounded, cross-request temporary store for AI-draw previews. */
final class AiDrawSessionStore
{
    private const TTL_SECONDS = 7200;
    private const MAX_TURNS = 10;
    private const MAX_GENERATIONS = 64;
    private const MAX_IMAGE_BYTES = MediaStorageService::MAX_IMAGE_BYTES;
    private const MAX_META_BYTES = 64 * 1024;
    private const MAX_PURGE_DIRECTORIES_PER_CALL = 256;
    private const STREAM_CHUNK_BYTES = 1024 * 1024;
    private const LOCK_TIMEOUT_NANOSECONDS = 5_000_000_000;

    public function __construct(
        private readonly StorageRequestResourceFactoryInterface $resourceFactory,
        private readonly StorageRuntimeDiagnosticsReporterInterface $diagnostics,
    ) {
    }

    public function getBaseDir(): string
    {
        $dir = rtrim(Env::VAR_DIR, '/\\') . DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'media-ai-draw' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException((string)__('无法创建 AI 作图临时目录。'));
        }

        return $dir;
    }

    public function createSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function createGenerationId(): string
    {
        return bin2hex(random_bytes(12));
    }

    /** @param array<string,mixed> $meta */
    public function storeGeneration(
        string $sessionId,
        int $adminId,
        string $generationId,
        string $bytes,
        array $meta,
    ): string {
        $sessionId = $this->requireSessionId($sessionId);
        $generationId = $this->requireGenerationId($generationId);
        if ($adminId < 1 || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException((string)__('AI 生成结果无效或超过大小限制。'));
        }

        return $this->withSessionLock($sessionId, function (string $dir) use (
            $sessionId,
            $adminId,
            $generationId,
            $bytes,
            $meta,
        ): string {
            $sessionMeta = $this->ensureSessionUnlocked($sessionId, $adminId, $dir);
            $binPath = $dir . $generationId . '.bin';
            $metaPath = $dir . $generationId . '.json';
            if (!is_file($binPath) && $this->generationCount($dir) >= self::MAX_GENERATIONS) {
                throw new \RuntimeException((string)__('单个 AI 作图会话的生成结果数量已达上限。'));
            }
            $previewToken = bin2hex(random_bytes(16));
            $meta['generation_id'] = $generationId;
            $meta['admin_id'] = $adminId;
            $meta['preview_token'] = $previewToken;
            $meta['created_at'] = time();
            $encodedMeta = $this->encodeMeta($meta);

            $this->atomicWrite($binPath, $bytes, self::MAX_IMAGE_BYTES);
            try {
                $this->atomicWrite($metaPath, $encodedMeta, self::MAX_META_BYTES);
            } catch (\Throwable $throwable) {
                @unlink($binPath);
                throw $throwable;
            }
            $sessionMeta['updated_at'] = time();
            $this->writeSessionMetaUnlocked($dir, $adminId, $sessionMeta);

            return $previewToken;
        });
    }

    /** @return array{bytes:string,meta:array<string,mixed>}|null */
    public function loadGeneration(string $sessionId, int $adminId, string $generationId): ?array
    {
        $sessionId = $this->requireSessionId($sessionId);
        $generationId = $this->requireGenerationId($generationId);
        $this->assertSessionOwner($sessionId, $adminId);

        return $this->loadGenerationFiles($this->sessionDir($sessionId), $generationId, $adminId);
    }

    /** @return array{bytes:string,meta:array<string,mixed>}|null */
    public function loadGenerationByPreviewToken(
        string $sessionId,
        string $generationId,
        string $previewToken,
    ): ?array {
        $sessionId = $this->requireSessionId($sessionId);
        $generationId = $this->requireGenerationId($generationId);
        $previewToken = strtolower(trim($previewToken));
        if (preg_match('/^[a-f0-9]{32}$/D', $previewToken) !== 1) {
            return null;
        }
        $loaded = $this->loadGenerationFiles($this->sessionDir($sessionId), $generationId, null);
        if ($loaded === null) {
            return null;
        }
        $storedToken = (string)($loaded['meta']['preview_token'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $previewToken)) {
            return null;
        }

        return $loaded;
    }

    /** @param list<string> $generationIds @return list<array{bytes:string,meta:array<string,mixed>}> */
    public function loadGenerations(string $sessionId, int $adminId, array $generationIds): array
    {
        if (count($generationIds) > self::MAX_GENERATIONS) {
            throw new \InvalidArgumentException((string)__('读取的 AI 生成结果数量超过上限。'));
        }
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
        $sessionId = $this->requireSessionId($sessionId);
        $generationId = $this->requireGenerationId($generationId);
        if (strlen($prompt) > 16_384) {
            throw new \InvalidArgumentException((string)__('AI 对话提示词超过长度限制。'));
        }
        $this->withSessionLock($sessionId, function (string $dir) use (
            $sessionId,
            $adminId,
            $generationId,
            $prompt,
        ): void {
            $meta = $this->ensureSessionUnlocked($sessionId, $adminId, $dir);
            $turns = is_array($meta['turns'] ?? null) ? $meta['turns'] : [];
            $turns[] = ['generation_id' => $generationId, 'prompt' => $prompt, 'at' => time()];
            $meta['turns'] = array_slice($turns, -self::MAX_TURNS);
            $meta['last_generation_id'] = $generationId;
            $meta['updated_at'] = time();
            $this->writeSessionMetaUnlocked($dir, $adminId, $meta);
        });
    }

    /** @return array<string,mixed> */
    public function readSessionMeta(string $sessionId, int $adminId): array
    {
        $sessionId = $this->requireSessionId($sessionId);

        return $this->withSessionLock(
            $sessionId,
            fn (string $dir): array => $this->ensureSessionUnlocked($sessionId, $adminId, $dir),
        );
    }

    public function ensureSession(string $sessionId, int $adminId): void
    {
        $sessionId = $this->requireSessionId($sessionId);
        $this->withSessionLock(
            $sessionId,
            fn (string $dir): array => $this->ensureSessionUnlocked($sessionId, $adminId, $dir),
        );
    }

    public function purgeExpired(): void
    {
        $base = $this->getBaseDir();
        $now = time();
        $checked = 0;
        foreach (new \DirectoryIterator($base) as $entry) {
            if ($checked >= self::MAX_PURGE_DIRECTORIES_PER_CALL) {
                break;
            }
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $name = $entry->getFilename();
            if (preg_match('/^[a-f0-9]{32}$/D', $name) !== 1) {
                continue;
            }
            ++$checked;
            if ($now - $entry->getMTime() <= self::TTL_SECONDS) {
                continue;
            }
            $dir = $entry->getPathname() . DIRECTORY_SEPARATOR;
            $lock = @fopen($dir . '.lock', 'c+b');
            if ($lock === false) {
                continue;
            }
            $guard = $this->resourceFactory->stream($lock);
            $claimed = false;
            $claimedPath = $base . '.purge-' . $name . '-' . bin2hex(random_bytes(4));
            try {
                if (flock($guard->stream(), LOCK_EX | LOCK_NB)
                    && $now - ((int)@filemtime($dir) ?: $now) > self::TTL_SECONDS
                ) {
                    $claimed = @rename(rtrim($dir, DIRECTORY_SEPARATOR), $claimedPath);
                    flock($guard->stream(), LOCK_UN);
                }
            } finally {
                $guard->close();
            }
            if ($claimed) {
                try {
                    $this->deleteFlatSessionDirectory($claimedPath);
                } catch (\Throwable) {
                    // A malformed or concurrently changed temporary directory
                    // must not take down an unrelated AI request. Put it back
                    // when possible so a later bounded cleanup can retry, and
                    // expose only a path-free diagnostic residue.
                    if (!file_exists(rtrim($dir, DIRECTORY_SEPARATOR))) {
                        @rename($claimedPath, rtrim($dir, DIRECTORY_SEPARATOR));
                    }
                    $this->diagnostics->operationResidue('ai_draw_expired_session_cleanup_failed');
                }
            }
            SchedulerSystem::yield();
        }
    }

    /** @return array{bytes:string,meta:array<string,mixed>}|null */
    private function loadGenerationFiles(string $dir, string $generationId, ?int $adminId): ?array
    {
        if ($this->sessionDirectoryExpired($dir)) {
            return null;
        }
        $metaPath = $dir . $generationId . '.json';
        $binPath = $dir . $generationId . '.bin';
        if (!is_file($metaPath) || !is_file($binPath)) {
            return null;
        }
        $meta = json_decode($this->readBounded($metaPath, self::MAX_META_BYTES), true);
        if (!is_array($meta) || ($adminId !== null && (int)($meta['admin_id'] ?? 0) !== $adminId)) {
            return null;
        }
        $bytes = $this->readBounded($binPath, self::MAX_IMAGE_BYTES);
        if ($bytes === '') {
            return null;
        }

        return ['bytes' => $bytes, 'meta' => $meta];
    }

    private function assertSessionOwner(string $sessionId, int $adminId): void
    {
        if ($adminId < 1) {
            throw new \RuntimeException((string)__('AI 作图会话缺少明确所有者。'));
        }
        $meta = $this->readSessionMeta($sessionId, $adminId);
        if ((int)($meta['admin_id'] ?? 0) !== $adminId) {
            throw new \RuntimeException((string)__('会话无权访问。'));
        }
    }

    /** @return array<string,mixed> */
    private function ensureSessionUnlocked(string $sessionId, int $adminId, string $dir): array
    {
        if ($adminId < 1) {
            throw new \InvalidArgumentException((string)__('AI 作图会话所有者无效。'));
        }
        $path = $dir . 'session.json';
        if (!is_file($path)) {
            $meta = ['admin_id' => $adminId, 'turns' => [], 'created_at' => time(), 'updated_at' => time()];
            $this->writeSessionMetaUnlocked($dir, $adminId, $meta);
            return $meta;
        }
        $decoded = json_decode($this->readBounded($path, self::MAX_META_BYTES), true);
        if (!is_array($decoded) || (int)($decoded['admin_id'] ?? 0) !== $adminId) {
            throw new \RuntimeException((string)__('会话无权访问。'));
        }
        $updatedAt = (int)($decoded['updated_at'] ?? 0);
        if ($updatedAt < 1 || time() - $updatedAt > self::TTL_SECONDS) {
            throw new \RuntimeException((string)__('AI 作图会话已过期，请新建会话。'));
        }

        return $decoded;
    }

    /** @param array<string,mixed> $meta */
    private function writeSessionMetaUnlocked(string $dir, int $adminId, array $meta): void
    {
        $meta['admin_id'] = $adminId;
        $this->atomicWrite($dir . 'session.json', $this->encodeMeta($meta), self::MAX_META_BYTES);
        @touch($dir);
    }

    /** @param array<string,mixed> $meta */
    private function encodeMeta(array $meta): string
    {
        $encoded = json_encode(
            $meta,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (strlen($encoded) > self::MAX_META_BYTES) {
            throw new \InvalidArgumentException((string)__('AI 作图临时元数据超过大小限制。'));
        }

        return $encoded;
    }

    /** @template T @param callable(string):T $callback @return T */
    private function withSessionLock(string $sessionId, callable $callback): mixed
    {
        $dir = $this->sessionDir($sessionId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException((string)__('无法创建 AI 作图会话目录。'));
        }
        if (is_link(rtrim($dir, DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException((string)__('AI 作图会话目录无效。'));
        }
        $lock = @fopen($dir . '.lock', 'c+b');
        if ($lock === false) {
            throw new \RuntimeException((string)__('无法创建 AI 作图会话锁。'));
        }
        $guard = $this->resourceFactory->stream($lock);
        try {
            $this->acquireSessionLock($guard);
            try {
                return $callback($dir);
            } finally {
                flock($guard->stream(), LOCK_UN);
            }
        } finally {
            $guard->close();
        }
    }

    private function acquireSessionLock(StorageRequestStreamInterface $guard): void
    {
        $deadline = hrtime(true) + self::LOCK_TIMEOUT_NANOSECONDS;
        do {
            if (flock($guard->stream(), LOCK_EX | LOCK_NB)) {
                return;
            }
            if (function_exists('connection_aborted') && connection_aborted()) {
                throw new \RuntimeException((string)__('客户端已断开，AI 作图会话锁定已取消。'));
            }
            SchedulerSystem::yield();
        } while (hrtime(true) < $deadline);

        throw new \RuntimeException((string)__('AI 作图会话正忙，请稍后重试。'));
    }

    private function sessionDirectoryExpired(string $dir): bool
    {
        $updatedAt = @filemtime(rtrim($dir, DIRECTORY_SEPARATOR));

        return $updatedAt === false || time() - (int)$updatedAt > self::TTL_SECONDS;
    }

    private function atomicWrite(string $path, string $contents, int $maxBytes): void
    {
        if (strlen($contents) > $maxBytes) {
            throw new \InvalidArgumentException((string)__('AI 作图临时文件超过大小限制。'));
        }
        $temporary = $this->resourceFactory->temporaryFile(dirname($path), '.ai-write-');
        $stream = @fopen($temporary->path(), 'wb');
        if ($stream === false) {
            $temporary->close();
            throw new \RuntimeException((string)__('无法创建 AI 作图临时写入流。'));
        }
        $target = $this->resourceFactory->stream($stream);
        try {
            $this->writeAll($target->stream(), $contents);
            if (!fflush($target->stream())) {
                throw new \RuntimeException((string)__('刷新 AI 作图临时文件失败。'));
            }
            if (function_exists('fsync')) {
                @fsync($target->stream());
            }
        } finally {
            $target->close();
        }
        @chmod($temporary->path(), 0600);
        if (!@rename($temporary->path(), $path)) {
            $temporary->close();
            throw new \RuntimeException((string)__('提交 AI 作图临时文件失败。'));
        }
        $temporary->close();
        @chmod($path, 0600);
    }

    private function readBounded(string $path, int $maxBytes): string
    {
        $size = @filesize($path);
        if ($size === false || $size < 0 || $size > $maxBytes) {
            throw new \RuntimeException((string)__('AI 作图临时文件大小无效。'));
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException((string)__('无法读取 AI 作图临时文件。'));
        }
        $source = $this->resourceFactory->stream($stream);
        $contents = '';
        try {
            while (!feof($source->stream())) {
                $chunk = fread($source->stream(), self::STREAM_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('读取 AI 作图临时文件失败。'));
                }
                if ($chunk === '') {
                    break;
                }
                if (strlen($contents) + strlen($chunk) > $maxBytes) {
                    throw new \RuntimeException((string)__('AI 作图临时文件超过大小限制。'));
                }
                $contents .= $chunk;
            }
        } finally {
            $source->close();
        }

        return $contents;
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $contents): void
    {
        $length = strlen($contents);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($stream, substr($contents, $offset, self::STREAM_CHUNK_BYTES));
            if ($written === false || $written === 0) {
                throw new \RuntimeException((string)__('写入 AI 作图临时文件失败。'));
            }
            $offset += $written;
        }
    }

    private function generationCount(string $dir): int
    {
        $count = 0;
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.bin')) {
                if (++$count >= self::MAX_GENERATIONS) {
                    break;
                }
            }
        }

        return $count;
    }

    private function deleteFlatSessionDirectory(string $dir): void
    {
        $entries = 0;
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (++$entries > (self::MAX_GENERATIONS * 2 + 4) || $entry->isDir()) {
                throw new \RuntimeException((string)__('AI 作图过期目录结构异常，已停止自动清理。'));
            }
            @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }

    private function sessionDir(string $sessionId): string
    {
        return $this->getBaseDir() . $this->requireSessionId($sessionId) . DIRECTORY_SEPARATOR;
    }

    private function requireSessionId(string $sessionId): string
    {
        $sessionId = strtolower(trim($sessionId));
        if (preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
            throw new \InvalidArgumentException((string)__('AI 作图会话 ID 无效。'));
        }

        return $sessionId;
    }

    private function requireGenerationId(string $generationId): string
    {
        $generationId = strtolower(trim($generationId));
        if (preg_match('/^[a-f0-9]{16,64}$/D', $generationId) !== 1) {
            throw new \InvalidArgumentException((string)__('AI 生成结果 ID 无效。'));
        }

        return $generationId;
    }
}
