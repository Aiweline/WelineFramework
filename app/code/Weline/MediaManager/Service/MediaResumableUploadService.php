<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestStreamInterface;
use Weline\Storage\Api\Runtime\StorageRuntimeDiagnosticsReporterInterface;
use Weline\Storage\Api\StorageDirectoryManagerInterface;
use Weline\Storage\Api\StorageManagerInterface;

/**
 * Cross-request, bounded upload staging for WLS.
 *
 * The service persists only data and regular files under var/tmp. It never
 * retains request objects, streams or SDK clients between requests.
 */
final class MediaResumableUploadService
{
    public const CHUNK_BYTES = 4 * 1024 * 1024;

    private const TTL_SECONDS = 2 * 60 * 60;
    private const MAX_MANIFEST_BYTES = 64 * 1024;
    private const MAX_PURGE_DIRECTORIES_PER_CALL = 64;
    private const MAX_CATALOG_DIRECTORIES = 2048;
    private const MAX_ACTIVE_SESSIONS_GLOBAL = 256;
    private const MAX_ACTIVE_SESSIONS_PER_ACTOR = 8;
    private const MAX_RESERVED_BYTES_GLOBAL = 128 * 1024 * 1024 * 1024;
    private const MAX_RESERVED_BYTES_PER_ACTOR = 4 * 1024 * 1024 * 1024;
    private const MAX_COMPLETION_ATTEMPTS = 10;
    private const STREAM_CHUNK_BYTES = 1024 * 1024;
    private const LOCK_TIMEOUT_NANOSECONDS = 5_000_000_000;

    public function __construct(
        private readonly StorageManagerInterface $storage,
        private readonly StorageDirectoryManagerInterface $directories,
        private readonly StorageRequestResourceFactoryInterface $resources,
        private readonly StorageRuntimeDiagnosticsReporterInterface $diagnostics,
        private readonly MediaStorageService $mediaStorage,
        private readonly MediaAssetUploadService $uploads,
        private readonly MediaFileAccessContextFactory $accessContexts,
        private readonly FileAssetLibraryInterface $assets,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $allowedMimes
     * @param list<string> $allowedExtensions
     * @return array<string,mixed>
     */
    public function start(
        array $input,
        int $actorId,
        array $allowedMimes,
        array $allowedExtensions,
        int $maxBytes,
    ): array {
        if ($actorId < 1) {
            throw new \InvalidArgumentException((string)__('分块上传缺少明确操作人。'));
        }
        $this->purgeExpired();

        $maxBytes = max(1, min(MediaAssetUploadService::MAX_ASSET_UPLOAD_BYTES, $maxBytes));
        $expectedSize = $this->positiveInt($input['file_size'] ?? null, 'file_size');
        if ($expectedSize > $maxBytes) {
            throw new \InvalidArgumentException((string)__('上传文件超过资源大小限制。'));
        }
        $filename = $this->mediaStorage->sanitizeLeafName((string)($input['file_name'] ?? ''));
        if ($filename === null) {
            throw new \InvalidArgumentException((string)__('上传文件名无效。'));
        }

        $allowedMimes = $this->normalizeStringList($allowedMimes, 256, 160);
        $allowedExtensions = $this->normalizeStringList($allowedExtensions, 256, 32);
        if ($allowedMimes === [] || $allowedExtensions === []) {
            throw new \InvalidArgumentException((string)__('上传文件类型白名单为空。'));
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException((string)__(
                '上传文件扩展名不允许：%{1}',
                [$extension !== '' ? $extension : (string)__('未知')],
            ));
        }

        $diskCode = trim((string)($input['storage'] ?? ''));
        $disk = $diskCode === ''
            ? $this->storage->defaultDisk()
            : $this->storage->disk($this->storage->canonicalizeDiskCode($diskCode));
        $diskCode = $disk->diskCode();
        $snapshot = $disk->snapshot();
        $capabilities = $this->directories->capabilities($diskCode);
        if (empty($capabilities['browse']) || empty($capabilities['upload'])) {
            throw new \RuntimeException((string)__('当前存储磁盘不支持上传。'));
        }

        $directory = $this->mediaStorage->objectKeyFromHash((string)($input['target'] ?? ''), true);
        $this->assertDirectoryExists($diskCode, $directory);
        $objectKey = trim(($directory === '' ? '' : $directory . '/') . $filename, '/');
        StorageObjectReference::assertObjectKey($objectKey);
        if ($disk->exists($objectKey)) {
            throw new \RuntimeException((string)__('目标文件已存在：%{1}', [$filename]));
        }

        $localeMetadata = $this->decodeMetadata($input['metadata'] ?? null);
        $localeMetadata = MediaAssetUploadService::normalizeMetadata($localeMetadata, $filename);
        $frozenInput = $this->accessContexts->freeze([
            'locale_code' => (string)($input['locale_code'] ?? ''),
        ], $actorId);
        // Validate the frozen context while the originating request is still
        // active; later chunks replay only this immutable data.
        $access = $this->accessContexts->fromFrozen($frozenInput, $actorId);

        $visibility = strtolower(trim((string)($input['visibility'] ?? '')));
        if ($visibility === '') {
            $visibility = $this->mediaStorage->defaultVisibility($diskCode);
        }
        if (!in_array($visibility, [
            FileAssetLibraryInterface::VISIBILITY_PUBLIC,
            FileAssetLibraryInterface::VISIBILITY_PRIVATE,
        ], true)) {
            throw new \InvalidArgumentException((string)__('文件资源可见性无效。'));
        }
        if (!hash_equals($snapshot->visibility(), $visibility)) {
            throw new \InvalidArgumentException((string)__(
                '文件资源可见性必须与所选磁盘可见性一致。',
            ));
        }

        return $this->withCatalogLock(function () use (
            $actorId,
            $disk,
            $diskCode,
            $objectKey,
            $filename,
            $expectedSize,
            $snapshot,
            $directory,
            $access,
            $visibility,
            $localeMetadata,
            $allowedMimes,
            $allowedExtensions,
            $frozenInput,
        ): array {
            $this->assertStagingQuota($actorId, $expectedSize, $diskCode, $objectKey);
            if ($disk->exists($objectKey)) {
                throw new \RuntimeException((string)__('目标文件已存在：%{1}', [$filename]));
            }
            [$sessionId, $directoryPath] = $this->createSessionDirectory();
            $stagePath = $directoryPath . 'upload.bin';
            $stage = @fopen($stagePath, 'x+b');
            if ($stage === false) {
                $this->deleteFlatDirectory($directoryPath);
                throw new \RuntimeException((string)__('无法创建分块上传暂存文件。'));
            }
            try {
                $stageResource = $this->resources->stream($stage);
                if (!fflush($stageResource->stream())) {
                    throw new \RuntimeException((string)__('无法初始化分块上传暂存文件。'));
                }
            } catch (\Throwable $throwable) {
                if (isset($stageResource)) {
                    $stageResource->close();
                }
                $this->deleteFlatDirectory($directoryPath);
                throw $throwable;
            }
            $stageResource->close();
            @chmod($stagePath, 0600);

            $now = time();
            $manifest = [
                'version' => 1,
                'session_id' => $sessionId,
                'actor_id' => $actorId,
                'status' => 'uploading',
                'disk_code' => $diskCode,
                'config_revision' => $snapshot->configRevision,
                'object_namespace_fingerprint' => $snapshot->objectNamespaceFingerprint(),
                'directory' => $directory,
                'object_key' => $objectKey,
                'filename' => $filename,
                'expected_size' => $expectedSize,
                'received_size' => 0,
                'locale_code' => $access->localeCode,
                'visibility' => $visibility,
                'locale_metadata' => $localeMetadata,
                'allowed_mimes' => $allowedMimes,
                'allowed_extensions' => $allowedExtensions,
                'frozen_input' => $frozenInput,
                'last_chunk' => null,
                'completion_attempts' => 0,
                'result' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            try {
                $this->writeManifest($directoryPath, $manifest);
            } catch (\Throwable $throwable) {
                $this->deleteFlatDirectory($directoryPath);
                throw $throwable;
            }

            return [
                'session_id' => $sessionId,
                'chunk_bytes' => self::CHUNK_BYTES,
                'expected_size' => $expectedSize,
                'received_size' => 0,
                'expires_at' => $now + self::TTL_SECONDS,
            ];
        });
    }

    /** @param array<string,mixed> $chunk @return array<string,mixed> */
    public function appendChunk(
        string $sessionId,
        int $actorId,
        int|string $offset,
        string $chunkSha256,
        array $chunk,
    ): array {
        $sessionId = $this->requireSessionId($sessionId);
        $offset = $this->nonNegativeInt($offset, 'offset');
        $chunkSha256 = strtolower(trim($chunkSha256));
        if (preg_match('/^[a-f0-9]{64}$/D', $chunkSha256) !== 1) {
            throw new \InvalidArgumentException((string)__('上传分块校验值无效。'));
        }
        $chunk = $this->normalizeChunkRecord($chunk);
        if ((int)$chunk['error'] !== UPLOAD_ERR_OK || (string)$chunk['tmp_name'] === '') {
            throw new \InvalidArgumentException((string)__('上传分块临时文件无效。'));
        }
        $chunkIdentity = $this->sealRegularFile((string)$chunk['tmp_name']);
        $chunkBytes = $chunkIdentity['size'];
        if ($chunkBytes < 1 || $chunkBytes > self::CHUNK_BYTES) {
            throw new \InvalidArgumentException((string)__('上传分块大小无效。'));
        }

        return $this->withSessionLock($sessionId, function (string $directoryPath) use (
            $sessionId,
            $actorId,
            $offset,
            $chunkSha256,
            $chunk,
            $chunkIdentity,
            $chunkBytes,
        ): array {
            $manifest = $this->readOwnedManifest($directoryPath, $sessionId, $actorId);
            if (($manifest['status'] ?? null) !== 'uploading') {
                throw new \RuntimeException((string)__('分块上传会话当前不可写。'));
            }
            $expectedSize = (int)$manifest['expected_size'];
            $receivedSize = (int)$manifest['received_size'];
            $lastChunk = is_array($manifest['last_chunk'] ?? null) ? $manifest['last_chunk'] : [];
            if ($offset < $receivedSize
                && $offset === (int)($lastChunk['offset'] ?? -1)
                && $chunkBytes === (int)($lastChunk['size'] ?? -1)
                && hash_equals((string)($lastChunk['sha256'] ?? ''), $chunkSha256)
            ) {
                $stageIdentity = $this->sealRegularFile($directoryPath . 'upload.bin');
                if ($stageIdentity['size'] !== $receivedSize) {
                    throw new \RuntimeException((string)__('分块上传暂存文件大小不一致。'));
                }
                return [
                    'session_id' => $sessionId,
                    'received_size' => $receivedSize,
                    'expected_size' => $expectedSize,
                    'complete' => $receivedSize === $expectedSize,
                    'replayed' => true,
                ];
            }
            if ($offset !== $receivedSize) {
                throw new \RuntimeException((string)__('上传分块偏移与服务端进度不一致。'));
            }
            $expectedChunkBytes = min(self::CHUNK_BYTES, $expectedSize - $receivedSize);
            if ($expectedChunkBytes < 1 || $chunkBytes !== $expectedChunkBytes) {
                throw new \InvalidArgumentException((string)__('上传分块长度与会话计划不一致。'));
            }

            $stagePath = $directoryPath . 'upload.bin';
            $stageIdentity = $this->sealRegularFile($stagePath);
            $plannedStageSize = $receivedSize + $chunkBytes;
            if ($stageIdentity['size'] > $receivedSize
                && $stageIdentity['size'] <= $plannedStageSize
            ) {
                // A Worker may have persisted the bytes and exited before the
                // manifest rename. An exact tail checksum is safe to adopt;
                // a partial tail is rolled back before replaying the chunk.
                if ($stageIdentity['size'] === $plannedStageSize
                    && hash_equals(
                        $chunkSha256,
                        $this->hashFileRange($stagePath, $stageIdentity, $receivedSize, $chunkBytes),
                    )
                ) {
                    $receivedSize = $plannedStageSize;
                    $manifest['received_size'] = $receivedSize;
                    $manifest['last_chunk'] = [
                        'offset' => $offset,
                        'size' => $chunkBytes,
                        'sha256' => $chunkSha256,
                    ];
                    $manifest['updated_at'] = time();
                    $this->writeManifest($directoryPath, $manifest);
                    return [
                        'session_id' => $sessionId,
                        'received_size' => $receivedSize,
                        'expected_size' => $expectedSize,
                        'complete' => $receivedSize === $expectedSize,
                        'replayed' => true,
                    ];
                }
                $stageIdentity = $this->truncateRegularFile($stagePath, $stageIdentity, $receivedSize);
            }
            if ($stageIdentity['size'] !== $receivedSize) {
                throw new \RuntimeException((string)__('分块上传暂存文件大小不一致。'));
            }
            $sourceHandle = @fopen((string)$chunk['tmp_name'], 'rb');
            $targetHandle = @fopen($stagePath, 'c+b');
            if ($sourceHandle === false || $targetHandle === false) {
                if (is_resource($sourceHandle)) {
                    fclose($sourceHandle);
                }
                if (is_resource($targetHandle)) {
                    fclose($targetHandle);
                }
                throw new \RuntimeException((string)__('无法打开分块上传流。'));
            }
            $source = $this->resources->stream($sourceHandle);
            try {
                $target = $this->resources->stream($targetHandle);
            } catch (\Throwable $registrationFailure) {
                $source->close();
                throw $registrationFailure;
            }
            $copied = 0;
            $hash = hash_init('sha256');
            $committed = false;
            try {
                $this->assertOpenFileIdentity($source, $chunkIdentity);
                $this->assertOpenFileIdentity($target, $stageIdentity);
                if (fseek($target->stream(), $receivedSize, SEEK_SET) !== 0) {
                    throw new \RuntimeException((string)__('无法定位分块上传暂存文件。'));
                }
                while (!feof($source->stream())) {
                    $this->assertRequestAlive((string)__('客户端已断开，上传分块已取消。'));
                    $bytes = fread($source->stream(), self::STREAM_CHUNK_BYTES);
                    if ($bytes === false) {
                        throw new \RuntimeException((string)__('读取上传分块失败。'));
                    }
                    if ($bytes === '') {
                        break;
                    }
                    $copied += strlen($bytes);
                    if ($copied > $chunkBytes) {
                        throw new \RuntimeException((string)__('上传分块在读取期间发生变化。'));
                    }
                    hash_update($hash, $bytes);
                    $this->writeAll($target->stream(), $bytes);
                    SchedulerSystem::yield();
                }
                $this->assertOpenFileIdentity($source, $chunkIdentity);
                if ($copied !== $chunkBytes || !hash_equals($chunkSha256, hash_final($hash))) {
                    throw new \RuntimeException((string)__('上传分块校验失败。'));
                }
                if (!fflush($target->stream())) {
                    throw new \RuntimeException((string)__('刷新分块上传暂存文件失败。'));
                }
                if (function_exists('fsync') && !@fsync($target->stream())) {
                    throw new \RuntimeException((string)__('持久化分块上传暂存文件失败。'));
                }
                $committed = true;
            } finally {
                if (!$committed) {
                    if (!@ftruncate($target->stream(), $receivedSize) || !@fflush($target->stream())) {
                        $manifest['status'] = 'corrupt';
                        $manifest['updated_at'] = time();
                        try {
                            $this->writeManifest($directoryPath, $manifest);
                        } catch (\Throwable) {
                        }
                        $this->diagnostics->operationResidue('media_resumable_chunk_rollback_failed');
                    }
                }
                $target->close();
                $source->close();
            }

            $receivedSize += $chunkBytes;
            $manifest['received_size'] = $receivedSize;
            $manifest['last_chunk'] = [
                'offset' => $offset,
                'size' => $chunkBytes,
                'sha256' => $chunkSha256,
            ];
            $manifest['updated_at'] = time();
            $this->writeManifest($directoryPath, $manifest);

            return [
                'session_id' => $sessionId,
                'received_size' => $receivedSize,
                'expected_size' => $expectedSize,
                'complete' => $receivedSize === $expectedSize,
                'replayed' => false,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function complete(string $sessionId, int $actorId): array
    {
        $sessionId = $this->requireSessionId($sessionId);

        return $this->withSessionLock($sessionId, function (string $directoryPath) use ($sessionId, $actorId): array {
            $manifest = $this->readOwnedManifest($directoryPath, $sessionId, $actorId);
            if (($manifest['status'] ?? null) === 'completed' && is_array($manifest['result'] ?? null)) {
                return (array)$manifest['result'];
            }
            if (!in_array(($manifest['status'] ?? null), ['uploading', 'completing'], true)) {
                throw new \RuntimeException((string)__('分块上传会话当前不可完成。'));
            }
            $expectedSize = (int)$manifest['expected_size'];
            if ((int)$manifest['received_size'] !== $expectedSize) {
                throw new \RuntimeException((string)__('分块上传尚未接收完整文件。'));
            }
            $attempts = (int)($manifest['completion_attempts'] ?? 0) + 1;
            if ($attempts > self::MAX_COMPLETION_ATTEMPTS) {
                throw new \RuntimeException((string)__('分块上传完成重试次数超过限制。'));
            }
            $manifest['status'] = 'completing';
            $manifest['completion_attempts'] = $attempts;
            $manifest['updated_at'] = time();
            $this->writeManifest($directoryPath, $manifest);

            $stagePath = $directoryPath . 'upload.bin';
            $stageIdentity = $this->sealRegularFile($stagePath);
            if ($stageIdentity['size'] !== $expectedSize) {
                throw new \RuntimeException((string)__('分块上传暂存文件大小不一致。'));
            }
            $disk = $this->storage->disk((string)$manifest['disk_code']);
            $diskCode = $disk->diskCode();
            $namespaceFingerprint = strtolower(trim((string)($manifest['object_namespace_fingerprint'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $namespaceFingerprint) !== 1
                || !hash_equals($namespaceFingerprint, $disk->snapshot()->objectNamespaceFingerprint())
            ) {
                throw new \RuntimeException((string)__(
                    '存储磁盘对象命名空间已变更，请重新上传。',
                ));
            }
            $directory = (string)$manifest['directory'];
            $objectKey = (string)$manifest['object_key'];
            $filename = (string)$manifest['filename'];
            $this->assertDirectoryExists($diskCode, $directory);
            $frozenInput = is_array($manifest['frozen_input'] ?? null) ? $manifest['frozen_input'] : [];
            $access = $this->accessContexts->fromFrozen($frozenInput, $actorId);
            $visibility = (string)$manifest['visibility'];
            if ($disk->exists($objectKey)) {
                $recovered = $this->recoverCompletedAsset(
                    $diskCode,
                    $objectKey,
                    $filename,
                    $expectedSize,
                    $stagePath,
                    $stageIdentity,
                    $access,
                    (array)$manifest['locale_metadata'],
                    $visibility,
                );
                if ($recovered === null) {
                    throw new \RuntimeException((string)__('目标文件已存在：%{1}', [$filename]));
                }
                $manifest['status'] = 'completed';
                $manifest['result'] = $this->boundedResult($recovered);
                $manifest['updated_at'] = time();
                $this->writeCompletedManifest($directoryPath, $manifest);
                if (is_file($stagePath) && !@unlink($stagePath)) {
                    $this->diagnostics->operationResidue('media_resumable_recovered_stage_cleanup_failed');
                }
                return (array)$manifest['result'];
            }
            $assetMetadata = [
                'upload_source' => 'media_manager_resumable',
                'upload_session_id' => $sessionId,
            ];
            if ($visibility === FileAssetLibraryInterface::VISIBILITY_PRIVATE) {
                $assetMetadata['access_policy'] = [
                    'owner_actor_id' => $actorId,
                    'policy_revision' => 1,
                ];
            }

            try {
                $added = $this->uploads->uploadFiles(
                    [[
                        'name' => $filename,
                        'tmp_name' => $stagePath,
                        'type' => 'application/octet-stream',
                        'error' => UPLOAD_ERR_OK,
                        'size' => $expectedSize,
                    ]],
                    $diskCode,
                    $directory,
                    $access->localeCode,
                    $access,
                    (array)$manifest['locale_metadata'],
                    $visibility,
                    (array)$manifest['allowed_mimes'],
                    $expectedSize,
                    [],
                    (array)$manifest['allowed_extensions'],
                    $assetMetadata,
                );
            } catch (\Throwable $throwable) {
                $manifest['updated_at'] = time();
                try {
                    $this->writeManifest($directoryPath, $manifest);
                } catch (\Throwable) {
                }
                throw $throwable;
            }
            $asset = $added[0] ?? null;
            if (!is_array($asset) || trim((string)($asset['asset_id'] ?? '')) === '') {
                throw new \RuntimeException((string)__('分块上传完成后缺少文件资源结果。'));
            }
            $result = $this->decorateAssetResult($asset, $objectKey, $filename, $expectedSize);
            $manifest['status'] = 'completed';
            $manifest['result'] = $this->boundedResult($result);
            $manifest['updated_at'] = time();
            try {
                $this->writeCompletedManifest($directoryPath, $manifest);
            } catch (\Throwable $throwable) {
                try {
                    $this->assets->deleteObject($diskCode, $objectKey, $access);
                } catch (\Throwable) {
                    $this->diagnostics->operationResidue('media_resumable_completion_receipt_rollback_failed');
                    throw new \RuntimeException(
                        (string)__('分块上传完成凭据写入失败，且已上传文件无法自动回收。'),
                        0,
                        $throwable,
                    );
                }
                throw $throwable;
            }
            if (is_file($stagePath) && !@unlink($stagePath)) {
                $this->diagnostics->operationResidue('media_resumable_completed_stage_cleanup_failed');
            }

            return (array)$manifest['result'];
        });
    }

    public function abort(string $sessionId, int $actorId): void
    {
        $sessionId = $this->requireSessionId($sessionId);
        $claimed = $this->withSessionLock($sessionId, function (string $directoryPath) use (
            $sessionId,
            $actorId,
        ): string {
            $manifest = $this->readOwnedManifest($directoryPath, $sessionId, $actorId);
            // A lost completion response may make the browser issue its normal
            // best-effort abort. Preserve the durable receipt and uploaded
            // asset so complete() remains idempotent on retry.
            if (($manifest['status'] ?? null) === 'completed') {
                return '';
            }
            $claimed = $this->baseDirectory() . '.delete-' . $sessionId . '-' . bin2hex(random_bytes(4));
            if (!@rename(rtrim($directoryPath, DIRECTORY_SEPARATOR), $claimed)) {
                throw new \RuntimeException((string)__('无法终止分块上传会话。'));
            }
            return $claimed;
        });
        if ($claimed === '') {
            return;
        }
        try {
            $this->deleteFlatDirectory($claimed . DIRECTORY_SEPARATOR);
            @unlink($this->lockPath($sessionId));
        } catch (\Throwable) {
            $this->diagnostics->operationResidue('media_resumable_abort_cleanup_failed');
            throw new \RuntimeException((string)__('分块上传会话已终止，但暂存文件清理失败。'));
        }
    }

    public function purgeExpired(): void
    {
        $base = $this->baseDirectory();
        $now = time();
        $scanned = 0;
        $purged = 0;
        foreach (new \DirectoryIterator($base) as $entry) {
            if ($purged >= self::MAX_PURGE_DIRECTORIES_PER_CALL) {
                break;
            }
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $sessionId = $entry->getFilename();
            if (preg_match('/^\.(?:delete|purge)-[a-f0-9]{32}-[a-f0-9]{8}$/D', $sessionId) === 1) {
                if (++$scanned > self::MAX_CATALOG_DIRECTORIES) {
                    $this->diagnostics->operationResidue('media_resumable_catalog_overflow');
                    break;
                }
                if ($now - $entry->getMTime() <= self::TTL_SECONDS) {
                    continue;
                }
                ++$purged;
                try {
                    $this->deleteFlatDirectory($entry->getPathname() . DIRECTORY_SEPARATOR);
                } catch (\Throwable) {
                    $this->diagnostics->operationResidue('media_resumable_claimed_session_cleanup_failed');
                }
                SchedulerSystem::yield();
                continue;
            }
            if (preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
                continue;
            }
            if (++$scanned > self::MAX_CATALOG_DIRECTORIES) {
                $this->diagnostics->operationResidue('media_resumable_catalog_overflow');
                break;
            }
            if ($now - $entry->getMTime() <= self::TTL_SECONDS) {
                continue;
            }
            ++$purged;
            $lockHandle = @fopen($this->lockPath($sessionId), 'c+b');
            if ($lockHandle === false) {
                continue;
            }
            $lock = $this->resources->stream($lockHandle);
            $claimed = '';
            try {
                if (!flock($lock->stream(), LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $directoryPath = $this->sessionDirectory($sessionId);
                $mtime = @filemtime(rtrim($directoryPath, DIRECTORY_SEPARATOR));
                if ($mtime === false || $now - (int)$mtime <= self::TTL_SECONDS) {
                    continue;
                }
                $claimed = $base . '.purge-' . $sessionId . '-' . bin2hex(random_bytes(4));
                if (!@rename(rtrim($directoryPath, DIRECTORY_SEPARATOR), $claimed)) {
                    $claimed = '';
                }
            } finally {
                @flock($lock->stream(), LOCK_UN);
                $lock->close();
            }
            if ($claimed === '') {
                continue;
            }
            try {
                $this->deleteFlatDirectory($claimed . DIRECTORY_SEPARATOR);
                @unlink($this->lockPath($sessionId));
            } catch (\Throwable) {
                $this->diagnostics->operationResidue('media_resumable_expired_session_cleanup_failed');
            }
            SchedulerSystem::yield();
        }
    }

    /** @template T @param callable(string):T $callback @return T */
    private function withSessionLock(string $sessionId, callable $callback): mixed
    {
        $directoryPath = $this->sessionDirectory($sessionId);
        if (!is_dir($directoryPath) || is_link(rtrim($directoryPath, DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException((string)__('分块上传会话不存在或已过期。'));
        }
        $lockHandle = @fopen($this->lockPath($sessionId), 'c+b');
        if ($lockHandle === false) {
            throw new \RuntimeException((string)__('无法打开分块上传会话锁。'));
        }
        $lock = $this->resources->stream($lockHandle);
        try {
            $this->acquireLock($lock, (string)__('分块上传会话正忙，请稍后重试。'));
            try {
                return $callback($directoryPath);
            } finally {
                @flock($lock->stream(), LOCK_UN);
            }
        } finally {
            $lock->close();
        }
    }

    /** @template T @param callable():T $callback @return T */
    private function withCatalogLock(callable $callback): mixed
    {
        $lockHandle = @fopen($this->catalogLockPath(), 'c+b');
        if ($lockHandle === false) {
            throw new \RuntimeException((string)__('无法打开分块上传目录锁。'));
        }
        $lock = $this->resources->stream($lockHandle);
        try {
            $this->acquireLock($lock, (string)__('分块上传目录正忙，请稍后重试。'));
            try {
                return $callback();
            } finally {
                @flock($lock->stream(), LOCK_UN);
            }
        } finally {
            $lock->close();
        }
    }

    private function acquireLock(StorageRequestStreamInterface $lock, string $busyMessage): void
    {
        $deadline = hrtime(true) + self::LOCK_TIMEOUT_NANOSECONDS;
        do {
            if (flock($lock->stream(), LOCK_EX | LOCK_NB)) {
                return;
            }
            $this->assertRequestAlive((string)__('客户端已断开，分块上传锁定已取消。'));
            SchedulerSystem::usleep(20_000);
        } while (hrtime(true) < $deadline);

        throw new \RuntimeException($busyMessage);
    }

    private function assertStagingQuota(
        int $actorId,
        int $expectedSize,
        string $diskCode,
        string $objectKey,
    ): void {
        $catalogCount = 0;
        $activeCount = 0;
        $actorActiveCount = 0;
        $reservedBytes = 0;
        $actorReservedBytes = 0;
        foreach (new \DirectoryIterator($this->baseDirectory()) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $sessionId = $entry->getFilename();
            if (preg_match('/^\.(?:delete|purge)-[a-f0-9]{32}-[a-f0-9]{8}$/D', $sessionId) === 1) {
                if (++$catalogCount >= self::MAX_CATALOG_DIRECTORIES) {
                    throw new \RuntimeException((string)__('分块上传暂存目录数量已达上限，请先清理过期会话。'));
                }
                continue;
            }
            if (preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
                continue;
            }
            if (++$catalogCount >= self::MAX_CATALOG_DIRECTORIES) {
                throw new \RuntimeException((string)__('分块上传暂存目录数量已达上限，请先清理过期会话。'));
            }
            try {
                $manifest = $this->readManifest($entry->getPathname() . DIRECTORY_SEPARATOR);
            } catch (\Throwable $throwable) {
                // Abort/purge claims a session by atomically renaming its
                // directory. A catalog scan that observed the former name can
                // safely ignore it once that exact directory has disappeared.
                if (!is_dir($entry->getPathname())) {
                    continue;
                }
                throw new \RuntimeException(
                    (string)__('分块上传暂存目录包含损坏会话，请先清理。'),
                    0,
                    $throwable,
                );
            }
            if (!in_array((string)($manifest['status'] ?? ''), ['uploading', 'completing', 'corrupt'], true)) {
                continue;
            }
            $manifestBytes = (int)($manifest['expected_size'] ?? 0);
            $manifestActorId = (int)($manifest['actor_id'] ?? 0);
            if ($manifestBytes < 1 || $manifestBytes > MediaAssetUploadService::MAX_ASSET_UPLOAD_BYTES) {
                throw new \RuntimeException((string)__('分块上传暂存目录包含损坏会话，请先清理。'));
            }
            ++$activeCount;
            $reservedBytes = $this->boundedByteSum($reservedBytes, $manifestBytes);
            if ($manifestActorId === $actorId) {
                ++$actorActiveCount;
                $actorReservedBytes = $this->boundedByteSum($actorReservedBytes, $manifestBytes);
            }
            if (hash_equals((string)($manifest['disk_code'] ?? ''), $diskCode)
                && hash_equals((string)($manifest['object_key'] ?? ''), $objectKey)
            ) {
                throw new \RuntimeException((string)__('目标文件已有进行中的分块上传。'));
            }
        }

        if ($activeCount >= self::MAX_ACTIVE_SESSIONS_GLOBAL) {
            throw new \RuntimeException((string)__('分块上传活动会话已达全局上限，请稍后重试。'));
        }
        if ($actorActiveCount >= self::MAX_ACTIVE_SESSIONS_PER_ACTOR) {
            throw new \RuntimeException((string)__('分块上传活动会话已达当前账号上限，请先完成或取消已有上传。'));
        }
        if ($this->boundedByteSum($reservedBytes, $expectedSize) > self::MAX_RESERVED_BYTES_GLOBAL) {
            throw new \RuntimeException((string)__('分块上传预留空间已达全局上限，请稍后重试。'));
        }
        if ($this->boundedByteSum($actorReservedBytes, $expectedSize) > self::MAX_RESERVED_BYTES_PER_ACTOR) {
            throw new \RuntimeException((string)__('分块上传预留空间已达当前账号上限，请先完成或取消已有上传。'));
        }
    }

    private function boundedByteSum(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            return PHP_INT_MAX;
        }
        return $left + $right;
    }

    /** @return array<string,mixed> */
    private function readOwnedManifest(string $directoryPath, string $sessionId, int $actorId): array
    {
        $manifest = $this->readManifest($directoryPath);
        if ((string)($manifest['session_id'] ?? '') !== $sessionId
            || (int)($manifest['actor_id'] ?? 0) !== $actorId
            || $actorId < 1
        ) {
            throw new \RuntimeException((string)__('无权访问分块上传会话。'));
        }
        $updatedAt = (int)($manifest['updated_at'] ?? 0);
        if ($updatedAt < 1 || time() - $updatedAt > self::TTL_SECONDS) {
            throw new \RuntimeException((string)__('分块上传会话已过期。'));
        }
        return $manifest;
    }

    /** @return array<string,mixed> */
    private function readManifest(string $directoryPath): array
    {
        $path = $directoryPath . 'session.json';
        $identity = $this->sealRegularFile($path);
        if ($identity['size'] < 2 || $identity['size'] > self::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException((string)__('分块上传会话凭据大小无效。'));
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法读取分块上传会话凭据。'));
        }
        $stream = $this->resources->stream($handle);
        $contents = '';
        try {
            $this->assertOpenFileIdentity($stream, $identity);
            while (!feof($stream->stream())) {
                $chunk = fread($stream->stream(), self::STREAM_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('读取分块上传会话凭据失败。'));
                }
                if ($chunk === '') {
                    break;
                }
                if (strlen($contents) + strlen($chunk) > self::MAX_MANIFEST_BYTES) {
                    throw new \RuntimeException((string)__('分块上传会话凭据超过大小限制。'));
                }
                $contents .= $chunk;
            }
            $this->assertOpenFileIdentity($stream, $identity);
        } finally {
            $stream->close();
        }
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException((string)__('分块上传会话凭据无效。'), 0, $exception);
        }
        if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1) {
            throw new \RuntimeException((string)__('分块上传会话凭据无效。'));
        }
        return $decoded;
    }

    /** @param array<string,mixed> $manifest */
    private function writeManifest(string $directoryPath, array $manifest): void
    {
        $encoded = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (strlen($encoded) > self::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException((string)__('分块上传会话凭据超过大小限制。'));
        }
        $temporary = $this->resources->temporaryFile($directoryPath, '.upload-meta-');
        $handle = @fopen($temporary->path(), 'wb');
        if ($handle === false) {
            $temporary->close();
            throw new \RuntimeException((string)__('无法创建分块上传会话凭据。'));
        }
        try {
            $stream = $this->resources->stream($handle);
        } catch (\Throwable $registrationFailure) {
            $temporary->close();
            throw $registrationFailure;
        }
        try {
            $this->writeAll($stream->stream(), $encoded);
            if (!fflush($stream->stream())) {
                throw new \RuntimeException((string)__('刷新分块上传会话凭据失败。'));
            }
            if (function_exists('fsync')) {
                @fsync($stream->stream());
            }
        } finally {
            $stream->close();
        }
        @chmod($temporary->path(), 0600);
        if (!@rename($temporary->path(), $directoryPath . 'session.json')) {
            $temporary->close();
            throw new \RuntimeException((string)__('提交分块上传会话凭据失败。'));
        }
        $temporary->close();
        @chmod($directoryPath . 'session.json', 0600);
        @touch(rtrim($directoryPath, DIRECTORY_SEPARATOR));
    }

    /** @param array<string,mixed> $manifest */
    private function writeCompletedManifest(string $directoryPath, array $manifest): void
    {
        if (($manifest['status'] ?? null) !== 'completed') {
            throw new \LogicException((string)__('分块上传完成凭据状态无效。'));
        }
        // The active-session manifest remains `completing` until this catalog
        // critical section. A concurrent start therefore either observes the
        // active target, or observes the committed object after this write.
        $this->withCatalogLock(function () use ($directoryPath, $manifest): void {
            $this->writeManifest($directoryPath, $manifest);
        });
    }

    /** @return array{0:string,1:string} */
    private function createSessionDirectory(): array
    {
        $base = $this->baseDirectory();
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $sessionId = bin2hex(random_bytes(16));
            $directoryPath = $base . $sessionId . DIRECTORY_SEPARATOR;
            if (@mkdir($directoryPath, 0700)) {
                $lock = @fopen($this->lockPath($sessionId), 'x+b');
                if ($lock !== false) {
                    fclose($lock);
                    @chmod($this->lockPath($sessionId), 0600);
                    return [$sessionId, $directoryPath];
                }
                @rmdir($directoryPath);
            }
        }
        throw new \RuntimeException((string)__('无法创建分块上传会话。'));
    }

    private function baseDirectory(): string
    {
        $base = rtrim(Env::VAR_DIR, '/\\') . DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'media-upload' . DIRECTORY_SEPARATOR;
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException((string)__('无法创建分块上传暂存目录。'));
        }
        if (is_link(rtrim($base, DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException((string)__('分块上传暂存目录无效。'));
        }
        $locks = $base . '.locks' . DIRECTORY_SEPARATOR;
        if (!is_dir($locks) && !@mkdir($locks, 0700, true) && !is_dir($locks)) {
            throw new \RuntimeException((string)__('无法创建分块上传锁目录。'));
        }
        if (is_link(rtrim($locks, DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException((string)__('分块上传锁目录无效。'));
        }
        return $base;
    }

    private function sessionDirectory(string $sessionId): string
    {
        return $this->baseDirectory() . $this->requireSessionId($sessionId) . DIRECTORY_SEPARATOR;
    }

    private function lockPath(string $sessionId): string
    {
        return $this->baseDirectory() . '.locks' . DIRECTORY_SEPARATOR
            . $this->requireSessionId($sessionId) . '.lock';
    }

    private function catalogLockPath(): string
    {
        return $this->baseDirectory() . '.locks' . DIRECTORY_SEPARATOR . 'catalog.lock';
    }

    private function requireSessionId(string $sessionId): string
    {
        $sessionId = strtolower(trim($sessionId));
        if (preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
            throw new \InvalidArgumentException((string)__('分块上传会话 ID 无效。'));
        }
        return $sessionId;
    }

    /** @return array{dev:int,ino:int,size:int} */
    private function sealRegularFile(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)
            || is_link($path)
            || (((int)($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($stat['nlink'] ?? 0) !== 1
            || (int)($stat['dev'] ?? -1) < 0
            || (int)($stat['ino'] ?? -1) < 0
            || (int)($stat['size'] ?? -1) < 0
        ) {
            throw new \RuntimeException((string)__('分块上传文件身份无效。'));
        }
        return [
            'dev' => (int)$stat['dev'],
            'ino' => (int)$stat['ino'],
            'size' => (int)$stat['size'],
        ];
    }

    /** @param array{dev:int,ino:int,size:int} $identity */
    private function assertOpenFileIdentity(StorageRequestStreamInterface $stream, array $identity): void
    {
        $stat = fstat($stream->stream());
        if (!is_array($stat)
            || (((int)($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($stat['nlink'] ?? 0) !== 1
            || (int)($stat['dev'] ?? -1) !== $identity['dev']
            || (int)($stat['ino'] ?? -1) !== $identity['ino']
            || (int)($stat['size'] ?? -1) !== $identity['size']
        ) {
            throw new \RuntimeException((string)__('分块上传文件身份已变化。'));
        }
    }

    /** @param array{dev:int,ino:int,size:int} $identity */
    private function hashFileRange(string $path, array $identity, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1 || $length > self::CHUNK_BYTES
            || $offset + $length > $identity['size']
        ) {
            throw new \RuntimeException((string)__('分块上传暂存文件大小不一致。'));
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法打开分块上传流。'));
        }
        $stream = $this->resources->stream($handle);
        $hash = hash_init('sha256');
        try {
            $this->assertOpenFileIdentity($stream, $identity);
            if (fseek($stream->stream(), $offset, SEEK_SET) !== 0) {
                throw new \RuntimeException((string)__('无法定位分块上传暂存文件。'));
            }
            $remaining = $length;
            while ($remaining > 0) {
                $bytes = fread($stream->stream(), min(self::STREAM_CHUNK_BYTES, $remaining));
                if ($bytes === false || $bytes === '') {
                    throw new \RuntimeException((string)__('读取上传分块失败。'));
                }
                $remaining -= strlen($bytes);
                hash_update($hash, $bytes);
                SchedulerSystem::yield();
            }
            $this->assertOpenFileIdentity($stream, $identity);
            return hash_final($hash);
        } finally {
            $stream->close();
        }
    }

    /**
     * @param array{dev:int,ino:int,size:int} $identity
     * @return array{dev:int,ino:int,size:int}
     */
    private function truncateRegularFile(string $path, array $identity, int $size): array
    {
        if ($size < 0 || $size > $identity['size']) {
            throw new \RuntimeException((string)__('分块上传暂存文件大小不一致。'));
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法打开分块上传流。'));
        }
        $stream = $this->resources->stream($handle);
        try {
            $this->assertOpenFileIdentity($stream, $identity);
            if (!@ftruncate($stream->stream(), $size) || !@fflush($stream->stream())) {
                throw new \RuntimeException((string)__('刷新分块上传暂存文件失败。'));
            }
            if (function_exists('fsync') && !@fsync($stream->stream())) {
                throw new \RuntimeException((string)__('持久化分块上传暂存文件失败。'));
            }
        } finally {
            $stream->close();
        }
        $resolved = $this->sealRegularFile($path);
        if ($resolved['size'] !== $size) {
            throw new \RuntimeException((string)__('分块上传暂存文件大小不一致。'));
        }
        return $resolved;
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $bytes): void
    {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($stream, substr($bytes, $offset, self::STREAM_CHUNK_BYTES));
            if ($written === false || $written === 0) {
                throw new \RuntimeException((string)__('写入分块上传文件失败。'));
            }
            $offset += $written;
        }
    }

    private function assertDirectoryExists(string $diskCode, string $directory): void
    {
        if ($directory === '') {
            return;
        }
        $parent = trim(dirname($directory), '/.');
        foreach ($this->directories->list($diskCode, $parent, false) as $entry) {
            if (($entry['type'] ?? null) === 'directory' && ($entry['path'] ?? null) === $directory) {
                return;
            }
        }
        throw new \RuntimeException((string)__('目标目录不存在。'));
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException(
                    (string)__('文件资源元数据格式无效。'),
                    0,
                    $exception,
                );
            }
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException((string)__('文件资源元数据格式无效。'));
        }
        return $value;
    }

    /** @param list<mixed> $values @return list<string> */
    private function normalizeStringList(array $values, int $maxItems, int $maxLength): array
    {
        if (!array_is_list($values) || count($values) > $maxItems) {
            throw new \InvalidArgumentException((string)__('上传白名单格式无效。'));
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException((string)__('上传白名单格式无效。'));
            }
            $value = strtolower(trim($value));
            if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x20\x7F]/', $value)) {
                throw new \InvalidArgumentException((string)__('上传白名单内容无效。'));
            }
            $result[$value] = $value;
        }
        return array_values($result);
    }

    /** @return array{name:string,tmp_name:string,type:string,error:int,size:int} */
    private function normalizeChunkRecord(array $chunk): array
    {
        if (is_array($chunk['name'] ?? null)) {
            if (count($chunk['name']) !== 1) {
                throw new \InvalidArgumentException((string)__('每个请求只能上传一个分块。'));
            }
            $chunk = [
                'name' => $chunk['name'][0] ?? '',
                'tmp_name' => $chunk['tmp_name'][0] ?? '',
                'type' => $chunk['type'][0] ?? '',
                'error' => $chunk['error'][0] ?? UPLOAD_ERR_NO_FILE,
                'size' => $chunk['size'][0] ?? 0,
            ];
        }
        return [
            'name' => (string)($chunk['name'] ?? ''),
            'tmp_name' => (string)($chunk['tmp_name'] ?? ''),
            'type' => (string)($chunk['type'] ?? ''),
            'error' => (int)($chunk['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => max(0, (int)($chunk['size'] ?? 0)),
        ];
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $resolved = $this->nonNegativeInt($value, $field);
        if ($resolved < 1) {
            throw new \InvalidArgumentException((string)__('上传文件大小无效。'));
        }
        return $resolved;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException((string)__('分块上传数值字段无效：%{1}', [$field]));
        }
        return $value;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function boundedResult(array $result): array
    {
        $allowed = [
            'asset_id', 'disk_code', 'object_key', 'original_name', 'name', 'mime', 'size',
            'width', 'height', 'default_locale', 'visibility', 'lifecycle_state',
            'asset_revision', 'sha256', 'hash', 'phash', 'path', 'ts',
        ];
        $bounded = [];
        foreach ($allowed as $key) {
            $value = $result[$key] ?? null;
            if (is_scalar($value) || $value === null) {
                $bounded[$key] = $value;
            }
        }
        return $bounded;
    }

    /**
     * Recover the narrow crash window after FileAsset commit but before the
     * small completion receipt is renamed. Exact bytes, path, locale metadata
     * and visibility must all match; otherwise the pre-existing target wins.
     *
     * @param array{dev:int,ino:int,size:int} $stageIdentity
     * @param array<string,mixed> $localeMetadata
     * @return array<string,mixed>|null
     */
    private function recoverCompletedAsset(
        string $diskCode,
        string $objectKey,
        string $filename,
        int $expectedSize,
        string $stagePath,
        array $stageIdentity,
        FileAccessContext $access,
        array $localeMetadata,
        string $visibility,
    ): ?array {
        $asset = $this->assets->describe($diskCode, $objectKey, $access->localeCode, $access);
        $assetId = trim((string)($asset['asset_id'] ?? ''));
        $sha256 = strtolower(trim((string)($asset['sha256'] ?? '')));
        if ($assetId === ''
            || empty($asset['asset_ready'])
            || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || !hash_equals($diskCode, (string)($asset['disk_code'] ?? ''))
            || !hash_equals($objectKey, (string)($asset['object_key'] ?? ''))
            || !hash_equals($filename, (string)($asset['original_name'] ?? ''))
            || (int)($asset['size'] ?? -1) !== $expectedSize
            || !hash_equals($visibility, (string)($asset['visibility'] ?? ''))
            || !hash_equals($access->localeCode, (string)($asset['default_locale'] ?? ''))
            || !hash_equals($access->localeCode, (string)($asset['locale_code'] ?? ''))
            || !hash_equals(
                FileAssetLibraryInterface::TRANSLATION_REVIEWED,
                (string)($asset['translation_state'] ?? ''),
            )
            || !hash_equals(
                FileAssetLibraryInterface::TRANSLATION_MANUAL,
                (string)($asset['translation_origin'] ?? ''),
            )
            || !hash_equals((string)($localeMetadata['display_name'] ?? ''), (string)($asset['display_name'] ?? ''))
            || !hash_equals((string)($localeMetadata['default_alt'] ?? ''), (string)($asset['default_alt'] ?? ''))
            || !hash_equals((string)($localeMetadata['description'] ?? ''), (string)($asset['description'] ?? ''))
            || !hash_equals((string)($localeMetadata['default_caption'] ?? ''), (string)($asset['default_caption'] ?? ''))
            || !hash_equals($sha256, $this->hashRegularFile($stagePath, $stageIdentity))
        ) {
            return null;
        }

        return $this->decorateAssetResult($asset, $objectKey, $filename, $expectedSize);
    }

    /** @param array<string,mixed> $asset @return array<string,mixed> */
    private function decorateAssetResult(
        array $asset,
        string $objectKey,
        string $filename,
        int $expectedSize,
    ): array {
        return array_replace($asset, [
            'hash' => $this->mediaStorage->encodeHash($objectKey),
            'phash' => $this->mediaStorage->encodeHash(trim(dirname($objectKey), '/.')),
            'name' => $filename,
            'path' => $objectKey,
            'size' => $expectedSize,
            'ts' => time(),
        ]);
    }

    /** @param array{dev:int,ino:int,size:int} $identity */
    private function hashRegularFile(string $path, array $identity): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法打开分块上传流。'));
        }
        $stream = $this->resources->stream($handle);
        $hash = hash_init('sha256');
        $emptyReads = 0;
        try {
            $this->assertOpenFileIdentity($stream, $identity);
            while (!feof($stream->stream())) {
                $this->assertRequestAlive((string)__('客户端已断开，上传分块已取消。'));
                $bytes = fread($stream->stream(), self::STREAM_CHUNK_BYTES);
                if ($bytes === false) {
                    throw new \RuntimeException((string)__('读取上传分块失败。'));
                }
                if ($bytes === '') {
                    if (feof($stream->stream())) {
                        break;
                    }
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('读取上传分块失败。'));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                hash_update($hash, $bytes);
                SchedulerSystem::yield();
            }
            $this->assertOpenFileIdentity($stream, $identity);
            return hash_final($hash);
        } finally {
            $stream->close();
        }
    }

    private function assertRequestAlive(string $message): void
    {
        $disconnected = function_exists('connection_aborted') && connection_aborted();
        if (!$disconnected
            && is_callable(SseContext::getAliveCallback())
            && !SseContext::isConnectionAlive()
        ) {
            $disconnected = true;
        }
        if ($disconnected) {
            throw new \RuntimeException($message);
        }
    }

    private function deleteFlatDirectory(string $directoryPath): void
    {
        $directory = rtrim($directoryPath, DIRECTORY_SEPARATOR);
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $entries = 0;
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (++$entries > 8 || $entry->isDir() || $entry->isLink()) {
                throw new \RuntimeException((string)__('分块上传暂存目录结构无效。'));
            }
            if (!@unlink($entry->getPathname()) && file_exists($entry->getPathname())) {
                throw new \RuntimeException((string)__('清理分块上传暂存文件失败。'));
            }
        }
        if (!@rmdir($directory) && is_dir($directory)) {
            throw new \RuntimeException((string)__('清理分块上传暂存目录失败。'));
        }
    }
}
