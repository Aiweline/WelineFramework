<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\StorageDirectoryManagerInterface;
use Weline\Storage\Api\StorageManagerInterface;

/** Unified FileAsset/Storage boundary used by AI-draw and trusted media consumers. */
final class MediaStorageService
{
    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const STREAM_CHUNK_BYTES = 1024 * 1024;
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];

    public function __construct(
        private readonly FileAssetLibraryInterface $assets,
        private readonly StorageManagerInterface $storage,
        private readonly StorageDirectoryManagerInterface $directories,
        private readonly StorageRequestResourceFactoryInterface $resourceFactory,
    ) {
    }

    public function encodeHash(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            $relativePath = '/';
        } else {
            StorageObjectReference::assertObjectKey($relativePath);
        }
        $encoded = rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=');

        return 'mm_' . $encoded;
    }

    public function decodeHash(string $hash): ?string
    {
        if (!str_starts_with($hash, 'mm_')) {
            return null;
        }
        $encoded = substr($hash, 3);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            return null;
        }
        $padded = $encoded . str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $encoded) {
            return null;
        }
        if ($decoded === '/') {
            return '';
        }
        $decoded = trim(str_replace('\\', '/', $decoded), '/');
        try {
            StorageObjectReference::assertObjectKey($decoded);
        } catch (\Throwable) {
            return null;
        }

        return $decoded;
    }

    public function objectKeyFromHash(string $hash, bool $allowRoot = false): string
    {
        $objectKey = $this->decodeHash($hash);
        if ($objectKey === null || (!$allowRoot && $objectKey === '')) {
            throw new \InvalidArgumentException((string)__('媒体文件目标无效。'));
        }

        return $objectKey;
    }

    /** @return array<string,mixed> */
    public function readFileBytes(
        string $diskCode,
        string $hash,
        FileAccessContext $access,
        int $maxBytes = self::MAX_IMAGE_BYTES,
    ): array {
        $disk = $this->storage->disk($diskCode);
        $objectKey = $this->objectKeyFromHash($hash);
        $descriptor = $this->assets->describe(
            $disk->diskCode(),
            $objectKey,
            $access->localeCode,
            $access,
        );
        if (empty($descriptor['asset_id']) || empty($descriptor['asset_ready'])) {
            throw new \RuntimeException((string)__('参考文件尚未建立可用的 FileAsset。'));
        }
        $declaredBytes = max(0, (int)($descriptor['size'] ?? 0));
        $maxBytes = max(1, min(self::MAX_IMAGE_BYTES, $maxBytes));
        if ($declaredBytes > $maxBytes) {
            throw new \RuntimeException((string)__('参考图片超过大小限制。'));
        }

        $handle = $disk->openRead($objectKey);
        $bytes = '';
        $emptyReads = 0;
        try {
            while (!$handle->eof()) {
                if (function_exists('connection_aborted') && connection_aborted()) {
                    throw new \RuntimeException((string)__('客户端已断开，图片读取已取消。'));
                }
                $chunk = $handle->read(self::STREAM_CHUNK_BYTES);
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('读取图片时连续无数据进展。'));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                if (strlen($bytes) + strlen($chunk) > $maxBytes) {
                    throw new \RuntimeException((string)__('参考图片超过大小限制。'));
                }
                $bytes .= $chunk;
                SchedulerSystem::yield();
            }
        } finally {
            $handle->close();
        }

        $mime = $this->detectImageMime($bytes);
        return [
            'asset_id' => (string)$descriptor['asset_id'],
            'disk_code' => $disk->diskCode(),
            'object_key' => $objectKey,
            'relative' => $objectKey,
            'bytes' => $bytes,
            'mime' => $mime,
            'hash' => $hash,
            'url' => $this->assets->resolveResourceUrl($disk->diskCode(), $objectKey, $access),
        ];
    }

    /**
     * @param array<string,mixed> $localeMetadata
     * @param array<string,mixed> $assetMetadata
     * @return array<string,mixed>
     */
    public function writeNewFile(
        string $diskCode,
        string $directoryHash,
        string $filename,
        string $bytes,
        string $mimeType,
        FileAccessContext $access,
        array $localeMetadata,
        string $visibility = FileAssetLibraryInterface::VISIBILITY_PUBLIC,
        array $assetMetadata = [],
    ): array {
        $filename = $this->sanitizeLeafName($filename)
            ?? throw new \InvalidArgumentException((string)__('文件名无效。'));
        $byteCount = strlen($bytes);
        if ($byteCount < 1 || $byteCount > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException((string)__('生成图片超过保存大小限制。'));
        }
        $disk = $this->storage->disk($diskCode);
        $directory = $this->objectKeyFromHash($directoryHash, true);
        $this->assertDirectoryExists($disk->diskCode(), $directory);
        $objectKey = trim(($directory === '' ? '' : $directory . '/') . $filename, '/');
        StorageObjectReference::assertObjectKey($objectKey);
        if ($disk->exists($objectKey)) {
            throw new \RuntimeException((string)__('同名文件已存在，请更换文件名。'));
        }

        $detectedMime = $this->detectImageMime($bytes);
        $claimedMime = strtolower(trim($mimeType));
        if ($claimedMime !== '' && $claimedMime !== 'application/octet-stream' && $claimedMime !== $detectedMime) {
            throw new \InvalidArgumentException((string)__('生成图片声明类型与实际内容不一致。'));
        }
        $metadata = MediaAssetUploadService::normalizeMetadata($localeMetadata, $filename);
        $imageInfo = @getimagesizefromstring($bytes);
        $width = is_array($imageInfo) ? max(0, (int)($imageInfo[0] ?? 0)) : 0;
        $height = is_array($imageInfo) ? max(0, (int)($imageInfo[1] ?? 0)) : 0;

        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException((string)__('无法创建图片保存流。'));
        }
        $source = $this->resourceFactory->stream($stream);
        try {
            $this->writeAll($source->stream(), $bytes);
            if (!rewind($source->stream())) {
                throw new \RuntimeException((string)__('无法重置图片保存流。'));
            }
            if ($visibility === FileAssetLibraryInterface::VISIBILITY_PRIVATE) {
                $assetMetadata['access_policy'] = [
                    'owner_actor_id' => $access->actorId,
                    'policy_revision' => $access->policyRevision,
                ];
            }
            $asset = $this->assets->upload(
                $disk->diskCode(),
                $objectKey,
                $source->stream(),
                $filename,
                $detectedMime,
                $access->localeCode,
                $access,
                $metadata,
                $visibility,
                array_replace(['upload_source' => 'media_manager_ai_draw'], $assetMetadata),
                $width > 0 ? $width : null,
                $height > 0 ? $height : null,
            );
        } finally {
            $source->close();
        }

        return array_replace($asset, [
            'hash' => $this->encodeHash($objectKey),
            'phash' => $this->encodeHash(trim(dirname($objectKey), '/.')),
            'name' => $filename,
            'mime' => $detectedMime,
            'size' => $byteCount,
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
            'ts' => time(),
            'path' => $objectKey,
        ]);
    }

    public function sanitizeLeafName(string $name): ?string
    {
        $name = trim($name);
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $name === '.' || $name === '..' || $length > 255
            || preg_match('//u', $name) !== 1
            || str_contains($name, '/') || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || basename($name) !== $name
        ) {
            return null;
        }

        return $name;
    }

    public function extensionForFormat(string $format): string
    {
        return match (strtolower(trim($format))) {
            'jpeg', 'jpg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
    }

    public function defaultVisibility(string $diskCode): string
    {
        $visibility = $this->storage->disk($diskCode)->snapshot()->visibility();
        if (!in_array($visibility, [
            FileAssetLibraryInterface::VISIBILITY_PUBLIC,
            FileAssetLibraryInterface::VISIBILITY_PRIVATE,
        ], true)) {
            throw new \RuntimeException((string)__('存储磁盘可见性配置无效。'));
        }

        return $visibility;
    }

    public function deleteFile(string $diskCode, string $hash, FileAccessContext $access): void
    {
        $this->assets->deleteObject(
            $this->storage->canonicalizeDiskCode($diskCode),
            $this->objectKeyFromHash($hash),
            $access,
        );
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

    private function detectImageMime(string $bytes): string
    {
        $mime = '';
        try {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $mime = is_string($detected) ? strtolower(trim($detected)) : '';
        } catch (\Throwable) {
        }
        if (!in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            $imageInfo = @getimagesizefromstring($bytes);
            $mime = is_array($imageInfo) ? strtolower(trim((string)($imageInfo['mime'] ?? ''))) : '';
        }
        if (!in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new \InvalidArgumentException((string)__('文件不是受支持的位图图片。'));
        }

        return $mime;
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $bytes): void
    {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length;) {
            $chunk = substr($bytes, $offset, self::STREAM_CHUNK_BYTES);
            $written = fwrite($stream, $chunk);
            if ($written === false || $written === 0) {
                throw new \RuntimeException((string)__('写入图片保存流失败。'));
            }
            $offset += $written;
            SchedulerSystem::yield();
        }
    }
}
